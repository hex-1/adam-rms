<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN')) die('404');
$ids = $_POST['projectAssetRequirements_ids'] ?? [];
if (!is_array($ids)) $ids = [$ids];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
if (!$ids) finish(false, ['message' => 'Select at least one planned requirement']);

$DBLIB->where('projectAssetRequirements.projectAssetRequirements_id', $ids, 'IN');
$DBLIB->where('projectAssetRequirements.projectAssetRequirements_deleted', 0);
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$DBLIB->where('projects.projects_deleted', 0);
$DBLIB->join('projects', 'projectAssetRequirements.projects_id=projects.projects_id', 'LEFT');
$requirements = $DBLIB->get('projectAssetRequirements', null, ['projectAssetRequirements.*', 'projects.instances_id']);
if (count($requirements) !== count($ids)) finish(false, ['message' => 'One or more planned requirements could not be found']);
$projectIds = array_values(array_unique(array_map('intval', array_column($requirements, 'projects_id'))));
if (count($projectIds) !== 1) finish(false, ['message' => 'Selected requirements must belong to one project']);
$projectId = $projectIds[0];

// Every bulk field is opt-in. This is important when the selected rows contain
// mixed quantities, prices or notes: an untouched control must remain untouched.
$quantityMode = $_POST['quantityMode'] ?? 'unchanged';
$quantityValue = filter_var($_POST['quantityValue'] ?? null, FILTER_VALIDATE_INT);
if (!in_array($quantityMode, ['unchanged', 'set', 'add', 'subtract'], true)) finish(false, ['message' => 'Invalid quantity adjustment']);
if ($quantityMode !== 'unchanged' && ($quantityValue === false || $quantityValue < 1)) finish(false, ['message' => 'Quantity adjustment must be at least 1']);

$setNote = filter_var($_POST['setNote'] ?? false, FILTER_VALIDATE_BOOLEAN);
$note = trim((string) ($_POST['note'] ?? ''));
if ($setNote && !$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMNET_COMMENT')) die('404');
if (strlen($note) > 10000) finish(false, ['message' => 'Note is too long']);

$setPrice = filter_var($_POST['setPrice'] ?? false, FILTER_VALIDATE_BOOLEAN);
$setDiscount = filter_var($_POST['setDiscount'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (($setPrice && !$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:EDIT:CUSTOM_PRICE')) || ($setDiscount && !$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:EDIT:DISCOUNT'))) die('404');
$periodPrice = null;
if ($setPrice && trim((string) ($_POST['periodPrice'] ?? '')) !== '') {
    try {
        $periodPrice = (new DecimalMoneyParser(new ISOCurrencies()))->parse((string) $_POST['periodPrice'], $AUTH->data['instance']['instances_config_currency'])->getAmount();
    } catch (Throwable $error) {
        finish(false, ['message' => 'Enter a valid period cost']);
    }
}
$discount = filter_var($_POST['discount'] ?? null, FILTER_VALIDATE_FLOAT);
if ($setDiscount && ($discount === false || $discount < 0 || $discount > 100)) finish(false, ['message' => 'Discount must be between 0 and 100']);

$moveSection = filter_var($_POST['moveSection'] ?? false, FILTER_VALIDATE_BOOLEAN);
$sectionId = filter_var($_POST['sectionId'] ?? null, FILTER_VALIDATE_INT) ?: null;
if ($moveSection && $sectionId) {
    $DBLIB->where('projectAssetRequirementSections_id', $sectionId);
    $DBLIB->where('projects_id', $projectId);
    $DBLIB->where('projectAssetRequirementSections_deleted', 0);
    if (!$DBLIB->getOne('projectAssetRequirementSections', ['projectAssetRequirementSections_id'])) finish(false, ['message' => 'Destination section not found']);
}

// Treat the selected requirements as one edit. A partial bulk update is much
// harder for a planner to notice and undo than a failed all-or-nothing request.
$DBLIB->startTransaction();
$rank = null;
if ($moveSection) {
    $DBLIB->where('projects_id', $projectId);
    if ($sectionId) $DBLIB->where('projectAssetRequirementSections_id', $sectionId);
    else $DBLIB->where('projectAssetRequirementSections_id', null, 'IS');
    $maxRequirementRank = (int) $DBLIB->getValue('projectAssetRequirements', 'MAX(projectAssetRequirements_rank)');
    $DBLIB->where('projects_id', $projectId);
    if ($sectionId) $DBLIB->where('projectAssetRequirementSections_parent_id', $sectionId);
    else $DBLIB->where('projectAssetRequirementSections_parent_id', null, 'IS');
    $maxSectionRank = (int) $DBLIB->getValue('projectAssetRequirementSections', 'MAX(projectAssetRequirementSections_rank)');
    $rank = max($maxRequirementRank, $maxSectionRank);
}
$updated = [];
foreach ($requirements as $requirement) {
    $changes = [];
    $quantity = (int) $requirement['projectAssetRequirements_quantity'];
    if ($quantityMode === 'set') $quantity = $quantityValue;
    elseif ($quantityMode === 'add') $quantity += $quantityValue;
    // A requirement is never allowed to become zero; deleting it is a separate,
    // protected action with explicit confirmation.
    elseif ($quantityMode === 'subtract') $quantity = max(1, $quantity - $quantityValue);
    if ($quantityMode !== 'unchanged') {
        $changes['projectAssetRequirements_quantity'] = $quantity;
        $changes['projectAssetRequirements_packedOverride'] = min($quantity, (int) $requirement['projectAssetRequirements_packedOverride']);
    }
    if ($setNote) $changes['projectAssetRequirements_note'] = $note === '' ? null : $note;
    if ($setPrice) $changes['projectAssetRequirements_periodPrice'] = $periodPrice;
    if ($setDiscount) $changes['projectAssetRequirements_discount'] = $discount;
    if ($moveSection) {
        $changes['projectAssetRequirementSections_id'] = $sectionId;
        $changes['projectAssetRequirements_rank'] = ++$rank;
    }
    if ($changes) {
        $DBLIB->where('projectAssetRequirements_id', $requirement['projectAssetRequirements_id']);
        if (!$DBLIB->update('projectAssetRequirements', $changes)) {
            $DBLIB->rollback();
            finish(false, ['message' => 'Could not update all selected requirements']);
        }
        $bCMS->auditLog('BULK-EDIT-ASSET-REQUIREMENT', 'projectAssetRequirements', $requirement['projectAssetRequirements_id'], $AUTH->data['users_userid'], null, $projectId);
    }
    $updated[] = ['id' => (int) $requirement['projectAssetRequirements_id'], 'quantity' => $quantity];
}
$DBLIB->commit();
finish(true, null, ['requirements' => $updated, 'sectionId' => $sectionId, 'moved' => $moveSection]);
