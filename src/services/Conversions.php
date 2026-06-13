<?php

namespace arifje\craftstorageoptimizer\services;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\jobs\ConvertGifJob;
use arifje\craftstorageoptimizer\models\Settings;
use Craft;
use craft\base\Component;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use yii\db\Expression;
use yii\db\IntegrityException;
use yii\db\Query;

class Conversions extends Component
{
    public const TABLE = '{{%storage_optimizer_gif_conversions}}';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MISSING = 'missing';

    private bool $savingGeneratedAsset = false;
    private array $animationCache = [];

    public function isSavingGeneratedAsset(): bool
    {
        return $this->savingGeneratedAsset;
    }

    public function isGifAsset(Asset $asset): bool
    {
        if ($asset->id === null) {
            return false;
        }

        if (ElementHelper::isDraftOrRevision($asset)) {
            return false;
        }

        return strtolower((string)$asset->getExtension()) === 'gif';
    }

    public function getWebpAsset(Asset $asset): ?Asset
    {
        if ($asset->id === null) {
            return null;
        }

        if (strtolower((string)$asset->getExtension()) === 'webp') {
            return $asset;
        }

        if (!$this->isGifAsset($asset)) {
            return null;
        }

        $record = $this->getRecordByAssetId((int)$asset->id);

        if ($record !== null && $this->hasFreshOutput($record, $asset)) {
            $record = $this->getRecordByAssetId((int)$asset->id);

            if (!empty($record['outputAssetId'])) {
                return $this->getAsset((int)$record['outputAssetId']);
            }
        }

        $output = $this->findOutputAssetForSource($asset);

        if ($output instanceof Asset && $this->outputIsAtLeastAsNewAsSource($output, $asset) && $this->outputAssetIsWorthUsing($asset, $output, 'webp')['keep']) {
            return $output;
        }

        return null;
    }

    public function getSourceGifAsset(Asset $asset): ?Asset
    {
        if ($asset->id === null) {
            return null;
        }

        if ($this->isGifAsset($asset)) {
            return $asset;
        }

        $extension = strtolower((string)$asset->getExtension());

        if ($extension !== 'webp') {
            return null;
        }

        $record = $this->getRecordByOutputAssetId((int)$asset->id);

        if ($record === null) {
            return null;
        }

        $source = $this->getAsset((int)$record['assetId']);

        return $source instanceof Asset && $this->isGifAsset($source) ? $source : null;
    }

    public function isGifOrConvertedWebp(Asset $asset): bool
    {
        if ($this->isGifAsset($asset)) {
            return true;
        }

        return strtolower((string)$asset->getExtension()) === 'webp'
            && $this->getSourceGifAsset($asset) instanceof Asset;
    }

    public function isAnimatedImage(Asset $asset): bool
    {
        return $this->isAnimatedGif($asset) || $this->isAnimatedWebp($asset);
    }

    public function isAnimatedGif(Asset $asset): bool
    {
        if (!$this->isGifAsset($asset)) {
            return false;
        }

        return $this->inspectAssetAnimation($asset, 'gif', fn(string $path): bool => $this->gifFileHasAnimation($path));
    }

    public function isAnimatedWebp(Asset $asset): bool
    {
        if (strtolower((string)$asset->getExtension()) !== 'webp') {
            return false;
        }

        return $this->inspectAssetAnimation($asset, 'webp', fn(string $path): bool => $this->webpFileHasAnimation($path));
    }

