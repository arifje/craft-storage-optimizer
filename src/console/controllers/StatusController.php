<?php

namespace arifje\craftstorageoptimizer\console\controllers;

use arifje\craftstorageoptimizer\console\BaseCommandController;
use yii\console\ExitCode;

class StatusController extends BaseCommandController
{
    public ?string $status = null;
    public int $limit = 20;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['status', 'limit']);
    }

    public function actionIndex(): int
    {
        $summary = $this->conversions()->statusSummary($this->status, $this->limit);

        $this->stdout('Counts' . PHP_EOL);

        if (empty($summary['counts'])) {
            $this->stdout('  none' . PHP_EOL);
        }

        foreach ($summary['counts'] as $row) {
            $this->stdout(sprintf('  %s: %s%s', $row['status'], $row['count'], PHP_EOL));
        }

        $this->stdout(PHP_EOL . 'Recent records' . PHP_EOL);

        if (empty($summary['records'])) {
            $this->stdout('  none' . PHP_EOL);
        }

        foreach ($summary['records'] as $row) {
            $this->stdout(sprintf(
                '  #%s asset=%s output=%s status=%s attempts=%s updated=%s%s',
                $row['id'],
                $row['assetId'],
                $row['outputAssetId'] ?: '-',
                $row['status'],
                $row['attempts'],
                $row['dateUpdated'],
                PHP_EOL
            ));

            if (!empty($row['lastError'])) {
                $this->stdout(sprintf('    error: %s%s', $row['lastError'], PHP_EOL));
            }
        }

        return ExitCode::OK;
    }
}
