<?php

declare(strict_types=1);

namespace ImageOxide\Tests;

use ImageOxide\Driver\DaemonManager;
use PHPUnit\Framework\TestCase;

/**
 * Self-check for the zero-setup daemon lifecycle: binary resolution and the
 * ensureRunning() spawn path (skip-safe when no binary is available).
 */
final class DaemonManagerTest extends TestCase
{
    public function testResolveBinaryPrefersEnvVar(): void
    {
        $dir = sys_get_temp_dir() . '/oxide-bin-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $fake = $dir . '/image-oxide';
        file_put_contents($fake, '#!/bin/sh\nexit 0\n');
        chmod($fake, 0755);

        putenv('IMAGE_OXIDE_DAEMON=' . $fake);
        try {
            self::assertSame($fake, DaemonManager::resolveBinary());
        } finally {
            putenv('IMAGE_OXIDE_DAEMON');
            unlink($fake);
            rmdir($dir);
        }
    }

    public function testResolveBinaryFindsBundledPlatformBinary(): void
    {
        $bundled = dirname(__DIR__) . '/bin/' . $this->platformDir() . '/image-oxide';
        if (!is_file($bundled) || !is_executable($bundled)) {
            $this->markTestSkipped('no bundled binary for this platform at ' . $bundled);
        }
        // No env override → resolution must reach the bundled path.
        putenv('IMAGE_OXIDE_DAEMON');
        self::assertSame($bundled, DaemonManager::resolveBinary());
    }

    public function testEnsureRunningSpawnsFromBundledBinary(): void
    {
        // No daemon must already be listening on the default socket for this
        // to be a genuine spawn test; skip if one is present.
        if (\ImageOxide\Driver\DaemonDriver::isReachable()) {
            $this->markTestSkipped('a daemon is already running on the default socket');
        }
        putenv('IMAGE_OXIDE_DAEMON');

        // The spawned daemon listens on the default socket; after it starts we
        // cannot cleanly reap it (detached), so give it a short TTL.
        $before = getenv('IMAGE_OXIDE_TTL_MS');
        putenv('IMAGE_OXIDE_TTL_MS=1500');

        try {
            self::assertTrue(DaemonManager::ensureRunning(), 'spawned daemon must become reachable');
            self::assertTrue(\ImageOxide\Driver\DaemonDriver::isReachable());
        } finally {
            putenv('IMAGE_OXIDE_TTL_MS' . ($before === false ? '' : '=' . $before));
            // Bounded wait for the short-TTL daemon to idle out.
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && \ImageOxide\Driver\DaemonDriver::isReachable()) {
                usleep(50000);
            }
        }
    }

    private function platformDir(): string
    {
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : (PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux');
        $arch = str_contains(strtolower((string) php_uname('m')), 'arm64') ? 'arm64' : 'x64';
        return $os . '-' . $arch;
    }
}
