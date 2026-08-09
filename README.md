# haidarrais/image-oxide

PHP client for the [image-oxide](https://github.com/haidarrais/image-oxide) image processing
daemon. Speaks the 001 wire protocol over a Unix socket and degrades to a pure-GD driver when
the daemon is not reachable.

> The Rust daemon and the wire protocol live in
> [haidarrais/image-oxide](https://github.com/haidarrais/image-oxide).

## Requirements

- PHP >= 8.2
- Extensions: `gd`, `json`, `sockets`
- Optional: the image-oxide daemon binary (for the faster, richer driver) — see [Zero setup](#zero-setup); none needed if GD is fine

## Install

```bash
composer require haidarrais/image-oxide
```

## Usage

The fluent API accumulates ops and executes them once, on the terminal call, so one chain can
be reused across many images.

## Zero setup

You don't need to start the daemon yourself. On first use, if the socket is
missing, the client lazily spawns the daemon from the first binary it finds:

1. `IMAGE_OXIDE_DAEMON` env var (absolute path)
2. `vendor/bin/image-oxide` in the consuming project
3. `bin/{os}-{arch}/image-oxide` bundled inside this package
4. `~/.image-oxide/bin/image-oxide`
5. `image-oxide` on `$PATH`

The daemon idles out on its own (60s default via `IMAGE_OXIDE_TTL_MS`), so a
spawned instance costs nothing once traffic stops. If no binary is found — or
the spawn/handshake fails — the client silently falls back to the GD driver.
Set `IMAGE_OXIDE_DAEMON` to pin an exact binary:

```bash
IMAGE_OXIDE_DAEMON=/opt/image-oxide/bin/image-oxide php artisan queue:work
```

```php
use ImageOxide\Oxide;

// Daemon auto-spawned on first use (bundled binary or PATH); GD fallback otherwise
Oxide::from('/path/to/in.png')
    ->resize(800, 600, 'cover')
    ->format('webp')
    ->quality(85)
    ->to('/path/to/out.webp');

// Explicit daemon socket
Oxide::from('/in.png', socketPath: '/run/user/1000/image-oxide.sock')
    ->resize(800, 600)
    ->get(); // returns the bytes

// Capability matrix for both drivers
$caps = Oxide::capabilities();
```

When you drive the daemon directly (not through the fluent `Oxide` API), the result
carries the daemon's server-side work time:

```php
use ImageOxide\Driver\DaemonDriver;

$r = (new DaemonDriver())->process($in, $ops, $out, 85);
$workMs = $r->durationMs;   // null for the GD driver
```

`durationMs` is the daemon's `duration_ms` (pure work time, excluding client IPC
round-trip). GD runs in-process, so its `durationMs` is `null`. Subtracting it from a
wall-clock measurement isolates client→daemon IPC overhead.

### Connection pooling

By default each `to()` opens a fresh daemon connection (PHP-13). To reuse one
connection across many operations — the daemon now serves sequential requests per
connection (`ipc_23`) — keep a single driver instance alive; it pools the connection
and reconnects transparently if the daemon restarts:

```php
$driver = new DaemonDriver();          // pooled connection inside
$r1 = $driver->process($in1, $ops, $out1, 85);
$r2 = $driver->process($in2, $ops, $out2, 85);   // reuses $r1's connection
```

Measurements show IPC is ~1–3 ms/op, so pooling's benefit is architectural (fewer
syscalls, no per-op handshake) rather than a large latency win.

### Ops

| Op | Signature | Notes |
|----|-----------|-------|
| `resize` | `resize(int\|string $width, int\|string $height = 0, string $fit = 'cover', string $position = 'center')` | fits: `cover`, `contain`, `fill` |
| `format` | `format(string $format)` | `jpeg`, `png`, `webp`, `gif` |
| `rotate` | `rotate(int $degrees)` | multiples of 90 |
| `watermark` | `watermark(string $imagePath, string $position = 'bottom-right', int $offsetX = 0, int $offsetY = 0, float $opacity = 1.0)` | grid positions |
| `quality` | `quality(int $quality)` | 1–100, lossy encode |

An op the active driver does not support throws `UnsupportedOperationException` — it never
silently no-ops.

## Drivers

| Driver | Needs | Capabilities |
|--------|-------|--------------|
| `daemon` | image-oxide daemon reachable at the socket | full op set, `avif` encode in a future release |
| `gd` | `ext-gd` | resize/format/rotate/watermark; **no `avif`** |

The GD capability matrix mirrors spec 003: `jpeg`, `png`, `webp`, `gif` decode/encode; `avif`
deferred.

## Tests

```bash
composer install
composer test
```

`AC-PHP-01/03` spawn the real daemon binary when `IMAGE_OXIDE_BIN` points at it; the rest run
against the GD fallback.

## License

MIT — see [LICENSE](LICENSE).
