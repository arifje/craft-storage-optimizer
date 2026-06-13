<?php

namespace arifje\craftstorageoptimizer\jobs;

use arifje\craftstorageoptimizer\StorageOptimizer;
use craft\queue\BaseJob;

class ScanAssetUsageJob extends BaseJob
{
    public int $runId;
    public int $batchSize;

    public function execute($queue): void
    {
        StorageOptimizer::getInstance()->assetUsage->processScanBatch($this->runId, $this->batchSize);
    }

    protected function defaultDescription(): ?string
    {
        return 'Scanning asset usage';
    }
}
