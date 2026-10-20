<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ProjectAssetRequirementNotes extends AbstractMigration
{
    public function change(): void
    {
        // Planning notes hold warehouse instructions before any serialised
        // assets have been allocated.
        $this->table('projectAssetRequirements')
            ->addColumn('projectAssetRequirements_note', 'text', ['null' => true, 'after' => 'projectAssetRequirements_discount'])
            ->update();
    }
}
