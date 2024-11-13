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

class RippleHandler
{
    public function __construct(private readonly Client $httpClient)
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
        $promise = new Promise(function () use ($request, $options, &$promise) {
            try {
                $response = $this->httpClient->request($request, $options);
                $promise->resolve($response);
            } catch (GuzzleException $exception) {
                $promise->reject($exception);
            } catch (Throwable $exception) {
                $promise->reject(new TransferException($exception->getMessage()));
            }
        });

        return $promise;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 14:31
     * @return Client
     */
    public function getHttpClient(): Client
    {
        return $this->httpClient;
    }
}
