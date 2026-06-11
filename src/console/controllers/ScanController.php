<?php

namespace arifje\giftowebp\console\controllers;

use arifje\giftowebp\console\BaseCommandController;
use yii\console\ExitCode;

class ScanController extends BaseCommandController
{
    public ?int $limit = null;
    public ?int $volumeId = null;
    public bool $queue = false;
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit', 'volumeId', 'queue', 'force']);
    }

    public function actionIndex(): int
    {
        $result = $this->conversions()->scan($this->limit, $this->volumeId, $this->queue, $this->force);
        $this->writeResult($result);

        return $result['errors'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
