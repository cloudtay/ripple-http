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

namespace Ripple\Http\Server;

use Closure;
use Generator;
use Ripple\Socket;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function basename;
use function Co\promise;
use function filesize;
use function implode;
use function is_array;
use function is_resource;
use function is_string;
use function str_contains;
use function strlen;
use function strtolower;
use function strval;

/**
 * response entity
 */
class Response
{
    /*** @var mixed */
    protected mixed $body;

    /*** @var array */
    protected array $headers = [];

    /*** @var array */
    protected array $cookies = [];

    /*** @var int */
    protected int $statusCode = 200;

    /*** @var string */
    protected string $statusText = 'OK';

    /**
     * @param Socket $stream
     */
    public function __construct(private readonly Socket $stream)
    {
    }

    /**
     * @param mixed $content
     *
     * @return $this
     */
    public function setContent(mixed $content): static
    {
        return $this->setBody($content);
    }

    /**
     * @param mixed $content
     *
     * @return static
     */
    public function setBody(mixed $content): static
    {
        if (is_string($content)) {
            $this->withHeader('Content-Length', strval(strlen($content)));
        } elseif ($content instanceof Generator) {
            $this->removeHeader('Content-Length');
        } elseif ($content instanceof Stream) {
            $path   = $content->getMetadata('uri');
            $length = filesize($path);
            $this->withHeader('Content-Length', strval($length));
            $this->withHeader('Content-Type', 'application/octet-stream');
            if (!$this->getHeader('Content-Disposition')) {
                $this->withHeader('Content-Disposition', 'attachment; filename=' . basename($path));
            }
        } elseif (is_resource($content)) {
            return $this->setBody(new Stream($content));
        } elseif ($content instanceof Closure) {
            return $this->setBody($content());
        }

        $this->body = $content;
        return $this;
    }

    /**
     * @param string       $name
     * @param string|array $value
     *
     * @return $this
     */
    public function withHeader(string $name, string|array $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function removeHeader(string $name): static
    {
        unset($this->headers[$name]);
        return $this;
    }

    /**
     * @param string|null $name
     *
     * @return mixed
     */
    public function getHeader(string $name = null): mixed
    {
        if (!$name) {
            return $this->headers;
        }
        return $this->headers[$name] ?? null;
    }

    /**
     * @param int|null $statusCode
     *
     * @return static
     * @throws ConnectionException
     */
    public function sendHeaders(int|null $statusCode = null): static
    {
        if ($statusCode) {
            $this->setStatusCode($statusCode);
        }

        $this->stream->writeInternal($this->buildPacket('header'), false);
        return $this;
    }

    /**
     * @param string $type
     *
     * @return string
     */
    private function buildPacket(string $type): string
    {
        switch ($type) {
            case 'status':
                return "HTTP/1.1 {$this->getStatusCode()} {$this->statusText}\r\n";
            case 'header':
                $content = '';
                foreach ($this->headers as $name => $values) {
                    if (is_string($values)) {
                        $content .= "$name: $values\r\n";
                    } elseif (is_array($values)) {
                        $content .= "$name: " . implode(', ', $values) . "\r\n";
                    }
                }
                foreach ($this->cookies as $cookie) {
                    $content .= 'Set-Cookie: ' . $cookie . "\r\n";
                }
                return $content;
            default:
                return '';
        }
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @param int $statusCode
     *
     * @return $this
     */
    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/1 14:12
     * @return Socket
     */
    public function getStream(): Socket
    {
        return $this->stream;
    }

    /**
     * @return static
     * @throws ConnectionException
     */
    public function sendStatus(): static
    {
        $this->stream->writeInternal($this->buildPacket('status'), false);
        return $this;
    }

    /**
     * @return void
     */
    public function respond(): void
    {
        try {
            $packet = $this->buildPacket('status') . $this->buildPacket('header');

            if (is_string($this->body)) {
                $this->stream->write("{$packet}\r\n{$this->body}");
            } else {
                $this->stream->write("{$packet}\r\n");
                $this->sendContent();
            }
        } catch (Throwable) {
            $this->stream->close();
            return;
        }

        $headerConnection = $this->getHeader('Connection');
        if (!$headerConnection) {
            $this->stream->close();
        } else {
            if (is_array($headerConnection)) {
                $headerConnection = implode(',', $headerConnection);
            }

            if (!str_contains(strtolower($headerConnection), 'keep-alive')) {
                $this->stream->close();
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/1 11:37
     * @return Response
     * @throws ConnectionException|Throwable
     */
    public function sendContent(): static
    {
        // An exception occurs during transfer with the HTTP client and the currently open file stream should be closed.
        if (is_string($this->body)) {
            $this->stream->write($this->body);
        } elseif ($this->body instanceof Stream) {
            promise(function (Closure $resolve, Closure $reject) {
                $this->body->onReadable(function (Stream $body) use ($resolve, $reject) {
                    $content = '';
                    while ($buffer = $body->read(8192)) {
                        $content .= $buffer;
                    }

                    try {
                        $this->stream->write($content);
                    } catch (Throwable $exception) {
                        $body->close();
                        $reject($exception);
                    }

                    if ($body->eof()) {
                        $body->close();
                        $resolve();
                    }
                });
            })->await();
        } elseif ($this->body instanceof Generator) {
            foreach ($this->body as $content) {
                $this->stream->write($content);
            }
            if ($this->body->getReturn() === false) {
                $this->stream->close();
            }
        } else {
            throw new ConnectionException('The response content is illegal.', ConnectionException::ERROR_ILLEGAL_CONTENT);
        }
        return $this;
    }

    /**
     * @param array $headers
     *
     * @return $this
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }

    /**
     * @param array $cookies
     *
     * @return $this
     */
    public function withCookies(array $cookies): static
    {
        foreach ($cookies as $name => $value) {
            $this->withCookie($name, $value);
        }
        return $this;
    }

    /**
     * @param string $name
     * @param string $value
     *
     * @return $this
     */
    public function withCookie(string $name, string $value): static
    {
        $this->cookies[$name] = $value;
        return $this;
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function getCookie(string $name): mixed
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * @return string
     */
    public function getStatusText(): string
    {
        return $this->statusText;
    }

    /**
     * @param string $statusText
     *
     * @return $this
     */
    public function setStatusText(string $statusText): static
    {
        $this->statusText = $statusText;
        return $this;
    }
}
