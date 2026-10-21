<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectAssetRequirements.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN")) die("404");

$projectsId = filter_input(INPUT_POST, 'projects_id', FILTER_VALIDATE_INT);
$assetTypesId = filter_input(INPUT_POST, 'assetTypes_id', FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
$selectedSectionId = filter_input(INPUT_POST, 'projectAssetRequirementSections_id', FILTER_VALIDATE_INT) ?: null;

if (!$projectsId || !$assetTypesId || !$quantity || $quantity < 1) {
    finish(false, ["message" => "Project, Asset Type and a positive quantity are required"]);
}

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $projectsId);
$project = $DBLIB->getOne("projects", ["projects_id", "instances_id", "projects_dates_deliver_start", "projects_dates_deliver_end"]);
if (!$project) finish(false, ["message" => "Project not found"]);

$DBLIB->where("assetTypes.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assetTypes.assetTypes_id", $assetTypesId);
$DBLIB->join("assetCategories", "assetTypes.assetCategories_id=assetCategories.assetCategories_id", "LEFT");
$DBLIB->join("assetCategoriesGroups", "assetCategories.assetCategoriesGroups_id=assetCategoriesGroups.assetCategoriesGroups_id", "LEFT");
$assetType = $DBLIB->getOne("assetTypes", ["assetTypes_id", "assetCategoriesGroups.assetCategoriesGroups_id", "assetCategoriesGroups.assetCategoriesGroups_name"]);
if (!$assetType) finish(false, ["message" => "Asset Type not found"]);

// A user-selected section wins, but automatic category sections may be placed
// beneath it so repeated Sound items end up together rather than creating a
// fresh Sound heading for every requirement.
$parentSectionId = $selectedSectionId;
if ($selectedSectionId) {
    $DBLIB->where('projectAssetRequirementSections_id', $selectedSectionId);
    $DBLIB->where('projects_id', $projectsId);
    $DBLIB->where('projectAssetRequirementSections_deleted', 0);
    $selectedSection = $DBLIB->getOne('projectAssetRequirementSections', [
        'projectAssetRequirementSections_id', 'projectAssetRequirementSections_parent_id', 'assetCategoriesGroups_id',
    ]);
    if (!$selectedSection) finish(false, ["message" => "Section not found"]);
} else $selectedSection = null;

//Place the requirement in an automatically managed category-group section.
$categoryGroupId = $assetType['assetCategoriesGroups_id'] ? (int) $assetType['assetCategoriesGroups_id'] : null;
if ($categoryGroupId && (!$selectedSection || (int) $selectedSection['assetCategoriesGroups_id'] !== $categoryGroupId)) {
    // Automatic categories are siblings beneath the nearest manual section. If
    // another automatic category was selected, use its parent rather than
    // nesting (for example, Sound must not be created inside Cable).
    $categoryParentId = $parentSectionId;
    if ($selectedSection && $selectedSection['assetCategoriesGroups_id']) {
        $categoryParentId = $selectedSection['projectAssetRequirementSections_parent_id'] ?: null;
    }
    $DBLIB->where('projects_id', $projectsId);
    $DBLIB->where('assetCategoriesGroups_id', $categoryGroupId);
    $DBLIB->where('projectAssetRequirementSections_deleted', 0);
    if ($categoryParentId) $DBLIB->where('projectAssetRequirementSections_parent_id', $categoryParentId);
    else $DBLIB->where('projectAssetRequirementSections_parent_id', null, 'IS');
    $categorySection = $DBLIB->getOne('projectAssetRequirementSections', ['projectAssetRequirementSections_id']);
    if ($categorySection) {
        $parentSectionId = (int) $categorySection['projectAssetRequirementSections_id'];
    } else {
        $DBLIB->where('projects_id', $projectsId);
        if ($categoryParentId) $DBLIB->where('projectAssetRequirementSections_parent_id', $categoryParentId);
        else $DBLIB->where('projectAssetRequirementSections_parent_id', null, 'IS');
        $maxSectionRank = (int) $DBLIB->getValue('projectAssetRequirementSections', 'MAX(projectAssetRequirementSections_rank)');
        $DBLIB->where('projects_id', $projectsId);
        if ($categoryParentId) $DBLIB->where('projectAssetRequirementSections_id', $categoryParentId);
        else $DBLIB->where('projectAssetRequirementSections_id', null, 'IS');
        $maxRequirementRank = (int) $DBLIB->getValue('projectAssetRequirements', 'MAX(projectAssetRequirements_rank)');
        $sectionRank = max($maxSectionRank, $maxRequirementRank) + 1;
        $parentSectionId = $DBLIB->insert('projectAssetRequirementSections', [
            'projects_id' => $projectsId,
            'projectAssetRequirementSections_parent_id' => $categoryParentId,
            'assetCategoriesGroups_id' => $categoryGroupId,
            'projectAssetRequirementSections_name' => $assetType['assetCategoriesGroups_name'],
            'projectAssetRequirementSections_rank' => $sectionRank,
            'projectAssetRequirementSections_deleted' => 0,
        ]);
        if (!$parentSectionId) finish(false, ["message" => "Could not create category section"]);
    }
}

