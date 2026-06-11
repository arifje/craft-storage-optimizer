<?php

namespace arifje\giftowebp\migrations;

use arifje\giftowebp\services\Conversions;
use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema(Conversions::TABLE, true) !== null) {
            return true;
        }

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

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->schema->getTableSchema(Conversions::TABLE, true) === null) {
            return true;
        }

        $this->dropTable(Conversions::TABLE);

        Craft::$app->getDb()->schema->refresh();

        return true;
    }
}
