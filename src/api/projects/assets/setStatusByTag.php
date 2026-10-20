<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMENT_STATUS") or !isset($_POST['projects_id']) or !isset($_POST['assetsAssignments_status']) or !isset($_POST['text']) or strlen($_POST['text']) < 1) finish(false, ["message" => "Missing required fields","code"=>"MISSINGFIELDS"]);

// Treat the printed asset tag and any active barcode as equivalent scanner
// input, while still keeping the lookup inside the current instance.
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("(assets.assets_tag = ? OR (assetsBarcodes.assetsBarcodes_value = ? AND assetsBarcodes.assetsBarcodes_deleted = 0))", [$_POST['text'], $_POST['text']]);
$DBLIB->join("assetsBarcodes", "assets.assets_id=assetsBarcodes.assets_id", "LEFT");
$asset = $DBLIB->getone("assets", ["assets.assets_id", "assets.assetTypes_id", "assets.instances_id"]);
if (!$asset) finish(false, ["message" => "Asset not found", "code" => "NOTFOUND"]);

$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$project = $DBLIB->getone("projects", ["projects.projects_id"]);
if (!$project) finish(false, ["message" => "Project not found", "code" => "NOTFOUND"]);

$DBLIB->where("assetsAssignments.assets_id", $asset['assets_id']);
$DBLIB->where("assetsAssignments.projects_id", $_POST['projects_id']);
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$assignment = $DBLIB->getone("assetsAssignments", ["assetsAssignments.assetsAssignments_id", "assetsAssignments.assetsAssignmentsStatus_id"]);
if (!$assignment) {
    $freeScan = isset($_POST['freeScan']) && $_POST['freeScan'] === '1';
    if (!$freeScan) {
        // Guarded scans may fill requirements, but must not silently add
        // unplanned stock or exceed the quantity the planner requested.
        $DBLIB->where("projects_id", $_POST['projects_id']);
        $DBLIB->where("assetTypes_id", $asset['assetTypes_id']);
        $DBLIB->where("projectAssetRequirements_deleted", 0);
        $requiredQuantity = (int) $DBLIB->getValue("projectAssetRequirements", "SUM(projectAssetRequirements_quantity)");

        $DBLIB->where("assetsAssignments.projects_id", $_POST['projects_id']);
        $DBLIB->where("assets.assetTypes_id", $asset['assetTypes_id']);
        $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
        $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
        $assignedQuantity = (int) $DBLIB->getValue("assetsAssignments", "COUNT(assetsAssignments.assetsAssignments_id)");

        if ($requiredQuantity < 1 || $assignedQuantity >= $requiredQuantity) {
            finish(false, [
                "message" => $requiredQuantity < 1 ? "This Asset Type is not required on this project" : "The planned quantity for this Asset Type is already fulfilled",
                "code" => "NOTREQUIRED",
                "assets_id" => $asset['assets_id'],
            ]);
        }
    }
    // Returning the asset id allows the browser to assign it and continue the
    // original status transition in the background.
    finish(false, ["message" => "Asset is required but not yet assigned to project", "code" => "NOTASSIGNED", "assets_id" => $asset['assets_id']]);
}

// Validate before the no-op check below, so a deleted status is always rejected even if the assignment already happens to be on it
$DBLIB->where("assetsAssignmentsStatus_id", $_POST['assetsAssignments_status']);
$DBLIB->where("instances_id", $asset['instances_id']); // Use the instance of the asset
$DBLIB->where("assetsAssignmentsStatus_deleted", 0);
$status = $DBLIB->getone("assetsAssignmentsStatus",["assetsAssignmentsStatus_id"]);
if (!$status or $status['assetsAssignmentsStatus_id'] == null) finish(false, ["message" => "Status not found","code"=>"STATUSNOTFOUND"]);

if ($assignment['assetsAssignmentsStatus_id'] == $_POST['assetsAssignments_status']) finish(true, null, ["assets_id" => $asset['assets_id']]); // No change

$DBLIB->where("assetsAssignments_id", $assignment['assetsAssignments_id']);
$update = $DBLIB->update("assetsAssignments", ["assetsAssignmentsStatus_id" => $status['assetsAssignmentsStatus_id']], 1);
if (!$update) finish(false, ["message" => "Asset not assigned to project","code"=>"NOTASSIGNED"]);
else {
    $bCMS->auditLog("EDIT-STATUS", "assetsAssignments", $assignment['assetsAssignments_id'] . " set from " . $assignment['assetsAssignmentsStatus_id'] . " to " . $status['assetsAssignmentsStatus_id'] . " by direct tag entry", $AUTH->data['users_userid'],null, $_POST['projects_id']);
    finish(true, null, ["assets_id" => $asset['assets_id']]);
}

/**
 *  @OA\Post(
 *      path="/projects/assets/setStatusByTag.php",
 *      summary="Set Asset Status by Tag",
 *      description="Set asset status for a project by the asset's tag",
 *      operationId="setStatusByTag",
 *      tags={"project_assets"},
 *      @OA\Response(
 *          response="200",
 *          description="Success",
 *          @OA\MediaType(
 *             mediaType="application/json", 
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
 *         ),
 *      ),
 *      @OA\Parameter(
 *          name="text",
 *          in="query",
 *          description="Value of the asset tag",
 *          required="true",
 *          @OA\Schema(
 *              type="string",
 *          ),
 *      ),
 *      @OA\Parameter(
 *          name="assetsAssignments_status",
 *          in="query",
 *          description="Status Id to set asset to",
 *          required="true",
 *          @OA\Schema(
 *              type="number",
 *          ),
 *      ),
 *  )
 */
