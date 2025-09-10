<?php declare(strict_types=1);

use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ripple\Http\Guzzle\Client;
use Ripple\Http\Server\Request;
use Ripple\Stream\Exception\ConnectionException;

/**
 *
 */
class HttpAsyncTest extends TestCase
{
    private string $testServer = 'http://127.0.0.1:8008';

    /**
     * @return void
     * @throws GuzzleException
     * @throws ConnectionException
     */
    #[Test]
    public function testStreamDownload(): void
    {
        $size   = 10 * 1024 * 1024;
        $server = new Ripple\Http\Server($this->testServer);
        $server->onRequest(function (Request $request) use ($size) {
            $generator = function () use ($size) {
                $sent  = 0;
                $chunk = 8192;
                while ($sent < $size) {
                    $remaining = $size - $sent;
                    $toSend    = \min($chunk, $remaining);
                    yield \str_repeat('a', $toSend);
                    $sent += $toSend;
                }

                yield false;
            };

            $request->respond($generator(), [
                'Content-Type'   => 'application/octet-stream',
                'Content-Length' => \strval($size),
            ]);
        });
        $server->listen();
        $tempFile = \tempnam(\sys_get_temp_dir(), 'download');
        $client   = new Client();
        $client->get($this->testServer, [
            'sink' => $tempFile,
        ]);

        $this->assertEquals($size, \filesize($tempFile));
    }

    /**
     * Test data transfer under simulated slow bandwidth conditions
     *
     * @return void
     * @throws GuzzleException
     * @throws ConnectionException
     */
    #[Test]
    public function testSlowBandwidthTransfer(): void
    {
        $size = 1 * 1024 * 1024;
        $chunk = 4096;
        $pattern = \md5(\uniqid('slow_bandwidth_test', true));

        $server = new Ripple\Http\Server($this->testServer);
        $server->onRequest(function (Request $request) use ($size, $pattern, $chunk) {
            $generator = function () use ($size, $pattern, $chunk) {
                $sent = 0;
                $patternLength = \strlen($pattern);

                while ($sent < $size) {
                    \Co\sleep(0.1);
                    $remaining = $size - $sent;
                    $toSend = \min($chunk, $remaining);

                    $data = '';
                    $dataSize = 0;
                    while ($dataSize < $toSend) {
                        $remainingInChunk = $toSend - $dataSize;
                        if ($remainingInChunk >= $patternLength) {
                            $data .= $pattern;
                            $dataSize += $patternLength;
                        } else {
                            $data .= \substr($pattern, 0, $remainingInChunk);
                            $dataSize += $remainingInChunk;
                        }
                    }

                    yield $data;
                    $sent += $toSend;
                }

                yield false;
            };

            $request->respond($generator(), [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => \strval($size),
            ]);
        });

        $server->listen();
        $tempFile = \tempnam(\sys_get_temp_dir(), 'slow_bandwidth_test');

        $client = new Client(['timeout' => 0]);

        $response = $client->get($this->testServer, [
            'sink' => $tempFile,
        ]);

        $this->assertEquals($size, \filesize($tempFile));

        $fileContent = \file_get_contents($tempFile);
        $patternLength = \strlen($pattern);

        for ($i = 0; $i < 10; $i++) {
            $offset = $i * $patternLength;
            if ($offset + $patternLength <= $size) {
                $chunk = \substr($fileContent, $offset, $patternLength);
                $this->assertEquals($pattern, $chunk, "Data integrity failed at offset {$offset}");
            }
        }

        $middleOffset = (int)($size / 2);
        $middleOffset = $middleOffset - ($middleOffset % $patternLength);
        $middleChunk = \substr($fileContent, $middleOffset, $patternLength);
        $this->assertEquals($pattern, $middleChunk, "Data integrity failed in the middle at offset {$middleOffset}");

        $endOffset = $size - $patternLength;
        $endOffset = $endOffset - ($endOffset % $patternLength);
        $endChunk = \substr($fileContent, $endOffset, $patternLength);
        $this->assertEquals($pattern, $endChunk, "Data integrity failed at the end at offset {$endOffset}");

        \unlink($tempFile);
    }
}
