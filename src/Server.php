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

use Closure;
use InvalidArgumentException;
use Ripple\Http\Enum\Status;
use Ripple\Http\Server\Connection;
use Ripple\Http\Server\Exception\FormatException;
use Ripple\Http\Server\Request;
use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func_array;
use function parse_url;
use function str_contains;
use function strtolower;

use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_NODELAY;

/**
 * Http service class
 */
class Server
{
    /**
     * request handler
     *
     * @var Closure
     */
    public Closure $onRequest;

    /*** @var \Ripple\Socket */
    private Socket $server;

    /**
     * @param string     $address
     * @param mixed|null $context
     *
     * @throws InvalidArgumentException|ConnectionException
     */
    public function __construct(string $address, mixed $context = null)
    {
        $addressInfo = parse_url($address);

        if (!$scheme = $addressInfo['scheme'] ?? null) {
            throw new InvalidArgumentException('Address format error');
        }

        if (!$host = $addressInfo['host']) {
            throw new InvalidArgumentException('Address format error');
        }

        $port = $addressInfo['port'] ?? match ($scheme) {
            'http'  => 80,
            'https' => 443,
            default => throw new InvalidArgumentException('Address format error')
        };

        $server = match ($scheme) {
            'http', 'https' => Socket::server("tcp://{$host}:{$port}", $context),
            default         => throw new InvalidArgumentException('Address format error')
        };

        if ($server === false) {
            throw new ConnectionException('Failed to create server', ConnectionException::CONNECTION_ERROR);
        }

        $this->server = $server;
        $this->server->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
        $this->server->setBlocking(false);
    }

    /**
     * @return void
     */
    public function listen(): void
    {
        $this->server->onReadable(function (Socket $stream) {
            if (!$client = $stream->accept()) {
                return;
            }

            $client->setBlocking(false);
            $client->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
            $client->setOption(SOL_TCP, TCP_NODELAY, 1);

            /*** Debug: Low Water Level & Buffer*/
            //            $lowWaterMarkRecv = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVLOWAT);
            //            $lowWaterMarkSend = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDLOWAT);
            //            $recvBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVBUF);
            //            $sendBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDBUF);
            //            var_dump($lowWaterMarkRecv, $lowWaterMarkSend, $recvBuffer, $sendBuffer);

            /*** Optimized buffer: 256kb standard rate frame*/
            //            $client->setOption(SOL_SOCKET, SO_RCVBUF, 256000);
            //            $client->setOption(SOL_SOCKET, SO_SNDBUF, 256000);
            //            $client->setOption(SOL_TCP, TCP_NODELAY, 1);

            /*** Set sending low water level to prevent filling memory @deprecated compatible without coverage */
            //            $client->setOption(SOL_SOCKET, SO_SNDLOWAT, 1024);

            /*** CPU intimacy @deprecated compatible not covered */
            //            $stream->setOption(SOL_SOCKET, SO_INCOMING_CPU, 1);
            $this->listenSocket($client);
        });
    }

    /**
     * @param \Ripple\Socket $stream
     *
     * @return void
     */
    private function listenSocket(Socket $stream): void
    {
        $connection = new Connection($stream);
        $connection->listen(function (array $requestInfo) use ($stream) {
            $request = new Request(
                $stream,
                $requestInfo['query'],
                $requestInfo['request'],
                $requestInfo['cookies'],
                $requestInfo['files'],
                $requestInfo['server'],
                $requestInfo['content']
            );

            $response = $request->getResponse();
            $response->withHeader('Server', 'ripple');

            $keepAlive = false;
            if ($headerConnection = $requestInfo['server']['HTTP_CONNECTION'] ?? null) {
                if (str_contains(strtolower($headerConnection), 'keep-alive')) {
                    $keepAlive = true;
                }
            }

            if ($keepAlive) {
                $response->withHeader('Connection', 'keep-alive');
            }

            try {
                if (isset($this->onRequest)) {
                    call_user_func_array($this->onRequest, [$request]);
                }
            } catch (ConnectionException) {
                $stream->close();
            } catch (FormatException) {
                /**** The message format is illegal*/
                $response->setStatusCode(Status::BAD_REQUEST)->setBody(Status::MESSAGES[Status::BAD_REQUEST])->respond();
            } catch (Throwable $e) {
                $response->setStatusCode(Status::INTERNAL_SERVER_ERROR)->setBody($e->getMessage())->respond();
                Output::exception($e);
            }
        });
    }

    /**
     * @param Closure $onRequest
     *
     * @return void
     */
    public function onRequest(Closure $onRequest): void
    {
        $this->onRequest = $onRequest;
    }
}
