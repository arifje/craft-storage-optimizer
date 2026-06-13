<?php

namespace arifje\craftstorageoptimizer\jobs;

use arifje\craftstorageoptimizer\StorageOptimizer;
use craft\queue\BaseJob;

class DeleteGhostAssetsJob extends BaseJob
{
    public int $runId;
    public int $batchSize;
    public bool $hardDelete = false;

    public function execute($queue): void
    {
        StorageOptimizer::getInstance()->assetUsage->processDeleteGhostsBatch($this->runId, $this->batchSize, $this->hardDelete);
    }

    protected function defaultDescription(): ?string
    {
        return 'Deleting ghost assets';
    }
}
