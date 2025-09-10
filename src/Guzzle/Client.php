<?php declare(strict_types=1);

namespace Ripple\Http\Guzzle;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\ClientTrait;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function array_merge;
use function call_user_func_array;

/**
 *
 */
class Client implements ClientInterface, \Psr\Http\Client\ClientInterface
{
    use ClientTrait;

    /**
     * @var \GuzzleHttp\Client
     */
    private \GuzzleHttp\Client $guzzleClient;

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->guzzleClient = new \GuzzleHttp\Client(array_merge(
            [
                'handler' => new RippleInvoke()
            ],
            $config
        ));
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->guzzleClient->send($request, $options);
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return PromiseInterface
     */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->guzzleClient->sendAsync($request, $options);
    }

    /**
     * @param string $method
     * @param $uri
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        return $this->guzzleClient->request($method, $uri, $options);
    }

    /**
     * @param string $method
     * @param $uri
     * @param array $options
     * @return PromiseInterface
     */
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        return $this->guzzleClient->requestAsync($method, $uri, $options);
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->guzzleClient->sendRequest($request);
    }

    /**
     * @param string|null $option
     * @return void
     */
    public function getConfig(?string $option = null): void
    {
        $this->guzzleClient->getConfig($option);
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        return call_user_func_array([$this->guzzleClient, $name], $arguments);
    }
}
