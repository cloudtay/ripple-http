<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Http\Client;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ripple\Coroutine\Coroutine;
use Ripple\Coroutine\WaitGroup;
use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream\Exception\RuntimeException;
use Throwable;

use function Co\cancel;
use function Co\delay;
use function Co\getSuspension;
use function count;
use function ctype_xdigit;
use function explode;
use function fclose;
use function fopen;
use function fwrite;
use function hexdec;
use function implode;
use function intval;
use function is_resource;
use function strlen;
use function strpos;
use function strtok;
use function strtoupper;
use function substr;

/**
 * @Author cclilshy
 * @Date   2024/8/27 21:47
 */
class Connection
{
    /*** @var int */
    private int $step = 0;

    /*** @var int */
    private int $statusCode = 0;

    /*** @var string */
    private string $statusMessage = '';

    /*** @var int */
    private int $contentLength = 0;

    /*** @var array */
    private array $headers = [];

    /*** @var string */
    private string $content = '';

    /*** @var int */
    private int $bodyLength = 0;

    /*** @var string */
    private string $versionString = '';

    /*** @var string */
    private string $buffer = '';

    /*** @var bool */
    private bool $chunk = false;

    /*** @var int */
    private int $chunkLength = 0;

    /*** @var int */
    private int $chunkStep = 0;

    /*** @var mixed|null */
    private mixed $output = null;

    /*** @var \Ripple\Http\Client\Capture|null */
    private Capture|null $capture = null;

    private WaitGroup $waitGroup;

    /**
     * @param Socket $stream
     */
    public function __construct(public Socket $stream)
    {
        $this->waitGroup = new WaitGroup();
        $this->reset();
    }

