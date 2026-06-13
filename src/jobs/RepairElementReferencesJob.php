<?php

namespace arifje\craftstorageoptimizer\jobs;

use arifje\craftstorageoptimizer\StorageOptimizer;
use craft\queue\BaseJob;

class RepairElementReferencesJob extends BaseJob
{
    public int $sourceElementId;

    public function execute($queue): void
    {
        StorageOptimizer::getInstance()->conversions->replaceReadyGifReferencesForSourceElement($this->sourceElementId);
    }

    protected function defaultDescription(): ?string
    {
        return sprintf('Repairing GIF references for element #%s', $this->sourceElementId);
    }
}
