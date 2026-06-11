<?php

namespace arifje\giftowebp\console\controllers;

use arifje\giftowebp\console\BaseCommandController;
use yii\console\ExitCode;

class DeleteController extends BaseCommandController
{
    public function actionIndex(): int
    {
        $this->stdout('Delete workflow is reserved for a later destructive cleanup policy.' . PHP_EOL);

        return ExitCode::OK;
    }
}
