<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Money\Parser\DecimalMoneyParser;

if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN')) die('404');

$requirementId = filter_input(INPUT_POST, 'projectAssetRequirements_id', FILTER_VALIDATE_INT);
if (!$requirementId) finish(false, ['message' => 'Invalid planned asset']);

$DBLIB->where('projectAssetRequirements.projectAssetRequirements_id', $requirementId);
$DBLIB->where('projectAssetRequirements.projectAssetRequirements_deleted', 0);
$DBLIB->join('projects', 'projectAssetRequirements.projects_id=projects.projects_id', 'LEFT');
$DBLIB->join('assetTypes', 'projectAssetRequirements.assetTypes_id=assetTypes.assetTypes_id', 'LEFT');
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$requirement = $DBLIB->getOne('projectAssetRequirements', [
    'projectAssetRequirements.*', 'assetTypes.assetTypes_name', 'assetTypes.assetTypes_mass', 'assetTypes.assetTypes_value', 'projects.instances_id AS project_instances_id',
]);
if (!$requirement) finish(false, ['message' => 'Planned asset not found']);

// The same endpoint serves the popup's initial read and its optional edits.
// This keeps the refreshed physical rows and returned totals in one shape.
if (isset($_POST['quantity'])) {
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    if (!$quantity || $quantity < 1) finish(false, ['message' => 'Quantity must be at least 1']);
    $DBLIB->where('projectAssetRequirements_id', $requirementId);
    if (!$DBLIB->update('projectAssetRequirements', [
        'projectAssetRequirements_quantity' => $quantity,
        'projectAssetRequirements_packedOverride' => min($quantity, (int) ($requirement['projectAssetRequirements_packedOverride'] ?? 0)),
    ])) {
        finish(false, ['message' => 'Could not update quantity']);
    }
    $requirement['projectAssetRequirements_quantity'] = $quantity;
    $bCMS->auditLog('EDIT-ASSET-REQUIREMENT', 'projectAssetRequirements', $requirementId, $AUTH->data['users_userid'], null, $requirement['projects_id']);
}
if (array_key_exists('note', $_POST)) {
    if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMNET_COMMENT')) die('404');
    $note = trim((string) $_POST['note']);
    if (strlen($note) > 10000) finish(false, ['message' => 'Warehouse note is too long']);
    $DBLIB->where('projectAssetRequirements_id', $requirementId);
    if (!$DBLIB->update('projectAssetRequirements', ['projectAssetRequirements_note' => $note === '' ? null : $note])) {
        finish(false, ['message' => 'Could not update warehouse note']);
    }
    $requirement['projectAssetRequirements_note'] = $note;
    $bCMS->auditLog('EDIT-ASSET-REQUIREMENT-NOTE', 'projectAssetRequirements', $requirementId, $AUTH->data['users_userid'], null, $requirement['projects_id']);
}
if (isset($_POST['periodPrice'], $_POST['discount'])) {
    if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:EDIT:CUSTOM_PRICE') || !$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:EDIT:DISCOUNT')) die('404');
    $discount = filter_var($_POST['discount'], FILTER_VALIDATE_FLOAT);
    if ($discount === false || $discount < 0 || $discount > 100) finish(false, ['message' => 'Discount must be between 0 and 100']);
    try {
        $periodPrice = trim((string) $_POST['periodPrice']) === '' ? null : (new DecimalMoneyParser(new ISOCurrencies()))->parse((string) $_POST['periodPrice'], $AUTH->data['instance']['instances_config_currency'])->getAmount();
    } catch (Throwable $error) {
        finish(false, ['message' => 'Enter a valid period price']);
    }
    $DBLIB->where('projectAssetRequirements_id', $requirementId);
    if (!$DBLIB->update('projectAssetRequirements', [
        'projectAssetRequirements_periodPrice' => $periodPrice,
        'projectAssetRequirements_discount' => $discount,
    ])) finish(false, ['message' => 'Could not update requirement pricing']);
    $requirement['projectAssetRequirements_periodPrice'] = $periodPrice;
    $requirement['projectAssetRequirements_discount'] = $discount;
}

