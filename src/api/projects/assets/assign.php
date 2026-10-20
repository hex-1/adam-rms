<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';
use Money\Currency;
use Money\Money;

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN") or !isset($_POST['projects_id'])) die("404");

$DBLIB->where("projects.instances_id", $AUTH->data['instance_ids'], 'IN');
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getone("projects", ["projects_id","projects_dates_deliver_start","projects_dates_deliver_end","projects_defaultDiscount","projects_name"]);
if (!$project) finish(false,["message"=>"Project not found"]);

$requirement = null;
// Dispatch can target either an existing requirement or just a section. A
// section target lets free-scan create or extend the appropriate requirement.
$selectedRequirementSectionId = filter_input(INPUT_POST, 'projectAssetRequirementSections_id', FILTER_VALIDATE_INT) ?: null;
if ($selectedRequirementSectionId) {
    $DBLIB->where('projectAssetRequirementSections_id', $selectedRequirementSectionId);
    $DBLIB->where('projects_id', $project['projects_id']);
    $DBLIB->where('projectAssetRequirementSections_deleted', 0);
    if (!$DBLIB->getOne('projectAssetRequirementSections', ['projectAssetRequirementSections_id'])) finish(false, ['message' => 'Selected section not found']);
}
if (isset($_POST['projectAssetRequirements_id'])) {
    $DBLIB->where('projectAssetRequirements_id', $_POST['projectAssetRequirements_id']);
    $DBLIB->where('projects_id', $project['projects_id']);
    $DBLIB->where('projectAssetRequirements_deleted', 0);
    $requirement = $DBLIB->getOne('projectAssetRequirements', ['projectAssetRequirements_id', 'assetTypes_id']);
    if (!$requirement) finish(false, ['message' => 'Planned asset not found']);
}

if ($project["projects_dates_deliver_start"] == null or $project["projects_dates_deliver_end"] == null or (strtotime($project["projects_dates_deliver_start"]) >= strtotime($project["projects_dates_deliver_end"]))) finish(false,["message"=>"Please set the dates for the project before attempting to assign assets"]);

$projectFinanceHelper = new projectFinance();
$projectFinanceCacher = new projectFinanceCacher($project['projects_id']);
$priceMaths = $projectFinanceHelper->durationMaths($project['projects_id']);

$assetRequiredFields = ["assetTypes_name","assets.assetTypes_id","assets_tag","assets_id","assets_dayRate","assets_weekRate","assetTypes_dayRate","assetTypes_weekRate","assetTypes_mass","assetTypes_value","assets_value","assets_mass","assets_assetGroups"];

