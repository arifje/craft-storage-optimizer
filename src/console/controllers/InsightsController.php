<?php

namespace arifje\craftstorageoptimizer\console\controllers;

use arifje\craftstorageoptimizer\console\BaseCommandController;
use arifje\craftstorageoptimizer\services\Insights;
use yii\console\ExitCode;

class InsightsController extends BaseCommandController
{
    public int $batchSize = 0;
    public int $runId = 0;
    public bool $hardDelete = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['batchSize', 'runId', 'hardDelete']);
    }

    public function actionScan(): int
    {
        $result = $this->insights()->queueScan($this->batchSize > 0 ? $this->batchSize : Insights::DEFAULT_BATCH_SIZE);
        $this->stdout(sprintf("queued: %s%s", $result['queued'] ? 'yes' : 'no', PHP_EOL));
        $this->stdout(sprintf("reason: %s%s", $result['reason'], PHP_EOL));
        $this->stdout(sprintf("runId: %s%s", $result['run']['id'] ?? '-', PHP_EOL));
        $this->stdout(sprintf("jobId: %s%s", $result['jobId'] ?? '-', PHP_EOL));

        return ExitCode::OK;
    }

    public function actionStatus(): int
    {
        $run = $this->insights()->latestRun();

        if ($run === null) {
            $this->stdout('No GIF usage scan has been run yet.' . PHP_EOL);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("runId: %s%s", $run['id'], PHP_EOL));
        $this->stdout(sprintf("status: %s%s", $run['status'], PHP_EOL));
        $this->stdout(sprintf("gifAssets: %s%s", $run['gifAssets'], PHP_EOL));
        $this->stdout(sprintf("totalBytes: %s%s", $run['totalBytesFormatted'], PHP_EOL));
        $this->stdout(sprintf("usedAssets: %s%s", $run['usedAssets'], PHP_EOL));
        $this->stdout(sprintf("unusedAssets: %s%s", $run['unusedAssets'], PHP_EOL));
        $this->stdout(sprintf("relationCount: %s%s", $run['relationCount'], PHP_EOL));
        $this->stdout(sprintf("directRelationCount: %s%s", $run['directRelationCount'], PHP_EOL));
        $this->stdout(sprintf("matrixRelationCount: %s%s", $run['matrixRelationCount'], PHP_EOL));
        $this->stdout(sprintf("ownerReferenceCount: %s%s", $run['ownerElementCount'], PHP_EOL));

        if (!empty($run['deleteStatus'])) {
            $this->stdout(sprintf("deleteStatus: %s%s", $run['deleteStatus'], PHP_EOL));
            $this->stdout(sprintf("deleteAttemptedAssets: %s%s", $run['deleteAttemptedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteDeletedAssets: %s%s", $run['deleteDeletedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteSkippedAssets: %s%s", $run['deleteSkippedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteFailedAssets: %s%s", $run['deleteFailedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteDeletedBytes: %s%s", $run['deleteFreedBytesFormatted'], PHP_EOL));
        }

        return ExitCode::OK;
    }

    public function actionDeleteUnused(): int
    {
        $result = $this->insights()->queueDeleteUnused(
            $this->runId > 0 ? $this->runId : null,
            $this->batchSize > 0 ? $this->batchSize : Insights::DEFAULT_DELETE_BATCH_SIZE,
            $this->hardDelete
        );

        $this->stdout(sprintf("queued: %s%s", $result['queued'] ? 'yes' : 'no', PHP_EOL));
        $this->stdout(sprintf("reason: %s%s", $result['reason'], PHP_EOL));
        $this->stdout(sprintf("runId: %s%s", $result['run']['id'] ?? '-', PHP_EOL));
        $this->stdout(sprintf("jobId: %s%s", $result['jobId'] ?? '-', PHP_EOL));
        $this->stdout(sprintf("hardDelete: %s%s", $this->hardDelete ? 'yes' : 'no', PHP_EOL));

        return ExitCode::OK;
    }

    public function actionClear(): int
    {
        $deleted = $this->insights()->clearSnapshots();
        $this->stdout(sprintf("deleted: %s%s", $deleted, PHP_EOL));

        return ExitCode::OK;
    }
}
