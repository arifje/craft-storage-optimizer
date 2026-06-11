<?php

namespace arifje\giftowebp\console\controllers;

use arifje\giftowebp\console\BaseCommandController;
use yii\console\ExitCode;

class ArchiveController extends BaseCommandController
{
    public function actionIndex(): int
    {
        $this->stdout('Archive workflow is reserved for a later retention policy.' . PHP_EOL);

        return ExitCode::OK;
    }
}