$DBLIB->where("projects_id", $projectsId);
$DBLIB->where("assetTypes_id", $assetTypesId);
$DBLIB->where("projectAssetRequirements_deleted", 0);
if ($parentSectionId) $DBLIB->where('projectAssetRequirementSections_id', $parentSectionId);
else $DBLIB->where('projectAssetRequirementSections_id', null, 'IS');
$existingRequirement = $DBLIB->getOne("projectAssetRequirements", [
    "projectAssetRequirements_id",
    "projectAssetRequirements_quantity",
]);

// Requirements of the same type in the same section are intentionally merged.
// Separate rows are still possible by placing them in different custom groups.
if ($existingRequirement) {
    $newQuantity = $existingRequirement['projectAssetRequirements_quantity'] + $quantity;
    $DBLIB->where("projectAssetRequirements_id", $existingRequirement['projectAssetRequirements_id']);
    $saved = $DBLIB->update("projectAssetRequirements", [
        "projectAssetRequirements_quantity" => $newQuantity,
    ]);
    $requirementId = $existingRequirement['projectAssetRequirements_id'];
} else {
    $newQuantity = $quantity;
    $DBLIB->where('projects_id', $projectsId);
    if ($parentSectionId) $DBLIB->where('projectAssetRequirementSections_id', $parentSectionId);
    else $DBLIB->where('projectAssetRequirementSections_id', null, 'IS');
    $maxRequirementRank = (int) $DBLIB->getValue('projectAssetRequirements', 'MAX(projectAssetRequirements_rank)');
    $DBLIB->where('projects_id', $projectsId);
    if ($parentSectionId) $DBLIB->where('projectAssetRequirementSections_parent_id', $parentSectionId);
    else $DBLIB->where('projectAssetRequirementSections_parent_id', null, 'IS');
    $maxSectionRank = (int) $DBLIB->getValue('projectAssetRequirementSections', 'MAX(projectAssetRequirementSections_rank)');
    $requirementRank = max($maxRequirementRank, $maxSectionRank) + 1;
    $requirementId = $DBLIB->insert("projectAssetRequirements", [
        "projects_id" => $projectsId,
        "assetTypes_id" => $assetTypesId,
        "projectAssetRequirements_quantity" => $quantity,
        "projectAssetRequirementSections_id" => $parentSectionId,
        "projectAssetRequirements_rank" => $requirementRank,
        "projectAssetRequirements_deleted" => 0,
    ]);
    $saved = (bool) $requirementId;
}

if (!$saved) finish(false, ["message" => "Could not save Asset Type requirement"]);

$bCMS->auditLog("ADD-ASSET-REQUIREMENT", "projectAssetRequirements", $requirementId, $AUTH->data['users_userid'], null, $projectsId);
finish(true, null, [
    "projectAssetRequirements_id" => $requirementId,
    "assetTypes_id" => $assetTypesId,
    "projectAssetRequirements_quantity" => $newQuantity,
    "availability" => projectAssetRequirementAvailability($project, (int) $assetTypesId),
    "packedCount" => projectAssetRequirementPackedCount($projectsId, (int) $assetTypesId),
    "reload" => true,
]);
