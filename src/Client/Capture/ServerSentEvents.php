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

namespace Ripple\Http\Client\Capturer;

use Closure;
use Exception;
use GuzzleHttp\Psr7\Response;
use Iterator;
use Ripple\Coroutine;
use Ripple\Http\Client\Capturer;
use Throwable;

use function array_shift;
use function Co\getSuspension;
use function count;
use function explode;
use function in_array;
use function strpos;
use function substr;
use function trim;
use function var_dump;

/**
 * @Author cclilshy
 * @Date   2024/9/4 12:21
 */
class ServerSentEvents extends Capturer
{
    /*** @var string */
    private string $status = 'pending';

    /*** @var string */
    private string $buffer = '';

    /**
     * @return void
     */
    public function onInject(): void
    {
        $this->status = 'inject';
    }

    /**
     * @param Throwable|Exception $exception
     *
     * @return void
     */
    public function onFail(Throwable|Exception $exception): void
    {
        $this->status = 'fail';

        foreach ($this->iterators as $iterator) {
            $iterator->onError($exception);
        }
    }

    /**
     * @param Throwable|Exception $exception
     *
     * @return void
     */
    public function onError(Throwable|Exception $exception): void
    {
        $this->status = 'error';

        foreach ($this->iterators as $iterator) {
            $iterator->onError($exception);
        }
    }

    /**
     * @param \GuzzleHttp\Psr7\Response $response
     *
     * @return void
     */
    public function onComplete(Response $response): void
    {
        $this->status = 'complete';

        if ($this->onComplete instanceof Closure) {
            ($this->onComplete)($response);
        }

        foreach ($this->iterators as $iterator) {
            $iterator->onEvent(null);
        }
    }

    /**
     * @param array $headers
     *
     * @return void
     */
    public function processHeader(array $headers): void
    {
    }

    /**
     * @param string $content
     *
     * @return void
     */
    public function processContent(string $content): void
    {
        $this->buffer .= $content;
        while (($eventEnd = strpos($this->buffer, "\n\n")) !== false) {
            $eventString = substr($this->buffer, 0, $eventEnd);
            $this->buffer = substr($this->buffer, $eventEnd + 2);

            // Split the data by lines
            $eventData = [];
            $lines     = explode("\n", $eventString);
            foreach ($lines as $line) {
                $keyValue = explode(':', $line, 2);
                if (count($keyValue) === 2) {
                    $eventData[trim($keyValue[0])] = trim($keyValue[1]);
                } else {
                    $eventData[] = $line;
                }
            }

            if ($this->onEvent instanceof Closure) {
                ($this->onEvent)($eventData);
            }

            foreach ($this->iterators as $iterator) {
                $iterator->onEvent($eventData);
            }
        }
    }

    /*** @return string */
    public function getStatus(): string
    {
        return $this->status;
    }

    /*** @var array */
    protected array $iterators = [];

    /**
     * @return iterable
     */
    public function getIterator(): iterable
    {
        return $this->iterators[] = new class ($this) implements Iterator {
            /*** @var \Revolt\EventLoop\Suspension[] */
            protected array $waiters = [];

            /**
             * @param ServerSentEvents $capture
             */
            public function __construct(protected readonly ServerSentEvents $capture)
            {
            }

            /**
             * @param array|null $event
             *
             * @return void
             */
            public function onEvent(array|null $event): void
            {
                while ($suspension = array_shift($this->waiters)) {
                    Coroutine::resume($suspension, $event);
                }
            }

            /*** @return void */
            public function onComplete(): void
            {
                while ($suspension = array_shift($this->waiters)) {
                    Coroutine::resume($suspension);
                }
            }

            /**
             * @param Throwable $exception
             *
             * @return void
             */
            public function onError(Throwable $exception): void
            {
                while ($suspension = array_shift($this->waiters)) {
                    Coroutine::throw($suspension, $exception);
                }
            }

            /**
             * @return array|null
             */
            public function current(): array|null
            {
                $this->waiters[] = $suspension = getSuspension();
                return Coroutine::suspend($suspension);
            }

            /**
             * @return mixed
             */
            public function key(): mixed
            {
                return null;
            }

            /**
             * @return bool
             */
            public function valid(): bool
            {
                return in_array($this->capture->getStatus(), ['pending', 'inject'], true);
            }

            /**
             * @return void
             */
            public function next(): void
            {
                // nothing happens
            }

            /**
             * @return void
             */
            public function rewind(): void
            {
                // nothing happens
            }
        };
    }

    /*** @var \Closure|null */
    public Closure|null $onEvent = null;

    /*** @var \Closure|null */
    public Closure|null $onComplete = null;
}
