<?php

namespace arifje\craftstorageoptimizer\migrations;

use arifje\craftstorageoptimizer\services\AssetUsage;
use arifje\craftstorageoptimizer\services\Conversions;
use arifje\craftstorageoptimizer\services\Insights;
use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->renameLegacyTables();

        if ($this->db->schema->getTableSchema(Conversions::TABLE, true) === null) {
            $this->createTable(Conversions::TABLE, [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->notNull(),
                'volumeId' => $this->integer(),
                'folderId' => $this->integer(),
                'sourcePath' => $this->string(1024),
                'sourceSize' => $this->bigInteger(),
                'sourceMtime' => $this->dateTime(),
                'sourceSignature' => $this->string(64),
                'outputAssetId' => $this->integer(),
                'outputPath' => $this->string(1024),
                'outputFilename' => $this->string(255),
                'status' => $this->string(16)->notNull()->defaultValue(Conversions::STATUS_PENDING),
                'attempts' => $this->integer()->notNull()->defaultValue(0),
                'lastJobId' => $this->string(255),
                'lastError' => $this->text(),
                'queuedAt' => $this->dateTime(),
                'startedAt' => $this->dateTime(),
                'completedAt' => $this->dateTime(),
                'verifiedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, Conversions::TABLE, ['assetId'], true);
            $this->createIndex(null, Conversions::TABLE, ['status']);
            $this->createIndex(null, Conversions::TABLE, ['outputAssetId']);

            $this->addForeignKey(null, Conversions::TABLE, ['assetId'], '{{%elements}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, Conversions::TABLE, ['outputAssetId'], '{{%elements}}', ['id'], 'SET NULL');
        }

        $this->createInsightsTables();
        $this->createAssetUsageTables();

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->schema->getTableSchema(Insights::ASSET_TABLE, true) !== null) {
            $this->dropTable(Insights::ASSET_TABLE);
        }

        if ($this->db->schema->getTableSchema(Insights::RUN_TABLE, true) !== null) {
            $this->dropTable(Insights::RUN_TABLE);
        }

        if ($this->db->schema->getTableSchema(AssetUsage::ASSET_TABLE, true) !== null) {
            $this->dropTable(AssetUsage::ASSET_TABLE);
        }

        if ($this->db->schema->getTableSchema(AssetUsage::RUN_TABLE, true) !== null) {
            $this->dropTable(AssetUsage::RUN_TABLE);
        }

        if ($this->db->schema->getTableSchema(Conversions::TABLE, true) === null) {
            return true;
        }

        $this->dropTable(Conversions::TABLE);

        Craft::$app->getDb()->schema->refresh();

        return true;
    }

    private function createInsightsTables(): void
    {
        if ($this->db->schema->getTableSchema(Insights::RUN_TABLE, true) === null) {
            $this->createTable(Insights::RUN_TABLE, [
                'id' => $this->primaryKey(),
                'status' => $this->string(16)->notNull()->defaultValue(Insights::STATUS_QUEUED),
                'batchSize' => $this->integer()->notNull()->defaultValue(Insights::DEFAULT_BATCH_SIZE),
                'lastAssetId' => $this->integer()->notNull()->defaultValue(0),
                'processedAssets' => $this->integer()->notNull()->defaultValue(0),
                'gifAssets' => $this->integer()->notNull()->defaultValue(0),
                'usedAssets' => $this->integer()->notNull()->defaultValue(0),
                'unusedAssets' => $this->integer()->notNull()->defaultValue(0),
                'totalBytes' => $this->bigInteger()->notNull()->defaultValue(0),
                'relationCount' => $this->integer()->notNull()->defaultValue(0),
                'directRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'matrixRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'sourceElementCount' => $this->integer()->notNull()->defaultValue(0),
                'ownerElementCount' => $this->integer()->notNull()->defaultValue(0),
                'largestAssetId' => $this->integer(),
                'largestBytes' => $this->bigInteger()->notNull()->defaultValue(0),
                'lastJobId' => $this->string(255),
                'lastError' => $this->text(),
                'deleteStatus' => $this->string(16),
                'deleteBatchSize' => $this->integer()->notNull()->defaultValue(Insights::DEFAULT_DELETE_BATCH_SIZE),
                'deleteLastAssetId' => $this->integer()->notNull()->defaultValue(0),
                'deleteJobId' => $this->string(255),
                'deleteAttemptedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteDeletedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteSkippedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteFailedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteFreedBytes' => $this->bigInteger()->notNull()->defaultValue(0),
                'deleteHardDelete' => $this->boolean()->notNull()->defaultValue(false),
                'deleteLastError' => $this->text(),
                'deleteStartedAt' => $this->dateTime(),
                'deleteCompletedAt' => $this->dateTime(),
                'startedAt' => $this->dateTime(),
                'completedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, Insights::RUN_TABLE, ['status']);
            $this->createIndex(null, Insights::RUN_TABLE, ['dateCreated']);
        }

        if ($this->db->schema->getTableSchema(Insights::ASSET_TABLE, true) === null) {
            $this->createTable(Insights::ASSET_TABLE, [
                'id' => $this->primaryKey(),
                'runId' => $this->integer()->notNull(),
                'assetId' => $this->integer()->notNull(),
                'volumeId' => $this->integer(),
                'folderId' => $this->integer(),
                'filename' => $this->string(255)->notNull(),
                'size' => $this->bigInteger()->notNull()->defaultValue(0),
                'width' => $this->integer(),
                'height' => $this->integer(),
                'relationCount' => $this->integer()->notNull()->defaultValue(0),
                'directRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'matrixRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'sourceElementCount' => $this->integer()->notNull()->defaultValue(0),
                'ownerElementCount' => $this->integer()->notNull()->defaultValue(0),
                'conversionStatus' => $this->string(16),
                'outputAssetId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, Insights::ASSET_TABLE, ['runId', 'assetId'], true);
            $this->createIndex(null, Insights::ASSET_TABLE, ['runId', 'size']);
            $this->createIndex(null, Insights::ASSET_TABLE, ['runId', 'relationCount']);
            $this->createIndex(null, Insights::ASSET_TABLE, ['assetId']);
            $this->addForeignKey(null, Insights::ASSET_TABLE, ['runId'], Insights::RUN_TABLE, ['id'], 'CASCADE');
            $this->addForeignKey(null, Insights::ASSET_TABLE, ['assetId'], '{{%elements}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, Insights::ASSET_TABLE, ['outputAssetId'], '{{%elements}}', ['id'], 'SET NULL');
        }
    }

    private function createAssetUsageTables(): void
    {
        if ($this->db->schema->getTableSchema(AssetUsage::RUN_TABLE, true) === null) {
            $this->createTable(AssetUsage::RUN_TABLE, [
                'id' => $this->primaryKey(),
                'status' => $this->string(16)->notNull()->defaultValue(AssetUsage::STATUS_QUEUED),
                'batchSize' => $this->integer()->notNull()->defaultValue(AssetUsage::DEFAULT_BATCH_SIZE),
                'volumeId' => $this->integer(),
                'lastAssetId' => $this->integer()->notNull()->defaultValue(0),
                'processedAssets' => $this->integer()->notNull()->defaultValue(0),
                'assetCount' => $this->integer()->notNull()->defaultValue(0),
                'relatedAssets' => $this->integer()->notNull()->defaultValue(0),
                'ghostAssets' => $this->integer()->notNull()->defaultValue(0),
                'protectedAssets' => $this->integer()->notNull()->defaultValue(0),
                'totalBytes' => $this->bigInteger()->notNull()->defaultValue(0),
                'ghostBytes' => $this->bigInteger()->notNull()->defaultValue(0),
                'relationCount' => $this->integer()->notNull()->defaultValue(0),
                'directRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'matrixRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'sourceElementCount' => $this->integer()->notNull()->defaultValue(0),
                'ownerElementCount' => $this->integer()->notNull()->defaultValue(0),
                'largestAssetId' => $this->integer(),
                'largestBytes' => $this->bigInteger()->notNull()->defaultValue(0),
                'lastJobId' => $this->string(255),
                'lastError' => $this->text(),
                'deleteStatus' => $this->string(16),
                'deleteBatchSize' => $this->integer()->notNull()->defaultValue(AssetUsage::DEFAULT_DELETE_BATCH_SIZE),
                'deleteLastAssetId' => $this->integer()->notNull()->defaultValue(0),
                'deleteJobId' => $this->string(255),
                'deleteAttemptedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteDeletedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteSkippedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteFailedAssets' => $this->integer()->notNull()->defaultValue(0),
                'deleteDeletedBytes' => $this->bigInteger()->notNull()->defaultValue(0),
                'deleteHardDelete' => $this->boolean()->notNull()->defaultValue(false),
                'deleteLastError' => $this->text(),
                'deleteStartedAt' => $this->dateTime(),
                'deleteCompletedAt' => $this->dateTime(),
                'startedAt' => $this->dateTime(),
                'completedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, AssetUsage::RUN_TABLE, ['status']);
            $this->createIndex(null, AssetUsage::RUN_TABLE, ['dateCreated']);
            $this->createIndex(null, AssetUsage::RUN_TABLE, ['volumeId']);
        }

        if ($this->db->schema->getTableSchema(AssetUsage::ASSET_TABLE, true) === null) {
            $this->createTable(AssetUsage::ASSET_TABLE, [
                'id' => $this->primaryKey(),
                'runId' => $this->integer()->notNull(),
                'assetId' => $this->integer()->notNull(),
                'volumeId' => $this->integer(),
                'folderId' => $this->integer(),
                'filename' => $this->string(255)->notNull(),
                'kind' => $this->string(50),
                'extension' => $this->string(20),
                'size' => $this->bigInteger()->notNull()->defaultValue(0),
                'width' => $this->integer(),
                'height' => $this->integer(),
                'relationCount' => $this->integer()->notNull()->defaultValue(0),
                'directRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'matrixRelationCount' => $this->integer()->notNull()->defaultValue(0),
                'sourceElementCount' => $this->integer()->notNull()->defaultValue(0),
                'ownerElementCount' => $this->integer()->notNull()->defaultValue(0),
                'isProtected' => $this->boolean()->notNull()->defaultValue(false),
                'protectedReason' => $this->string(64),
                'cleanupCandidate' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, AssetUsage::ASSET_TABLE, ['runId', 'assetId'], true);
            $this->createIndex(null, AssetUsage::ASSET_TABLE, ['runId', 'cleanupCandidate', 'size']);
            $this->createIndex(null, AssetUsage::ASSET_TABLE, ['runId', 'relationCount']);
            $this->createIndex(null, AssetUsage::ASSET_TABLE, ['assetId']);
            $this->addForeignKey(null, AssetUsage::ASSET_TABLE, ['runId'], AssetUsage::RUN_TABLE, ['id'], 'CASCADE');
            $this->addForeignKey(null, AssetUsage::ASSET_TABLE, ['assetId'], '{{%elements}}', ['id'], 'CASCADE');
        }
    }

    private function renameLegacyTables(): void
    {
        foreach ($this->legacyTableMap() as $legacyTable => $newTable) {
            if (
                $this->db->schema->getTableSchema($legacyTable, true) !== null
                && $this->db->schema->getTableSchema($newTable, true) === null
            ) {
                $this->renameTable($legacyTable, $newTable);
            }
        }

        $this->db->schema->refresh();
    }

    private function legacyTableMap(): array
    {
        return [
            '{{%gif_to_webp_conversions}}' => Conversions::TABLE,
            '{{%gif_to_webp_insight_runs}}' => Insights::RUN_TABLE,
            '{{%gif_to_webp_insight_assets}}' => Insights::ASSET_TABLE,
            '{{%asset_optimizer_usage_runs}}' => AssetUsage::RUN_TABLE,
            '{{%asset_optimizer_usage_assets}}' => AssetUsage::ASSET_TABLE,
        ];
    }
}
