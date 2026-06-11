# GIF to WebP

Craft CMS plugin for converting GIF assets to animated WebP files with `gif2webp` from libwebp.

This plugin is intentionally separate from any GIF-to-MP4 workflow. GIF assets remain the source of truth; this plugin creates WebP sibling assets and tracks conversion state in its own database table.

## Requirements

- Craft CMS `^4.4 || ^5.0`
- PHP `>=8.0.2`
- `gif2webp` available on the server path, or configured with an absolute binary path

## Installation

```bash
composer require arifje/craft-gif-to-webp
php craft plugin/install gif-to-webp
```

## Settings

- **Convert GIFs on asset save**: enqueues a conversion whenever a GIF asset is saved.
- **Queue delay**: defaults to `300` seconds so other plugins, such as a separate GIF-to-MP4 plugin, can process the original GIF first.
- **gif2webp path**: defaults to `gif2webp`; environment variables are supported.
- **Quality**, **method**, and **multithreading** options are passed to `gif2webp`.

Asset-save conversion never runs inline. It only creates or updates a conversion state row and pushes a queue job.

## Console Commands

```bash
php craft gif-to-webp/scan
php craft gif-to-webp/queue
php craft gif-to-webp/convert
php craft gif-to-webp/convert 123
php craft gif-to-webp/status
php craft gif-to-webp/verify
php craft gif-to-webp/retry-failed
php craft gif-to-webp/archive
php craft gif-to-webp/delete
```

`archive` and `delete` are command placeholders for the later retention/destructive workflow.

## Conversion State

The plugin stores state in `{{%gif_to_webp_conversions}}`, keyed by the source GIF asset ID. This prevents duplicate active jobs for the same GIF and lets the plugin retry or verify conversions later.