$DBLIB->where('assetsAssignments.projects_id', $requirement['projects_id']);
$DBLIB->where('assetsAssignments.assetsAssignments_deleted', 0);
$DBLIB->join('assets', 'assetsAssignments.assets_id=assets.assets_id', 'LEFT');
$DBLIB->join('assetTypes', 'assets.assetTypes_id=assetTypes.assetTypes_id', 'LEFT');
$DBLIB->join('instances', 'assets.instances_id=instances.instances_id', 'LEFT');
$DBLIB->join('assetsAssignmentsStatus', 'assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id', 'LEFT');
$DBLIB->orderBy('assets.assets_tag', 'ASC');
$allProjectAssignments = $DBLIB->get('assetsAssignments', null, [
    'assetsAssignments.assetsAssignments_id', 'assetsAssignments.assetsAssignments_linkedTo', 'assetsAssignments.projectAssetRequirements_id',
    'assets.assets_id', 'assets.assets_tag', 'assets.assets_mass', 'assets.assets_value', 'assets.assets_storageLocation', 'assets.asset_definableFields_1', 'assets.asset_definableFields_2', 'assets.asset_definableFields_3', 'assets.asset_definableFields_4', 'assets.asset_definableFields_5', 'assets.asset_definableFields_6', 'assets.asset_definableFields_7', 'assets.asset_definableFields_8', 'assets.asset_definableFields_9', 'assets.asset_definableFields_10', 'assets.assetTypes_id', 'assets.instances_id', 'assetTypes.assetTypes_name', 'assetTypes.assetTypes_mass', 'assetTypes.assetTypes_value', 'assetTypes.assetTypes_definableFields', 'instances.instances_name AS sourceInstanceName', 'assetsAssignments.assetsAssignments_comment',
    'assetsAssignmentsStatus.assetsAssignmentsStatus_id', 'assetsAssignmentsStatus.assetsAssignmentsStatus_name', 'assetsAssignmentsStatus.assetsAssignmentsStatus_order',
    'assetsAssignmentsStatus.instances_id AS status_instances_id',
]);
$rootAssets = [];
$linkedAssetsByParent = [];
foreach ($allProjectAssignments as $assignmentAsset) {
    if ($assignmentAsset['assetsAssignments_linkedTo']) $linkedAssetsByParent[(int) $assignmentAsset['assetsAssignments_linkedTo']][] = $assignmentAsset;
    elseif ((int) $assignmentAsset['assetTypes_id'] === (int) $requirement['assetTypes_id'] && ((int) $assignmentAsset['projectAssetRequirements_id'] === $requirementId || !$assignmentAsset['projectAssetRequirements_id'])) $rootAssets[] = $assignmentAsset;
}
// Return linked accessories immediately after their parent so the client can
// rebuild a stable, collapsible tree without another request.
$flattenAssets = function (array $assets, int $depth = 0) use (&$flattenAssets, &$linkedAssetsByParent): array {
    $result = [];
    foreach ($assets as $asset) {
        $asset['linkedDepth'] = $depth;
        $asset['isLinkedAsset'] = $depth > 0;
        $result[] = $asset;
        if (!empty($linkedAssetsByParent[(int) $asset['assetsAssignments_id']])) $result = array_merge($result, $flattenAssets($linkedAssetsByParent[(int) $asset['assetsAssignments_id']], $depth + 1));
    }
    return $result;
};
$assignedAssets = $flattenAssets($rootAssets);
// These details are deliberately loaded only for the assets in this editor.
// Flags and latest scans require separate legacy queries and are too expensive
// to fetch for every possible inventory item.
foreach ($assignedAssets as &$assignedAsset) {
    $assignedAsset['flagsblocks'] = assetFlagsAndBlocks($assignedAsset['assets_id']);
    $assignedAsset['latestScan'] = assetLatestScan($assignedAsset['assets_id']);
    $assignedAsset['assetTypes_definableFields_ARRAY'] = array_values(array_filter(explode(',', (string) $assignedAsset['assetTypes_definableFields'])));
    if ((int) $assignedAsset['instances_id'] === (int) $requirement['project_instances_id']) $assignedAsset['sourceInstanceName'] = null;
}
unset($assignedAsset);
$DBLIB->where('instances_id', $AUTH->data['instance_ids'], 'IN');
$DBLIB->where('assetsAssignmentsStatus_deleted', 0);
$DBLIB->where('LOWER(assetsAssignmentsStatus_name)', 'packed');
$packedStatuses = $DBLIB->get('assetsAssignmentsStatus', null, ['instances_id', 'assetsAssignmentsStatus_order']);
$packedOrderByInstance = [];
foreach ($packedStatuses as $packedStatus) $packedOrderByInstance[(int) $packedStatus['instances_id']] = (int) $packedStatus['assetsAssignmentsStatus_order'];
foreach ($assignedAssets as &$assignedAsset) {
    $packedOrder = $packedOrderByInstance[(int) ($assignedAsset['status_instances_id'] ?? 0)] ?? null;
    $assignedAsset['canRemove'] = !$assignedAsset['isLinkedAsset'] && !(
        strtolower((string) $assignedAsset['assetsAssignmentsStatus_name']) === 'packed'
        || ($assignedAsset['assetsAssignmentsStatus_order'] !== null && $packedOrder !== null && (int) $assignedAsset['assetsAssignmentsStatus_order'] >= $packedOrder)
    );
    $normalisedStatusName = strtolower((string) $assignedAsset['assetsAssignmentsStatus_name']);
    $statusColours = ['prepared' => '#ffc107', 'packed' => '#28a745', 'dispatched' => '#dc3545', 'stored' => '#6c757d'];
    $assignedAsset['statusColour'] = $statusColours[$normalisedStatusName] ?? ($normalisedStatusName ? '#17a2b8' : '#6c757d');
    $assignedAsset['statusTextColour'] = $normalisedStatusName === 'prepared' ? '#212529' : '#fff';
}
unset($assignedAsset);

