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

namespace Ripple\Http\Client\Capture;

use Closure;
use Exception;
use GuzzleHttp\Psr7\Response;
use Iterator;
use Ripple\Coroutine\Context;
use Ripple\Coroutine\Coroutine;
use Ripple\Http\Client\Capture;
use Throwable;

use function Co\getContext;
use function count;
use function explode;
use function in_array;
use function strpos;
use function substr;
use function trim;

/**
 * @Author cclilshy
 * @Date   2024/9/4 12:21
 */
class ServerSentEvents extends Capture
{
    /*** @var Closure|null */
    public Closure|null $onEvent = null;

    /*** @var Closure|null */
    public Closure|null $onComplete = null;

    /*** @var array */
    protected array $iterators = [];

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
     * @param Response $response
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

    /**
     * @return iterable
     */
    public function getIterator(): iterable
    {
        return $this->iterators[] = new class ($this) implements Iterator {
            /*** @var Context[] */

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
                foreach ($this->waiters as $key => $context) {
                    unset($this->waiters[$key]);
                    Coroutine::resume($context, $event);
                }
            }

            /*** @return void */
            public function onComplete(): void
            {
                foreach ($this->waiters as $key => $context) {
                    unset($this->waiters[$key]);
                    Coroutine::resume($context);
                }
            }

            /**
             * @param Throwable $exception
             *
             * @return void
             */
            public function onError(Throwable $exception): void
            {
                foreach ($this->waiters as $key => $context) {
                    unset($this->waiters[$key]);
                    Coroutine::resume($context, $exception);
                }
            }

            /**
             * @return array|null
             */
            public function current(): array|null
            {
                return Coroutine::suspend(getContext());
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
                $this->waiters[] = getContext();
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

    /*** @return string */
    public function getStatus(): string
    {
        return $this->status;
    }
}
