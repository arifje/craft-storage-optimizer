<?php

namespace arifje\giftowebp\console\controllers;

use arifje\giftowebp\console\BaseCommandController;
use yii\console\ExitCode;

class VerifyController extends BaseCommandController
{
    public ?int $assetId = null;
    public ?int $limit = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['assetId', 'limit']);
    }

    public function actionIndex(): int
    {
        $result = $this->conversions()->verify($this->assetId, $this->limit);
        $this->writeResult($result);

        return $result['missing'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
