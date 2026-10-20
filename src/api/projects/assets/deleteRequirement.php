<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN')) die('404');
$requirementId = filter_input(INPUT_POST, 'projectAssetRequirements_id', FILTER_VALIDATE_INT);
$DBLIB->where('projectAssetRequirements.projectAssetRequirements_id', $requirementId);
$DBLIB->where('projectAssetRequirements.projectAssetRequirements_deleted', 0);
$DBLIB->join('projects', 'projectAssetRequirements.projects_id=projects.projects_id', 'LEFT');
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$requirement = $DBLIB->getOne('projectAssetRequirements', ['projectAssetRequirements.projects_id', 'projectAssetRequirements.projectAssetRequirements_packedOverride']);
if (!$requirement) finish(false, ['message' => 'Planned asset not found']);
// Manual fulfilment deserves the same protection as a scanned Packed asset.
// The user must deliberately clear that state before removing the requirement.
if ((int) $requirement['projectAssetRequirements_packedOverride'] > 0) finish(false, ['message' => 'This requirement is marked as packed. Clear its planned packed override before deleting it']);

$DBLIB->where('projectAssetRequirements_id', $requirementId);
$requirementTypeId = (int) $DBLIB->getValue('projectAssetRequirements', 'assetTypes_id');
$DBLIB->where('assetsAssignments.projects_id', $requirement['projects_id']);
$DBLIB->where('assetsAssignments.assetsAssignments_deleted', 0);
$DBLIB->where('assets.assetTypes_id', $requirementTypeId);
$DBLIB->where('(assetsAssignments.projectAssetRequirements_id = ? OR assetsAssignments.projectAssetRequirements_id IS NULL)', [$requirementId]);
$DBLIB->join('assets', 'assetsAssignments.assets_id=assets.assets_id', 'LEFT');
$DBLIB->join('assetsAssignmentsStatus', 'assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id', 'LEFT');
$assignments = $DBLIB->get('assetsAssignments', null, [
    'assetsAssignments.assetsAssignments_id', 'assets.assets_tag',
    'assetsAssignmentsStatus.assetsAssignmentsStatus_name', 'assetsAssignmentsStatus.assetsAssignmentsStatus_order',
    'assetsAssignmentsStatus.instances_id AS status_instances_id',
]);
$DBLIB->where('instances_id', $AUTH->data['instance_ids'], 'IN');
$DBLIB->where('assetsAssignmentsStatus_deleted', 0);
$DBLIB->where('LOWER(assetsAssignmentsStatus_name)', 'packed');
$packedStatuses = $DBLIB->get('assetsAssignmentsStatus', null, ['instances_id', 'assetsAssignmentsStatus_order']);
$packedOrderByInstance = [];
foreach ($packedStatuses as $packedStatus) $packedOrderByInstance[(int) $packedStatus['instances_id']] = (int) $packedStatus['assetsAssignmentsStatus_order'];
foreach ($assignments as $assignment) {
    $packedOrder = $packedOrderByInstance[(int) ($assignment['status_instances_id'] ?? 0)] ?? null;
    if (strtolower((string) $assignment['assetsAssignmentsStatus_name']) === 'packed' || ($assignment['assetsAssignmentsStatus_order'] !== null && $packedOrder !== null && (int) $assignment['assetsAssignmentsStatus_order'] >= $packedOrder)) {
        finish(false, ['message' => 'This requirement contains packed assets, or assets beyond Packed, so it cannot be deleted']);
    }
}
// Deletion is a two-phase operation. The first call is a read-only preflight;
// the client unassigns safe physical assets, then returns with finalize=1.
if (!isset($_POST['finalize'])) {
    finish(true, null, ['assignmentsToRemove' => array_map('intval', array_column($assignments, 'assetsAssignments_id'))]);
}
if ($assignments) finish(false, ['message' => 'Allocated assets must be removed before deleting this requirement']);
$DBLIB->where('projectAssetRequirements_id', $requirementId);
if (!$DBLIB->update('projectAssetRequirements', ['projectAssetRequirements_deleted' => 1])) finish(false, ['message' => 'Could not delete planned asset']);
$bCMS->auditLog('DELETE-ASSET-REQUIREMENT', 'projectAssetRequirements', $requirementId, $AUTH->data['users_userid'], null, $requirement['projects_id']);
finish(true);
