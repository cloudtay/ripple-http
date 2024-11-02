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

use function dechex;
use function explode;
use function strlen;

class Chunk
{
    /**
     * @Author cclilshy
     * @Date   2024/9/1 15:51
     *
     * @param string      $event
     * @param string      $data
     * @param string|null $id
     * @param int|null    $retry
     *
     * @return string
     */
    public static function event(string $event = '', string $data = '', string|null $id = null, int|null $retry = null): string
    {
        $output = "";

        if ($event !== '') {
            $output .= "event: {$event}\n";
        }

        if ($retry) {
            $output .= "retry: {$retry}\n";
        }

        if ($id) {
            $output .= "id: {$id}\n";
        }

        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $output .= "data: {$line}\n";
        }

        $output .= "\n";
        return $output;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/1 15:51
     *
     * @param string $data
     *
     * @return string
     */
    public static function chunk(string $data): string
    {
        $length = dechex(strlen($data));
        return "{$length}\r\n{$data}\r\n";
    }
}
