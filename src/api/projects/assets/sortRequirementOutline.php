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
$submittedDirectTypes = [];
foreach ($outline as $node) {
    $kind = $node['kind'] ?? '';
    $id = filter_var($node['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || !in_array($kind, ['section', 'requirement', 'direct'], true)) finish(false, ['message' => 'Invalid item in asset requirement order']);
    if ($kind === 'section' && $id > 0) $submittedSections[] = $id;
    elseif ($kind === 'requirement' && $id > 0) $submittedRequirements[] = $id;
    elseif ($kind === 'direct' && $id < 0) $submittedDirectTypes[] = -$id;
    elseif (!($kind === 'section' && $id < -1000000)) finish(false, ['message' => 'Invalid item in asset requirement order']);
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
if (count($submittedDirectTypes) !== count(array_unique($submittedDirectTypes))) finish(false, ['message' => 'Duplicate directly assigned Asset Type']);

// The flattened browser outline encodes parentage as depth. Rebuild the active
// section stack while walking it to recover each row's database parent.
$sectionStack = [];
$ranksByParent = [];
$promoted = false;
foreach ($outline as $node) {
    $kind = $node['kind'];
    $id = (int) $node['id'];
    $requestedDepth = max(0, (int) ($node['depth'] ?? 0));
    if ($kind === 'section') {
        if ($id < 0) {
            $categoryGroupId = -$id - 1000000;
            $DBLIB->where('assetsAssignments.projects_id', $projectsId);
            $DBLIB->where('assetsAssignments.assetsAssignments_deleted', 0);
            $DBLIB->where('assetsAssignments.projectAssetRequirements_id', null, 'IS');
            $DBLIB->where('assetsAssignments.assetsAssignments_linkedTo', null, 'IS');
            $DBLIB->where('assetCategories.assetCategoriesGroups_id', $categoryGroupId);
            $DBLIB->join('assets', 'assets.assets_id=assetsAssignments.assets_id', 'LEFT');
            $DBLIB->join('assetTypes', 'assetTypes.assetTypes_id=assets.assetTypes_id', 'LEFT');
            $DBLIB->join('assetCategories', 'assetCategories.assetCategories_id=assetTypes.assetCategories_id', 'LEFT');
            $directCategory = $DBLIB->getOne('assetsAssignments', ['assetCategories.assetCategoriesGroups_id']);
            if (!$directCategory) finish(false, ['message' => 'Direct assignment category not found']);
            $DBLIB->where('assetCategoriesGroups_id', $categoryGroupId);
            $categoryName = $DBLIB->getValue('assetCategoriesGroups', 'assetCategoriesGroups_name');
            if (!$categoryName) finish(false, ['message' => 'Asset category not found']);
            $DBLIB->where('projects_id', $projectsId);
            $DBLIB->where('assetCategoriesGroups_id', $categoryGroupId);
            $DBLIB->where('projectAssetRequirementSections_deleted', 0);
            $DBLIB->where('projectAssetRequirementSections_parent_id', null, 'IS');
            $id = (int) $DBLIB->getValue('projectAssetRequirementSections', 'projectAssetRequirementSections_id');
            if (!$id) {
                $id = (int) $DBLIB->insert('projectAssetRequirementSections', [
                    'projects_id' => $projectsId,
                    'projectAssetRequirementSections_parent_id' => null,
                    'assetCategoriesGroups_id' => $categoryGroupId,
                    'projectAssetRequirementSections_name' => $categoryName,
                    'projectAssetRequirementSections_rank' => 0,
                    'projectAssetRequirementSections_deleted' => 0,
                ]);
                if (!$id) finish(false, ['message' => 'Could not create direct assignment category']);
            }
            $promoted = true;
        }
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
        if ($kind === 'direct') {
            $assetTypeId = -$id;
            $DBLIB->where('assetsAssignments.projects_id', $projectsId);
            $DBLIB->where('assetsAssignments.assetsAssignments_deleted', 0);
            $DBLIB->where('assetsAssignments.projectAssetRequirements_id', null, 'IS');
            $DBLIB->where('assetsAssignments.assetsAssignments_linkedTo', null, 'IS');
            $DBLIB->where('assets.assetTypes_id', $assetTypeId);
            $DBLIB->join('assets', 'assets.assets_id=assetsAssignments.assets_id', 'LEFT');
            $directAssignmentIds = array_map('intval', $DBLIB->getValue('assetsAssignments', 'assetsAssignments.assetsAssignments_id', null) ?: []);
            if (!$directAssignmentIds) finish(false, ['message' => 'Direct assignments changed; reload the page and try again']);
            $DBLIB->where('projects_id', $projectsId);
            $DBLIB->where('assetTypes_id', $assetTypeId);
            $DBLIB->where('projectAssetRequirements_deleted', 0);
            if ($sectionId) $DBLIB->where('projectAssetRequirementSections_id', $sectionId);
            else $DBLIB->where('projectAssetRequirementSections_id', null, 'IS');
            $requirement = $DBLIB->getOne('projectAssetRequirements', ['projectAssetRequirements_id', 'projectAssetRequirements_quantity']);
            if ($requirement) {
                $id = (int) $requirement['projectAssetRequirements_id'];
                $DBLIB->where('projectAssetRequirements_id', $id);
                if (!$DBLIB->update('projectAssetRequirements', ['projectAssetRequirements_quantity' => (int) $requirement['projectAssetRequirements_quantity'] + count($directAssignmentIds)])) finish(false, ['message' => 'Could not update direct assignment requirement']);
            } else {
                $id = (int) $DBLIB->insert('projectAssetRequirements', [
                    'projects_id' => $projectsId,
                    'assetTypes_id' => $assetTypeId,
                    'projectAssetRequirements_quantity' => count($directAssignmentIds),
                    'projectAssetRequirementSections_id' => $sectionId,
                    'projectAssetRequirements_rank' => $rank,
                    'projectAssetRequirements_deleted' => 0,
                ]);
                if (!$id) finish(false, ['message' => 'Could not create direct assignment requirement']);
            }
            $DBLIB->where('assetsAssignments_id', $directAssignmentIds, 'IN');
            if (!$DBLIB->update('assetsAssignments', ['projectAssetRequirements_id' => $id])) finish(false, ['message' => 'Could not attach direct assignments to their requirement']);
            $promoted = true;
        }
        $DBLIB->where('projectAssetRequirements_id', $id);
        if (!$DBLIB->update('projectAssetRequirements', [
            'projectAssetRequirementSections_id' => $sectionId,
            'projectAssetRequirements_rank' => $rank,
        ])) finish(false, ['message' => 'Could not reorder planned assets']);
    }
}

$bCMS->auditLog('SORT-ASSET-REQUIREMENT-OUTLINE', 'projects', $projectsId, $AUTH->data['users_userid'], null, $projectsId);
finish(true, null, ['promoted' => $promoted]);
