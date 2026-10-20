<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN")) die("404");

$projectsId = filter_input(INPUT_POST, 'projects_id', FILTER_VALIDATE_INT);
$parentId = filter_input(INPUT_POST, 'parent_id', FILTER_VALIDATE_INT) ?: null;
$name = trim($_POST['name'] ?? '');
if (!$projectsId || $name === '' || mb_strlen($name) > 255) finish(false, ["message" => "Project and a section name are required"]);

// Sections and requirements share one visual ordering. Compare both rank sets
// when appending so a newly-created heading always lands at the true end.
$DBLIB->where('projects_id', $projectsId);
$DBLIB->where('instances_id', $AUTH->data['instance']['instances_id']);
$DBLIB->where('projects_deleted', 0);
$project = $DBLIB->getOne('projects', ['projects_id']);
if (!$project) finish(false, ["message" => "Project not found"]);

if ($parentId) {
    $DBLIB->where('projectAssetRequirementSections_id', $parentId);
    $DBLIB->where('projects_id', $projectsId);
    $DBLIB->where('projectAssetRequirementSections_deleted', 0);
    if (!$DBLIB->getOne('projectAssetRequirementSections', ['projectAssetRequirementSections_id'])) {
        finish(false, ["message" => "Parent section not found"]);
    }
}

$DBLIB->where('projects_id', $projectsId);
if ($parentId) $DBLIB->where('projectAssetRequirementSections_parent_id', $parentId);
else $DBLIB->where('projectAssetRequirementSections_parent_id', null, 'IS');
$sectionRank = (int) $DBLIB->getValue('projectAssetRequirementSections', 'MAX(projectAssetRequirementSections_rank)');
$DBLIB->where('projects_id', $projectsId);
if ($parentId) $DBLIB->where('projectAssetRequirementSections_id', $parentId);
else $DBLIB->where('projectAssetRequirementSections_id', null, 'IS');
$requirementRank = (int) $DBLIB->getValue('projectAssetRequirements', 'MAX(projectAssetRequirements_rank)');
$rank = max($sectionRank, $requirementRank) + 1;

$sectionId = $DBLIB->insert('projectAssetRequirementSections', [
    'projects_id' => $projectsId,
    'projectAssetRequirementSections_parent_id' => $parentId,
    'projectAssetRequirementSections_name' => $name,
    'projectAssetRequirementSections_rank' => $rank,
    'projectAssetRequirementSections_deleted' => 0,
]);
if (!$sectionId) finish(false, ["message" => "Could not create section"]);

$bCMS->auditLog('ADD-ASSET-REQUIREMENT-SECTION', 'projectAssetRequirementSections', $sectionId, $AUTH->data['users_userid'], null, $projectsId);
finish(true, null, ['projectAssetRequirementSections_id' => $sectionId]);
