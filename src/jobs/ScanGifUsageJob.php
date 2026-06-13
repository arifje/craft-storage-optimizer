<?php

namespace arifje\craftstorageoptimizer\jobs;

use arifje\craftstorageoptimizer\StorageOptimizer;
use craft\queue\BaseJob;

class ScanGifUsageJob extends BaseJob
{
    public int $runId;
    public int $batchSize;

    public function execute($queue): void
    {
        StorageOptimizer::getInstance()->insights->processRunBatch($this->runId, $this->batchSize);
    }

    protected function defaultDescription(): ?string
    {
        return 'Scanning GIF asset usage';
    }
}
