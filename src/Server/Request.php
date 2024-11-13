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

use Ripple\Socket;

use function array_merge;
use function is_string;
use function json_encode;

/**
 * requesting entity
 */
class Request
{
    /*** @var array */
    public readonly array $REQUEST;

    /*** @var Response */
    protected Response $response;

    /**
     * @param Socket     $stream
     * @param array      $GET
     * @param array      $POST
     * @param array      $COOKIE
     * @param array      $FILES
     * @param array      $SERVER
     * @param mixed|null $CONTENT
     */
    public function __construct(
        public readonly Socket $stream,
        public readonly array  $GET = [],
        public readonly array  $POST = [],
        public readonly array  $COOKIE = [],
        public readonly array  $FILES = [],
        public readonly array  $SERVER = [],
        public readonly mixed  $CONTENT = null,
    ) {
        $this->REQUEST = array_merge($this->GET, $this->POST);
    }

    /**
     * @return Socket
     */
    public function getStream(): Socket
    {
        return $this->stream;
    }

    /**
     * @param mixed $content
     * @param array $withHeaders
     * @param int   $statusCode
     *
     * @return void
     */
    public function respondJson(mixed $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            is_string($content) ? $content : json_encode($content),
            array_merge(['Content-Type' => 'application/json'], $withHeaders),
            $statusCode
        );
    }

    /**
     * @param mixed $content
     * @param int   $statusCode
     * @param array $withHeaders
     *
     * @return void
     */
    public function respond(mixed $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $response = $this->getResponse();

        if ($statusCode) {
            $response->setStatusCode($statusCode);
        }

        foreach ($withHeaders as $name => $value) {
            $response->withHeader($name, $value);
        }

        $response->setBody($content)->respond();
    }

    /**
     * @return Response
     */
    public function getResponse(): Response
    {
        if (!isset($this->response)) {
            $this->response = new Response($this->stream);
        }
        return $this->response;
    }

    /**
     * @param mixed $content
     * @param array $withHeaders
     * @param int   $statusCode
     *
     * @return void
     */
    public function respondText(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            $content,
            array_merge(['Content-Type' => 'text/plain'], $withHeaders),
            $statusCode
        );
    }

    /**
     * @param mixed $content
     * @param array $withHeaders
     * @param int   $statusCode
     *
     * @return void
     */
    public function respondHtml(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            $content,
            array_merge(['Content-Type' => 'text/html'], $withHeaders),
            $statusCode
        );
    }
}
