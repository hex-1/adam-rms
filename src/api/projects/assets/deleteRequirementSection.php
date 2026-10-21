<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN')) die('404');
$sectionId = filter_input(INPUT_POST, 'projectAssetRequirementSections_id', FILTER_VALIDATE_INT);
$DBLIB->where('projectAssetRequirementSections.projectAssetRequirementSections_id', $sectionId);
$DBLIB->where('projectAssetRequirementSections.projectAssetRequirementSections_deleted', 0);
$DBLIB->join('projects', 'projectAssetRequirementSections.projects_id=projects.projects_id', 'LEFT');
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$section = $DBLIB->getOne('projectAssetRequirementSections', ['projectAssetRequirementSections.*']);
if (!$section) finish(false, ['message' => 'Section not found']);
$parentId = $section['projectAssetRequirementSections_parent_id'];
// Deleting a heading must not delete its contents. Promote direct children by
// one level, matching what the user sees immediately in the browser.
$DBLIB->where('projectAssetRequirementSections_parent_id', $sectionId);
$DBLIB->update('projectAssetRequirementSections', ['projectAssetRequirementSections_parent_id' => $parentId]);
$DBLIB->where('projectAssetRequirementSections_id', $sectionId);
$DBLIB->update('projectAssetRequirements', ['projectAssetRequirementSections_id' => $parentId]);
$DBLIB->where('projectAssetRequirementSections_id', $sectionId);
if (!$DBLIB->update('projectAssetRequirementSections', ['projectAssetRequirementSections_deleted' => 1])) finish(false, ['message' => 'Could not delete section']);
$bCMS->auditLog('DELETE-ASSET-REQUIREMENT-SECTION', 'projectAssetRequirementSections', $sectionId, $AUTH->data['users_userid'], null, $section['projects_id']);
finish(true);
