<?php

namespace arifje\craftstorageoptimizer\jobs;

use arifje\craftstorageoptimizer\StorageOptimizer;
use craft\queue\BaseJob;

class DeleteUnusedGifAssetsJob extends BaseJob
{
    public int $runId;
    public int $batchSize;
    public bool $hardDelete = false;

    public function execute($queue): void
    {
        StorageOptimizer::getInstance()->insights->processDeleteUnusedBatch($this->runId, $this->batchSize, $this->hardDelete);
    }

    protected function defaultDescription(): ?string
    {
        return 'Deleting unused GIF assets';
    }
}
