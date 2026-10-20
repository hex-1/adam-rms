<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class ProjectAssetRequirements extends AbstractMigration
{
    public function change(): void
    {
        // A requirement describes demand for an Asset Type. It intentionally
        // does not reserve a physical asset during the planning stage.
        $this->table('projectAssetRequirements', [
            'id' => false,
            'primary_key' => ['projectAssetRequirements_id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('projectAssetRequirements_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'identity' => true,
            ])
            ->addColumn('projects_id', 'integer', ['null' => false, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addColumn('assetTypes_id', 'integer', ['null' => false, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addColumn('projectAssetRequirements_quantity', 'integer', ['null' => false, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addColumn('projectAssetRequirements_timestamp', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('projectAssetRequirements_deleted', 'boolean', ['null' => false, 'default' => false])
            ->addIndex(['projects_id'], ['name' => 'projectAssetRequirements_projects_projects_id_fk'])
            ->addIndex(['assetTypes_id'], ['name' => 'projectAssetRequirements_assetTypes_assetTypes_id_fk'])
            ->addForeignKey('projects_id', 'projects', 'projects_id', [
                'constraint' => 'projectAssetRequirements_projects_projects_id_fk',
                'update' => 'CASCADE',
                'delete' => 'CASCADE',
            ])
            ->addForeignKey('assetTypes_id', 'assetTypes', 'assetTypes_id', [
                'constraint' => 'projectAssetRequirements_assetTypes_assetTypes_id_fk',
                'update' => 'CASCADE',
                'delete' => 'CASCADE',
            ])
            ->create();
    }
}
