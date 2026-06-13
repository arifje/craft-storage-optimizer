<?php

namespace arifje\craftstorageoptimizer\console\controllers;

use arifje\craftstorageoptimizer\console\BaseCommandController;
use yii\console\ExitCode;

class RetryFailedController extends BaseCommandController
{
    public ?int $limit = null;
    public ?int $delay = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit', 'delay']);
    }

    public function actionIndex(): int
    {
        $result = $this->conversions()->retryFailed($this->limit, $this->delay);
        $this->writeResult($result);

        return $result['errors'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
