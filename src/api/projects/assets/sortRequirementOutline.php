<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN")) die("404");

$projectsId = filter_input(INPUT_POST, 'projects_id', FILTER_VALIDATE_INT);
$outline = json_decode($_POST['outline'] ?? '', true);
if (!$projectsId || !is_array($outline)) finish(false, ['message' => 'Invalid asset requirement order']);

$DBLIB->where('projects.projects_id', $projectsId);
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$DBLIB->where('projects.projects_deleted', 0);
if (!$DBLIB->getOne('projects', ['projects.projects_id'])) finish(false, ['message' => 'Project not found']);

$DBLIB->where('projects_id', $projectsId);
$DBLIB->where('projectAssetRequirementSections_deleted', 0);
$sectionIds = array_map('intval', $DBLIB->getValue('projectAssetRequirementSections', 'projectAssetRequirementSections_id', null) ?: []);
$DBLIB->where('projects_id', $projectsId);
$DBLIB->where('projectAssetRequirements_deleted', 0);
$requirementIds = array_map('intval', $DBLIB->getValue('projectAssetRequirements', 'projectAssetRequirements_id', null) ?: []);

$submittedSections = [];
$submittedRequirements = [];
foreach ($outline as $node) {
    $kind = $node['kind'] ?? '';
    $id = filter_var($node['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || !in_array($kind, ['section', 'requirement'], true)) finish(false, ['message' => 'Invalid item in asset requirement order']);
    if ($kind === 'section') $submittedSections[] = $id;
    else $submittedRequirements[] = $id;
}
sort($sectionIds);
sort($requirementIds);
$uniqueSubmittedSections = array_values(array_unique($submittedSections));
$uniqueSubmittedRequirements = array_values(array_unique($submittedRequirements));
sort($uniqueSubmittedSections);
sort($uniqueSubmittedRequirements);
// Refuse stale or incomplete drag payloads. Updating only part of the outline
// could orphan rows or make two users' simultaneous edits silently overwrite.
if ($sectionIds !== $uniqueSubmittedSections || $requirementIds !== $uniqueSubmittedRequirements || count($submittedSections) !== count($uniqueSubmittedSections) || count($submittedRequirements) !== count($uniqueSubmittedRequirements)) {
    finish(false, ['message' => 'The asset requirement list changed; reload the page and try again']);
}

// The flattened browser outline encodes parentage as depth. Rebuild the active
// section stack while walking it to recover each row's database parent.
$sectionStack = [];
$ranksByParent = [];
foreach ($outline as $node) {
    $kind = $node['kind'];
    $id = (int) $node['id'];
    $requestedDepth = max(0, (int) ($node['depth'] ?? 0));
    if ($kind === 'section') {
        $depth = min($requestedDepth, count($sectionStack));
        $parentId = $depth > 0 ? $sectionStack[$depth - 1] : null;
        $parentKey = $parentId ?: 0;
        $rank = ($ranksByParent[$parentKey] ?? 0) + 1;
        $ranksByParent[$parentKey] = $rank;
        $DBLIB->where('projectAssetRequirementSections_id', $id);
        if (!$DBLIB->update('projectAssetRequirementSections', [
            'projectAssetRequirementSections_parent_id' => $parentId,
            'projectAssetRequirementSections_rank' => $rank,
        ])) finish(false, ['message' => 'Could not reorder asset sections']);
        $sectionStack = array_slice($sectionStack, 0, $depth);
        $sectionStack[$depth] = $id;
    } else {
        $depth = min($requestedDepth, count($sectionStack));
        $sectionId = $depth > 0 ? $sectionStack[$depth - 1] : null;
        $sectionKey = $sectionId ?: 0;
        $rank = ($ranksByParent[$sectionKey] ?? 0) + 1;
        $ranksByParent[$sectionKey] = $rank;
        $DBLIB->where('projectAssetRequirements_id', $id);
        if (!$DBLIB->update('projectAssetRequirements', [
            'projectAssetRequirementSections_id' => $sectionId,
            'projectAssetRequirements_rank' => $rank,
        ])) finish(false, ['message' => 'Could not reorder planned assets']);
    }
}

$bCMS->auditLog('SORT-ASSET-REQUIREMENT-OUTLINE', 'projects', $projectsId, $AUTH->data['users_userid'], null, $projectsId);
finish(true);