    public function scan(?int $limit = null, ?int $volumeId = null, bool $queue = false, bool $force = false): array
    {
        $result = [
            'found' => 0,
            'tracked' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($this->gifAssetQuery($limit, $volumeId)->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $result['found']++;

            try {
                $this->prepareRecord($asset, $force);
                $result['tracked']++;

                if ($queue) {
                    $queueResult = $this->queueAsset($asset, $force);
                    $queueResult['queued'] ? $result['queued']++ : $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['errors']++;
                Craft::error(sprintf('GIF media scan failed for asset %s: %s', $asset->id, $e->getMessage()), __METHOD__);
            }
        }

        return $result;
    }

    public function queueAsset(Asset $asset, bool $force = false, ?int $delay = null): array
    {
        if (!$this->isGifAsset($asset)) {
            return [
                'queued' => false,
                'reason' => 'not-gif',
                'record' => null,
                'jobId' => null,
            ];
        }

        $record = $this->prepareRecord($asset, $force);

        if (!$this->hasEnabledOutputTarget()) {
            return [
                'queued' => false,
                'reason' => 'no-enabled-targets',
                'record' => $record,
                'jobId' => null,
            ];
        }

        if (!$force && $this->hasActiveJob($record)) {
            return [
                'queued' => false,
                'reason' => 'already-active',
                'record' => $record,
                'jobId' => $record['lastJobId'] ?? null,
            ];
        }

        if (!$force && ($record['status'] ?? null) === self::STATUS_SKIPPED && $this->skippedRecordShouldStaySkipped($record)) {
            return [
                'queued' => false,
                'reason' => 'previously-skipped',
                'record' => $record,
                'jobId' => null,
            ];
        }

        if (!$force && $this->hasFreshOutput($record, $asset)) {
            $record = $this->getRecordById((int)$record['id']) ?? $record;
            $outputAsset = !empty($record['outputAssetId']) ? $this->getAsset((int)$record['outputAssetId']) : $this->findOutputAssetForSource($asset);
            $replacement = $this->replaceAssetReferencesIfEnabled($asset, $outputAsset);

            return [
                'queued' => false,
                'reason' => 'already-converted',
                'record' => $record,
                'jobId' => null,
                'referencesReplaced' => $replacement['replaced'],
            ];
        }

        $now = $this->now();

        $this->updateRecord((int)$record['id'], [
            'status' => self::STATUS_QUEUED,
            'queuedAt' => $now,
            'startedAt' => null,
            'completedAt' => null,
            'verifiedAt' => null,
            'lastError' => null,
        ]);

        $jobId = $this->pushJob(new ConvertGifJob([
            'conversionId' => (int)$record['id'],
            'assetId' => (int)$asset->id,
            'force' => $force,
        ]), $delay ?? $this->settings()->queueDelay);

        $this->updateRecord((int)$record['id'], [
            'lastJobId' => $jobId !== null ? (string)$jobId : null,
        ]);

        return [
            'queued' => true,
            'reason' => 'queued',
            'record' => $this->getRecordById((int)$record['id']),
            'jobId' => $jobId,
        ];
    }

    public function queueAll(?int $limit = null, ?int $volumeId = null, bool $force = false, ?int $delay = null): array
    {
        $result = [
            'found' => 0,
            'queued' => 0,
            'skipped' => 0,
            'referencesReplaced' => 0,
            'errors' => 0,
        ];

        foreach ($this->gifAssetQuery($limit, $volumeId)->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $result['found']++;

            try {
                $queueResult = $this->queueAsset($asset, $force, $delay);
                $queueResult['queued'] ? $result['queued']++ : $result['skipped']++;
                $result['referencesReplaced'] += (int)($queueResult['referencesReplaced'] ?? 0);
            } catch (\Throwable $e) {
                $result['errors']++;
                Craft::error(sprintf('GIF media queue failed for asset %s: %s', $asset->id, $e->getMessage()), __METHOD__);
            }
        }

        return $result;
    }

    public function convertAsset(int $assetId, bool $force = false): array
    {
        $asset = $this->getAsset($assetId);

        if (!$asset instanceof Asset || !$this->isGifAsset($asset)) {
            return [
                'converted' => false,
                'status' => self::STATUS_MISSING,
                'message' => sprintf('GIF asset %s was not found.', $assetId),
                'record' => null,
            ];
        }

        $record = $this->prepareRecord($asset, $force);

        return $this->convertRecord((int)$record['id'], $force);
    }

    public function convertQueued(?int $limit = null, bool $force = false): array
    {
        $result = [
            'processed' => 0,
            'converted' => 0,
            'skipped' => 0,
            'referencesReplaced' => 0,
            'failed' => 0,
        ];

        $query = (new Query())
            ->from(self::TABLE)
            ->where(['status' => [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_FAILED]])
            ->orderBy(['queuedAt' => SORT_ASC, 'dateUpdated' => SORT_ASC]);

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->all() as $record) {
            $result['processed']++;
            $conversion = $this->convertRecord((int)$record['id'], $force);

            if ($conversion['converted']) {
                $result['converted']++;
            } elseif (($conversion['status'] ?? null) === self::STATUS_FAILED || ($conversion['status'] ?? null) === self::STATUS_MISSING) {
                $result['failed']++;
            } else {
                $result['skipped']++;
            }

            $result['referencesReplaced'] += (int)($conversion['referencesReplaced'] ?? 0);
        }

        return $result;
    }

    public function convertRecord(int $recordId, bool $force = false): array
    {
        $record = $this->getRecordById($recordId);

        if ($record === null) {
            return [
                'converted' => false,
                'status' => self::STATUS_MISSING,
                'message' => sprintf('Conversion record %s was not found.', $recordId),
                'record' => null,
            ];
        }

        $asset = $this->getAsset((int)$record['assetId']);

        if (!$asset instanceof Asset || !$this->isGifAsset($asset)) {
            $this->markFailed($recordId, self::STATUS_MISSING, 'Source GIF asset is missing or is no longer a GIF.');

            return [
                'converted' => false,
                'status' => self::STATUS_MISSING,
                'message' => 'Source GIF asset is missing or is no longer a GIF.',
                'record' => $this->getRecordById($recordId),
            ];
        }

        $record = $this->prepareRecord($asset, false);

        if (!$this->hasEnabledOutputTarget()) {
            $message = 'No conversion output targets are enabled.';
            $this->updateRecord((int)$record['id'], [
                'status' => self::STATUS_SKIPPED,
                'lastError' => $message,
            ]);

            return [
                'converted' => false,
                'status' => self::STATUS_SKIPPED,
                'message' => $message,
                'record' => $this->getRecordById((int)$record['id']),
                'referencesReplaced' => 0,
            ];
        }

        if (!$force && $this->hasFreshOutput($record, $asset)) {
            $record = $this->getRecordById((int)$record['id']) ?? $record;
            $outputAsset = !empty($record['outputAssetId']) ? $this->getAsset((int)$record['outputAssetId']) : $this->findOutputAssetForSource($asset);
            $replacement = $this->replaceAssetReferencesIfEnabled($asset, $outputAsset);

            $this->updateRecord((int)$record['id'], [
                'status' => $this->freshOutputStatus($record),
                'completedAt' => $record['completedAt'] ?? $this->now(),
                'lastError' => null,
            ]);

            return [
                'converted' => false,
                'status' => self::STATUS_COMPLETED,
                'message' => $this->conversionMessage('Fresh generated output already exists.', $replacement),
                'record' => $this->getRecordById((int)$record['id']),
                'referencesReplaced' => $replacement['replaced'],
            ];
        }

        $now = $this->now();

        $this->updateRecord((int)$record['id'], [
            'status' => self::STATUS_PROCESSING,
            'attempts' => ((int)($record['attempts'] ?? 0)) + 1,
            'startedAt' => $now,
            'completedAt' => null,
            'verifiedAt' => null,
            'lastError' => null,
        ]);

        $sourceTemp = null;
        $webpTemp = null;

        try {
            $sourceTemp = $asset->getCopyOfFile();
            $updates = [
                'verifiedAt' => null,
            ];
            $messages = [];
            $errors = [];
            $converted = false;
            $webpAsset = null;

            if ($this->settings()->convertToWebp) {
                try {
                    $webpTemp = $this->tempWebpPath($asset);
                    $process = $this->runGif2Webp($sourceTemp, $webpTemp);

                    if ($process['exitCode'] !== 0) {
                        throw new \RuntimeException(trim($process['output']) ?: sprintf('gif2webp exited with code %s.', $process['exitCode']));
                    }

                    if (!is_file($webpTemp) || filesize($webpTemp) === 0) {
                        throw new \RuntimeException('gif2webp did not produce a WebP file.');
                    }

                    $sizeCheck = $this->generatedOutputIsWorthSaving($sourceTemp, $webpTemp, 'webp');

                    if (!$sizeCheck['keep']) {
                        $messages[] = $this->sizeSkipMessage($sizeCheck);
                    } else {
                        $webpAsset = $this->saveOutputAsset($asset, $webpTemp, $this->targetFilename($asset));
                        $updates['outputAssetId'] = $webpAsset->id;
                        $updates['outputPath'] = $webpAsset->getPath();
                        $updates['outputFilename'] = $webpAsset->getFilename();
                        $converted = true;
                        $messages[] = sprintf('Converted asset %s to %s.', $asset->id, $webpAsset->filename);
                    }
                } catch (\Throwable $e) {
                    $errors[] = sprintf('WebP: %s', $e->getMessage());
                }
            }

            $completedAt = $this->now();
            $status = $converted ? self::STATUS_COMPLETED : (!empty($errors) ? self::STATUS_FAILED : self::STATUS_SKIPPED);
            $message = trim(implode(' ', array_merge($messages, $errors)));

            if ($message === '') {
                $message = $converted ? 'Generated output was created.' : 'No conversion output was created.';
            }

            $this->updateRecord((int)$record['id'], array_merge($updates, [
                'status' => $status,
                'completedAt' => $completedAt,
                'verifiedAt' => null,
                'lastError' => !empty($errors) || !$converted ? $message : null,
            ]));

            $replacement = $this->replaceAssetReferencesIfEnabled($asset, $webpAsset);
            $message = $this->conversionMessage($message, $replacement);

            return [
                'converted' => $converted,
                'status' => $status,
                'message' => $message,
                'record' => $this->getRecordById((int)$record['id']),
                'referencesReplaced' => $replacement['replaced'],
            ];
        } catch (\Throwable $e) {
            $this->markFailed((int)$record['id'], self::STATUS_FAILED, $e->getMessage());

            Craft::error(sprintf('GIF media conversion failed for asset %s: %s', $asset->id, $e->getMessage()), __METHOD__);

            return [
                'converted' => false,
                'status' => self::STATUS_FAILED,
                'message' => $e->getMessage(),
                'record' => $this->getRecordById((int)$record['id']),
            ];
        } finally {
            $this->removeTempFile($sourceTemp);
            $this->removeTempFile($webpTemp);
        }
    }

    public function verify(?int $assetId = null, ?int $limit = null): array
    {
        $result = [
            'checked' => 0,
            'verified' => 0,
            'pending' => 0,
            'referencesReplaced' => 0,
            'missing' => 0,
        ];

        $query = (new Query())
            ->from(self::TABLE)
            ->where(['status' => [self::STATUS_COMPLETED, self::STATUS_VERIFIED]]);

        if ($assetId !== null) {
            $query->andWhere(['assetId' => $assetId]);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->all() as $record) {
            $result['checked']++;

            $source = $this->getAsset((int)$record['assetId']);
            $output = !empty($record['outputAssetId']) ? $this->getAsset((int)$record['outputAssetId']) : null;
            $settings = $this->settings();

            if (!$source instanceof Asset || !$this->isGifAsset($source)) {
                $this->markFailed((int)$record['id'], self::STATUS_MISSING, 'Source GIF asset is missing.');
                $result['missing']++;
                continue;
            }

            if ($settings->convertToWebp && (!$output instanceof Asset || strtolower($output->getExtension()) !== 'webp')) {
                $this->markFailed((int)$record['id'], self::STATUS_MISSING, 'Output WebP asset is missing.');
                $result['missing']++;
                continue;
            }

            if (($record['sourceSignature'] ?? null) !== $this->sourceSignature($source)) {
                $this->updateRecord((int)$record['id'], [
                    'status' => self::STATUS_PENDING,
                    'lastError' => 'Source GIF changed after the last conversion.',
                    'verifiedAt' => null,
                ]);
                $result['pending']++;
                continue;
            }

            $replacement = $this->replaceAssetReferencesIfEnabled($source, $output);
            $result['referencesReplaced'] += $replacement['replaced'];

            $this->updateRecord((int)$record['id'], [
                'status' => self::STATUS_VERIFIED,
                'verifiedAt' => $this->now(),
                'lastError' => null,
            ]);

            $result['verified']++;
        }

        return $result;
    }

    public function retryFailed(?int $limit = null, ?int $delay = null): array
    {
        $result = [
            'found' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $query = (new Query())
            ->from(self::TABLE)
            ->where(['status' => self::STATUS_FAILED])
            ->orderBy(['dateUpdated' => SORT_ASC]);

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->all() as $record) {
            $result['found']++;

            $asset = $this->getAsset((int)$record['assetId']);

            if (!$asset instanceof Asset || !$this->isGifAsset($asset)) {
                $this->markFailed((int)$record['id'], self::STATUS_MISSING, 'Source GIF asset is missing or is no longer a GIF.');
                $result['skipped']++;
                continue;
            }

            try {
                $queueResult = $this->queueAsset($asset, true, $delay);
                $queueResult['queued'] ? $result['queued']++ : $result['skipped']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                Craft::error(sprintf('GIF media retry failed for asset %s: %s', $asset->id, $e->getMessage()), __METHOD__);
            }
        }

        return $result;
    }

    public function statusSummary(?string $status = null, int $limit = 20): array
    {
        $countQuery = (new Query())
            ->select(['status', 'count' => new Expression('COUNT(*)')])
            ->from(self::TABLE)
            ->groupBy(['status'])
            ->orderBy(['status' => SORT_ASC]);

        $recordsQuery = (new Query())
            ->from(self::TABLE)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit);

        if ($status !== null && $status !== '') {
            $countQuery->where(['status' => $status]);
            $recordsQuery->where(['status' => $status]);
        }

        return [
            'counts' => $countQuery->all(),
            'records' => $recordsQuery->all(),
        ];
    }

