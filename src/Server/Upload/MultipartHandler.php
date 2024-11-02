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

namespace Ripple\Http\Server\Upload;

use Ripple\Http\Server\Exception\FormatException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_pop;
use function array_shift;
use function explode;
use function fclose;
use function fopen;
use function fwrite;
use function preg_match;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;

/**
 * Http upload parser
 */
class MultipartHandler
{
    private const STATUS_WAIT = 0;
    private const STATUS_TRAN = 1;

    /**
     * @var int
     */
    private int $status = MultipartHandler::STATUS_WAIT;

    /**
     * @var array
     */
    private array $task;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * Upload file structure
     *
     * @param string $boundary
     */
    public function __construct(private readonly string $boundary)
    {
    }

    /**
     * CONTEXT PUSH
     *
     * @param string $content
     *
     * @return array
     * @throws FormatException
     */
    public function tick(string $content): array
    {
        $this->buffer .= $content;
        $result       = array();
        while (!empty($this->buffer)) {
            if ($this->status === MultipartHandler::STATUS_WAIT) {
                if (!$info = $this->parseFileInfo()) {
                    break;
                }

                $this->status = MultipartHandler::STATUS_TRAN;

                if (!empty($info['fileName'])) {
                    $info['path']   = sys_get_temp_dir() . '/' . uniqid();
                    $info['stream'] = fopen($info['path'], 'wb+');
                    $this->task     = $info;
                } else {
                    // If it's not a file, handle text data
                    $this->status = MultipartHandler::STATUS_WAIT;
                    $textContent  = $this->parseTextContent();
                    if ($textContent !== false) {
                        $result[$info['name']] = $textContent;
                    }
                }
            }

            if ($this->status === MultipartHandler::STATUS_TRAN) {
                if (!$this->processTransmitting()) {
                    break;
                }
                $this->status                  = MultipartHandler::STATUS_WAIT;
                $result[$this->task['name']][] = new UploadedFile(
                    $this->task['path'],
                    $this->task['fileName'],
                    $this->task['contentType'],
                );
                fclose($this->task['stream']);
            }
        }

        return $result;
    }

    /**
     * @return array|false
     * @throws FormatException
     */
    private function parseFileInfo(): array|false
    {
        $headerEndPosition = strpos($this->buffer, "\r\n\r\n");
        if ($headerEndPosition === false) {
            return false;
        }

        $header       = substr($this->buffer, 0, $headerEndPosition);
        $this->buffer = substr($this->buffer, $headerEndPosition + 4);

        $headerLines = explode("\r\n", $header);

        $boundaryLine = array_shift($headerLines);
        if (trim($boundaryLine) !== '--' . $this->boundary) {
            throw new FormatException('Boundary is invalid');
        }

        $name        = '';
        $fileName    = '';
        $contentType = '';

        while ($line = array_pop($headerLines)) {
            if (preg_match('/^Content-Disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]*)")?$/i', trim($line), $matches)) {
                $name = $matches[1];
                if (isset($matches[2])) {
                    $fileName = $matches[2];
                }
            } elseif (preg_match('/^Content-Type:\s*(.+)$/i', trim($line), $matches)) {
                $contentType = $matches[1];
            }
        }

        if ($name === '') {
            throw new FormatException('File information is incomplete');
        }

        if ($contentType && $contentType !== 'text/plain' && $fileName === '') {
            throw new FormatException('Content type must be text/plain for non-file fields');
        }

        return array(
            'name'        => $name,
            'fileName'    => $fileName,
            'contentType' => $contentType
        );
    }


    /**
     * Parse text content
     *
     * @return string|false
     */
    private function parseTextContent(): string|false
    {
        $boundaryPosition = strpos($this->buffer, "\r\n--{$this->boundary}");
        if ($boundaryPosition === false) {
            return false;
        }

        $textContent  = substr($this->buffer, 0, $boundaryPosition);
        $this->buffer = substr($this->buffer, $boundaryPosition + 2);
        return $textContent;
    }

    /**
     * Processing transfer
     *
     * @return bool
     */
    private function processTransmitting(): bool
    {
        $mode = "\r\n--{$this->boundary}\r\n";

        $fileContent      = $this->buffer;
        $boundaryPosition = strpos($fileContent, $mode);

        if ($boundaryPosition === false) {
            $boundaryPosition = strpos($fileContent, "\r\n--{$this->boundary}--");
        }

        if ($boundaryPosition !== false) {
            $fileContent  = substr($fileContent, 0, $boundaryPosition);
            $this->buffer = substr($this->buffer, $boundaryPosition + 2);
            fwrite($this->task['stream'], $fileContent);
            return true;
        } else {
            $this->buffer = '';
            fwrite($this->task['stream'], $fileContent);
            return false;
        }
    }

    /**
     * @return void
     */
    public function cancel(): void
    {
        if ($this->status === MultipartHandler::STATUS_TRAN) {
            fclose($this->task['stream']);
        }
    }
}
