<?php

namespace arifje\craftstorageoptimizer\migrations;

use arifje\craftstorageoptimizer\services\AssetUsage;
use arifje\craftstorageoptimizer\services\Conversions;
use arifje\craftstorageoptimizer\services\Insights;
use craft\db\Migration;

class m260613_000000_rename_tables_for_storage_optimizer extends Migration
{
    public function safeUp(): bool
    {
        $this->renameTables($this->legacyTableMap());

        return true;
    }

    public function safeDown(): bool
    {
        $this->renameTables(array_flip($this->legacyTableMap()));

        return true;
    }

    private function renameTables(array $tableMap): void
    {
        foreach ($tableMap as $fromTable => $toTable) {
            if (
                $this->db->schema->getTableSchema($fromTable, true) !== null
                && $this->db->schema->getTableSchema($toTable, true) === null
            ) {
                $this->renameTable($fromTable, $toTable);
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
