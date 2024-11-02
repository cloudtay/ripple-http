<?php declare(strict_types=1);

namespace Ripple\Http\Client;

use Exception;
use GuzzleHttp\Psr7\Response;
use Throwable;

abstract class Capturer
{
    /**
     * 装载失败
     *
     * @param Throwable|Exception $exception
     *
     * @return void
     */
    abstract public function onFail(Throwable|Exception $exception): void;

    /**
     * 请求发生异常
     *
     * @param Throwable|Exception $exception
     *
     * @return void
     */
    abstract public function onError(Throwable|Exception $exception): void;


    /**
     * 请求完成
     *
     * @param \GuzzleHttp\Psr7\Response $response
     *
     * @return void
     */
    abstract public function onComplete(Response $response): void;


    /**
     * 收到完整请求头
     *
     * @param array $headers
     *
     * @return void
     */
    abstract public function processHeader(array $headers): void;

    /**
     * 填充内容时
     *
     * @param string $content
     *
     * @return void
     */
    abstract public function processContent(string $content): void;

    /**
     * 装载成功
     *
     * @return void
     */
    abstract public function onInject(): void;
}
