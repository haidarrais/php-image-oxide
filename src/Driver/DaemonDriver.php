<?php

declare(strict_types=1);

namespace ImageOxide\Driver;

use ImageOxide\Exception\OxideException;
use ImageOxide\Transport\Connection;

/**
 * Daemon driver: sends the op chain over the 001 wire protocol (PHP-05/12).
 * Retryable failures (DAEMON_OVERLOADED, INPUT_UNREADABLE, OUTPUT_WRITE_FAILED)
 * are retried with capped exponential backoff (PHP-11, AC-PHP-03).
 */
final class DaemonDriver implements Driver
{
    private const MAX_RETRIES = 3;
    private const BASE_BACKOFF_MS = 50;
    private const MAX_BACKOFF_MS = 1000;

    /** Keep-alive connection reused across `process()` calls (PHP-13 opt-out). */
    private ?Connection $connection = null;

    public function __construct(
        private readonly ?string $socketPath = null,
    ) {
    }

    public function process(string $inputPath, array $ops, string $outputPath, ?int $quality = null): ProcessResult
    {
        $socket = $this->socketPath ?? Connection::defaultSocketPath();
        $attempt = 0;
        while (true) {
            $request = [
                'id' => self::newId(),
                'ops' => $ops,
                'input' => ['path' => $inputPath],
                'output' => ['path' => $outputPath],
            ];
            if ($quality !== null) {
                $request['quality'] = $quality;
            }

            try {
                $reply = $this->roundTrip($request, $socket);
            } catch (OxideException $e) {
                // A retryable failure (e.g. DAEMON_OVERLOADED) usually means the
                // daemon rejected AND closed the connection — drop the pooled
                // stream so the retry reconnects instead of writing to a dead fd.
                if ($e->isRetryable()) {
                    $this->connection?->close();
                    $this->connection = null;
                }
                if (!$e->isRetryable() || $attempt >= self::MAX_RETRIES) {
                    throw $e;
                }
                // IPC-22: exponential, capped backoff for retryable codes.
                $backoff = min(self::MAX_BACKOFF_MS, self::BASE_BACKOFF_MS * (1 << $attempt));
                usleep($backoff * 1000);
                $attempt++;
                continue;
            }

            return new ProcessResult(
                width: (int) $reply['width'],
                height: (int) $reply['height'],
                bytes: (int) $reply['bytes'],
                durationMs: isset($reply['duration_ms']) ? (int) $reply['duration_ms'] : null,
            );
        }
    }

    /**
     * Send one request on the pooled connection (kept alive across calls).
     * Reconnects transparently if the pooled stream died.
     */
    private function roundTrip(array $request, string $socket): array
    {
        if ($this->connection === null || !$this->connection->isOpen()) {
            $this->connection = (new Connection($socket))->keepAlive();
        }
        return $this->connection->roundTrip($request);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            ops: ['resize' => true, 'format' => true, 'rotate' => true, 'watermark' => true],
            decode: ['jpeg' => true, 'png' => true, 'webp' => true, 'gif' => true, 'avif' => false],
            encode: ['jpeg' => true, 'png' => true, 'webp' => true, 'gif' => true, 'avif' => false],
            fits: ['cover' => true, 'contain' => true, 'fill' => true],
            positions: ['center', 'top', 'top-left', 'top-right', 'bottom', 'bottom-left', 'bottom-right', 'left', 'right'],
        );
    }

    public function __destruct()
    {
        $this->connection?->close();
    }

    /**
     * PHP-05: "reachable" means connectable and speaking the protocol — a
     * leftover socket file must not be treated as a live daemon. Lightweight:
     * connect + hello, expect the ack frame, close.
     */
    public static function isReachable(?string $socketPath = null): bool
    {
        try {
            (new Connection($socketPath ?? Connection::defaultSocketPath()))->probe();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