if (isset($_POST['assetGroups_id'])) {
    $DBLIB->where("(users_userid IS NULL OR users_userid = '" . $AUTH->data['users_userid'] . "')");
    $DBLIB->where("instances_id",$AUTH->data['instance']["instances_id"]);
    $DBLIB->where("assetGroups_id", $_POST['assetGroups_id']);
    $DBLIB->where("assetGroups_deleted",0);
    $group = $DBLIB->getOne("assetGroups",["assetGroups_id"]);
    if (!$group) finish(false,["message"=>"Group not found"]);

    $DBLIB->where("FIND_IN_SET(" . $group['assetGroups_id'] . ", assets.assets_assetGroups)");
} elseif (isset($_POST['assets_id'])) $DBLIB->where("assets_id", $_POST['assets_id']);
elseif (isset($_POST['assetTypes_id'])) $DBLIB->where("assets.assetTypes_id", $_POST['assetTypes_id']);
elseif ($AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_ALL_BUSINESS_ASSETS")) {
    $DBLIB->where("(assets_linkedTo IS NULL)"); //We'll handle linked assets later in the script but for now add all assets
    $DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
} else finish(false,["message"=>"Cannot add all assets"]);
$DBLIB->where("(assets.assets_endDate IS NULL OR assets.assets_endDate >= '" . $project["projects_dates_deliver_end"] . "')");
$DBLIB->where("assets.instances_id", $AUTH->data['instance_ids'], 'IN');
$DBLIB->where("assets_deleted", 0);
$DBLIB->join("assetTypes","assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
$assetIDs = $DBLIB->get("assets", null, $assetRequiredFields);

function linkedAssets($assetId,$linkCount) {
    global $DBLIB,$assetsToProcess,$assetRequiredFields,$project,$assetsLinked;
    array_push($assetsLinked,$assetId);
    $DBLIB->where("assets.assets_linkedTo", $assetId);
    $DBLIB->where("assets.assets_id", $assetsLinked, "NOT IN"); // Make sure an asset is not double counted
    $DBLIB->where("assets.assets_deleted", 0);
    $DBLIB->where("(assets.assets_endDate IS NULL OR assets.assets_endDate >= '" . $project["projects_dates_deliver_end"] . "')");
    $DBLIB->join("assetTypes","assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $assets = $DBLIB->get("assets",null,$assetRequiredFields);
    foreach ($assets as $asset) {
        $asset['linkedto'] = $linkCount;
        $assetsToProcess[] = $asset;
        linkedAssets($asset['assets_id'],(count($assetsToProcess)-1));
    }
}
$assetsToProcess = [];
$assetsLinked = [];
foreach ($assetIDs as $asset) {
    $asset['linkedto'] = false;
    $assetsToProcess[] = $asset;
    linkedAssets($asset['assets_id'],(count($assetsToProcess)-1));
}
$assetsFailed = [];
$assetsProcessing = [];
foreach ($assetsToProcess as $asset) {
    // Linked accessories may be a different type; only the root asset fulfils
    // the requirement selected by the operator.
    if ($requirement && $asset['linkedto'] === false && (int) $asset['assetTypes_id'] !== (int) $requirement['assetTypes_id']) {
        finish(false, ['message' => 'That physical asset is not the required Asset Type']);
    }
    $DBLIB->where("assets_id", $asset['assets_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
    $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->where("(projects.projects_id = '" . $project['projects_id'] . "' OR projectsStatuses.projectsStatuses_assetsReleased = 0)");
    $DBLIB->where("((projects_dates_deliver_start >= '" . $project["projects_dates_deliver_start"] . "' AND projects_dates_deliver_start <= '" . $project["projects_dates_deliver_end"] . "') OR (projects_dates_deliver_end >= '" . $project["projects_dates_deliver_start"] . "' AND projects_dates_deliver_end <= '" . $project["projects_dates_deliver_end"] . "') OR (projects_dates_deliver_end >= '" . $project["projects_dates_deliver_end"] . "' AND projects_dates_deliver_start <= '" . $project["projects_dates_deliver_start"] . "'))");
    $assignment = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.projects_id"]);
    $flagsBlocks = assetFlagsAndBlocks($asset['assets_id']);
    if ($assignment or $flagsBlocks['COUNT']['BLOCK']>0) { //Can't assign anything with a block on it
        //It's got a clash so we can't assign it
        if (isset($_POST['assets_id']) and $_POST['assets_id'] == $asset['assets_id']) finish(false,["message"=>"Asset wanted not available"]); //Fail because the one we were supposed to assign hasn't worked
        $assetsFailed[] = ["assets_id" => $asset['assets_id']];
    } else {
        if (!$requirement && $selectedRequirementSectionId && $asset['linkedto'] === false) {
            // Reuse the section's requirement until its planned quantity is
            // full. Further free-scans deliberately increase that quantity.
            $DBLIB->where('projects_id', $project['projects_id']);
            $DBLIB->where('assetTypes_id', $asset['assetTypes_id']);
            $DBLIB->where('projectAssetRequirementSections_id', $selectedRequirementSectionId);
            $DBLIB->where('projectAssetRequirements_deleted', 0);
            $requirement = $DBLIB->getOne('projectAssetRequirements', ['projectAssetRequirements_id', 'assetTypes_id', 'projectAssetRequirements_quantity']);
            if ($requirement) {
                $DBLIB->where('projectAssetRequirements_id', $requirement['projectAssetRequirements_id']);
                $DBLIB->where('assetsAssignments_deleted', 0);
                $assignedToRequirement = (int) $DBLIB->getValue('assetsAssignments', 'COUNT(assetsAssignments_id)');
                if ($assignedToRequirement >= (int) $requirement['projectAssetRequirements_quantity']) {
                    $requirement['projectAssetRequirements_quantity']++;
                    $DBLIB->where('projectAssetRequirements_id', $requirement['projectAssetRequirements_id']);
                    if (!$DBLIB->update('projectAssetRequirements', ['projectAssetRequirements_quantity' => $requirement['projectAssetRequirements_quantity']])) finish(false, ['message' => 'Could not increase planned quantity']);
                }
            } else {
                $DBLIB->where('projects_id', $project['projects_id']);
                $DBLIB->where('projectAssetRequirementSections_id', $selectedRequirementSectionId);
                $rank = (int) $DBLIB->getValue('projectAssetRequirements', 'MAX(projectAssetRequirements_rank)') + 1;
                $requirementId = $DBLIB->insert('projectAssetRequirements', [
                    'projects_id' => $project['projects_id'], 'assetTypes_id' => $asset['assetTypes_id'],
                    'projectAssetRequirements_quantity' => 1, 'projectAssetRequirementSections_id' => $selectedRequirementSectionId,
                    'projectAssetRequirements_rank' => $rank, 'projectAssetRequirements_deleted' => 0,
                ]);
                if (!$requirementId) finish(false, ['message' => 'Could not create planned requirement']);
                $requirement = ['projectAssetRequirements_id' => $requirementId, 'assetTypes_id' => $asset['assetTypes_id']];
            }
        }
        $insertData = [
            "projects_id" => $project['projects_id'],
            "assets_id" => $asset['assets_id'],
            "assetsAssignments_deleted" => 0,
            "assetsAssignments_timestamp" => date('Y-m-d H:i:s'),
            "assetsAssignments_linkedTo" => ($asset['linkedto'] !== false ? $assetsProcessing[$asset['linkedto']]['insertedid'] : null),
            "assetsAssignments_discount" => ($asset['linkedto'] !== false ? $AUTH->data['instance']['instances_config_linkedDefaultDiscount'] : $project['projects_defaultDiscount'])
        ];
        if ($requirement && $asset['linkedto'] === false) {
            // Keep the planning note with the physical assignment so warehouse
            // instructions survive once a particular unit has been chosen.
            $insertData['projectAssetRequirements_id'] = $requirement['projectAssetRequirements_id'];
            $DBLIB->where('projectAssetRequirements_id', $requirement['projectAssetRequirements_id']);
            $insertData['assetsAssignments_comment'] = $DBLIB->getValue('projectAssetRequirements', 'projectAssetRequirements_note');
        }
        $insert = $DBLIB->insert("assetsAssignments", $insertData);
        if ($insert) {
            //Calculate the maths changes needed for this assignment and add it to the project
            $projectFinanceCacher->adjust('projectsFinanceCache_mass',($asset['assets_mass'] !== null ? $asset['assets_mass'] : $asset['assetTypes_mass']));
            $projectFinanceCacher->adjust('projectsFinanceCache_value',new Money(($asset['assets_value'] !== null ? $asset['assets_value'] : $asset['assetTypes_value']), new Currency($AUTH->data['instance']['instances_config_currency'])));

            $price = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
            $price = $price->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['days']));
            $price = $price->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['weeks']));
            $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $price,false);

            //Thought a discount can't be set, there might be a default one from the project
            if ($insertData['assetsAssignments_discount'] > 0) $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $price->subtract($price->multiply(1 - ($insertData['assetsAssignments_discount'] / 100))));

            $asset['insertedid'] = $insert;

            $usersNotified = []; //If user follows multiple groups which this asset is in they'll be notified multiple times otherwise
            foreach (explode(",",$asset['assets_assetGroups']) as $group) {
                if (is_numeric($group)) {
                    foreach ($bCMS->usersWatchingGroup($group) as $user) {
                        if ($user != $AUTH->data['users_userid'] and !in_array($user,$usersNotified)) {
                            array_push($usersNotified,$user);
                            notify(18,$user, $AUTH->data['instance']['instances_id'], "Asset " . $bCMS->aTag($asset['assets_tag']) . " assigned to project", "Asset " . $bCMS->aTag($asset['assets_tag']) . " (" . $asset["assetTypes_name"] . ") has been added to the project " . $project['projects_name'] . " by " . $AUTH->data['users_name1'] . " " . $AUTH->data['users_name2']);
                        }
                    }
                }
            }

            $bCMS->auditLog("ASSIGN-ASSET", "assetsAssignments", $insert, $AUTH->data['users_userid'], null, $project['projects_id']);
        } elseif (isset($_POST['assets_id']) and $_POST['assets_id'] == $asset['assets_id']) finish(false,["message"=>"Cannot insert assignment"]); //Fail because the one we were supposed to assign hasn't worked
        else $assetsFailed[] = $asset;
    }
    $assetsProcessing[] = $asset;
}
if ($projectFinanceCacher->save()) finish(true, null, ["failed" => $assetsFailed]);
else finish(false,["message"=>"Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/assets/assign.php", 
 *     summary="Assign Asset to Project", 
 *     description="Assign an asset to a project  
Requires Instance Permission PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN
", 
 *     operationId="assignAssetToProject", 
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
 *     @OA\Response(
 *         response="404", 
 *         description="Permission Error",
 *     ), 
 *     @OA\Parameter(
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="assetGroups_id",
 *         in="query",
 *         description="Asset Group ID",
 *         required="false", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="assets_id",
 *         in="query",
 *         description="Asset ID",
 *         required="false", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 * )
 */
