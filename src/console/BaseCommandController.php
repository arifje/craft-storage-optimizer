<?php

namespace arifje\craftstorageoptimizer\console;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\AssetUsage;
use arifje\craftstorageoptimizer\services\Conversions;
use arifje\craftstorageoptimizer\services\Insights;
use craft\console\Controller;

abstract class BaseCommandController extends Controller
{
    protected function conversions(): Conversions
    {
        return StorageOptimizer::getInstance()->conversions;
    }

    protected function insights(): Insights
    {
        return StorageOptimizer::getInstance()->insights;
    }

    protected function assetUsage(): AssetUsage
    {
        return StorageOptimizer::getInstance()->assetUsage;
    }

    protected function writeResult(array $result): void
    {
        foreach ($result as $key => $value) {
            $this->stdout(sprintf('%s: %s%s', $key, $value, PHP_EOL));
        }
    }
}
