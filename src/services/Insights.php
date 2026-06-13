<?php

namespace arifje\craftstorageoptimizer\services;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\jobs\DeleteUnusedGifAssetsJob;
use arifje\craftstorageoptimizer\jobs\ScanGifUsageJob;
use Craft;
use craft\base\Component;
use craft\elements\Asset;
use yii\db\Expression;
use yii\db\Query;

class Insights extends Component
{
    public const RUN_TABLE = '{{%storage_optimizer_gif_usage_runs}}';
    public const ASSET_TABLE = '{{%storage_optimizer_gif_usage_assets}}';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const DEFAULT_BATCH_SIZE = 500;
    public const DEFAULT_DELETE_BATCH_SIZE = 100;

    public function queueScan(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $batchSize = max(1, min(5000, $batchSize));
        $activeRun = $this->getActiveRun();

        if ($activeRun !== null) {
            return [
                'queued' => false,
                'reason' => 'already-active',
                'run' => $activeRun,
                'jobId' => $activeRun['lastJobId'] ?? null,
            ];
        }

        $now = $this->now();

        Craft::$app->getDb()->createCommand()
            ->insert(self::RUN_TABLE, [
                'status' => self::STATUS_QUEUED,
                'batchSize' => $batchSize,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ])
            ->execute();

        $runId = (int)Craft::$app->getDb()->getLastInsertID();
        $jobId = $this->pushBatchJob($runId, $batchSize);

        $this->updateRun($runId, [
            'lastJobId' => $jobId !== null ? (string)$jobId : null,
        ]);

        return [
            'queued' => true,
            'reason' => 'queued',
            'run' => $this->getRunById($runId),
            'jobId' => $jobId,
        ];
    }

