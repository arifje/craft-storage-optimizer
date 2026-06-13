<?php

namespace arifje\craftstorageoptimizer\migrations;

use arifje\craftstorageoptimizer\services\Conversions;
use craft\db\Migration;

class m260613_020000_remove_mp4_conversion_output extends Migration
{
    private const INDEX_MP4_ASSET = 'idx_storage_optimizer_gif_conversions_mp4AssetId';
    private const FK_MP4_ASSET = 'fk_storage_optimizer_gif_conversions_mp4AssetId';

    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema(Conversions::TABLE, true) === null) {
            return true;
        }

        $this->dropForeignKeysForColumn('mp4AssetId');
        $this->dropNamedIndexIfExists(self::INDEX_MP4_ASSET);

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

        $this->db->schema->refresh();

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }

    private function dropForeignKeysForColumn(string $column): void
    {
        $this->dropNamedForeignKeyIfExists(self::FK_MP4_ASSET);
        $table = $this->db->schema->getTableSchema(Conversions::TABLE, true);

        foreach (($table->foreignKeys ?? []) as $name => $foreignKey) {
            if (!is_string($name) || !is_array($foreignKey) || !array_key_exists($column, $foreignKey)) {
                continue;
            }

            $this->dropNamedForeignKeyIfExists($name);
        }
    }

    private function dropNamedForeignKeyIfExists(string $name): void
    {
        try {
            $this->dropForeignKey($name, Conversions::TABLE);
        } catch (\Throwable $e) {
        }
    }

    private function dropNamedIndexIfExists(string $name): void
    {
        try {
            $this->dropIndex($name, Conversions::TABLE);
        } catch (\Throwable $e) {
        }
    }

    private function columnExists(string $column): bool
    {
        $table = $this->db->schema->getTableSchema(Conversions::TABLE, true);

        return $table !== null && isset($table->columns[$column]);
    }
}
