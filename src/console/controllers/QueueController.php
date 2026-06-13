<?php

namespace arifje\craftstorageoptimizer\console\controllers;

use arifje\craftstorageoptimizer\console\BaseCommandController;
use yii\console\ExitCode;

class QueueController extends BaseCommandController
{
    public ?int $limit = null;
    public ?int $volumeId = null;
    public ?int $delay = null;
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit', 'volumeId', 'delay', 'force']);
    }

    public function actionIndex(): int
    {
        $result = $this->conversions()->queueAll($this->limit, $this->volumeId, $this->force, $this->delay);
        $this->writeResult($result);

        return $result['errors'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
