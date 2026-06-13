<?php

namespace arifje\craftstorageoptimizer\migrations;

use arifje\craftstorageoptimizer\services\Insights;
use craft\db\Migration;

class m260612_000000_add_gif_usage_insights extends Migration
{
    public function safeUp(): bool
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

        return true;
    }
}
