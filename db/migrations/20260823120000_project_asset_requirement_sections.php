<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class ProjectAssetRequirementSections extends AbstractMigration
{
    public function change(): void
    {
        // Sections form a project-scoped outline. The optional category link
        // identifies automatically generated headings without restricting
        // users from creating their own headings with the same display name.
        $this->table('projectAssetRequirementSections', [
            'id' => false,
            'primary_key' => ['projectAssetRequirementSections_id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('projectAssetRequirementSections_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'identity' => true,
            ])
            ->addColumn('projects_id', 'integer', ['null' => false, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addColumn('projectAssetRequirementSections_parent_id', 'integer', ['null' => true, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addColumn('assetCategoriesGroups_id', 'integer', ['null' => true, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addColumn('projectAssetRequirementSections_name', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('projectAssetRequirementSections_rank', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('projectAssetRequirementSections_deleted', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('projectAssetRequirementSections_timestamp', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['projects_id'], ['name' => 'pars_project_idx'])
            ->addIndex(['projectAssetRequirementSections_parent_id'], ['name' => 'pars_parent_idx'])
            ->addIndex(['assetCategoriesGroups_id'], ['name' => 'pars_category_group_idx'])
            ->addForeignKey('projects_id', 'projects', 'projects_id', [
                'constraint' => 'pars_project_fk', 'update' => 'CASCADE', 'delete' => 'CASCADE',
            ])
            ->addForeignKey('projectAssetRequirementSections_parent_id', 'projectAssetRequirementSections', 'projectAssetRequirementSections_id', [
                'constraint' => 'pars_parent_fk', 'update' => 'CASCADE', 'delete' => 'CASCADE',
            ])
            ->addForeignKey('assetCategoriesGroups_id', 'assetCategoriesGroups', 'assetCategoriesGroups_id', [
                'constraint' => 'pars_category_group_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL',
            ])
            ->create();

        // Requirements and physical assignments remain separate records. The
        // nullable links let existing AdamRMS assignments continue to work.
        $this->table('projectAssetRequirements')
            ->addColumn('projectAssetRequirementSections_id', 'integer', ['null' => true, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addColumn('projectAssetRequirements_rank', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['projectAssetRequirementSections_id'], ['name' => 'par_section_idx'])
            ->addForeignKey('projectAssetRequirementSections_id', 'projectAssetRequirementSections', 'projectAssetRequirementSections_id', [
                'constraint' => 'par_section_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL',
            ])
            ->update();

        $this->table('assetsAssignments')
            ->addColumn('projectAssetRequirements_id', 'integer', ['null' => true, 'limit' => MysqlAdapter::INT_REGULAR])
            ->addIndex(['projectAssetRequirements_id'], ['name' => 'aa_requirement_idx'])
            ->addForeignKey('projectAssetRequirements_id', 'projectAssetRequirements', 'projectAssetRequirements_id', [
                'constraint' => 'aa_requirement_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL',
            ])
            ->update();
    }
}
