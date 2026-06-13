<?php

namespace arifje\craftstorageoptimizer\jobs;

use arifje\craftstorageoptimizer\StorageOptimizer;
use craft\queue\BaseJob;

class ConvertGifJob extends BaseJob
{
    public int $conversionId;
    public bool $force = false;

    public function execute($queue): void
    {
        StorageOptimizer::getInstance()->conversions->convertRecord($this->conversionId, $this->force);
    }

    protected function defaultDescription(): ?string
    {
        return 'Converting GIF asset to animated WebP';
    }
}
