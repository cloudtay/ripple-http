<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Ripple\Http;

use Co\IO;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Ripple\Coroutine;
use Ripple\Http\Client\Connection;
use Ripple\Http\Client\ConnectionPool;
use Ripple\Socket\Tunnel\Http;
use Ripple\Socket\Tunnel\Socks5;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function Co\cancel;
use function Co\delay;
use function Co\getSuspension;
use function fclose;
use function fopen;
use function getenv;
use function implode;
use function in_array;
use function is_resource;
use function parse_url;
use function str_contains;
use function strtolower;

class Client
{
    /*** @var ConnectionPool */
    private ConnectionPool $connectionPool;

    /*** @var bool */
    private bool $pool;

    /*** @param array $config */
    public function __construct(private readonly array $config = [])
    {
        $pool       = $this->config['pool'] ?? 'off';
        $this->pool = in_array($pool, [true, 1, 'on'], true);

        if ($this->pool) {
            $this->connectionPool = new ConnectionPool();
        }
    }

    /**
     * @param RequestInterface $request
     * @param array            $option
     *
     * @return Response
     * @throws \Ripple\Stream\Exception\ConnectionException
     * @throws \Ripple\Stream\Exception\RuntimeException
     */
    public function request(RequestInterface $request, array $option = []): Response
    {
        $uri    = $request->getUri();
        $method = $request->getMethod();
        $scheme = $uri->getScheme();
        $host   = $uri->getHost();

        if (!$port = $uri->getPort()) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        if (!$path = $uri->getPath()) {
            $path = '/';
        }

        if ($query = $uri->getQuery()) {
            $query = "?{$query}";
        } else {
            $query = '';
        }

        if (!isset($option['proxy'])) {
            if ($scheme === 'http' && $httpProxy = getenv('http_proxy')) {
                $option['proxy'] = $httpProxy;
            } elseif ($scheme === 'https' && $httpsProxy = getenv('https_proxy')) {
                $option['proxy'] = $httpsProxy;
            }
        }

        $connection = $this->pullConnection(
            $host,
            $port,
            $scheme === 'https',
            $option['timeout'] ?? 0,
            $option['proxy'] ?? null
        );

        $write = fn (string|false $content) => $connection->stream->write($content);
        $tick  = fn (string|false $content) => $connection->tick($content);

        if ($captureWrite = $option['capture_write'] ?? null) {
            $write = fn (string|false $content) => $captureWrite($content, $write);
        }

        if ($captureRead = $option['capture_read'] ?? null) {
            $tick = fn (string|false $content) => $captureRead($content, $tick);
        }

        $suspension = getSuspension();
        $header     = "{$method} {$path}{$query} HTTP/1.1\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            $header .= "{$name}: " . implode(', ', $values) . "\r\n";
        }

        $write($header);
        if ($bodyStream = $request->getBody()) {
            if (!$request->getHeader('Content-Length')) {
                $size = $bodyStream->getSize();
                $size > 0 && $write("Content-Length: {$bodyStream->getSize()}\r\n");
            }

            if ($bodyStream->getMetadata('uri') === 'php://temp') {
                $write("\r\n");
                if ($bodyContent = $bodyStream->getContents()) {
                    $write($bodyContent);
                }
            } elseif ($bodyStream instanceof MultipartStream) {
                if (!$request->getHeader('Content-Type')) {
                    $write("Content-Type: multipart/form-data; boundary={$bodyStream->getBoundary()}\r\n");
                }
                $write("\r\n");
                try {
                    while (!$bodyStream->eof()) {
                        $write($bodyStream->read(8192));
                    }
                } catch (Throwable) {
                    $bodyStream->close();
                    $connection->stream->close();
                    throw new ConnectionException('Invalid body stream');
                }
            } else {
                throw new ConnectionException('Invalid body stream');
            }
        } else {
            $write("\r\n");
        }

        /*** Parse response process*/
        if ($timeout = $option['timeout'] ?? null) {
            $timeoutOID = delay(static function () use ($connection, $suspension) {
                Coroutine::throw(
                    $suspension,
                    new ConnectionException('Request timeout', ConnectionException::CONNECTION_TIMEOUT)
                );
            }, $timeout);
        }

