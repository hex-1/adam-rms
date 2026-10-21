<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectAssetRequirements.php';

if (!$AUTH->instancePermissionCheck('PROJECTS:VIEW')) die('404');
$projectsId = filter_input(INPUT_POST, 'projects_id', FILTER_VALIDATE_INT);
$DBLIB->where('projects_id', $projectsId);
$DBLIB->where('instances_id', $AUTH->data['instance']['instances_id']);
$DBLIB->where('projects_deleted', 0);
$project = $DBLIB->getOne('projects', ['projects_id', 'instances_id', 'projects_dates_deliver_start', 'projects_dates_deliver_end']);
if (!$project) finish(false, ['message' => 'Project not found']);

$DBLIB->where('projects_id', $projectsId);
$DBLIB->where('projectAssetRequirements_deleted', 0);
$DBLIB->orderBy('projectAssetRequirements_rank', 'ASC');
$requirements = $DBLIB->get('projectAssetRequirements', null, [
    'projectAssetRequirements_id', 'assetTypes_id', 'projectAssetRequirements_quantity', 'projectAssetRequirements_packedOverride',
]);
// Packed physical assets are allocated across same-type requirements in display
// order. This prevents one physical asset satisfying multiple planning rows.
$packedRemainingByType = [];
$rowColours = [
    'shortage' => 'rgba(220, 53, 69, 0.18)',
    'turnaround' => 'rgba(253, 126, 20, 0.18)',
    'low-stock' => 'rgba(255, 193, 7, 0.18)',
    'unknown' => 'rgba(108, 117, 125, 0.10)',
    'available' => 'transparent',
];
$statuses = [];
foreach ($requirements as $requirement) {
    $typeId = (int) $requirement['assetTypes_id'];
    if (!isset($packedRemainingByType[$typeId])) {
        $packedRemainingByType[$typeId] = projectAssetRequirementPackedCount($projectsId, $typeId);
    }
    $quantity = (int) $requirement['projectAssetRequirements_quantity'];
    $physicalPacked = min($quantity, $packedRemainingByType[$typeId]);
    $manualPacked = min($quantity, (int) $requirement['projectAssetRequirements_packedOverride']);
    // Manual packing is an override of fulfilment, not an additional quantity.
    $packed = max($physicalPacked, $manualPacked);
    $packedRemainingByType[$typeId] = max(0, $packedRemainingByType[$typeId] - $physicalPacked);
    $availability = projectAssetRequirementAvailability($project, $typeId);
    $statuses[] = [
        'id' => (int) $requirement['projectAssetRequirements_id'],
        'packed' => $packed,
        'required' => $quantity,
        'availability' => $availability,
        'rowColour' => $rowColours[$availability['status']] ?? 'transparent',
    ];
}
finish(true, null, ['requirements' => $statuses]);
