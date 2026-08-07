<?php

declare(strict_types=1);

namespace ImageOxide\Driver;

/**
 * Result of a driver run: dimensions of the final image and output byte size.
 *
 * `durationMs` is the daemon's server-side work time (the daemon's `duration_ms`
 * field), or null for in-process drivers (GD) where there is no separate work
 * time to report. It lets callers separate daemon work from client round-trip
 * (IPC) overhead.
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
        public readonly ?int $durationMs = null,
    ) {
    }
}
