<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ProjectAssetRequirementPricing extends AbstractMigration
{
    public function change(): void
    {
        // Store the charge for the complete hire period in the same integer
        // money format used elsewhere; null means use the calculated rate.
        $this->table('projectAssetRequirements')
            ->addColumn('projectAssetRequirements_periodPrice', 'biginteger', ['null' => true, 'after' => 'projectAssetRequirements_quantity'])
            ->addColumn('projectAssetRequirements_discount', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => false, 'default' => 0, 'after' => 'projectAssetRequirements_periodPrice'])
            ->update();
    }
}
