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

use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Tunnel\Http;
use Ripple\Tunnel\Socks5;
use Throwable;

use function array_pop;
use function Co\cancel;
use function Co\cancelForked;
use function Co\forked;
use function parse_url;

class ConnectionPool
{
    /*** @var array */
    private array $idleConnections = [];

    /*** @var array */
    private array $listenEventMap = [];

    /*** @var string */
    private string $forkEventId;

    public function __construct()
    {
        $this->registerForkHandler();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @return void
     */
    private function registerForkHandler(): void
    {
        $this->forkEventId = forked(function () {
            $this->registerForkHandler();
            $this->clearConnectionPool();
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     *
     * @param string|null $key
     *
     * @return void
     */
    public function clearConnectionPool(string|null $key = null): void
    {
        if ($key) {
            if (!isset($this->idleConnections[$key])) {
                return;
            }
            foreach ($this->idleConnections[$key] as $connection) {
                $connection->stream->close();
            }
            unset($this->idleConnections[$key]);
            return;
        }

        foreach ($this->idleConnections as $keyI => $connections) {
            foreach ($connections as $keyK => $connection) {
                $connection->stream->close();
                unset($this->idleConnections[$keyI][$keyK]);
            }
        }
    }

    public function __destruct()
    {
        $this->clearConnectionPool();
        cancelForked($this->forkEventId);
    }

    /**
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int|float   $timeout
     * @param string|null $proxy http://username:password@proxy.example.com:8080
     *
     * @return Connection
     * @throws ConnectionException
     */
    public function pullConnection(
        string      $host,
        int         $port,
        bool        $ssl = false,
        int|float   $timeout = 0,
        string|null $proxy = null,
    ): Connection {
        $key = ConnectionPool::generateConnectionKey($host, $port);
        if (!isset($this->idleConnections[$key]) || empty($this->idleConnections[$key])) {
            return $this->createConnection($host, $port, $ssl, $timeout, $proxy);
        } else {
            /**
             * @var Connection $connection
             */
            $connection = array_pop($this->idleConnections[$key]);
            if (empty($this->idleConnections[$key])) {
                unset($this->idleConnections[$key]);
            }

            cancel($this->listenEventMap[$connection->stream->id]);
            unset($this->listenEventMap[$connection->stream->id]);
            return $connection;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 09:43
     *
     * @param string $host
     * @param int    $port
     *
     * @return string
     */
    public static function generateConnectionKey(string $host, int $port): string
    {
        return "{$host}:{$port}";
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     *
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int|float   $timeout
     * @param string|null $proxy
     *
     * @return Connection
     * @throws ConnectionException
     */
    private function createConnection(string $host, int $port, bool $ssl, int|float $timeout, string|null $proxy = null): Connection
    {
        if ($proxy) {
            $parse = parse_url($proxy);
            if (!isset($parse['host'], $parse['port'])) {
                throw new ConnectionException('Invalid proxy address', ConnectionException::CONNECTION_ERROR);
            }
            $payload = ['host' => $host, 'port' => $port];
            if (isset($parse['user'], $parse['pass'])) {
                $payload['username'] = $parse['user'];
                $payload['password'] = $parse['pass'];
            }
            $proxySocket = $this->createProxySocket($parse, $payload);
            $ssl && $proxySocket->enableSSL();
            return new Connection($proxySocket);
        }

        $stream = Socket::connect("tcp://{$host}:{$port}", $timeout);
        $ssl && $stream->enableSSL();
        return new Connection($stream);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     *
     * @param array $parse
     * @param array $payload
     *
     * @return Socket
     * @throws ConnectionException
     */
    private function createProxySocket(array $parse, array $payload): Socket
    {
        return match ($parse['scheme']) {
            'socks', 'socks5' => Socks5::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocket(),
            'http', 'https'   => Http::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocket(),
            default           => throw new ConnectionException('Unsupported proxy protocol', ConnectionException::CONNECTION_ERROR),
        };
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     *
     * @param Connection $connection
     * @param string     $key
     *
     * @return void
     */
    public function pushConnection(Connection $connection, string $key): void
    {
        $streamId = $connection->stream->id;
        if (isset($this->listenEventMap[$streamId])) {
            cancel($this->listenEventMap[$streamId]);
            unset($this->listenEventMap[$streamId]);
        }
        $this->idleConnections[$key][$streamId] = $connection;
        $this->listenEventMap[$streamId] = $connection->stream->onReadable(function (Socket $stream) use ($key, $connection) {
            try {
                if ($stream->read(1) === '' && $stream->eof()) {
                    throw new ConnectionException('Connection closed by peer', ConnectionException::CONNECTION_CLOSED);
                }
            } catch (Throwable) {
                $stream->close();
                $this->removeConnection($key, $connection);
            }
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     *
     * @param string     $key
     * @param Connection $connection
     *
     * @return void
     */
    private function removeConnection(string $key, Connection $connection): void
    {
        $streamId = $connection->stream->id;
        unset($this->idleConnections[$key][$streamId]);
        if (empty($this->idleConnections[$key])) {
            unset($this->idleConnections[$key]);
        }
        if (isset($this->listenEventMap[$streamId])) {
            cancel($this->listenEventMap[$streamId]);
            unset($this->listenEventMap[$streamId]);
        }
    }
}