    public function processRunBatch(int $runId, int $batchSize): array
    {
        $run = $this->getRunById($runId);

        if ($run === null) {
            return [
                'processed' => 0,
                'queuedNext' => false,
                'completed' => false,
            ];
        }

        if (!in_array($run['status'], [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return [
                'processed' => 0,
                'queuedNext' => false,
                'completed' => $run['status'] === self::STATUS_COMPLETED,
            ];
        }

        if ($run['status'] === self::STATUS_QUEUED) {
            $this->updateRun($runId, [
                'status' => self::STATUS_RUNNING,
                'startedAt' => $this->now(),
                'lastError' => null,
            ]);
        }

        try {
            $assets = $this->nextGifAssetBatch((int)$run['lastAssetId'], $batchSize);

            if (empty($assets)) {
                $this->updateRun($runId, [
                    'status' => self::STATUS_COMPLETED,
                    'completedAt' => $this->now(),
                    'lastError' => null,
                ]);

                return [
                    'processed' => 0,
                    'queuedNext' => false,
                    'completed' => true,
                ];
            }

            $assetIds = array_map(static fn(array $asset): int => (int)$asset['id'], $assets);
            $relationStats = $this->relationStats($assetIds);
            $conversionStats = $this->conversionStats($assetIds);
            $batchSummary = $this->storeAssetBatch($runId, $assets, $relationStats, $conversionStats);
            $run = $this->getRunById($runId) ?? $run;
            $lastAssetId = max($assetIds);

            $largestAssetId = $run['largestAssetId'] ?? null;
            $largestBytes = (int)($run['largestBytes'] ?? 0);

            if ($batchSummary['largestBytes'] > $largestBytes) {
                $largestAssetId = $batchSummary['largestAssetId'];
                $largestBytes = $batchSummary['largestBytes'];
            }

            $this->updateRun($runId, [
                'lastAssetId' => $lastAssetId,
                'processedAssets' => ((int)$run['processedAssets']) + $batchSummary['assetCount'],
                'gifAssets' => ((int)$run['gifAssets']) + $batchSummary['assetCount'],
                'usedAssets' => ((int)$run['usedAssets']) + $batchSummary['usedAssets'],
                'unusedAssets' => ((int)$run['unusedAssets']) + $batchSummary['unusedAssets'],
                'totalBytes' => ((int)$run['totalBytes']) + $batchSummary['totalBytes'],
                'relationCount' => ((int)$run['relationCount']) + $batchSummary['relationCount'],
                'directRelationCount' => ((int)$run['directRelationCount']) + $batchSummary['directRelationCount'],
                'matrixRelationCount' => ((int)$run['matrixRelationCount']) + $batchSummary['matrixRelationCount'],
                'sourceElementCount' => ((int)$run['sourceElementCount']) + $batchSummary['sourceElementCount'],
                'ownerElementCount' => ((int)$run['ownerElementCount']) + $batchSummary['ownerElementCount'],
                'largestAssetId' => $largestAssetId,
                'largestBytes' => $largestBytes,
                'lastError' => null,
            ]);

            $queuedNext = count($assets) === $batchSize;

            if ($queuedNext) {
                $jobId = $this->pushBatchJob($runId, $batchSize);
                $this->updateRun($runId, [
                    'lastJobId' => $jobId !== null ? (string)$jobId : null,
                ]);
            } else {
                $this->updateRun($runId, [
                    'status' => self::STATUS_COMPLETED,
                    'completedAt' => $this->now(),
                ]);
            }

            return [
                'processed' => $batchSummary['assetCount'],
                'queuedNext' => $queuedNext,
                'completed' => !$queuedNext,
            ];
        } catch (\Throwable $e) {
            $this->updateRun($runId, [
                'status' => self::STATUS_FAILED,
                'lastError' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function queueDeleteUnused(?int $runId = null, int $batchSize = self::DEFAULT_DELETE_BATCH_SIZE, bool $hardDelete = false): array
    {
        $batchSize = max(1, min(1000, $batchSize));
        $run = $runId !== null ? $this->getRunById($runId) : $this->getLatestCompletedRun();

        if ($run === null) {
            return [
                'queued' => false,
                'reason' => 'no-completed-scan',
                'run' => null,
                'jobId' => null,
            ];
        }

        if (($run['status'] ?? null) !== self::STATUS_COMPLETED) {
            return [
                'queued' => false,
                'reason' => 'scan-not-completed',
                'run' => $run,
                'jobId' => $run['deleteJobId'] ?? null,
            ];
        }

        if (in_array($run['deleteStatus'] ?? null, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return [
                'queued' => false,
                'reason' => 'delete-already-active',
                'run' => $run,
                'jobId' => $run['deleteJobId'] ?? null,
            ];
        }

        if ((int)($run['unusedAssets'] ?? 0) === 0) {
            return [
                'queued' => false,
                'reason' => 'no-unused-assets',
                'run' => $run,
                'jobId' => null,
            ];
        }

        $runId = (int)$run['id'];

        $this->updateRun($runId, [
            'deleteStatus' => self::STATUS_QUEUED,
            'deleteBatchSize' => $batchSize,
            'deleteLastAssetId' => 0,
            'deleteAttemptedAssets' => 0,
            'deleteDeletedAssets' => 0,
            'deleteSkippedAssets' => 0,
            'deleteFailedAssets' => 0,
            'deleteFreedBytes' => 0,
            'deleteHardDelete' => $hardDelete,
            'deleteLastError' => null,
            'deleteStartedAt' => null,
            'deleteCompletedAt' => null,
        ]);

        $jobId = $this->pushDeleteUnusedJob($runId, $batchSize, $hardDelete);

        $this->updateRun($runId, [
            'deleteJobId' => $jobId !== null ? (string)$jobId : null,
        ]);

        return [
            'queued' => true,
            'reason' => 'queued',
            'run' => $this->getRunById($runId),
            'jobId' => $jobId,
        ];
    }

    public function processDeleteUnusedBatch(int $runId, int $batchSize, bool $hardDelete = false): array
    {
        $run = $this->getRunById($runId);

        if ($run === null || !in_array($run['deleteStatus'] ?? null, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return [
                'attempted' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'failed' => 0,
                'queuedNext' => false,
                'completed' => false,
            ];
        }

        if (($run['deleteStatus'] ?? null) === self::STATUS_QUEUED) {
            $this->updateRun($runId, [
                'deleteStatus' => self::STATUS_RUNNING,
                'deleteStartedAt' => $this->now(),
                'deleteLastError' => null,
            ]);
        }

        try {
            $rows = $this->nextUnusedAssetRows($runId, (int)($run['deleteLastAssetId'] ?? 0), $batchSize);

            if (empty($rows)) {
                $this->updateRun($runId, [
                    'deleteStatus' => self::STATUS_COMPLETED,
                    'deleteCompletedAt' => $this->now(),
                ]);

                return [
                    'attempted' => 0,
                    'deleted' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'queuedNext' => false,
                    'completed' => true,
                ];
            }

            $summary = [
                'attempted' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'failed' => 0,
                'freedBytes' => 0,
                'lastError' => null,
            ];
            $lastAssetId = 0;

            foreach ($rows as $row) {
                $lastAssetId = max($lastAssetId, (int)$row['assetId']);
                $summary['attempted']++;
                $result = $this->deleteUnusedAsset($row, $hardDelete);

                if ($result['status'] === 'deleted') {
                    $summary['deleted']++;
                    $summary['freedBytes'] += (int)$result['bytes'];
                } elseif ($result['status'] === 'failed') {
                    $summary['failed']++;
                    $summary['lastError'] = $result['error'];
                } else {
                    $summary['skipped']++;
                }
            }

            $run = $this->getRunById($runId) ?? $run;

            $updateValues = [
                'deleteLastAssetId' => $lastAssetId,
                'deleteAttemptedAssets' => ((int)($run['deleteAttemptedAssets'] ?? 0)) + $summary['attempted'],
                'deleteDeletedAssets' => ((int)($run['deleteDeletedAssets'] ?? 0)) + $summary['deleted'],
                'deleteSkippedAssets' => ((int)($run['deleteSkippedAssets'] ?? 0)) + $summary['skipped'],
                'deleteFailedAssets' => ((int)($run['deleteFailedAssets'] ?? 0)) + $summary['failed'],
                'deleteFreedBytes' => ((int)($run['deleteFreedBytes'] ?? 0)) + $summary['freedBytes'],
            ];

            if ($summary['lastError'] !== null) {
                $updateValues['deleteLastError'] = $summary['lastError'];
            }

            $this->updateRun($runId, $updateValues);

            $queuedNext = count($rows) === $batchSize;

            if ($queuedNext) {
                $jobId = $this->pushDeleteUnusedJob($runId, $batchSize, $hardDelete);
                $this->updateRun($runId, [
                    'deleteJobId' => $jobId !== null ? (string)$jobId : null,
                ]);
            } else {
                $this->updateRun($runId, [
                    'deleteStatus' => self::STATUS_COMPLETED,
                    'deleteCompletedAt' => $this->now(),
                ]);
            }

            return [
                'attempted' => $summary['attempted'],
                'deleted' => $summary['deleted'],
                'skipped' => $summary['skipped'],
                'failed' => $summary['failed'],
                'queuedNext' => $queuedNext,
                'completed' => !$queuedNext,
            ];
        } catch (\Throwable $e) {
            $this->updateRun($runId, [
                'deleteStatus' => self::STATUS_FAILED,
                'deleteLastError' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function latestRun(): ?array
    {
        if (!$this->tableExists(self::RUN_TABLE)) {
            return null;
        }

        $run = (new Query())
            ->from(self::RUN_TABLE)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return $run ? $this->decorateRun($run) : null;
    }

    public function topAssets(int $runId, int $limit = 25): array
    {
        if (!$this->tableExists(self::ASSET_TABLE)) {
            return [];
        }

        $rows = (new Query())
            ->from(self::ASSET_TABLE)
            ->where(['runId' => $runId])
            ->orderBy(['size' => SORT_DESC, 'relationCount' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row): array => $this->decorateAssetRow($row), $rows);
    }

    public function clearSnapshots(): int
    {
        if (!$this->tableExists(self::RUN_TABLE)) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(self::RUN_TABLE)
            ->execute();
    }

    public function getRunById(int $runId): ?array
    {
        if (!$this->tableExists(self::RUN_TABLE)) {
            return null;
        }

        $run = (new Query())
            ->from(self::RUN_TABLE)
            ->where(['id' => $runId])
            ->one();

        return $run ?: null;
    }

    public function formattedBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        if ($unit === 0) {
            return sprintf('%d %s', $bytes, $units[$unit]);
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }

    private function getActiveRun(): ?array
    {
        if (!$this->tableExists(self::RUN_TABLE)) {
            return null;
        }

        $run = (new Query())
            ->from(self::RUN_TABLE)
            ->where(['status' => [self::STATUS_QUEUED, self::STATUS_RUNNING]])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return $run ?: null;
    }

    private function getLatestCompletedRun(): ?array
    {
        if (!$this->tableExists(self::RUN_TABLE)) {
            return null;
        }

        $run = (new Query())
            ->from(self::RUN_TABLE)
            ->where(['status' => self::STATUS_COMPLETED])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return $run ?: null;
    }

    private function nextGifAssetBatch(int $lastAssetId, int $limit): array
    {
        return (new Query())
            ->select([
                'id' => 'a.id',
                'volumeId' => 'a.volumeId',
                'folderId' => 'a.folderId',
                'filename' => 'a.filename',
                'size' => 'a.size',
                'width' => 'a.width',
                'height' => 'a.height',
            ])
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->where(['>', 'a.id', $lastAssetId])
            ->andWhere(['e.dateDeleted' => null])
            ->andWhere(new Expression('LOWER([[a.filename]]) LIKE :gifExtension', [
                ':gifExtension' => '%.gif',
            ]))
            ->orderBy(['a.id' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    private function relationStats(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $query = (new Query())
            ->from(['r' => '{{%relations}}'])
            ->where(['r.targetId' => $assetIds])
            ->groupBy(['r.targetId']);

        $matrixConditions = [];
        $ownerColumns = ['[[r.sourceId]]'];

        if ($this->hasTableColumn('{{%matrixblocks}}', 'primaryOwnerId')) {
            $query->leftJoin(['mb' => '{{%matrixblocks}}'], '[[mb.id]] = [[r.sourceId]]');
            array_unshift($ownerColumns, '[[mb.primaryOwnerId]]');
            $matrixConditions[] = '[[mb.id]] IS NOT NULL';
        }

        if ($this->hasTableColumn('{{%entries}}', 'primaryOwnerId')) {
            $query->leftJoin(['se' => '{{%entries}}'], '[[se.id]] = [[r.sourceId]]');
            array_unshift($ownerColumns, '[[se.primaryOwnerId]]');
            $matrixConditions[] = '[[se.primaryOwnerId]] IS NOT NULL';
        }

        $matrixCondition = empty($matrixConditions) ? '0 = 1' : '(' . implode(' OR ', $matrixConditions) . ')';
        $ownerExpression = count($ownerColumns) === 1
            ? 'COUNT(DISTINCT [[r.sourceId]])'
            : 'COUNT(DISTINCT COALESCE(' . implode(', ', $ownerColumns) . '))';

        $rows = $query
            ->select([
                'targetId' => 'r.targetId',
                'relationCount' => new Expression('COUNT(*)'),
                'directRelationCount' => new Expression('SUM(CASE WHEN ' . $matrixCondition . ' THEN 0 ELSE 1 END)'),
                'matrixRelationCount' => new Expression('SUM(CASE WHEN ' . $matrixCondition . ' THEN 1 ELSE 0 END)'),
                'sourceElementCount' => new Expression('COUNT(DISTINCT [[r.sourceId]])'),
                'ownerElementCount' => new Expression($ownerExpression),
            ])
            ->all();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(int)$row['targetId']] = [
                'relationCount' => (int)$row['relationCount'],
                'directRelationCount' => (int)$row['directRelationCount'],
                'matrixRelationCount' => (int)$row['matrixRelationCount'],
                'sourceElementCount' => (int)$row['sourceElementCount'],
                'ownerElementCount' => (int)$row['ownerElementCount'],
            ];
        }

        return $stats;
    }

    private function conversionStats(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $rows = (new Query())
            ->select(['assetId', 'status', 'outputAssetId'])
            ->from(Conversions::TABLE)
            ->where(['assetId' => $assetIds])
            ->all();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(int)$row['assetId']] = [
                'status' => $row['status'],
                'outputAssetId' => $row['outputAssetId'] !== null ? (int)$row['outputAssetId'] : null,
            ];
        }

        return $stats;
    }

    private function nextUnusedAssetRows(int $runId, int $lastAssetId, int $limit): array
    {
        return (new Query())
            ->from(self::ASSET_TABLE)
            ->where([
                'runId' => $runId,
                'relationCount' => 0,
            ])
            ->andWhere(['>', 'assetId', $lastAssetId])
            ->orderBy(['assetId' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    private function storeAssetBatch(int $runId, array $assets, array $relationStats, array $conversionStats): array
    {
        $now = $this->now();
        $rows = [];
        $assetIds = [];
        $summary = [
            'assetCount' => 0,
            'usedAssets' => 0,
            'unusedAssets' => 0,
            'totalBytes' => 0,
            'relationCount' => 0,
            'directRelationCount' => 0,
            'matrixRelationCount' => 0,
            'sourceElementCount' => 0,
            'ownerElementCount' => 0,
            'largestAssetId' => null,
            'largestBytes' => 0,
        ];

        foreach ($assets as $asset) {
            $assetId = (int)$asset['id'];
            $assetIds[] = $assetId;
            $size = (int)($asset['size'] ?? 0);
            $relations = $relationStats[$assetId] ?? [
                'relationCount' => 0,
                'directRelationCount' => 0,
                'matrixRelationCount' => 0,
                'sourceElementCount' => 0,
                'ownerElementCount' => 0,
            ];
            $conversion = $conversionStats[$assetId] ?? [
                'status' => null,
                'outputAssetId' => null,
            ];

            $summary['assetCount']++;
            $summary['totalBytes'] += $size;
            $summary['relationCount'] += $relations['relationCount'];
            $summary['directRelationCount'] += $relations['directRelationCount'];
            $summary['matrixRelationCount'] += $relations['matrixRelationCount'];
            $summary['sourceElementCount'] += $relations['sourceElementCount'];
            $summary['ownerElementCount'] += $relations['ownerElementCount'];

            if ($relations['relationCount'] > 0) {
                $summary['usedAssets']++;
            } else {
                $summary['unusedAssets']++;
            }

            if ($size > $summary['largestBytes']) {
                $summary['largestAssetId'] = $assetId;
                $summary['largestBytes'] = $size;
            }

            $rows[] = [
                $runId,
                $assetId,
                $asset['volumeId'] !== null ? (int)$asset['volumeId'] : null,
                $asset['folderId'] !== null ? (int)$asset['folderId'] : null,
                (string)$asset['filename'],
                $size,
                $asset['width'] !== null ? (int)$asset['width'] : null,
                $asset['height'] !== null ? (int)$asset['height'] : null,
                $relations['relationCount'],
                $relations['directRelationCount'],
                $relations['matrixRelationCount'],
                $relations['sourceElementCount'],
                $relations['ownerElementCount'],
                $conversion['status'],
                $conversion['outputAssetId'],
                $now,
                $now,
            ];
        }

        if (!empty($assetIds)) {
            Craft::$app->getDb()->createCommand()
                ->delete(self::ASSET_TABLE, ['runId' => $runId, 'assetId' => $assetIds])
                ->execute();

            Craft::$app->getDb()->createCommand()
                ->batchInsert(self::ASSET_TABLE, [
                    'runId',
                    'assetId',
                    'volumeId',
                    'folderId',
                    'filename',
                    'size',
                    'width',
                    'height',
                    'relationCount',
                    'directRelationCount',
                    'matrixRelationCount',
                    'sourceElementCount',
                    'ownerElementCount',
                    'conversionStatus',
                    'outputAssetId',
                    'dateCreated',
                    'dateUpdated',
                ], $rows)
                ->execute();
        }

        return $summary;
    }

    private function pushBatchJob(int $runId, int $batchSize)
    {
        return Craft::$app->getQueue()->push(new ScanGifUsageJob([
            'runId' => $runId,
            'batchSize' => $batchSize,
        ]));
    }

    private function pushDeleteUnusedJob(int $runId, int $batchSize, bool $hardDelete)
    {
        return Craft::$app->getQueue()->push(new DeleteUnusedGifAssetsJob([
            'runId' => $runId,
            'batchSize' => $batchSize,
            'hardDelete' => $hardDelete,
        ]));
    }

    private function updateRun(int $runId, array $values): void
    {
        $values['dateUpdated'] = $this->now();

        Craft::$app->getDb()->createCommand()
            ->update(self::RUN_TABLE, $values, ['id' => $runId])
            ->execute();
    }

    private function decorateRun(array $run): array
    {
        $totalBytes = (int)($run['totalBytes'] ?? 0);
        $gifAssets = (int)($run['gifAssets'] ?? 0);

        $run['totalBytesFormatted'] = $this->formattedBytes($totalBytes);
        $run['largestBytesFormatted'] = $this->formattedBytes((int)($run['largestBytes'] ?? 0));
        $run['averageBytesFormatted'] = $gifAssets > 0 ? $this->formattedBytes((int)floor($totalBytes / $gifAssets)) : '0 B';
        $run['deleteFreedBytesFormatted'] = $this->formattedBytes((int)($run['deleteFreedBytes'] ?? 0));
        $run['isActive'] = in_array($run['status'] ?? null, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
        $run['deleteIsActive'] = in_array($run['deleteStatus'] ?? null, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);

        return $run;
    }

    private function decorateAssetRow(array $row): array
    {
        $row['sizeFormatted'] = $this->formattedBytes((int)($row['size'] ?? 0));

        return $row;
    }

    private function tableExists(string $table): bool
    {
        return Craft::$app->getDb()->schema->getTableSchema($table, true) !== null;
    }

    private function hasTableColumn(string $table, string $column): bool
    {
        $schema = Craft::$app->getDb()->schema->getTableSchema($table, true);

        return $schema !== null && isset($schema->columns[$column]);
    }

    private function deleteUnusedAsset(array $row, bool $hardDelete): array
    {
        $assetId = (int)$row['assetId'];

        try {
            $asset = Asset::find()
                ->id($assetId)
                ->site('*')
                ->status(null)
                ->trashed(false)
                ->one();

            if (!$asset instanceof Asset) {
                return [
                    'status' => 'skipped',
                    'bytes' => 0,
                    'error' => null,
                ];
            }

            if (strtolower((string)$asset->getExtension()) !== 'gif') {
                return [
                    'status' => 'skipped',
                    'bytes' => 0,
                    'error' => null,
                ];
            }

            $currentRelations = $this->relationStats([$assetId]);

            if ((int)($currentRelations[$assetId]['relationCount'] ?? 0) > 0) {
                return [
                    'status' => 'skipped',
                    'bytes' => 0,
                    'error' => null,
                ];
            }

            if (!Craft::$app->getElements()->deleteElement($asset, $hardDelete)) {
                return [
                    'status' => 'failed',
                    'bytes' => 0,
                    'error' => sprintf('Craft did not delete asset %s.', $assetId),
                ];
            }

            return [
                'status' => 'deleted',
                'bytes' => (int)($row['size'] ?? 0),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Craft::error(
                sprintf('Could not delete unused GIF asset %s: %s', $assetId, $e->getMessage()),
                __METHOD__
            );

            return [
                'status' => 'failed',
                'bytes' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
