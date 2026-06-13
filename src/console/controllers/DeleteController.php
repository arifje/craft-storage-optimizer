<?php

namespace arifje\craftstorageoptimizer\console\controllers;

use arifje\craftstorageoptimizer\console\BaseCommandController;
use yii\console\ExitCode;

class DeleteController extends BaseCommandController
{
    public function actionIndex(): int
    {
        $this->stdout('Delete workflow is reserved for a later destructive cleanup policy.' . PHP_EOL);

        return ExitCode::OK;
    }
}
