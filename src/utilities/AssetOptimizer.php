<?php

namespace arifje\craftstorageoptimizer\utilities;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\AssetUsage;
use Craft;
use craft\base\Utility;

class AssetOptimizer extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('storage-optimizer', 'Asset Optimizer');
    }

    public static function id(): string
    {
        return 'asset-optimizer-usage';
    }

    public static function contentHtml(): string
    {
        $assetUsage = StorageOptimizer::getInstance()->assetUsage;
        $latestRun = $assetUsage->latestRun();

        return Craft::$app->getView()->renderTemplate('storage-optimizer/utilities/asset-optimizer.twig', [
            'latestRun' => $latestRun,
            'topGhostAssets' => $latestRun !== null ? $assetUsage->topGhostAssets((int)$latestRun['id']) : [],
            'defaultBatchSize' => AssetUsage::DEFAULT_BATCH_SIZE,
            'defaultDeleteBatchSize' => AssetUsage::DEFAULT_DELETE_BATCH_SIZE,
            'volumeOptions' => array_merge([
                [
                    'label' => Craft::t('storage-optimizer', 'All volumes'),
                    'value' => '',
                ],
            ], $assetUsage->availableVolumes()),
        ]);
    }
}
