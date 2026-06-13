# Craft Storage Optimizer

Craft CMS plugin for optimizing asset storage with GIF-to-WebP/MP4 conversion, usage insights, and safe cleanup for ghost assets.

Source GIF assets remain available for conversion history and cleanup. The plugin can create WebP and MP4 sibling assets, can update Craft asset-field references to the generated WebP, exposes Twig helpers for MP4-first frontend rendering, and tracks conversion state in its own database table.

The Composer package is `arifje/craft-storage-optimizer` and the Craft plugin handle is `storage-optimizer`.

## Requirements

- Craft CMS `^4.4 || ^5.0`
- PHP `>=8.0.2`
- `gif2webp` available on the server path, or configured with an absolute binary path, when WebP generation is enabled
- `ffmpeg` available on the server path, or configured with an absolute binary path, when MP4 generation is enabled

## Installation

```bash
composer require arifje/craft-storage-optimizer
php craft plugin/install storage-optimizer
```

## Settings

- **Convert GIFs on asset save**: enqueues a conversion whenever a GIF asset is saved.
- **Generate WebP assets**: creates animated WebP sibling assets.
- **Generate MP4 assets**: creates MP4 sibling assets with ffmpeg.
- **Replace GIF references with WebP**: after a conversion completes, updates Craft asset-field relations, including Matrix-owned fields, so entries use the generated WebP asset while the original GIF remains in the volume.
- **Queue delay**: defaults to `300` seconds so other plugins can process the original GIF first.
- **gif2webp path**: defaults to `gif2webp`; environment variables are supported.
- **ffmpeg path**: defaults to `ffmpeg`; environment variables are supported.
- **Compression mode**: defaults to lossy WebP. Lossless animated WebP can be larger than an optimized GIF.
- **Quality**, **method**, **minimize output size**, and **multithreading** options are passed to `gif2webp`.
- **Skip WebP files that are not smaller**: enabled by default. If the generated WebP is the same size or larger than the source GIF, it is not saved and GIF references are not replaced. You can also require a minimum savings percentage.
- **MP4 CRF**, **preset**, **faststart**, and **minimum savings** control ffmpeg output. MP4 generation is opt-in, and generated MP4 assets never replace image-field relations automatically.

Asset-save conversion never runs inline. It only creates or updates a conversion state row and pushes a queue job. If reference replacement is enabled, relation updates happen inside that queue job after the WebP asset has been saved. MP4 assets are intended for frontend video rendering via Twig helpers.

## Asset Optimizer

The plugin adds an **Asset Optimizer** utility under Craft's Utilities section. It scans assets in batches and records a cached snapshot of relation-based usage.

The scan records:

- total asset count and total size
- related asset count
- ghost asset count and total ghost asset size
- protected asset count
- total relation references
- Matrix relation references
- largest ghost assets by size

Ghost assets are assets with no Craft relation references. Generated WebP and MP4 assets from this plugin are protected while their source GIF is still active, even if the generated asset has no direct Craft relations.

The utility can queue soft deletion for ghost assets from the latest completed snapshot. Each snapshot cleanup can be completed once; run a new scan before deleting newly discovered ghost assets. Each asset is re-checked against live relations and plugin protections immediately before deletion, so stale snapshot data will not delete assets that became used after the scan.

Start a scan from the utility, or from the console:

```bash
php craft storage-optimizer/asset-usage/scan --batchSize=500
php craft storage-optimizer/asset-usage/scan --volumeId=1 --batchSize=500
php craft storage-optimizer/asset-usage/status
php craft storage-optimizer/asset-usage/delete-ghosts --batchSize=100
php craft storage-optimizer/asset-usage/cancel-delete-ghosts
php craft storage-optimizer/asset-usage/clear
```

Console cleanup also supports `--runId=123` to target a specific completed snapshot and `--hardDelete=1` when you intentionally want permanent deletion instead of Craft trash. Use `cancel-delete-ghosts` with optional `--runId=123` to stop an active cleanup snapshot; the current batch may finish, but no new batches will be queued.

## GIF Usage Insights

The plugin adds a **GIF Usage** utility under Craft's Utilities section. The utility never scans live on page load; it only displays the latest cached snapshot and lets an authorized user queue a new batched scan.

The scan records:

- total GIF asset count
- total GIF bytes and average size
- used and unused GIF asset counts
- total relation references
- direct asset field references
- Matrix relation references
- owner references, with Matrix block references rolled up to their owning element
- largest GIF assets by size
- conversion status for each captured GIF

Usage is counted through Craft's `relations` table where GIF assets are the relation target. That means GIFs selected in normal asset fields and asset fields nested inside Matrix blocks are both included. Matrix references are detected by joining relation sources against Craft's Matrix ownership tables when available, and owner references use the Matrix block's primary owner when available. If a direct asset field and a Matrix block on the same entry both reference the same GIF, that counts as two relation references, one Matrix reference, and one owner reference for that GIF.

The utility can also queue deletion for GIF assets that were unused in the latest completed snapshot. Each snapshot cleanup can be completed once; run a new scan before deleting newly discovered unused GIF assets. Deletion is batched, every asset is re-checked against live relations immediately before deletion, and Control Panel cleanup uses Craft's soft delete/trash behavior by default.

Start a scan from the utility, or from the console:

```bash
php craft storage-optimizer/insights/scan --batchSize=500
php craft storage-optimizer/insights/status
php craft storage-optimizer/insights/delete-unused --batchSize=100
php craft storage-optimizer/insights/cancel-delete-unused
php craft storage-optimizer/insights/clear
```

Console cleanup also supports `--runId=123` to target a specific completed snapshot and `--hardDelete=1` when you intentionally want permanent deletion instead of Craft trash. Use `cancel-delete-unused` with optional `--runId=123` to stop an active cleanup snapshot; the current batch may finish, but no new batches will be queued.

## Console Commands

```bash
php craft storage-optimizer/scan
php craft storage-optimizer/queue
php craft storage-optimizer/convert
php craft storage-optimizer/convert 123
php craft storage-optimizer/status
php craft storage-optimizer/verify
php craft storage-optimizer/retry-failed
php craft storage-optimizer/archive
php craft storage-optimizer/delete
php craft storage-optimizer/asset-usage/scan
php craft storage-optimizer/asset-usage/status
php craft storage-optimizer/asset-usage/delete-ghosts
php craft storage-optimizer/asset-usage/cancel-delete-ghosts
php craft storage-optimizer/insights/delete-unused
php craft storage-optimizer/insights/cancel-delete-unused
```

`archive` and `delete` are command placeholders for the later retention/destructive workflow.

When **Replace GIF references with WebP** is enabled, `verify` also repairs existing completed conversions by swapping any remaining GIF asset-field references to the generated WebP.

## Twig Helpers

The plugin registers `craft.storageOptimizer` so frontend templates can reason about GIF-derived WebP/MP4 assets without relying on `image/gif` MIME checks. The legacy `craft.gifToWebp` variable remains available as an alias for existing templates.

### Replace an `image/gif` Check

If your frontend currently branches on `asset.mimeType == 'image/gif'`, replace that with:

```twig
{% if craft.storageOptimizer.isGifOrConvertedMedia(asset) %}
    {# Original GIF, or a generated WebP/MP4 from an original GIF. #}
{% endif %}
```

This keeps existing GIF/MP4 fallback logic working after the image shown on the frontend becomes WebP or MP4.

### Render MP4 First, WebP/Image Fallback

```twig
{% set image = craft.storageOptimizer.webpFor(asset) ?? asset %}
{% set mp4 = craft.storageOptimizer.mp4For(asset) %}

{% if mp4 %}
    <video autoplay muted loop playsinline poster="{{ image.url }}">
        <source src="{{ mp4.url }}" type="video/mp4">
    </video>
{% else %}
    <img src="{{ image.url }}" alt="{{ image.alt }}">
{% endif %}
```

`mp4For(asset)` returns the generated MP4 for the source GIF or generated WebP. `webpFor(asset)` is still useful as a poster image or image fallback.

### Get the Preferred Generated Media

```twig
{% set media = craft.storageOptimizer.mediaFor(asset) %}

{% if media and media.extension == 'mp4' %}
    <video autoplay muted loop playsinline>
        <source src="{{ media.url }}" type="video/mp4">
    </video>
{% elseif media %}
    <img src="{{ media.url }}" alt="{{ media.alt }}">
{% endif %}
```

### Detect Actual Animation

Use the animation helpers when you need to know whether the file itself contains multiple frames:

```twig
{% if craft.storageOptimizer.isAnimatedImage(asset) %}
    {# Animated GIF or animated WebP. #}
{% endif %}

{% if craft.storageOptimizer.isAnimatedWebp(asset) %}
    {# Animated WebP only. #}
{% endif %}
```

Use `isGifOrConvertedMedia()` for GIF/WebP/MP4 fallback behavior. Use `isAnimatedImage()` when standalone animated WebP files should also be treated as animated media.

Available helpers:

- `craft.storageOptimizer.webpFor(asset)` returns the converted WebP asset for a source GIF, or the same asset if it is already a WebP.
- `craft.storageOptimizer.mp4For(asset)` returns the generated MP4 asset for a source GIF or GIF-derived asset, or the same asset if it is already a generated MP4.
- `craft.storageOptimizer.mediaFor(asset)` returns MP4 first, then WebP, then the source GIF.
- `craft.storageOptimizer.sourceGifFor(asset)` returns the original GIF for a converted WebP/MP4 asset, or the same asset if it is already a GIF.
- `craft.storageOptimizer.isGifOrConvertedWebp(asset)` is the drop-in replacement for a frontend `image/gif` check when converted WebP assets should follow the same MP4/video fallback path.
- `craft.storageOptimizer.isGifOrConvertedMedia(asset)` matches original GIF assets and generated WebP/MP4 assets.
- `craft.storageOptimizer.isAnimatedImage(asset)` inspects GIF/WebP file data and returns true only when the image actually has multiple frames.
- `craft.storageOptimizer.isAnimatedGif(asset)` and `craft.storageOptimizer.isAnimatedWebp(asset)` expose the format-specific checks.
- `craft.storageOptimizer.conversion(asset)` returns the plugin conversion row for a source GIF or generated WebP/MP4 asset.

## Conversion State

The plugin stores conversion state in `{{%storage_optimizer_gif_conversions}}`, keyed by the source GIF asset ID. Usage snapshots are stored in `{{%storage_optimizer_gif_usage_*}}` and `{{%storage_optimizer_asset_usage_*}}` tables. This prevents duplicate active jobs for the same GIF and lets the plugin retry or verify conversions later.