$requirement['totalMass'] = 0.0;
$requirement['unknownMassCount'] = 0;
$requirement['totalValue'] = 0;
$requirement['unknownValueCount'] = 0;
$requirement['blockedAssetCount'] = 0;
$requirement['flaggedAssetCount'] = 0;
$allocatedRootCount = 0;
foreach ($assignedAssets as $assignedAsset) {
    if ((int) ($assignedAsset['linkedDepth'] ?? 0) === 0) $allocatedRootCount++;
    $assetMass = $assignedAsset['assets_mass'] !== null ? $assignedAsset['assets_mass'] : $assignedAsset['assetTypes_mass'];
    if ($assetMass === null || (float) $assetMass <= 0) $requirement['unknownMassCount']++;
    else $requirement['totalMass'] += (float) $assetMass;
    $assetValue = $assignedAsset['assets_value'] !== null ? $assignedAsset['assets_value'] : $assignedAsset['assetTypes_value'];
    if ($assetValue === null || (int) $assetValue <= 0) $requirement['unknownValueCount']++;
    else $requirement['totalValue'] += (int) $assetValue;
    if (!empty($assignedAsset['flagsblocks']['COUNT']['BLOCK'])) $requirement['blockedAssetCount']++;
    if (!empty($assignedAsset['flagsblocks']['COUNT']['FLAG'])) $requirement['flaggedAssetCount']++;
}
$unallocatedCount = max(0, (int) $requirement['projectAssetRequirements_quantity'] - $allocatedRootCount);
if ($unallocatedCount) {
    if ($requirement['assetTypes_mass'] === null || (float) $requirement['assetTypes_mass'] <= 0) $requirement['unknownMassCount'] += $unallocatedCount;
    else $requirement['totalMass'] += $unallocatedCount * (float) $requirement['assetTypes_mass'];
    if ($requirement['assetTypes_value'] === null || (int) $requirement['assetTypes_value'] <= 0) $requirement['unknownValueCount'] += $unallocatedCount;
    else $requirement['totalValue'] += $unallocatedCount * (int) $requirement['assetTypes_value'];
}

$requirement['projectAssetRequirements_periodPriceDecimal'] = $requirement['projectAssetRequirements_periodPrice'] === null
    ? ''
    : (new DecimalMoneyFormatter(new ISOCurrencies()))->format(new Money((int) $requirement['projectAssetRequirements_periodPrice'], new Currency($AUTH->data['instance']['instances_config_currency'])));

finish(true, null, [
    'requirement' => $requirement,
    'assignedAssets' => $assignedAssets,
]);
