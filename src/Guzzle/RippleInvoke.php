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

namespace Ripple\Http\Guzzle;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Ripple\Http\Client;
use Throwable;

use const PHP_SAPI;

/**
 *
 */
class RippleInvoke
{
    /**
     * @param bool $enableConnectionPool
     */
    public function __construct(public readonly bool $enableConnectionPool = PHP_SAPI === 'cli')
    {
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $promise = new Promise();

        try {
            $response = Client::instance([
                'enable_connection_pool' => $this->enableConnectionPool
            ])->request($request, $options);
            $promise->resolve($response);
        } catch (GuzzleException $exception) {
            $promise->reject($exception);
        } catch (Throwable $exception) {
            $promise->reject(new TransferException($exception->getMessage()));
        }

        return $promise;
    }
}