        if ($sink = $option['sink'] ?? null) {
            $connection->setOutput(fopen($sink, 'wb'));
        }

        while (1) {
            try {
                $connection->stream->waitForReadable();
            } catch (Throwable $e) {
                if (isset($timeoutOID)) {
                    cancel($timeoutOID);
                }

                if ($sink && is_resource($sink)) {
                    fclose($sink);
                }

                $connection->stream->close();
                throw new ConnectionException(
                    'Connection closed by peer',
                    ConnectionException::CONNECTION_CLOSED,
                    null,
                    $connection->stream,
                    true
                );
            }

            $content = $connection->stream->readContinuously(8192);
            if ($content === '') {
                if (!$connection->stream->eof()) {
                    continue;
                }
                $response = $tick(false);
            } else {
                $response = $tick($content);
            }

            if ($response) {
                $k = implode(', ', $response->getHeader('Connection'));
                if (str_contains(strtolower($k), 'keep-alive') && $this->pool) {
                    /*** Push into connection pool*/
                    $this->pushConnection(
                        $connection,
                        ConnectionPool::generateConnectionKey($host, $port)
                    );
                    $connection->stream->cancelReadable();
                } else {
                    $connection->stream->close();
                }

                if (isset($timeoutOID)) {
                    cancel($timeoutOID);
                }

                if ($sink && is_resource($sink)) {
                    fclose($sink);
                }
                return $response;
            }
        }
    }

    /**
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int         $timeout
     * @param string|null $tunnel
     *
     * @return Connection
     * @throws ConnectionException
     */
    private function pullConnection(string $host, int $port, bool $ssl, int $timeout = 0, string|null $tunnel = null): Connection
    {
        if ($tunnel && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $tunnel = null;
        }
        if ($this->pool) {
            $connection = $this->connectionPool->pullConnection($host, $port, $ssl, $timeout, $tunnel);
        } else {
            if ($tunnel) {
                $parse = parse_url($tunnel);
                if (!isset($parse['host'], $parse['port'])) {
                    throw new ConnectionException('Invalid proxy address', ConnectionException::CONNECTION_ERROR);
                }
                $payload = [
                    'host' => $host,
                    'port' => $port,
                ];
                if (isset($parse['user'], $parse['pass'])) {
                    $payload['username'] = $parse['user'];
                    $payload['password'] = $parse['pass'];
                }

                switch ($parse['scheme']) {
                    case 'socks':
                    case 'socks5':
                        $tunnelSocket = Socks5::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
                        $ssl && IO::Socket()->enableSSL($tunnelSocket, $timeout);
                        $connection = new Connection($tunnelSocket);
                        break;
                    case 'http':
                        $tunnelSocket = Http::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
                        $ssl && IO::Socket()->enableSSL($tunnelSocket, $timeout);
                        $connection = new Connection($tunnelSocket);
                        break;
                    case 'https':
                        $tunnel       = IO::Socket()->connectWithSSL("tcp://{$parse['host']}:{$parse['port']}", $timeout);
                        $tunnelSocket = Http::connect($tunnel, $payload)->getSocketStream();
                        $ssl && IO::Socket()->enableSSL($tunnelSocket, $timeout);
                        $connection = new Connection($tunnelSocket);
                        break;
                    default:
                        throw new ConnectionException('Unsupported proxy protocol', ConnectionException::CONNECTION_ERROR);
                }
            } else {
                $connection = $ssl
                    ? new Connection(IO::Socket()->connectWithSSL("ssl://{$host}:{$port}", $timeout))
                    : new Connection(IO::Socket()->connect("tcp://{$host}:{$port}", $timeout));
            }
        }

        $connection->stream->setBlocking(false);
        return $connection;
    }

    /**
     * @param Connection $connection
     * @param string     $key
     *
     * @return void
     */
    private function pushConnection(Connection $connection, string $key): void
    {
        if ($this->pool) {
            $this->connectionPool->pushConnection($connection, $key);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 14:32
     * @return ConnectionPool
     */
    public function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool;
    }
}
