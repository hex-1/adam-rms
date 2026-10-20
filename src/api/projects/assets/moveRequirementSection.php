<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN")) die("404");

$sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
if (!$sectionId || !in_array($action, ['up', 'down', 'indent', 'outdent'], true)) finish(false, ["message" => "Invalid section move"]);

$DBLIB->where('projectAssetRequirementSections_id', $sectionId);
$DBLIB->where('projectAssetRequirementSections_deleted', 0);
$DBLIB->join('projects', 'projectAssetRequirementSections.projects_id=projects.projects_id', 'LEFT');
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$DBLIB->where('projects.projects_deleted', 0);
$section = $DBLIB->getOne('projectAssetRequirementSections', ['projectAssetRequirementSections.*']);
if (!$section) finish(false, ["message" => "Section not found"]);

$parentId = $section['projectAssetRequirementSections_parent_id'];
// Movement is relative to siblings. Ordering by id as a tie-breaker keeps
// older outlines deterministic when several rows still have rank zero.
$DBLIB->where('projects_id', $section['projects_id']);
$DBLIB->where('projectAssetRequirementSections_deleted', 0);
if ($parentId) $DBLIB->where('projectAssetRequirementSections_parent_id', $parentId);
else $DBLIB->where('projectAssetRequirementSections_parent_id', null, 'IS');
$DBLIB->orderBy('projectAssetRequirementSections_rank', 'ASC');
$DBLIB->orderBy('projectAssetRequirementSections_id', 'ASC');
$siblings = $DBLIB->get('projectAssetRequirementSections', null, ['projectAssetRequirementSections_id', 'projectAssetRequirementSections_rank']);
$currentIndex = array_search($sectionId, array_map('intval', array_column($siblings, 'projectAssetRequirementSections_id')), true);

if ($action === 'up' || $action === 'down') {
    // Swapping ranks avoids renumbering the whole branch for a one-step move.
    $targetIndex = $action === 'up' ? $currentIndex - 1 : $currentIndex + 1;
    if ($currentIndex === false || !isset($siblings[$targetIndex])) finish(true);
    $DBLIB->where('projectAssetRequirementSections_id', $sectionId);
    $DBLIB->update('projectAssetRequirementSections', ['projectAssetRequirementSections_rank' => $siblings[$targetIndex]['projectAssetRequirementSections_rank']]);
    $DBLIB->where('projectAssetRequirementSections_id', $siblings[$targetIndex]['projectAssetRequirementSections_id']);
    $DBLIB->update('projectAssetRequirementSections', ['projectAssetRequirementSections_rank' => $section['projectAssetRequirementSections_rank']]);
} elseif ($action === 'indent') {
    // Indenting makes the immediately preceding sibling the new parent, which
    // matches the visual behaviour of the drag-and-drop outline.
    if ($currentIndex === false || $currentIndex < 1) finish(true);
    $newParentId = (int) $siblings[$currentIndex - 1]['projectAssetRequirementSections_id'];
    $DBLIB->where('projects_id', $section['projects_id']);
    $DBLIB->where('projectAssetRequirementSections_parent_id', $newParentId);
    $newRank = ((int) $DBLIB->getValue('projectAssetRequirementSections', 'MAX(projectAssetRequirementSections_rank)')) + 1;
    $DBLIB->where('projectAssetRequirementSections_id', $sectionId);
    $DBLIB->update('projectAssetRequirementSections', [
        'projectAssetRequirementSections_parent_id' => $newParentId,
        'projectAssetRequirementSections_rank' => $newRank,
    ]);
} else {
    // An outdented section is placed immediately after its former parent.
    if (!$parentId) finish(true);
    $DBLIB->where('projectAssetRequirementSections_id', $parentId);
    $parent = $DBLIB->getOne('projectAssetRequirementSections', ['projectAssetRequirementSections_parent_id', 'projectAssetRequirementSections_rank']);
    if (!$parent) finish(false, ["message" => "Parent section not found"]);
    $DBLIB->where('projectAssetRequirementSections_id', $sectionId);
    $DBLIB->update('projectAssetRequirementSections', [
        'projectAssetRequirementSections_parent_id' => $parent['projectAssetRequirementSections_parent_id'],
        'projectAssetRequirementSections_rank' => ((int) $parent['projectAssetRequirementSections_rank']) + 1,
    ]);
}

$bCMS->auditLog('MOVE-ASSET-REQUIREMENT-SECTION', 'projectAssetRequirementSections', $sectionId, $AUTH->data['users_userid'], null, $section['projects_id']);
finish(true);
