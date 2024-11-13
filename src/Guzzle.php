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

use GuzzleHttp\Client;
use Ripple\Http\Guzzle\RippleHandler;

use function array_merge;

use const PHP_SAPI;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
class Guzzle
{
    /*** @var Guzzle */
    protected static Guzzle $instance;

    /*** @var RippleHandler */
    protected RippleHandler $rippleHandler;

    /**
     *
     */
    protected function __construct()
    {
        $config              = [];
        $httpClient          = new \Ripple\Http\Client(array_merge(['pool' => PHP_SAPI === 'cli'], $config));
        $this->rippleHandler = new RippleHandler($httpClient);
    }

    /**
     * @param array $config
     *
     * @return \GuzzleHttp\Client
     */
    public static function newClient(array $config = []): Client
    {
        return new Client(array_merge(['handler' => self::getInstance()->getHandler()], $config));
    }

    /*** @return Guzzle */
    public static function getInstance(): Guzzle
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return \Ripple\Http\Client
     */
    public function getHttpClient(): \Ripple\Http\Client
    {
        return $this->getHandler()->getHttpClient();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 14:28
     * @return RippleHandler
     */
    public function getHandler(): RippleHandler
    {
        return $this->rippleHandler;
    }
}
