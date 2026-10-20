<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN')) die('404');
$requirementId = filter_input(INPUT_POST, 'projectAssetRequirements_id', FILTER_VALIDATE_INT);
$text = trim($_POST['text'] ?? '');
if (!$requirementId || $text === '') finish(false, ['message' => 'Enter an asset tag or barcode']);
$DBLIB->where('projectAssetRequirements.projectAssetRequirements_id', $requirementId);
$DBLIB->where('projectAssetRequirements.projectAssetRequirements_deleted', 0);
$DBLIB->join('projects', 'projectAssetRequirements.projects_id=projects.projects_id', 'LEFT');
$DBLIB->where('projects.instances_id', $AUTH->data['instance']['instances_id']);
$requirement = $DBLIB->getOne('projectAssetRequirements', ['projectAssetRequirements.projects_id', 'projectAssetRequirements.assetTypes_id']);
if (!$requirement) finish(false, ['message' => 'Planned asset not found']);
// Scanners may send either the human tag or any active barcode attached to the
// asset. The type check prevents accidentally satisfying the wrong requirement.
$DBLIB->where('(assets.assets_tag = ? OR (assetsBarcodes.assetsBarcodes_value = ? AND assetsBarcodes.assetsBarcodes_deleted = 0))', [$text, $text]);
$DBLIB->where('assets.assetTypes_id', $requirement['assetTypes_id']);
$DBLIB->where('assets.assets_deleted', 0);
$DBLIB->join('assetsBarcodes', 'assets.assets_id=assetsBarcodes.assets_id', 'LEFT');
$asset = $DBLIB->getOne('assets', ['assets.assets_id', 'assets.assets_tag']);
if (!$asset) finish(false, ['message' => 'No matching asset of the required type was found']);
finish(true, null, ['asset' => $asset, 'projects_id' => $requirement['projects_id']]);
