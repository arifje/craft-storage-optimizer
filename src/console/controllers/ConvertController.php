<?php

namespace arifje\craftstorageoptimizer\console\controllers;

use arifje\craftstorageoptimizer\console\BaseCommandController;
use yii\console\ExitCode;

class ConvertController extends BaseCommandController
{
    public ?int $limit = null;
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit', 'force']);
    }

    public function actionIndex(?int $assetId = null): int
    {
        if ($assetId !== null) {
            $result = $this->conversions()->convertAsset($assetId, $this->force);
            $this->stdout($result['message'] . PHP_EOL);

            return $result['converted'] || $result['status'] === 'completed' ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $this->conversions()->convertQueued($this->limit, $this->force);
        $this->writeResult($result);

        return $result['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