    public function getRecordById(int $id): ?array
    {
        $record = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();

        return $record ?: null;
    }

    public function getRecordForAsset(Asset $asset): ?array
    {
        if ($asset->id === null) {
            return null;
        }

        $record = (new Query())
            ->from(self::TABLE)
            ->where([
                'or',
                ['assetId' => $asset->id],
                ['outputAssetId' => $asset->id],
            ])
            ->one();

        return $record ?: null;
    }

    public function replaceAssetReferences(Asset $sourceAsset, Asset $outputAsset, ?int $sourceElementId = null): array
    {
        $summary = $this->emptyReferenceReplacementSummary(true);
        $sourceAssetId = (int)($sourceAsset->id ?? 0);
        $outputAssetId = (int)($outputAsset->id ?? 0);

        if ($sourceAssetId <= 0 || $outputAssetId <= 0 || $sourceAssetId === $outputAssetId) {
            return $summary;
        }

        $db = Craft::$app->getDb();
        $query = (new Query())
            ->select(['id', 'fieldId', 'sourceId', 'sourceSiteId'])
            ->from(Table::RELATIONS)
            ->where(['targetId' => $sourceAssetId]);

        if ($sourceElementId !== null) {
            $query->andWhere(['sourceId' => $sourceElementId]);
        }

        $relations = $query->all($db);

        $summary['relations'] = count($relations);

        if (empty($relations)) {
            return $summary;
        }

        $transaction = $db->beginTransaction();

        try {
            foreach ($relations as $relation) {
                $condition = [
                    'fieldId' => (int)$relation['fieldId'],
                    'sourceId' => (int)$relation['sourceId'],
                    'sourceSiteId' => $relation['sourceSiteId'] !== null ? (int)$relation['sourceSiteId'] : null,
                    'targetId' => $outputAssetId,
                ];
                $alreadyRelated = (new Query())
                    ->from(Table::RELATIONS)
                    ->where($condition)
                    ->exists($db);

                if ($alreadyRelated) {
                    $db->createCommand()
                        ->delete(Table::RELATIONS, ['id' => (int)$relation['id']])
                        ->execute();
                    $summary['deletedDuplicates']++;
                    continue;
                }

                $db->createCommand()
                    ->update(Table::RELATIONS, [
                        'targetId' => $outputAssetId,
                        'dateUpdated' => $this->now(),
                    ], ['id' => (int)$relation['id']])
                    ->execute();
                $summary['updated']++;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $summary['replaced'] = $summary['updated'] + $summary['deletedDuplicates'];

        if ($summary['replaced'] > 0) {
            Craft::info(
                sprintf(
                    'Replaced %s GIF asset relation(s) from asset %s to WebP asset %s.',
                    $summary['replaced'],
                    $sourceAssetId,
                    $outputAssetId
                ),
                __METHOD__
            );
        }

        return $summary;
    }

    public function replaceReadyGifReferencesForSourceElement(int $sourceElementId): array
    {
        $enabled = (bool)$this->settings()->replaceAssetReferences && (bool)$this->settings()->convertToWebp;
        $summary = $this->emptyReferenceReplacementSummary($enabled);
        $summary['sourceElementId'] = $sourceElementId;
        $summary['gifAssets'] = 0;

        if (!$enabled || $sourceElementId <= 0) {
            return $summary;
        }

        $targetIds = (new Query())
            ->select(['targetId'])
            ->distinct()
            ->from(Table::RELATIONS)
            ->where(['sourceId' => $sourceElementId])
            ->column(Craft::$app->getDb());

        foreach ($targetIds as $targetId) {
            $sourceAsset = $this->getAsset((int)$targetId);

            if (!$sourceAsset instanceof Asset || !$this->isGifAsset($sourceAsset)) {
                continue;
            }

            $outputAsset = $this->getWebpAsset($sourceAsset);

            if (!$outputAsset instanceof Asset || (int)$outputAsset->id === (int)$sourceAsset->id) {
                continue;
            }

            $replacement = $this->replaceAssetReferences($sourceAsset, $outputAsset, $sourceElementId);
            $summary['gifAssets']++;
            $summary['relations'] += $replacement['relations'];
            $summary['updated'] += $replacement['updated'];
            $summary['deletedDuplicates'] += $replacement['deletedDuplicates'];
            $summary['replaced'] += $replacement['replaced'];
        }

        return $summary;
    }

    private function replaceAssetReferencesIfEnabled(Asset $sourceAsset, ?Asset $outputAsset): array
    {
        $enabled = (bool)$this->settings()->replaceAssetReferences;
        $summary = $this->emptyReferenceReplacementSummary($enabled);

        if (!$enabled || !$outputAsset instanceof Asset) {
            return $summary;
        }

        return $this->replaceAssetReferences($sourceAsset, $outputAsset);
    }

    private function emptyReferenceReplacementSummary(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'relations' => 0,
            'updated' => 0,
            'deletedDuplicates' => 0,
            'replaced' => 0,
        ];
    }

    private function conversionMessage(string $message, array $replacement): string
    {
        $replaced = (int)($replacement['replaced'] ?? 0);

        if ($replaced <= 0) {
            return $message;
        }

        return sprintf(
            '%s Replaced %s GIF asset reference%s with the generated WebP.',
            $message,
            $replaced,
            $replaced === 1 ? '' : 's'
        );
    }

    private function gifAssetQuery(?int $limit = null, ?int $volumeId = null)
    {
        $query = Asset::find()
            ->kind('image')
            ->filename(['*.gif', '*.GIF'])
            ->status(null)
            ->unique()
            ->orderBy(['dateUpdated' => SORT_DESC]);

        if ($volumeId !== null && $volumeId > 0) {
            $query->volumeId($volumeId);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    private function prepareRecord(Asset $asset, bool $force = false): array
    {
        $record = $this->getRecordByAssetId((int)$asset->id);
        $signature = $this->sourceSignature($asset);
        $sourceChanged = $record === null || ($record['sourceSignature'] ?? null) !== $signature;

        $values = [
            'assetId' => $asset->id,
            'volumeId' => $asset->volumeId,
            'folderId' => $asset->folderId,
            'sourcePath' => $asset->getPath(),
            'sourceSize' => $asset->size,
            'sourceMtime' => $this->dateForDb($asset->dateModified ?? null),
            'sourceSignature' => $signature,
            'outputFilename' => $this->targetFilename($asset),
        ];

        if ($record === null) {
            $now = $this->now();
            $values['status'] = self::STATUS_PENDING;
            $values['dateCreated'] = $now;
            $values['dateUpdated'] = $now;

            try {
                Craft::$app->getDb()->createCommand()
                    ->insert(self::TABLE, $values)
                    ->execute();
            } catch (IntegrityException $e) {
                $record = $this->getRecordByAssetId((int)$asset->id);

                if ($record === null) {
                    throw $e;
                }
            }
        } elseif ($force || $sourceChanged) {
            if (!$this->hasActiveJob($record) || $force) {
                $values['status'] = self::STATUS_PENDING;
                $values['lastError'] = null;
                $values['verifiedAt'] = null;

                if ($sourceChanged) {
                    $values['outputAssetId'] = null;
                    $values['outputPath'] = null;
                    $values['completedAt'] = null;
                }
            }

            $this->updateRecord((int)$record['id'], $values);
        } else {
            $this->updateRecord((int)$record['id'], $values);
        }

        return $this->getRecordByAssetId((int)$asset->id);
    }

    private function getRecordByAssetId(int $assetId): ?array
    {
        $record = (new Query())
            ->from(self::TABLE)
            ->where(['assetId' => $assetId])
            ->one();

        return $record ?: null;
    }

    private function getRecordByOutputAssetId(int $assetId): ?array
    {
        $record = (new Query())
            ->from(self::TABLE)
            ->where(['outputAssetId' => $assetId])
            ->one();

        return $record ?: null;
    }

    private function updateRecord(int $id, array $values): void
    {
        $values['dateUpdated'] = $this->now();

        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, $values, ['id' => $id])
            ->execute();
    }

    private function markFailed(int $recordId, string $status, string $message): void
    {
        $this->updateRecord($recordId, [
            'status' => $status,
            'lastError' => $message,
        ]);
    }

    private function hasActiveJob(array $record): bool
    {
        return in_array($record['status'] ?? null, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    private function hasEnabledOutputTarget(): bool
    {
        return (bool)$this->settings()->convertToWebp;
    }

    private function skippedRecordShouldStaySkipped(array $record): bool
    {
        $settings = $this->settings();

        if ($settings->convertToWebp && !$settings->skipLargerWebp) {
            return false;
        }

        return true;
    }

    private function hasFreshOutput(array $record, Asset $asset): bool
    {
        if (($record['sourceSignature'] ?? null) !== $this->sourceSignature($asset)) {
            return false;
        }

        $settings = $this->settings();
        $updates = [
            'status' => $this->freshOutputStatus($record),
            'completedAt' => $record['completedAt'] ?? $this->now(),
            'lastError' => null,
        ];

        if ($settings->convertToWebp) {
            $output = $this->freshWebpOutput($record, $asset);

            if (!$output instanceof Asset) {
                return false;
            }

            $updates['outputAssetId'] = $output->id;
            $updates['outputPath'] = $output->getPath();
            $updates['outputFilename'] = $output->getFilename();
        }

        $this->updateRecord((int)$record['id'], $updates);

        return true;
    }

    private function freshWebpOutput(array $record, Asset $asset): ?Asset
    {
        $output = null;

        if (!empty($record['outputAssetId'])) {
            $output = $this->getAsset((int)$record['outputAssetId']);
        }

        if (!$output instanceof Asset) {
            $output = $this->findOutputAssetForSource($asset);
        }

        if (!$output instanceof Asset || strtolower($output->getExtension()) !== 'webp') {
            return null;
        }

        if (!$this->outputIsAtLeastAsNewAsSource($output, $asset)) {
            return null;
        }

        $sizeCheck = $this->outputAssetIsWorthUsing($asset, $output, 'webp');

        if (!$sizeCheck['keep']) {
            $this->updateRecord((int)$record['id'], [
                'status' => self::STATUS_SKIPPED,
                'lastError' => $this->sizeSkipMessage($sizeCheck),
            ]);

            return null;
        }

        return $output;
    }

    private function freshOutputStatus(array $record): string
    {
        return ($record['status'] ?? null) === self::STATUS_VERIFIED ? self::STATUS_VERIFIED : self::STATUS_COMPLETED;
    }

    private function findOutputAssetForSource(Asset $sourceAsset): ?Asset
    {
        if ($sourceAsset->folderId === null || $sourceAsset->volumeId === null) {
            return null;
        }

        $asset = Asset::find()
            ->volumeId($sourceAsset->volumeId)
            ->folderId($sourceAsset->folderId)
            ->filename($this->targetFilename($sourceAsset))
            ->status(null)
            ->one();

        return $asset instanceof Asset ? $asset : null;
    }

    private function outputIsAtLeastAsNewAsSource(Asset $outputAsset, Asset $sourceAsset): bool
    {
        $outputModified = $outputAsset->dateModified ?? null;
        $sourceModified = $sourceAsset->dateModified ?? null;

        if (!$outputModified instanceof \DateTimeInterface || !$sourceModified instanceof \DateTimeInterface) {
            return true;
        }

        return $outputModified->getTimestamp() >= $sourceModified->getTimestamp();
    }

    private function pushJob(ConvertGifJob $job, int $delay)
    {
        $queue = Craft::$app->getQueue();

        if ($delay > 0 && method_exists($queue, 'delay')) {
            return $queue->delay($delay)->push($job);
        }

        return $queue->push($job);
    }

    private function getAsset(int $assetId): ?Asset
    {
        $asset = Asset::find()
            ->id($assetId)
            ->status(null)
            ->one();

        return $asset instanceof Asset ? $asset : null;
    }

    private function saveOutputAsset(Asset $sourceAsset, string $outputPath, string $filename): Asset
    {
        $asset = new Asset();
        $asset->tempFilePath = $outputPath;
        $asset->filename = $filename;
        $asset->newFolderId = $sourceAsset->folderId;
        $asset->volumeId = $sourceAsset->volumeId;
        $asset->avoidFilenameConflicts = true;

        $this->savingGeneratedAsset = true;

        try {
            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new \RuntimeException(implode(' ', $asset->getErrorSummary(true)));
            }
        } finally {
            $this->savingGeneratedAsset = false;
        }

        return $asset;
    }

    private function runGif2Webp(string $sourcePath, string $targetPath): array
    {
        $settings = $this->settings();
        $binary = App::parseEnv($settings->gif2webpPath) ?: 'gif2webp';
        $compressionMode = in_array($settings->compressionMode, ['lossless', 'lossy', 'mixed'], true) ? $settings->compressionMode : 'lossy';
        $command = [
            $binary,
        ];

        if ($compressionMode === 'lossy') {
            $command[] = '-lossy';
        } elseif ($compressionMode === 'mixed') {
            $command[] = '-mixed';
        }

        $command[] = '-q';
        $command[] = (string)$settings->quality;
        $command[] = '-m';
        $command[] = (string)$settings->method;

        if ($settings->minimizeOutputSize) {
            $command[] = '-min_size';
        }

        if ($settings->multiThreaded) {
            $command[] = '-mt';
        }

        $command[] = $sourcePath;
        $command[] = '-o';
        $command[] = $targetPath;

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start gif2webp.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'output' => trim((string)$stdout . "\n" . (string)$stderr),
        ];
    }

    private function generatedOutputIsWorthSaving(string $sourcePath, string $targetPath, string $format): array
    {
        return $this->outputSizeDecision(
            is_file($sourcePath) ? (int)filesize($sourcePath) : 0,
            is_file($targetPath) ? (int)filesize($targetPath) : 0,
            $format
        );
    }

    private function outputAssetIsWorthUsing(Asset $sourceAsset, Asset $outputAsset, string $format): array
    {
        return $this->outputSizeDecision(
            $this->assetByteSize($sourceAsset),
            $this->assetByteSize($outputAsset),
            $format
        );
    }

    private function outputSizeDecision(int $sourceSize, int $outputSize, string $format): array
    {
        $settings = $this->settings();
        $minimumSavingsPercent = max(0, min(100, (int)$settings->minimumSavingsPercent));
        $requiredMaxSize = $minimumSavingsPercent > 0
            ? (int)floor($sourceSize * (100 - $minimumSavingsPercent) / 100)
            : $sourceSize - 1;
        $savingsBytes = $sourceSize - $outputSize;
        $savingsPercent = $sourceSize > 0 ? ($savingsBytes / $sourceSize) * 100 : 0.0;

        return [
            'keep' => !$settings->skipLargerWebp || $sourceSize <= 0 || $outputSize <= 0 || $outputSize <= $requiredMaxSize,
            'format' => $format,
            'sourceSize' => $sourceSize,
            'outputSize' => $outputSize,
            'requiredMaxSize' => $requiredMaxSize,
            'minimumSavingsPercent' => $minimumSavingsPercent,
            'savingsBytes' => $savingsBytes,
            'savingsPercent' => $savingsPercent,
        ];
    }

    private function sizeSkipMessage(array $sizeCheck): string
    {
        $source = $this->formattedBytes((int)($sizeCheck['sourceSize'] ?? 0));
        $output = $this->formattedBytes((int)($sizeCheck['outputSize'] ?? 0));
        $minimumSavingsPercent = (int)($sizeCheck['minimumSavingsPercent'] ?? 0);
        $format = strtoupper((string)($sizeCheck['format'] ?? 'webp'));

        if ($minimumSavingsPercent > 0) {
            return sprintf(
                'Generated %s was not saved because it did not meet the %s%% minimum savings requirement (GIF: %s, %s: %s, savings: %.1f%%).',
                $format,
                $minimumSavingsPercent,
                $source,
                $format,
                $output,
                (float)($sizeCheck['savingsPercent'] ?? 0)
            );
        }

        return sprintf(
            'Generated %s was not saved because it is not smaller than the source GIF (GIF: %s, %s: %s).',
            $format,
            $source,
            $format,
            $output
        );
    }

    private function assetByteSize(Asset $asset): int
    {
        $size = (int)($asset->size ?? 0);

        if ($size > 0) {
            return $size;
        }

        try {
            $path = $asset->getPath();
        } catch (\Throwable $e) {
            return 0;
        }

        return is_string($path) && is_file($path) ? (int)filesize($path) : 0;
    }

    private function formattedBytes(int $bytes): string
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

    private function targetFilename(Asset $asset): string
    {
        return $asset->getFilename(false) . '.webp';
    }

    private function tempWebpPath(Asset $asset): string
    {
        $directory = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'storage-optimizer';
        FileHelper::createDirectory($directory);

        return $directory . DIRECTORY_SEPARATOR . sprintf(
            '%s-%s.webp',
            $asset->id,
            bin2hex(random_bytes(8))
        );
    }

    private function removeTempFile(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            FileHelper::unlink($path);
        }
    }

    private function inspectAssetAnimation(Asset $asset, string $type, callable $inspector): bool
    {
        $key = $type . ':' . $this->assetCacheKey($asset);

        if (array_key_exists($key, $this->animationCache)) {
            return $this->animationCache[$key];
        }

        $path = null;

        try {
            $path = $asset->getCopyOfFile();
            $this->animationCache[$key] = $path !== null && $inspector($path);
        } catch (\Throwable $e) {
            Craft::warning(
                sprintf('Could not inspect animation state for asset %s: %s', $asset->id, $e->getMessage()),
                __METHOD__
            );

            $this->animationCache[$key] = false;
        } finally {
            $this->removeTempFile($path);
        }

        return $this->animationCache[$key];
    }

    private function webpFileHasAnimation(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 12);

            if (!is_string($header) || strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
                return false;
            }

            while (!feof($handle)) {
                $chunkHeader = fread($handle, 8);

                if (!is_string($chunkHeader) || strlen($chunkHeader) < 8) {
                    return false;
                }

                $chunkType = substr($chunkHeader, 0, 4);
                $chunkSize = unpack('Vsize', substr($chunkHeader, 4, 4))['size'];

                if ($chunkType === 'ANIM' || $chunkType === 'ANMF') {
                    return true;
                }

                if ($chunkType === 'VP8X') {
                    $flags = fread($handle, 1);

                    if (is_string($flags) && strlen($flags) === 1 && (ord($flags) & 0x02) === 0x02) {
                        return true;
                    }

                    $remaining = max(0, $chunkSize - 1);
                    if ($remaining > 0) {
                        fseek($handle, $remaining, SEEK_CUR);
                    }
                } else {
                    fseek($handle, $chunkSize, SEEK_CUR);
                }

                if ($chunkSize % 2 === 1) {
                    fseek($handle, 1, SEEK_CUR);
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    private function gifFileHasAnimation(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 13);

            if (!is_string($header) || strlen($header) !== 13 || !in_array(substr($header, 0, 6), ['GIF87a', 'GIF89a'], true)) {
                return false;
            }

            $packed = ord($header[10]);

            if (($packed & 0x80) === 0x80) {
                $globalColorTableSize = 3 * (2 ** (($packed & 0x07) + 1));
                fseek($handle, $globalColorTableSize, SEEK_CUR);
            }

            $frames = 0;

            while (!feof($handle)) {
                $block = fread($handle, 1);

                if (!is_string($block) || $block === '') {
                    return false;
                }

                $blockType = ord($block);

                if ($blockType === 0x2C) {
                    $frames++;

                    if ($frames > 1) {
                        return true;
                    }

                    $descriptor = fread($handle, 9);

                    if (!is_string($descriptor) || strlen($descriptor) !== 9) {
                        return false;
                    }

                    $imagePacked = ord($descriptor[8]);

                    if (($imagePacked & 0x80) === 0x80) {
                        $localColorTableSize = 3 * (2 ** (($imagePacked & 0x07) + 1));
                        fseek($handle, $localColorTableSize, SEEK_CUR);
                    }

                    fseek($handle, 1, SEEK_CUR);

                    if (!$this->skipGifSubBlocks($handle)) {
                        return false;
                    }

                    continue;
                }

                if ($blockType === 0x21) {
                    fseek($handle, 1, SEEK_CUR);

                    if (!$this->skipGifSubBlocks($handle)) {
                        return false;
                    }

                    continue;
                }

                if ($blockType === 0x3B) {
                    return false;
                }

                return false;
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    /**
     * @param resource $handle
     */
    private function skipGifSubBlocks($handle): bool
    {
        while (!feof($handle)) {
            $sizeByte = fread($handle, 1);

            if (!is_string($sizeByte) || $sizeByte === '') {
                return false;
            }

            $size = ord($sizeByte);

            if ($size === 0) {
                return true;
            }

            fseek($handle, $size, SEEK_CUR);
        }

        return false;
    }

    private function assetCacheKey(Asset $asset): string
    {
        $modified = $asset->dateModified ?? null;

        if ($modified instanceof \DateTimeInterface) {
            $modified = $modified->getTimestamp();
        }

        return implode(':', [
            $asset->id ?? 'new',
            $asset->size ?? 0,
            (string)$modified,
        ]);
    }

    private function sourceSignature(Asset $asset): string
    {
        $modified = $asset->dateModified ?? null;

        if ($modified instanceof \DateTimeInterface) {
            $modified = $modified->getTimestamp();
        }

        return hash('sha256', implode('|', [
            $asset->id,
            $asset->volumeId,
            $asset->folderId,
            $asset->getPath(),
            $asset->size,
            (string)$modified,
        ]));
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function dateForDb($date): ?string
    {
        if (!$date instanceof \DateTimeInterface) {
            return null;
        }

        $copy = (clone $date)->setTimezone(new \DateTimeZone('UTC'));

        return $copy->format('Y-m-d H:i:s');
    }

    private function settings(): Settings
    {
        return StorageOptimizer::getInstance()->getSettings();
    }
}
