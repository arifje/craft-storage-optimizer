<?php

namespace arifje\craftstorageoptimizer\services;

use arifje\craftstorageoptimizer\jobs\DeleteGhostAssetsJob;
use arifje\craftstorageoptimizer\jobs\ScanAssetUsageJob;
use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Entry;
use yii\db\Expression;
use yii\db\Query;

class AssetUsage extends Component
{
    public const RUN_TABLE = '{{%storage_optimizer_asset_usage_runs}}';
    public const ASSET_TABLE = '{{%storage_optimizer_asset_usage_assets}}';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const DEFAULT_BATCH_SIZE = 500;
    public const DEFAULT_DELETE_BATCH_SIZE = 100;
    public const DEFAULT_REPORT_LIMIT = 25;
    public const MAX_REPORT_LIMIT = 100;

    private const REPORT_SORT_COLUMNS = [
        'assetId' => 'a.assetId',
        'filename' => 'a.filename',
        'kind' => 'a.kind',
        'extension' => 'a.extension',
        'size' => 'a.size',
        'volume' => 'v.name',
        'dateCreated' => 'e.dateCreated',
        'dateUpdated' => 'e.dateUpdated',
    ];

    public function queueScan(int $batchSize = self::DEFAULT_BATCH_SIZE, ?int $volumeId = null): array
    {
        $batchSize = max(1, min(5000, $batchSize));
        $volumeId = $volumeId !== null && $volumeId > 0 ? $volumeId : null;
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
                'volumeId' => $volumeId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ])
            ->execute();

        $runId = (int)Craft::$app->getDb()->getLastInsertID();
        $jobId = $this->pushScanJob($runId, $batchSize);

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

    public function processScanBatch(int $runId, int $batchSize): array
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
            $volumeId = !empty($run['volumeId']) ? (int)$run['volumeId'] : null;
            $assets = $this->nextAssetBatch((int)$run['lastAssetId'], $batchSize, $volumeId);

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
            $protectionStats = $this->protectionStats($assetIds);
            $batchSummary = $this->storeAssetBatch($runId, $assets, $relationStats, $protectionStats);
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
                'assetCount' => ((int)$run['assetCount']) + $batchSummary['assetCount'],
                'relatedAssets' => ((int)$run['relatedAssets']) + $batchSummary['relatedAssets'],
                'ghostAssets' => ((int)$run['ghostAssets']) + $batchSummary['ghostAssets'],
                'protectedAssets' => ((int)$run['protectedAssets']) + $batchSummary['protectedAssets'],
                'totalBytes' => ((int)$run['totalBytes']) + $batchSummary['totalBytes'],
                'ghostBytes' => ((int)$run['ghostBytes']) + $batchSummary['ghostBytes'],
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
                $jobId = $this->pushScanJob($runId, $batchSize);
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

    public function queueDeleteGhosts(?int $runId = null, int $batchSize = self::DEFAULT_DELETE_BATCH_SIZE, bool $hardDelete = false): array
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

        if ((int)($run['ghostAssets'] ?? 0) === 0) {
            return [
                'queued' => false,
                'reason' => 'no-ghost-assets',
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
            'deleteDeletedBytes' => 0,
            'deleteHardDelete' => $hardDelete,
            'deleteLastError' => null,
            'deleteStartedAt' => null,
            'deleteCompletedAt' => null,
        ]);

        $jobId = $this->pushDeleteGhostsJob($runId, $batchSize, $hardDelete);

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

    public function processDeleteGhostsBatch(int $runId, int $batchSize, bool $hardDelete = false): array
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
            $rows = $this->nextGhostAssetRows($runId, (int)($run['deleteLastAssetId'] ?? 0), $batchSize);

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
                'deletedBytes' => 0,
                'lastError' => null,
            ];
            $lastAssetId = 0;

            foreach ($rows as $row) {
                $lastAssetId = max($lastAssetId, (int)$row['assetId']);
                $summary['attempted']++;
                $result = $this->deleteGhostAsset($row, $hardDelete);

                if ($result['status'] === 'deleted') {
                    $summary['deleted']++;
                    $summary['deletedBytes'] += (int)$result['bytes'];
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
                'deleteDeletedBytes' => ((int)($run['deleteDeletedBytes'] ?? 0)) + $summary['deletedBytes'],
            ];

            if ($summary['lastError'] !== null) {
                $updateValues['deleteLastError'] = $summary['lastError'];
            }

            $this->updateRun($runId, $updateValues);

            $queuedNext = count($rows) === $batchSize;

            if ($queuedNext) {
                $jobId = $this->pushDeleteGhostsJob($runId, $batchSize, $hardDelete);
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

    public function ghostAssetReport(
        int $runId,
        int $page = 1,
        int $perPage = self::DEFAULT_REPORT_LIMIT,
        string $sort = 'size',
        string $direction = 'desc'
    ): array
    {
        if (!$this->tableExists(self::ASSET_TABLE)) {
            return $this->emptyReport($page, $perPage, $sort, $direction);
        }

        $sort = array_key_exists($sort, self::REPORT_SORT_COLUMNS) ? $sort : 'size';
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $directionFlag = $direction === 'asc' ? SORT_ASC : SORT_DESC;
        $perPage = max(5, min(self::MAX_REPORT_LIMIT, $perPage));
        $page = max(1, $page);

        $query = (new Query())
            ->from(['a' => self::ASSET_TABLE])
            ->leftJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.assetId]]')
            ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
            ->where([
                'runId' => $runId,
                'cleanupCandidate' => true,
            ]);

        $total = (int)(clone $query)->count('*', Craft::$app->getDb());
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $query
            ->select([
                'id' => 'a.id',
                'runId' => 'a.runId',
                'assetId' => 'a.assetId',
                'volumeId' => 'a.volumeId',
                'volumeName' => 'v.name',
                'folderId' => 'a.folderId',
                'filename' => 'a.filename',
                'kind' => 'a.kind',
                'extension' => 'a.extension',
                'size' => 'a.size',
                'width' => 'a.width',
                'height' => 'a.height',
                'relationCount' => 'a.relationCount',
                'directRelationCount' => 'a.directRelationCount',
                'matrixRelationCount' => 'a.matrixRelationCount',
                'sourceElementCount' => 'a.sourceElementCount',
                'ownerElementCount' => 'a.ownerElementCount',
                'isProtected' => 'a.isProtected',
                'protectedReason' => 'a.protectedReason',
                'cleanupCandidate' => 'a.cleanupCandidate',
                'assetDateCreated' => 'e.dateCreated',
                'assetDateUpdated' => 'e.dateUpdated',
            ])
            ->orderBy([
                self::REPORT_SORT_COLUMNS[$sort] => $directionFlag,
                'a.assetId' => SORT_ASC,
            ])
            ->offset($offset)
            ->limit($perPage)
            ->all();

        return [
            'rows' => $this->decorateAssetRows($rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'sort' => $sort,
            'direction' => $direction,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + count($rows), $total),
            'sortColumns' => array_keys(self::REPORT_SORT_COLUMNS),
        ];
    }

    public function topGhostAssets(int $runId, int $limit = self::DEFAULT_REPORT_LIMIT): array
    {
        return $this->ghostAssetReport($runId, 1, $limit)['rows'];
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

    public function availableVolumes(): array
    {
        $volumes = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumes[] = [
                'label' => $volume->name,
                'value' => $volume->id,
            ];
        }

        return $volumes;
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

    private function nextAssetBatch(int $lastAssetId, int $limit, ?int $volumeId): array
    {
        $query = (new Query())
            ->select([
                'id' => 'a.id',
                'volumeId' => 'a.volumeId',
                'folderId' => 'a.folderId',
                'filename' => 'a.filename',
                'kind' => 'a.kind',
                'size' => 'a.size',
                'width' => 'a.width',
                'height' => 'a.height',
            ])
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->where(['>', 'a.id', $lastAssetId])
            ->andWhere(['e.dateDeleted' => null])
            ->orderBy(['a.id' => SORT_ASC])
            ->limit($limit);

        if ($volumeId !== null) {
            $query->andWhere(['a.volumeId' => $volumeId]);
        }

        return $query->all();
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
        $expressions = $this->relationOwnerExpressions($query);

        $rows = $query
            ->select([
                'targetId' => 'r.targetId',
                'relationCount' => new Expression('COUNT(*)'),
                'directRelationCount' => new Expression('SUM(CASE WHEN ' . $expressions['matrixCondition'] . ' THEN 0 ELSE 1 END)'),
                'matrixRelationCount' => new Expression('SUM(CASE WHEN ' . $expressions['matrixCondition'] . ' THEN 1 ELSE 0 END)'),
                'sourceElementCount' => new Expression('COUNT(DISTINCT [[r.sourceId]])'),
                'ownerElementCount' => new Expression($expressions['ownerCountExpression']),
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

    private function relationOwnerExpressions(Query $query): array
    {
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

        $ownerExpression = count($ownerColumns) === 1
            ? '[[r.sourceId]]'
            : 'COALESCE(' . implode(', ', $ownerColumns) . ')';

        return [
            'matrixCondition' => empty($matrixConditions) ? '0 = 1' : '(' . implode(' OR ', $matrixConditions) . ')',
            'ownerCountExpression' => 'COUNT(DISTINCT ' . $ownerExpression . ')',
            'ownerIdExpression' => 'MIN(' . $ownerExpression . ')',
        ];
    }

    private function protectionStats(array $assetIds): array
    {
        if (empty($assetIds) || !$this->tableExists(Conversions::TABLE)) {
            return [];
        }

        $rows = (new Query())
            ->select([
                'outputAssetId' => 'c.outputAssetId',
            ])
            ->from(['c' => Conversions::TABLE])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[c.assetId]]')
            ->where(['c.outputAssetId' => $assetIds])
            ->andWhere(['e.dateDeleted' => null])
            ->all();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(int)$row['outputAssetId']] = [
                'protected' => true,
                'reason' => 'storage-optimizer-output',
            ];
        }

        return $stats;
    }

    private function nextGhostAssetRows(int $runId, int $lastAssetId, int $limit): array
    {
        return (new Query())
            ->from(self::ASSET_TABLE)
            ->where([
                'runId' => $runId,
                'cleanupCandidate' => true,
            ])
            ->andWhere(['>', 'assetId', $lastAssetId])
            ->orderBy(['assetId' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    private function storeAssetBatch(int $runId, array $assets, array $relationStats, array $protectionStats): array
    {
        $now = $this->now();
        $rows = [];
        $assetIds = [];
        $summary = [
            'assetCount' => 0,
            'relatedAssets' => 0,
            'ghostAssets' => 0,
            'protectedAssets' => 0,
            'totalBytes' => 0,
            'ghostBytes' => 0,
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
            $extension = strtolower(pathinfo((string)$asset['filename'], PATHINFO_EXTENSION));
            $relations = $relationStats[$assetId] ?? [
                'relationCount' => 0,
                'directRelationCount' => 0,
                'matrixRelationCount' => 0,
                'sourceElementCount' => 0,
                'ownerElementCount' => 0,
            ];
            $protection = $protectionStats[$assetId] ?? [
                'protected' => false,
                'reason' => null,
            ];
            $cleanupCandidate = (int)$relations['relationCount'] === 0 && !$protection['protected'];

            $summary['assetCount']++;
            $summary['totalBytes'] += $size;
            $summary['relationCount'] += $relations['relationCount'];
            $summary['directRelationCount'] += $relations['directRelationCount'];
            $summary['matrixRelationCount'] += $relations['matrixRelationCount'];
            $summary['sourceElementCount'] += $relations['sourceElementCount'];
            $summary['ownerElementCount'] += $relations['ownerElementCount'];

            if ($relations['relationCount'] > 0) {
                $summary['relatedAssets']++;
            } elseif ($protection['protected']) {
                $summary['protectedAssets']++;
            } else {
                $summary['ghostAssets']++;
                $summary['ghostBytes'] += $size;
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
                (string)($asset['kind'] ?? ''),
                $extension,
                $size,
                $asset['width'] !== null ? (int)$asset['width'] : null,
                $asset['height'] !== null ? (int)$asset['height'] : null,
                $relations['relationCount'],
                $relations['directRelationCount'],
                $relations['matrixRelationCount'],
                $relations['sourceElementCount'],
                $relations['ownerElementCount'],
                $protection['protected'] ? 1 : 0,
                $protection['reason'],
                $cleanupCandidate ? 1 : 0,
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
                    'kind',
                    'extension',
                    'size',
                    'width',
                    'height',
                    'relationCount',
                    'directRelationCount',
                    'matrixRelationCount',
                    'sourceElementCount',
                    'ownerElementCount',
                    'isProtected',
                    'protectedReason',
                    'cleanupCandidate',
                    'dateCreated',
                    'dateUpdated',
                ], $rows)
                ->execute();
        }

        return $summary;
    }

    private function pushScanJob(int $runId, int $batchSize)
    {
        return Craft::$app->getQueue()->push(new ScanAssetUsageJob([
            'runId' => $runId,
            'batchSize' => $batchSize,
        ]));
    }

    private function pushDeleteGhostsJob(int $runId, int $batchSize, bool $hardDelete)
    {
        return Craft::$app->getQueue()->push(new DeleteGhostAssetsJob([
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
        $assetCount = (int)($run['assetCount'] ?? 0);

        $run['totalBytesFormatted'] = $this->formattedBytes($totalBytes);
        $run['ghostBytesFormatted'] = $this->formattedBytes((int)($run['ghostBytes'] ?? 0));
        $run['largestBytesFormatted'] = $this->formattedBytes((int)($run['largestBytes'] ?? 0));
        $run['averageBytesFormatted'] = $assetCount > 0 ? $this->formattedBytes((int)floor($totalBytes / $assetCount)) : '0 B';
        $run['deleteDeletedBytesFormatted'] = $this->formattedBytes((int)($run['deleteDeletedBytes'] ?? 0));
        $run['volumeName'] = $this->volumeName((int)($run['volumeId'] ?? 0));
        $run['isActive'] = in_array($run['status'] ?? null, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
        $run['deleteIsActive'] = in_array($run['deleteStatus'] ?? null, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);

        return $run;
    }

    private function decorateAssetRows(array $rows): array
    {
        $assetIds = array_map(static fn(array $row): int => (int)$row['assetId'], $rows);
        $assets = $this->assetsForRows($assetIds);
        $ownerIdsByAsset = $this->ownerIdsForAssets($assetIds);
        $owners = $this->ownerEntries(array_values($ownerIdsByAsset));

        return array_map(function(array $row) use ($assets, $ownerIdsByAsset, $owners): array {
            return $this->decorateAssetRow($row, $assets, $ownerIdsByAsset, $owners);
        }, $rows);
    }

    private function decorateAssetRow(array $row, array $assets = [], array $ownerIdsByAsset = [], array $owners = []): array
    {
        $row['sizeFormatted'] = $this->formattedBytes((int)($row['size'] ?? 0));
        $row['volumeName'] = $row['volumeName'] ?: $this->volumeName((int)($row['volumeId'] ?? 0));
        $row['assetDateCreatedFormatted'] = $this->formatDateTime($row['assetDateCreated'] ?? null);
        $row['assetDateUpdatedFormatted'] = $this->formatDateTime($row['assetDateUpdated'] ?? null);

        $asset = $assets[(int)$row['assetId']] ?? null;

        $row['assetUrl'] = $asset instanceof Asset ? $this->assetUrl($asset) : null;
        $row['assetCpEditUrl'] = $asset instanceof Asset ? $asset->getCpEditUrl() : null;

        $ownerId = $ownerIdsByAsset[(int)$row['assetId']] ?? null;
        $owner = $ownerId !== null ? ($owners[$ownerId] ?? null) : null;

        $row['ownerId'] = $ownerId;
        $row['ownerTitle'] = $owner['title'] ?? null;
        $row['ownerUrl'] = $owner['url'] ?? null;

        return $row;
    }

    private function assetsForRows(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_unique(array_map('intval', $assetIds))));

        if (empty($assetIds)) {
            return [];
        }

        $assets = Asset::find()
            ->id($assetIds)
            ->site('*')
            ->status(null)
            ->trashed(null)
            ->all();
        $indexed = [];

        foreach ($assets as $asset) {
            if (!isset($indexed[(int)$asset->id])) {
                $indexed[(int)$asset->id] = $asset;
            }
        }

        return $indexed;
    }

    private function assetUrl(Asset $asset): ?string
    {
        try {
            return $asset->getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    private function emptyReport(int $page, int $perPage, string $sort, string $direction): array
    {
        return [
            'rows' => [],
            'total' => 0,
            'page' => max(1, $page),
            'perPage' => max(5, min(self::MAX_REPORT_LIMIT, $perPage)),
            'totalPages' => 1,
            'sort' => array_key_exists($sort, self::REPORT_SORT_COLUMNS) ? $sort : 'size',
            'direction' => strtolower($direction) === 'asc' ? 'asc' : 'desc',
            'from' => 0,
            'to' => 0,
            'sortColumns' => array_keys(self::REPORT_SORT_COLUMNS),
        ];
    }

    private function volumeName(?int $volumeId): ?string
    {
        if (empty($volumeId)) {
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        return $volume?->name;
    }

    private function formatDateTime($value): string
    {
        if (empty($value)) {
            return '-';
        }

        try {
            return Craft::$app->getFormatter()->asDatetime($value, 'short');
        } catch (\Throwable) {
            return (string)$value;
        }
    }

    private function ownerIdsForAssets(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_unique(array_map('intval', $assetIds))));

        if (empty($assetIds)) {
            return [];
        }

        $query = (new Query())
            ->from(['r' => '{{%relations}}'])
            ->where(['r.targetId' => $assetIds])
            ->groupBy(['r.targetId']);
        $expressions = $this->relationOwnerExpressions($query);
        $rows = $query
            ->select([
                'targetId' => 'r.targetId',
                'ownerElementId' => new Expression($expressions['ownerIdExpression']),
            ])
            ->all();

        $owners = [];

        foreach ($rows as $row) {
            if (!empty($row['ownerElementId'])) {
                $owners[(int)$row['targetId']] = (int)$row['ownerElementId'];
            }
        }

        return $owners;
    }

    private function ownerEntries(array $ownerIds): array
    {
        $ownerIds = array_values(array_filter(array_unique(array_map('intval', $ownerIds))));

        if (empty($ownerIds)) {
            return [];
        }

        $entries = Entry::find()
            ->id($ownerIds)
            ->site('*')
            ->status(null)
            ->all();
        $owners = [];

        foreach ($entries as $entry) {
            if (isset($owners[(int)$entry->id])) {
                continue;
            }

            $owners[(int)$entry->id] = [
                'title' => (string)$entry,
                'url' => $entry->getCpEditUrl(),
            ];
        }

        return $owners;
    }

    private function deleteGhostAsset(array $row, bool $hardDelete): array
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

            $currentRelations = $this->relationStats([$assetId]);

            if ((int)($currentRelations[$assetId]['relationCount'] ?? 0) > 0) {
                return [
                    'status' => 'skipped',
                    'bytes' => 0,
                    'error' => null,
                ];
            }

            $currentProtection = $this->protectionStats([$assetId]);

            if (!empty($currentProtection[$assetId]['protected'])) {
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
                sprintf('Could not delete ghost asset %s: %s', $assetId, $e->getMessage()),
                __METHOD__
            );

            return [
                'status' => 'failed',
                'bytes' => 0,
                'error' => $e->getMessage(),
            ];
        }
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

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