    /**
     * @return void
     */
    private function reset(): void
    {
        if ($this->output) {
            if (is_resource($this->output)) {
                fclose($this->output);
                $this->output = null;
            }
        }

        $this->step          = 0;
        $this->statusCode    = 0;
        $this->statusMessage = '';
        $this->contentLength = 0;
        $this->headers       = [];
        $this->content       = '';
        $this->bodyLength    = 0;
        $this->versionString = '';
        $this->buffer        = '';
        $this->chunk         = false;
        $this->chunkLength   = 0;
        $this->chunkStep     = 0;
        $this->capture = null;
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param array                              $option
     *
     * @return \GuzzleHttp\Psr7\Response
     * @throws \Ripple\Stream\Exception\ConnectionException
     * @throws \Ripple\Stream\Exception\RuntimeException
     */
    public function request(RequestInterface $request, array $option = []): Response
    {
        $this->waitGroup->wait();
        $this->waitGroup->add();
        try {
            return $this->queue($request, $option);
        } finally {
            $this->reset();
            $this->waitGroup->done();
        }
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param array                              $option
     *
     * @return \GuzzleHttp\Psr7\Response
     * @throws \Ripple\Stream\Exception\ConnectionException
     * @throws \Ripple\Stream\Exception\RuntimeException
     */
    private function queue(RequestInterface $request, array $option = []): Response
    {
        $uri    = $request->getUri();
        $method = $request->getMethod();

        if (!$path = $uri->getPath()) {
            $path = '/';
        }

        if ($query = $uri->getQuery()) {
            $query = "?{$query}";
        } else {
            $query = '';
        }

        $this->capture = $capture = $option['capture'] ?? null;
        if (!$capture instanceof Capture) {
            $capture = null;
        }

        $suspension = getSuspension();
        $header     = "{$method} {$path}{$query} HTTP/1.1\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            $header .= "{$name}: " . implode(', ', $values) . "\r\n";
        }

        $this->stream->write($header);
        if ($bodyStream = $request->getBody()) {
            if (!$request->getHeader('Content-Length')) {
                $size = $bodyStream->getSize();
                $size > 0 && $this->stream->write("Content-Length: {$bodyStream->getSize()}\r\n");
            }

            if ($bodyStream->getMetadata('uri') === 'php://temp') {
                $this->stream->write("\r\n");
                if ($bodyContent = $bodyStream->getContents()) {
                    $this->stream->write($bodyContent);
                }
            } elseif ($bodyStream instanceof MultipartStream) {
                if (!$request->getHeader('Content-Type')) {
                    $this->stream->write("Content-Type: multipart/form-data; boundary={$bodyStream->getBoundary()}\r\n");
                }
                $this->stream->write("\r\n");
                try {
                    while (!$bodyStream->eof()) {
                        $this->stream->write($bodyStream->read(8192));
                    }
                } catch (Throwable) {
                    $bodyStream->close();
                    $this->stream->close();
                    throw new ConnectionException('Invalid body stream');
                }
            } else {
                throw new ConnectionException('Invalid body stream');
            }
        } else {
            $this->stream->write("\r\n");
        }

        /*** Parse response process*/
        if ($timeout = $option['timeout'] ?? null) {
            $timeoutOID = delay(static function () use ($suspension) {
                Coroutine::throw(
                    $suspension,
                    new ConnectionException('Request timeout', ConnectionException::CONNECTION_TIMEOUT)
                );
            }, $timeout);
        }

        if ($sink = $option['sink'] ?? null) {
            $this->setOutput(fopen($sink, 'wb'));
        }

        while (1) {
            try {
                $this->stream->waitForReadable();
            } catch (Throwable $e) {
                if (isset($timeoutOID)) {
                    cancel($timeoutOID);
                }

                if ($sink && is_resource($sink)) {
                    fclose($sink);
                }

                $this->stream->close();
                throw new ConnectionException(
                    'Connection closed by peer',
                    ConnectionException::CONNECTION_CLOSED,
                    null,
                    $this->stream,
                    true
                );
            }

            $content = $this->stream->readContinuously(8192);
            if ($content === '') {
                if (!$this->stream->eof()) {
                    continue;
                }
                $response = $this->tickClose();
            } else {
                $response = $this->tick($content);
            }
            if ($response) {
                if (isset($timeoutOID)) {
                    cancel($timeoutOID);
                }

                if ($sink && is_resource($sink)) {
                    fclose($sink);
                }

                $this->capture?->onComplete($response);
                return $response;
            }
        }
    }

    /**
     * @param mixed $resource
     *
     * @return void
     */
    public function setOutput(mixed $resource): void
    {
        $this->output = $resource;
    }

    /**
     * @param string|false $content
     *
     * @return ResponseInterface|null
     * @throws RuntimeException
     */
    public function tick(string|false $content): ResponseInterface|null
    {
        if ($content === false) {
            return $this->tickClose();
        }
        $this->buffer .= $content;
        return $this->process();
    }

    /**
     * @return ResponseInterface|null
     * @throws \Ripple\Stream\Exception\RuntimeException
     */
    public function tickClose(): ResponseInterface|null
    {
        if (!$this->headers) {
            throw new RuntimeException('Response header is required');
        } elseif (isset($this->headers['CONTENT-LENGTH'])) {
            throw new RuntimeException('Response content length is required');
        } elseif ($this->chunk) {
            throw new RuntimeException('Response chunked is required');
        } else {
            $this->step = 2;
        }
        return $this->process();
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface|null
     * @throws \Ripple\Stream\Exception\RuntimeException
     */
    public function process(): ResponseInterface|null
    {
        if ($this->step === 0) {
            if ($headerEnd = strpos($this->buffer, "\r\n\r\n")) {
                $buffer = $this->freeBuffer();

                /**
                 * Cut and parse the head and body parts
                 */
                $this->step = 1;
                $header     = substr($buffer, 0, $headerEnd);
                $firstLine  = strtok($header, "\r\n");

                if (count($base = explode(' ', $firstLine)) < 3) {
                    throw new RuntimeException('Request head is not match: ' . $firstLine);
                }

                $this->versionString = $base[0];
                $this->statusCode    = intval($base[1]);
                $this->statusMessage = $base[2];

                /*** Parse header*/
                while ($line = strtok("\r\n")) {
                    $lineParam = explode(': ', $line, 2);
                    if (count($lineParam) >= 2) {
                        $this->headers[strtoupper($lineParam[0])] = $lineParam[1];
                    }
                }

                if ($this->chunk = isset($this->headers['TRANSFER-ENCODING']) && $this->headers['TRANSFER-ENCODING'] === 'chunked') {
                    $this->step   = 3;
                    $this->buffer = substr($buffer, $headerEnd + 4);
                } else {
                    $contentLength = $this->headers['CONTENT-LENGTH'] ?? $this->headers['CONTENT-LENGTH'] ?? null;
                    if ($contentLength !== null) {
                        $this->contentLength = intval($contentLength);
                        $buffer              = substr($buffer, $headerEnd + 4);
                        $this->output($buffer);
                        $this->bodyLength += strlen($buffer);
                        if ($this->bodyLength === $this->contentLength) {
                            $this->step = 2;
                        }
                    }
                }

                $this->capture?->processHeader($this->headers);
            }
        }

        if ($this->step === 1 && $buffer = $this->freeBuffer()) {
            $this->output($buffer);
            $this->bodyLength += strlen($buffer);
            if ($this->bodyLength === $this->contentLength) {
                $this->step = 2;
            }
        }

        if ($this->step === 3 && $buffer = $this->freeBuffer()) {
            do {
                if ($this->chunkStep === 0) {
                    $chunkEnd = strpos($buffer, "\r\n");
                    if ($chunkEnd === false) {
                        break;
                    }

                    $chunkLengthHex = substr($buffer, 0, $chunkEnd);
                    if (!ctype_xdigit($chunkLengthHex)) {
                        throw new RuntimeException("Invalid chunk length: " . $chunkLengthHex);
                    }

                    $this->chunkLength = hexdec($chunkLengthHex);
                    $buffer            = substr($buffer, $chunkEnd + 2);

                    if ($this->chunkLength === 0) {
                        if (strlen($buffer) < 2) {
                            break;
                        }
                        $buffer     = substr($buffer, 2);
                        $this->step = 2;
                        break;
                    }

                    $this->chunkStep = 1;
                } else {
                    if (strlen($buffer) < $this->chunkLength + 2) {
                        break;
                    }

                    $chunkData = substr($buffer, 0, $this->chunkLength);
                    $this->output($chunkData);
                    $buffer          = substr($buffer, $this->chunkLength + 2);
                    $this->chunkStep = 0;
                }
            } while ($this->step !== 2);
            $this->buffer = $buffer;
        }

        if ($this->step === 2) {
            return new Response(
                $this->statusCode,
                $this->headers,
                $this->content,
                $this->versionString,
                $this->statusMessage,
            );
        }
        return null;
    }

    /**
     * @return string
     */
    private function freeBuffer(): string
    {
        $buffer       = $this->buffer;
        $this->buffer = '';
        return $buffer;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 21:47
     *
     * @param string $content
     *
     * @return void
     */
    private function output(string $content): void
    {
        if ($this->output) {
            fwrite($this->output, $content);
        } else {
            $this->content .= $content;
        }

        $this->capture?->processContent($content);
    }
}
