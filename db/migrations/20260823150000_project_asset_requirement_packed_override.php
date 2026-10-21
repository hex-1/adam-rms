<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class ProjectAssetRequirementPackedOverride extends AbstractMigration
{
    public function change(): void
    {
        // Consumables and rushed jobs may be packed by quantity without
        // selecting physical assets. This value is combined with real packed
        // assignments and capped at the required quantity by the application.
        $this->table('projectAssetRequirements')
            ->addColumn('projectAssetRequirements_packedOverride', 'integer', [
                'null' => false,
                'default' => 0,
                'limit' => MysqlAdapter::INT_REGULAR,
                'after' => 'projectAssetRequirements_quantity',
            ])
            ->update();
    }
}
