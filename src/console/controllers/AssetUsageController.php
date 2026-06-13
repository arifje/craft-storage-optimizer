<?php

namespace arifje\craftstorageoptimizer\console\controllers;

use arifje\craftstorageoptimizer\console\BaseCommandController;
use arifje\craftstorageoptimizer\services\AssetUsage;
use yii\console\ExitCode;

class AssetUsageController extends BaseCommandController
{
    public int $batchSize = 0;
    public int $volumeId = 0;
    public int $runId = 0;
    public bool $hardDelete = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['batchSize', 'volumeId', 'runId', 'hardDelete']);
    }

    public function actionScan(): int
    {
        $result = $this->assetUsage()->queueScan(
            $this->batchSize > 0 ? $this->batchSize : AssetUsage::DEFAULT_BATCH_SIZE,
            $this->volumeId > 0 ? $this->volumeId : null
        );

        $this->stdout(sprintf("queued: %s%s", $result['queued'] ? 'yes' : 'no', PHP_EOL));
        $this->stdout(sprintf("reason: %s%s", $result['reason'], PHP_EOL));
        $this->stdout(sprintf("runId: %s%s", $result['run']['id'] ?? '-', PHP_EOL));
        $this->stdout(sprintf("jobId: %s%s", $result['jobId'] ?? '-', PHP_EOL));

        return ExitCode::OK;
    }

    public function actionStatus(): int
    {
        $run = $this->assetUsage()->latestRun();

        if ($run === null) {
            $this->stdout('No asset usage scan has been run yet.' . PHP_EOL);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("runId: %s%s", $run['id'], PHP_EOL));
        $this->stdout(sprintf("status: %s%s", $run['status'], PHP_EOL));
        $this->stdout(sprintf("volumeId: %s%s", $run['volumeId'] ?? '-', PHP_EOL));
        $this->stdout(sprintf("assetCount: %s%s", $run['assetCount'], PHP_EOL));
        $this->stdout(sprintf("totalBytes: %s%s", $run['totalBytesFormatted'], PHP_EOL));
        $this->stdout(sprintf("relatedAssets: %s%s", $run['relatedAssets'], PHP_EOL));
        $this->stdout(sprintf("ghostAssets: %s%s", $run['ghostAssets'], PHP_EOL));
        $this->stdout(sprintf("ghostBytes: %s%s", $run['ghostBytesFormatted'], PHP_EOL));
        $this->stdout(sprintf("protectedAssets: %s%s", $run['protectedAssets'], PHP_EOL));
        $this->stdout(sprintf("relationCount: %s%s", $run['relationCount'], PHP_EOL));
        $this->stdout(sprintf("matrixRelationCount: %s%s", $run['matrixRelationCount'], PHP_EOL));

        if (!empty($run['deleteStatus'])) {
            $this->stdout(sprintf("deleteStatus: %s%s", $run['deleteStatus'], PHP_EOL));
            $this->stdout(sprintf("deleteAttemptedAssets: %s%s", $run['deleteAttemptedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteDeletedAssets: %s%s", $run['deleteDeletedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteSkippedAssets: %s%s", $run['deleteSkippedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteFailedAssets: %s%s", $run['deleteFailedAssets'], PHP_EOL));
            $this->stdout(sprintf("deleteDeletedBytes: %s%s", $run['deleteDeletedBytesFormatted'], PHP_EOL));
        }

        return ExitCode::OK;
    }

    public function actionDeleteGhosts(): int
    {
        $result = $this->assetUsage()->queueDeleteGhosts(
            $this->runId > 0 ? $this->runId : null,
            $this->batchSize > 0 ? $this->batchSize : AssetUsage::DEFAULT_DELETE_BATCH_SIZE,
            $this->hardDelete
        );

        $this->stdout(sprintf("queued: %s%s", $result['queued'] ? 'yes' : 'no', PHP_EOL));
        $this->stdout(sprintf("reason: %s%s", $result['reason'], PHP_EOL));
        $this->stdout(sprintf("runId: %s%s", $result['run']['id'] ?? '-', PHP_EOL));
        $this->stdout(sprintf("jobId: %s%s", $result['jobId'] ?? '-', PHP_EOL));
        $this->stdout(sprintf("hardDelete: %s%s", $this->hardDelete ? 'yes' : 'no', PHP_EOL));

        return ExitCode::OK;
    }

    public function actionCancelDeleteGhosts(): int
    {
        $result = $this->assetUsage()->cancelDeleteGhosts($this->runId > 0 ? $this->runId : null);

        $this->stdout(sprintf("canceled: %s%s", $result['canceled'] ? 'yes' : 'no', PHP_EOL));
        $this->stdout(sprintf("reason: %s%s", $result['reason'], PHP_EOL));
        $this->stdout(sprintf("runId: %s%s", $result['run']['id'] ?? '-', PHP_EOL));

        return ExitCode::OK;
    }

    public function actionClear(): int
    {
        $deleted = $this->assetUsage()->clearSnapshots();
        $this->stdout(sprintf("deleted: %s%s", $deleted, PHP_EOL));

        return ExitCode::OK;
    }
}
