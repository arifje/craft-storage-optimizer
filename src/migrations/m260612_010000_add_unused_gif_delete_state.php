<?php

namespace arifje\craftstorageoptimizer\migrations;

use arifje\craftstorageoptimizer\services\Insights;
use craft\db\Migration;

class m260612_010000_add_unused_gif_delete_state extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema(Insights::RUN_TABLE, true) === null) {
            return true;
        }

        $this->addColumnIfMissing('deleteStatus', $this->string(16));
        $this->addColumnIfMissing('deleteBatchSize', $this->integer()->notNull()->defaultValue(Insights::DEFAULT_DELETE_BATCH_SIZE));
        $this->addColumnIfMissing('deleteLastAssetId', $this->integer()->notNull()->defaultValue(0));
        $this->addColumnIfMissing('deleteJobId', $this->string(255));
        $this->addColumnIfMissing('deleteAttemptedAssets', $this->integer()->notNull()->defaultValue(0));
        $this->addColumnIfMissing('deleteDeletedAssets', $this->integer()->notNull()->defaultValue(0));
        $this->addColumnIfMissing('deleteSkippedAssets', $this->integer()->notNull()->defaultValue(0));
        $this->addColumnIfMissing('deleteFailedAssets', $this->integer()->notNull()->defaultValue(0));
        $this->addColumnIfMissing('deleteFreedBytes', $this->bigInteger()->notNull()->defaultValue(0));
        $this->addColumnIfMissing('deleteHardDelete', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumnIfMissing('deleteLastError', $this->text());
        $this->addColumnIfMissing('deleteStartedAt', $this->dateTime());
        $this->addColumnIfMissing('deleteCompletedAt', $this->dateTime());

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->schema->getTableSchema(Insights::RUN_TABLE, true) === null) {
            return true;
        }

        foreach ([
            'deleteCompletedAt',
            'deleteStartedAt',
            'deleteLastError',
            'deleteHardDelete',
            'deleteFreedBytes',
            'deleteFailedAssets',
            'deleteSkippedAssets',
            'deleteDeletedAssets',
            'deleteAttemptedAssets',
            'deleteJobId',
            'deleteLastAssetId',
            'deleteBatchSize',
            'deleteStatus',
        ] as $column) {
            if ($this->columnExists($column)) {
                $this->dropColumn(Insights::RUN_TABLE, $column);
            }
        }

        return true;
    }

    private function addColumnIfMissing(string $column, $type): void
    {
        if (!$this->columnExists($column)) {
            $this->addColumn(Insights::RUN_TABLE, $column, $type);
        }
    }

    private function columnExists(string $column): bool
    {
        $table = $this->db->schema->getTableSchema(Insights::RUN_TABLE, true);

        return $table !== null && isset($table->columns[$column]);
    }
}
