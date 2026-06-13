<?php

namespace arifje\craftstorageoptimizer\variables;

use arifje\craftstorageoptimizer\StorageOptimizer;
use craft\elements\Asset;

class StorageOptimizerVariable
{
    public function webpFor(?Asset $asset): ?Asset
    {
        if (!$asset instanceof Asset) {
            return null;
        }

        return StorageOptimizer::getInstance()->conversions->getWebpAsset($asset);
    }

    public function sourceGifFor(?Asset $asset): ?Asset
    {
        if (!$asset instanceof Asset) {
            return null;
        }

        return StorageOptimizer::getInstance()->conversions->getSourceGifAsset($asset);
    }

    public function isGifOrConvertedWebp(?Asset $asset): bool
    {
        if (!$asset instanceof Asset) {
            return false;
        }

        return StorageOptimizer::getInstance()->conversions->isGifOrConvertedWebp($asset);
    }

    public function isAnimatedImage(?Asset $asset): bool
    {
        if (!$asset instanceof Asset) {
            return false;
        }

        return StorageOptimizer::getInstance()->conversions->isAnimatedImage($asset);
    }

    public function isAnimatedGif(?Asset $asset): bool
    {
        if (!$asset instanceof Asset) {
            return false;
        }

        return StorageOptimizer::getInstance()->conversions->isAnimatedGif($asset);
    }

    public function isAnimatedWebp(?Asset $asset): bool
    {
        if (!$asset instanceof Asset) {
            return false;
        }

        return StorageOptimizer::getInstance()->conversions->isAnimatedWebp($asset);
    }

    public function conversion(?Asset $asset): ?array
    {
        if (!$asset instanceof Asset) {
            return null;
        }

        return StorageOptimizer::getInstance()->conversions->getRecordForAsset($asset);
    }
}
