<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMENT_STATUS") or !isset($_POST['projects_id']) or !isset($_POST['assetsAssignments_status']) or !isset($_POST['text']) or strlen($_POST['text']) < 1) finish(false);

$hasType = isset($_POST['type']) && strlen($_POST['type']) > 0 && $_POST['type'] !== 'UNKNOWN';

//See if Barcode is in database - scope to current instance via assets join
$DBLIB->where("assetsBarcodes.assetsBarcodes_value", $_POST['text']);
if ($hasType) $DBLIB->where("assetsBarcodes.assetsBarcodes_type", $_POST['type']);
$DBLIB->where("assetsBarcodes.assetsBarcodes_deleted", 0);
$DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->join("assets", "assets.assets_id=assetsBarcodes.assets_id", "LEFT");
$barcode = $DBLIB->getone("assetsBarcodes", ["assetsBarcodes.assets_id", "assetsBarcodes.assetsBarcodes_id", "assets.assetTypes_id"]);
if ($barcode and $barcode['assets_id'] != null) {
    $scan = [
        "assetsBarcodes_id" => $barcode['assetsBarcodes_id'],
        "users_userid" => $AUTH->data['users_userid'],
        "assetsBarcodesScans_timestamp" => date('Y-m-d H:i:s'),
        "locationsBarcodes_id" => (isset($_POST['locationType']) && $_POST['locationType'] == "barcode" && isset($_POST['location']) ? $_POST['location'] : null),
        "location_assets_id" => (isset($_POST['locationType']) && $_POST['locationType'] == "asset" && isset($_POST['location']) ? $_POST['location'] : null),
        "assetsBarcodes_customLocation" => (isset($_POST['locationType']) && $_POST['locationType'] == "Custom" && isset($_POST['location']) ? $_POST['location'] : null)
    ];
    $DBLIB->insert("assetsBarcodesScans", $scan);

    $DBLIB->where("assetsAssignments.assets_id", $barcode['assets_id']);
    $DBLIB->where("assetsAssignments.projects_id", $_POST['projects_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
    $currentAssignment = $DBLIB->getOne("assetsAssignments", ["assetsAssignments.assetsAssignments_id", "assetsAssignmentsStatus_id"]);

    if (!$currentAssignment) {
        $freeScan = isset($_POST['freeScan']) && $_POST['freeScan'] === '1';
        if (!$freeScan) {
            // In guarded mode a scan is accepted only while this Asset Type
            // still has an unfulfilled planned quantity.
            $DBLIB->where("projects_id", $_POST['projects_id']);
            $DBLIB->where("assetTypes_id", $barcode['assetTypes_id']);
            $DBLIB->where("projectAssetRequirements_deleted", 0);
            $requiredQuantity = (int) $DBLIB->getValue("projectAssetRequirements", "SUM(projectAssetRequirements_quantity)");

            $DBLIB->where("assetsAssignments.projects_id", $_POST['projects_id']);
            $DBLIB->where("assets.assetTypes_id", $barcode['assetTypes_id']);
            $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
            $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
            $assignedQuantity = (int) $DBLIB->getValue("assetsAssignments", "COUNT(assetsAssignments.assetsAssignments_id)");

            if ($requiredQuantity < 1 || $assignedQuantity >= $requiredQuantity) {
                finish(false, [
                    "message" => $requiredQuantity < 1 ? "This Asset Type is not required on this project" : "The planned quantity for this Asset Type is already fulfilled",
                    "code" => "NOTREQUIRED",
                    "assets_id" => $barcode['assets_id'],
                ]);
            }
        }
        // The client handles NOTASSIGNED by assigning the unit, then repeating
        // the requested status change without interrupting the scan workflow.
        finish(false, ["message" => "Asset is required but not yet assigned to project", "code" => "NOTASSIGNED", "assets_id" => $barcode['assets_id']]);
    }

    // If the assignment already has the requested status, treat this as success (no-op)
    if ((int)$currentAssignment['assetsAssignmentsStatus_id'] === (int)$_POST['assetsAssignments_status']) {
        $bCMS->auditLog("EDIT-STATUS", "assetsAssignments", "set to " . $_POST['assetsAssignments_status'] . " by barcode scan", $AUTH->data['users_userid'], null, $_POST['projects_id']);
        finish(true, null, ["assets_id" => $barcode['assets_id']]);
    }

    // Validate that the requested status belongs to the current instance and is not deleted
    $DBLIB->where("assetsAssignmentsStatus_id", $_POST['assetsAssignments_status']);
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("assetsAssignmentsStatus_deleted", 0);
    $status = $DBLIB->getone("assetsAssignmentsStatus", ["assetsAssignmentsStatus_id"]);
    if (!$status or $status['assetsAssignmentsStatus_id'] == null) finish(false, ["message" => "Status not found", "code" => "STATUSNOTFOUND"]);

    // Update using the assignment ID while also asserting the originally matched
    // asset/project identity still holds, so a concurrent swap cannot cause this
    // scan to update the status of a replacement asset on the same assignment row.
    $DBLIB->where("assetsAssignments_id", $currentAssignment['assetsAssignments_id']);
    $DBLIB->where("assets_id", $barcode['assets_id']);
    $DBLIB->where("projects_id", $_POST['projects_id']);
    $DBLIB->where("assetsAssignments_deleted", 0);
    $assignment = $DBLIB->update("assetsAssignments", ["assetsAssignmentsStatus_id" => $status['assetsAssignmentsStatus_id']], 1);

    if (!$assignment) {
        finish(false, ["message" => "Asset not assigned to project", "code" => "NOTASSIGNED", "assets_id" => $barcode['assets_id']]);
    }

    // MySQL returns 0 affected rows when the row exists but the value is unchanged
    // (e.g. a concurrent scan already wrote this status). Re-read to distinguish a
    // genuine no-match from an idempotent write, avoiding a false NOTASSIGNED error.
    if ($DBLIB->count != 1) {
        $DBLIB->where("assetsAssignments_id", $currentAssignment['assetsAssignments_id']);
        $DBLIB->where("assets_id", $barcode['assets_id']);
        $DBLIB->where("projects_id", $_POST['projects_id']);
        $DBLIB->where("assetsAssignments_deleted", 0);
        $DBLIB->where("assetsAssignmentsStatus_id", $status['assetsAssignmentsStatus_id']);
        $confirm = $DBLIB->getone("assetsAssignments", ["assetsAssignments_id"]);
        if (!$confirm) finish(false, ["message" => "Asset not assigned to project", "code" => "NOTASSIGNED", "assets_id" => $barcode['assets_id']]);
    }

    $bCMS->auditLog("EDIT-STATUS", "assetsAssignments", "set to " . $_POST['assetsAssignments_status'] . " by barcode scan", $AUTH->data['users_userid'], null, $_POST['projects_id']);
    finish(true, null, ["assets_id" => $barcode['assets_id']]);
} else finish(false);

/** @OA\Post(
 *     path="/projects/assets/setStatusBarcode.php", 
 *     summary="Set Asset Assignment Status using Barcode", 
 *     description="Set the status for an asset assignment using a barcode  
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:ASSIGNMENT_STATUS
", 
 *     operationId="setAssetAssignmentStatusBarcode", 
 *     tags={"project_assets"}, 
 *     @OA\Response(
 *         response="200", 
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json", 
 *             @OA\Schema( 
 *                 type="object", 
 *                 @OA\Property(
 *                     property="result", 
 *                     type="boolean", 
 *                     description="Whether the request was successful",
 *                 ),
 *             ),
 *         ),
 *     ), 
 *     @OA\Parameter(
 *         name="text",
 *         in="query",
 *         description="barcode value",
 *         required="true", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="type",
 *         in="query",
 *         description="barcode type (optional - if not provided or set to UNKNOWN, searches by value only)",
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="locationType",
 *         in="query",
 *         description="location type",
 *         required="true", 
 *         @OA\Schema(
 *             type="enum"), 
 *         ), 
 *     @OA\Parameter(
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="assetsAssignments_status",
 *         in="query",
 *         description="Status ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 * )
 */
