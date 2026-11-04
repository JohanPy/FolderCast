<?php

declare(strict_types=1);

namespace OCA\FolderCast\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

class RangeStreamResponse extends Response implements ICallbackResponse
{
    /** @var resource */
    private $stream;
    private int $start;
    private int $length;

    /**
     * @param resource $stream
     */
    public function __construct($stream, int $start, int $length, int $status = Http::STATUS_PARTIAL_CONTENT)
    {
        parent::__construct();
        $this->setStatus($status);
        $this->stream = $stream;
        $this->start = $start;
        $this->length = $length;
    }

    /**
     * Parses HTTP Range header.
     * Pure function for testing.
     *
     * @param string|null $header Header value, e.g. "bytes=0-1023", "bytes=500-", "bytes=-500"
     * @param int $size Total file size in bytes
     * @return array{0: int, 1: int}|null|false
     *         null: No Range header or multi-range (serve 200)
     *         false: Invalid or unsatisfiable range (serve 416)
     *         [start, end]: Valid range 0-indexed inclusive
     */
    public static function parseRange(?string $header, int $size): array|null|false
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $header = trim($header);
        if (!preg_match('/^bytes\s*=\s*(.+)$/i', $header, $parts)) {
            return null;
        }

        $ranges = $parts[1];
        if (str_contains($ranges, ',')) {
            // Multi-range: ignore and serve full file (200) as per spec/plan
            return null;
        }

        $range = trim($ranges);
        if (!preg_match('/^(\d*)-(\d*)$/', $range, $matches)) {
            return false;
        }

        $startStr = $matches[1];
        $endStr = $matches[2];

        if ($startStr === '' && $endStr === '') {
            return false;
        }

        if ($startStr === '') {
            // Suffix range: bytes=-500 (last 500 bytes)
            $suffixLength = (int)$endStr;
            if ($suffixLength <= 0 || $size === 0) {
                return false;
            }
            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } elseif ($endStr === '') {
            // Prefix range: bytes=500- (from 500 to end)
            $start = (int)$startStr;
            if ($start >= $size || $start < 0) {
                return false;
            }
            $end = $size - 1;
        } else {
            // Explicit range: bytes=start-end
            $start = (int)$startStr;
            $end = (int)$endStr;
            if ($start > $end || $start < 0 || $start >= $size) {
                return false;
            }
            $end = min($end, $size - 1);
        }

        return [$start, $end];
    }

    public function callback(IOutput $output): void
    {
        if ($output->getHttpResponseCode() === Http::STATUS_NOT_MODIFIED) {
            return;
        }

        if (!is_resource($this->stream)) {
            return;
        }

        try {
            // Position stream at $start
            $seekable = false;
            $meta = stream_get_meta_data($this->stream);
            if ($meta['seekable'] ?? false) {
                $seekable = (fseek($this->stream, $this->start) === 0);
            }

            if (!$seekable) {
                @rewind($this->stream);
                $remainingToDiscard = $this->start;
                while ($remainingToDiscard > 0 && !feof($this->stream)) {
                    $chunk = min($remainingToDiscard, 8192);
                    $read = fread($this->stream, $chunk);
                    if ($read === false || strlen($read) === 0) {
                        break;
                    }
                    $remainingToDiscard -= strlen($read);
                }
            }

            // Flush active output buffers to ensure direct media streaming in web SAPI
            if (php_sapi_name() !== 'cli') {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
            }

            // Stream in 64KB chunks to keep memory flat
            $out = fopen('php://output', 'wb');
            if ($out !== false) {
                if ($this->length > 0) {
                    $remaining = $this->length;
                    while ($remaining > 0 && !feof($this->stream)) {
                        $chunkSize = min($remaining, 65536);
                        $chunk = fread($this->stream, $chunkSize);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        fwrite($out, $chunk);
                        $remaining -= strlen($chunk);
                        if (function_exists('flush')) {
                            @flush();
                        }
                        if (connection_aborted()) {
                            break;
                        }
                    }
                }
                fclose($out);
            }
        } finally {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
        }
    }
}
