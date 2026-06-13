<?php

namespace arifje\craftstorageoptimizer\migrations;

use arifje\craftstorageoptimizer\services\Conversions;
use craft\db\Migration;

class m260613_010000_add_mp4_conversion_output extends Migration
{
    private const INDEX_MP4_ASSET = 'idx_storage_optimizer_gif_conversions_mp4AssetId';
    private const FK_MP4_ASSET = 'fk_storage_optimizer_gif_conversions_mp4AssetId';

    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema(Conversions::TABLE, true) === null) {
            return true;
        }

        $this->addColumnIfMissing('mp4AssetId', $this->integer());
        $this->addColumnIfMissing('mp4Path', $this->string(1024));
        $this->addColumnIfMissing('mp4Filename', $this->string(255));
        $this->addColumnIfMissing('mp4Status', $this->string(16));
        $this->addColumnIfMissing('mp4LastError', $this->text());

        try {
            $this->createIndex(self::INDEX_MP4_ASSET, Conversions::TABLE, ['mp4AssetId']);
        } catch (\Throwable $e) {
        }

        try {
            $this->addForeignKey(self::FK_MP4_ASSET, Conversions::TABLE, ['mp4AssetId'], '{{%elements}}', ['id'], 'SET NULL');
        } catch (\Throwable $e) {
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->schema->getTableSchema(Conversions::TABLE, true) === null) {
            return true;
        }

        try {
            $this->dropForeignKey(self::FK_MP4_ASSET, Conversions::TABLE);
        } catch (\Throwable $e) {
        }

        try {
            $this->dropIndex(self::INDEX_MP4_ASSET, Conversions::TABLE);
        } catch (\Throwable $e) {
        }

        foreach ([
            'mp4LastError',
            'mp4Status',
            'mp4Filename',
            'mp4Path',
            'mp4AssetId',
        ] as $column) {
            if ($this->columnExists($column)) {
                $this->dropColumn(Conversions::TABLE, $column);
            }
        }

        return true;
    }

    private function addColumnIfMissing(string $column, $type): void
    {
        if (!$this->columnExists($column)) {
            $this->addColumn(Conversions::TABLE, $column, $type);
        }
    }

    private function columnExists(string $column): bool
    {
        $table = $this->db->schema->getTableSchema(Conversions::TABLE, true);

        return $table !== null && isset($table->columns[$column]);
    }

}
