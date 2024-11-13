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

namespace Ripple\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Ripple\Http\Client\Capture;
use Ripple\Http\Client\Connection;
use Ripple\Http\Client\ConnectionPool;
use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Tunnel\Http;
use Ripple\Tunnel\Socks5;
use Throwable;

use function getenv;
use function implode;
use function in_array;
use function parse_url;
use function str_contains;
use function strtolower;

class Client
{
    /*** @var ConnectionPool|null */
    private ConnectionPool|null $connectionPool = null;

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
     */
    public function request(RequestInterface $request, array $option = []): Response
    {
        $uri    = $request->getUri();
        $scheme = $uri->getScheme();
        $host   = $uri->getHost();

        if (!$port = $uri->getPort()) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        if (!isset($option['proxy'])) {
            if ($scheme === 'http' && $httpProxy = getenv('http_proxy')) {
                $option['proxy'] = $httpProxy;
            } elseif ($scheme === 'https' && $httpsProxy = getenv('https_proxy')) {
                $option['proxy'] = $httpsProxy;
            }
        }

        $capture = $option['capture'] ?? null;

        try {
            $connection = $this->pullConnection(
                $host,
                $port,
                $scheme === 'https',
                $option['timeout'] ?? 0,
                $option['proxy'] ?? null
            );
        } catch (Throwable $exception) {
            if ($capture instanceof Capture) {
                $capture->onFail($exception);
            }
            throw $exception;
        }

        try {
            $response = $connection->request($request, $option);
        } catch (Throwable $exception) {
            if ($capture instanceof Capture) {
                $capture->onError($exception);
            }
            throw $exception;
        }
        $keepAlive = implode(', ', $response->getHeader('Connection'));
        if (str_contains(strtolower($keepAlive), 'keep-alive') && $this->pool) {
            /*** Push into connection pool*/
            $this->connectionPool?->pushConnection($connection, ConnectionPool::generateConnectionKey($host, $port));
            $connection->stream->cancelReadable();
        } else {
            $connection->stream->close();
        }
        return $response;
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
                        $tunnelSocket = Socks5::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocket();
                        $ssl && $tunnelSocket->enableSSL();
                        $connection = new Connection($tunnelSocket);
                        break;
                    case 'http':
                        $tunnelSocket = Http::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocket();
                        $ssl && $tunnelSocket->enableSSL();
                        $connection = new Connection($tunnelSocket);
                        break;
                    case 'https':
                        $tunnel       = Socket::connectWithSSL("tcp://{$parse['host']}:{$parse['port']}", $timeout);
                        $tunnelSocket = Http::connect($tunnel, $payload)->getSocket();
                        $ssl && $tunnelSocket->enableSSL();
                        $connection = new Connection($tunnelSocket);
                        break;
                    default:
                        throw new ConnectionException('Unsupported proxy protocol', ConnectionException::CONNECTION_ERROR);
                }
            } else {
                $connection = $ssl
                    ? new Connection(Socket::connectWithSSL("ssl://{$host}:{$port}", $timeout))
                    : new Connection(Socket::connect("tcp://{$host}:{$port}", $timeout));
            }
        }

        $connection->stream->setBlocking(false);
        return $connection;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 14:32
     * @return ConnectionPool|null
     */
    public function getConnectionPool(): ConnectionPool|null
    {
        return $this->connectionPool;
    }
}
