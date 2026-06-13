<?php

namespace arifje\craftstorageoptimizer\utilities;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\Insights;
use Craft;
use craft\base\Utility;

class GifUsage extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('storage-optimizer', 'GIF Usage');
    }

    public static function id(): string
    {
        return 'storage-optimizer-gif-usage';
    }

    public static function contentHtml(): string
    {
        $insights = StorageOptimizer::getInstance()->insights;
        $latestRun = $insights->latestRun();

        return Craft::$app->getView()->renderTemplate('storage-optimizer/utilities/gif-usage.twig', [
            'latestRun' => $latestRun,
            'topAssets' => $latestRun !== null ? $insights->topAssets((int)$latestRun['id']) : [],
            'defaultBatchSize' => Insights::DEFAULT_BATCH_SIZE,
            'defaultDeleteBatchSize' => Insights::DEFAULT_DELETE_BATCH_SIZE,
        ]);
    }
}
