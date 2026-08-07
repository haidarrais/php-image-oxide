# haidarrais/image-oxide

PHP client for the [image-oxide](https://github.com/haidarrais/image-oxide) image processing
daemon. Speaks the 001 wire protocol over a Unix socket and degrades to a pure-GD driver when
the daemon is not reachable.

> Spec 004 of the image-oxide project. The Rust daemon and the wire protocol live in
> [haidarrais/image-oxide](https://github.com/haidarrais/image-oxide).

## Requirements

- PHP >= 8.1
- Extensions: `gd`, `json`, `sockets`
- Optional: the image-oxide daemon binary (for the faster, richer driver)

## Install

```bash
composer require haidarrais/image-oxide
```

## Usage

The fluent API accumulates ops and executes them once, on the terminal call, so one chain can
be reused across many images.

```php
use ImageOxide\Oxide;

// Daemon when the socket is reachable, else GD (default socket path)
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
