<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ripple\Http\Guzzle;
use Ripple\Http\Server\Request;

/**
 *
 */
class HttpAsyncTest extends TestCase
{
    private string $testServer = 'http://127.0.0.1:8008';

    /**
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Ripple\Stream\Exception\ConnectionException
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
        $client   = Guzzle::newClient();
        $client->get($this->testServer, [
            'sink' => $tempFile,
        ]);

        $this->assertEquals($size, \filesize($tempFile));
    }
}
