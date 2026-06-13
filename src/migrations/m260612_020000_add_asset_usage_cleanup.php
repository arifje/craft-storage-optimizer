<?php

namespace arifje\craftstorageoptimizer\migrations;

use arifje\craftstorageoptimizer\services\AssetUsage;
use craft\db\Migration;

class m260612_020000_add_asset_usage_cleanup extends Migration
{
    public function safeUp(): bool
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

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->schema->getTableSchema(AssetUsage::ASSET_TABLE, true) !== null) {
            $this->dropTable(AssetUsage::ASSET_TABLE);
        }

        if ($this->db->schema->getTableSchema(AssetUsage::RUN_TABLE, true) !== null) {
            $this->dropTable(AssetUsage::RUN_TABLE);
        }

        return true;
    }
}
