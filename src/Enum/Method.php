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

namespace Ripple\Http\Enum;

use function in_array;

enum Method: string
{
    case GET        = 'GET';
    case POST       = 'POST';
    case PUT        = 'PUT';
    case DELETE     = 'DELETE';
    case PATCH      = 'PATCH';
    case OPTIONS    = 'OPTIONS';
    case HEAD       = 'HEAD';
    case TRACE      = 'TRACE';
    case CONNECT    = 'CONNECT';

    private const METHOD_WITH_BODY = [self::POST, self::PUT, self::DELETE, self::PATCH];

    public function hasBody(): bool
    {
        return in_array($this, self::METHOD_WITH_BODY, true);
    }
}
