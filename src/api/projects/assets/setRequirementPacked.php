<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMENT_STATUS')) die('404');

$requirementIds = $_POST['projectAssetRequirements_ids'] ?? [];
if (!is_array($requirementIds)) $requirementIds = [$requirementIds];
$requirementIds = array_values(array_unique(array_filter(array_map('intval', $requirementIds))));
$packed = filter_var($_POST['packed'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if (!$requirementIds || $packed === null) finish(false, ['message' => 'Planned requirements and packed state are required']);

$DBLIB->where('projectAssetRequirements.projectAssetRequirements_id', $requirementIds, 'IN');
$DBLIB->where('projectAssetRequirements.projectAssetRequirements_deleted', 0);
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$DBLIB->where('projects.projects_deleted', 0);
$DBLIB->join('projects', 'projectAssetRequirements.projects_id=projects.projects_id', 'LEFT');
$requirements = $DBLIB->get('projectAssetRequirements', null, [
    'projectAssetRequirements.projectAssetRequirements_id',
    'projectAssetRequirements.projectAssetRequirements_quantity',
    'projectAssetRequirements.projects_id',
]);
if (count($requirements) !== count($requirementIds)) finish(false, ['message' => 'One or more planned requirements could not be found']);

// Store the quantity at the moment it is marked. If the requirement is reduced
// later the edit endpoints clamp this value; increasing it does not silently
// claim the newly-added items are already packed.
foreach ($requirements as $requirement) {
    $value = $packed ? (int) $requirement['projectAssetRequirements_quantity'] : 0;
    $DBLIB->where('projectAssetRequirements_id', $requirement['projectAssetRequirements_id']);
    if (!$DBLIB->update('projectAssetRequirements', ['projectAssetRequirements_packedOverride' => $value])) {
        finish(false, ['message' => 'Could not update planned requirement']);
    }
    $bCMS->auditLog(
        $packed ? 'PACK-ASSET-REQUIREMENT' : 'UNPACK-ASSET-REQUIREMENT',
        'projectAssetRequirements',
        $requirement['projectAssetRequirements_id'],
        $AUTH->data['users_userid'],
        null,
        $requirement['projects_id']
    );
}

finish(true);
