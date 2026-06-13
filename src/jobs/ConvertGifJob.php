<?php

namespace arifje\craftstorageoptimizer\jobs;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\Conversions;
use Craft;
use craft\queue\BaseJob;
use yii\db\Query;

class ConvertGifJob extends BaseJob
{
    public int $conversionId;
    public ?int $assetId = null;
    public bool $force = false;

    public function execute($queue): void
    {
        StorageOptimizer::getInstance()->conversions->convertRecord($this->conversionId, $this->force);
    }

    protected function defaultDescription(): ?string
    {
        $assetId = $this->assetId ?? $this->assetIdFromConversion();

        if ($assetId !== null) {
            return sprintf('Converting GIF asset #%s to optimized media', $assetId);
        }

        return sprintf('Converting GIF conversion #%s to optimized media', $this->conversionId);
    }

    private function assetIdFromConversion(): ?int
    {
        $record = (new Query())
            ->select(['assetId'])
            ->from(Conversions::TABLE)
            ->where(['id' => $this->conversionId])
            ->one(Craft::$app->getDb());

        return !empty($record['assetId']) ? (int)$record['assetId'] : null;
    }
}
