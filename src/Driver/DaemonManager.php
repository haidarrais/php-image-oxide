<?php

declare(strict_types=1);

namespace ImageOxide\Driver;

use ImageOxide\Transport\Connection;

/**
 * Zero-setup daemon lifecycle: locate a platform binary and lazy-spawn it the
 * first time the client needs the daemon.
 *
 * Binary resolution order (first hit wins):
 *   1. `IMAGE_OXIDE_DAEMON` env var (absolute path)
 *   2. `vendor/bin/image-oxide` in the consuming project (composer bin-compat)
 *   3. `bin/{os}-{arch}/image-oxide` bundled inside this package
 *   4. `~/.image-oxide/bin/image-oxide` (user cache, e.g. post-install download)
 *   5. `image-oxide` on `$PATH`
 *
 * The daemon idles out on its own (IMAGE_OXIDE_TTL_MS, 60s default upstream),
 * so a spawned instance costs nothing once traffic stops. Spawning is
 * best-effort: any failure leaves the caller free to fall back to GD.
 */
final class DaemonManager
{
    /** How long to wait for the freshly spawned daemon to accept the handshake. */
    private const SPAWN_TIMEOUT_MS = 1500;

    /** Poll interval while waiting for the socket to come up. */
    private const SPAWN_POLL_US = 50_000; // 50ms

    /** Guard against spawning twice in one PHP process (e.g. Octane workers). */
    private static bool $spawnAttempted = false;

    /**
     * Ensure the daemon is reachable: probe first, spawn + wait if not.
     * Returns true when the daemon answers the handshake afterwards.
     */
    public static function ensureRunning(?string $socketPath = null): bool
    {
        $socket = $socketPath ?? Connection::defaultSocketPath();
        if (DaemonDriver::isReachable($socket)) {
            return true;
        }
        if (!self::$spawnAttempted) {
            self::$spawnAttempted = true;
            self::spawn();
        }
        return self::awaitReachable($socket);
    }

    /**
     * Resolve the daemon binary for this platform, or null when none is
     * available (caller should stay on the GD fallback).
     */
    public static function resolveBinary(): ?string
    {
        $env = getenv('IMAGE_OXIDE_DAEMON');
        if (is_string($env) && $env !== '' && is_file($env) && is_executable($env)) {
            return $env;
        }

        $name = PHP_OS_FAMILY === 'Windows' ? 'image-oxide.exe' : 'image-oxide';
        $candidates = [];
        // Consuming project's vendor/bin (composer binaries end up here).
        $vendorBin = self::projectRoot() . '/vendor/bin/' . $name;
        if ($vendorBin !== '/vendor/bin/' . $name) {
            $candidates[] = $vendorBin;
        }
        // Bundled per-platform binary inside this package.
        $candidates[] = dirname(__DIR__, 2) . '/bin/' . self::platformDir() . '/' . $name;
        // User-level cache (downloaded by an installer script, CI, …).
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: null);
        if (is_string($home) && $home !== '') {
            $candidates[] = $home . '/.image-oxide/bin/' . $name;
        }

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        // Last resort: whatever is on PATH.
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        $found = trim((string) @shell_exec("$which $name 2>/dev/null"));
        return $found !== '' && is_file($found) ? $found : null;
    }

    /**
     * Detached background spawn; returns immediately. The daemon's own idle
     * TTL bounds its lifetime — no pid tracking, no reaping.
     */
    private static function spawn(): void
    {
        $binary = self::resolveBinary();
        if ($binary === null) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // ponytail: detached spawn on Windows without ext-com_dotnet is cmd /c start
            pclose(popen('start /B "" ' . escapeshellarg($binary), 'r'));
            return;
        }
        $cmd = sprintf(
            'nohup %s >/dev/null 2>&1 &',
            escapeshellarg($binary),
        );
        @shell_exec($cmd);
    }

    /** Poll for the handshake until the spawn timeout elapses. */
    private static function awaitReachable(string $socket): bool
    {
        $deadline = microtime(true) + self::SPAWN_TIMEOUT_MS / 1000;
        do {
            if (DaemonDriver::isReachable($socket)) {
                return true;
            }
            usleep(self::SPAWN_POLL_US);
        } while (microtime(true) < $deadline);
        return DaemonDriver::isReachable($socket);
    }

    /** Platform triple used for the bundled-binary directory name. */
    private static function platformDir(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => strtolower(PHP_OS_FAMILY),
        };
        $machine = strtolower((string) php_uname('m'));
        $arch = match (true) {
            str_contains($machine, 'arm64'), str_contains($machine, 'aarch64') => 'arm64',
            default => 'x64',
        };
        return $os . '-' . $arch;
    }

    /**
     * Best-effort consuming-project root: walk up from this package looking
     * for a composer.json that is not ours (path repos sit beside the project).
     */
    private static function projectRoot(): string
    {
        $dir = dirname(__DIR__, 2);
        for ($i = 0; $i < 4; $i++) {
            $dir = dirname($dir);
            if (is_file($dir . '/composer.json')) {
                $json = json_decode((string) @file_get_contents($dir . '/composer.json'), true);
                if (($json['name'] ?? '') !== 'haidarrais/image-oxide') {
                    return $dir;
                }
            }
        }
        return '';
    }
}
