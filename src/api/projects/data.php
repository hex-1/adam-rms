<?php
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) require_once __DIR__ . '/../apiHeadSecure.php'; //Only if it wasn't included from somewhere else
require_once __DIR__ . '/../../common/libs/bCMS/projectFinance.php';
require_once __DIR__ . '/../../common/libs/bCMS/projectAssetRequirements.php';
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Formatter\IntlMoneyFormatter;

$array = [];
if (isset($_POST['formData'])) {
    foreach ($_POST['formData'] as $item) {
        $array[$item['name']] = $item['value'];
        $_GET[$item['name']] = $item['value'];
    }
}
if (isset($_POST['id'])) $_GET['id'] = $_POST['id'];
if (!$AUTH->instancePermissionCheck("PROJECTS:VIEW") or !isset($_GET['id'])) finish(false);

//The project itself
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_GET['id']);
$DBLIB->join("projectsTypes", "projects.projectsTypes_id=projectsTypes.projectsTypes_id", "LEFT");
$DBLIB->join("clients", "projects.clients_id=clients.clients_id", "LEFT");
$DBLIB->join("users", "projects.projects_manager=users.users_userid", "LEFT");
$DBLIB->join("locations","locations.locations_id=projects.locations_id","LEFT");
$DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
$PAGEDATA['project'] = $DBLIB->getone("projects", ["projects.*", "projectsTypes.*", "clients.clients_id", "clients_name","clients_website","clients_email","clients_notes","clients_address","clients_phone","users.users_userid", "users.users_name1", "users.users_name2", "users.users_email","locations.locations_name","locations.locations_address","projectsStatuses.projectsStatuses_id", "projectsStatuses.projectsStatuses_name", "projectsStatuses.projectsStatuses_foregroundColour","projectsStatuses.projectsStatuses_backgroundColour","projectsStatuses.projectsStatuses_assetsReleased","projectsStatuses.projectsStatuses_description"]);
if (!$PAGEDATA['project']) $PAGEDATA['USE_TWIG_404'] ? die($TWIG->render('404.twig', $PAGEDATA)) : die("404");

//subprojects
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_parent_project_id", $_GET['id']);
$DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
$PAGEDATA['project']['subProjects'] = $DBLIB->get("projects", null, ["projects.*", "projectsStatuses.projectsStatuses_name", "projectsStatuses.projectsStatuses_foregroundColour","projectsStatuses.projectsStatuses_backgroundColour"]);

//Finances

//Payments and also
function projectFinancials($project) {
    global $DBLIB,$AUTH,$bCMS;
    $projectFinanceHelper = new projectFinance();
    $return = [];

    //create a formatter for money
    $numberFormatter = new \NumberFormatter('en_GB', \NumberFormatter::CURRENCY);
    $moneyFormatter = new IntlMoneyFormatter($numberFormatter, new ISOCurrencies());

    $DBLIB->where("payments.payments_deleted", 0);
    $DBLIB->orderBy("payments.payments_date", "ASC");
    $DBLIB->where("payments.projects_id", $project['projects_id']);
    $payments = $DBLIB->get("payments");
    $return['payments'] = ["received" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "sales" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "subHire" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "staff" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))]];
    foreach ($payments as $payment) {
        $payment['files'] = $bCMS->s3List(14, $payment['payments_id']);
        $key = false;
        switch ($payment['payments_type']) {
            case 1:
                $key = "received";
                break;
            case 2:
                $key = 'sales';
                break;
            case 3:
                $key = 'subHire';
                break;
            case 4:
                $key = 'staff';
                break;
        }
        if ($key) {
            $payment['payments_amount'] = new Money($payment['payments_amount'], new Currency($AUTH->data['instance']['instances_config_currency']));
            $payment['payments_amountTotal'] = $payment['payments_amount']->multiply($payment['payments_quantity']);
            $return['payments'][$key]['total'] = $payment['payments_amountTotal']->add($return['payments'][$key]['total']);
            $return['payments'][$key]['ledger'][] = $payment;
        } else throw new Exception("Unknown payment type found");
    }

    //Assets
    $DBLIB->where("projects_id", $project['projects_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->join("manufacturers", "manufacturers.manufacturers_id=assetTypes.manufacturers_id", "LEFT");
    $DBLIB->join("assetCategories", "assetTypes.assetCategories_id=assetCategories.assetCategories_id", "LEFT");
    $DBLIB->join("assetCategoriesGroups", "assetCategoriesGroups.assetCategoriesGroups_id=assetCategories.assetCategoriesGroups_id", "LEFT");
    $DBLIB->join("assetsAssignmentsStatus", "assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id", "LEFT");
    $DBLIB->orderBy("assetCategories.assetCategories_rank", "ASC");
    $DBLIB->orderBy("assetCategories.assetCategories_id", "ASC");
    $DBLIB->orderBy("assetTypes.assetTypes_id", "ASC");
    $DBLIB->orderBy("assets.assets_tag", "ASC");
    $DBLIB->where("assets.assets_deleted", 0);
    $assets = $DBLIB->get("assetsAssignments", null, ["assetCategories.assetCategories_rank","assetsAssignmentsStatus.assetsAssignmentsStatus_id", "assetsAssignmentsStatus.assetsAssignmentsStatus_order", "assetsAssignmentsStatus.assetsAssignmentsStatus_name","assetsAssignments.*", "manufacturers.manufacturers_name", "assetTypes.*", "assets.*", "assetCategories.assetCategories_name", "assetCategories.assetCategories_fontAwesome", "assetCategoriesGroups.assetCategoriesGroups_id", "assetCategoriesGroups.assetCategoriesGroups_name", "assets.instances_id"]);

    $return['assetsAssigned'] = [];
    $return['assetsAssignedSUB'] = [];
    $return['mass'] = 0.0; //TODO evaluate whether using floats for mass is a good idea....
    $return['value'] = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
    $return['prices'] = ["subTotal" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])), "discounts" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])), "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))];

    $return['priceMaths'] = $projectFinanceHelper->durationMaths($project['projects_id']);
    foreach ($assets as $asset) {
        $return['mass'] += ($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']);
        $asset['value'] = new Money(($asset['assets_value'] != null ? $asset['assets_value'] : $asset['assetTypes_value']), new Currency($AUTH->data['instance']['instances_config_currency']));
        $return['value'] = $return['value']->add($asset['value']);

        if ($asset['assetsAssignments_customPrice'] == null) {
            //The actual pricing calculator
            $asset['price'] = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
            $asset['price'] = $asset['price']->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($return['priceMaths']['days']));
            $asset['price'] = $asset['price']->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($return['priceMaths']['weeks']));
        } else $asset['price'] = new Money($asset['assetsAssignments_customPrice'],new Currency($AUTH->data['instance']['instances_config_currency']));

        $return['prices']['subTotal'] = $asset['price']->add($return['prices']['subTotal']);

        if ($asset['assetsAssignments_discount'] > 0) $asset['discountPrice'] = $asset['price']->multiply(1 - ($asset['assetsAssignments_discount'] / 100));
        else $asset['discountPrice'] = $asset['price'];

        $return['prices']['discounts'] = $return['prices']['discounts']->add($asset['price']->subtract($asset['discountPrice']));
        $return['prices']['total'] = $return['prices']['total']->add($asset['discountPrice']);

        //Formatted values for each asset
        $asset['formattedValue'] = $moneyFormatter->format($asset['value']);
        $asset['formattedPrice'] = $moneyFormatter->format($asset['price']);
        $asset['formattedDiscountPrice'] = $moneyFormatter->format($asset['discountPrice']);
        $asset['formattedMass'] = number_format(($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']), 2, '.', '') . "kg";

        $asset['flagsblocks'] = assetFlagsAndBlocks($asset['assets_id']);

        $asset['assetTypes_definableFields_ARRAY'] = array_filter(explode(",", $asset['assetTypes_definableFields']));

        $asset['latestScan'] = assetLatestScan($asset['assets_id']);

        if ($asset['instances_id'] != $project['instances_id']) {
            if (!isset($return['assetsAssignedSUB'][$asset['instances_id']]['assets'])) $return['assetsAssignedSUB'][$asset['instances_id']]['assets'] = [];
            if (!isset($return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']])) $return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']]['assets'] = [];
            $return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']]['assets'][] = $asset;
        } else {
            if (!isset($return['assetsAssigned'][$asset['assetTypes_id']])) $return['assetsAssigned'][$asset['assetTypes_id']]['assets'] = [];
            $return['assetsAssigned'][$asset['assetTypes_id']]['assets'][] = $asset;
        }
    }
    foreach ($return['assetsAssigned'] as $key => $type) {
        if (!isset($return['assetsAssigned'][$key]['totals'])) $return['assetsAssigned'][$key]['totals'] = ["status" => null,"discountPrice"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"price"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"mass"=>0.0];
        foreach ($type['assets'] as $asset) {
            if ($return['assetsAssigned'][$key]['totals']['status'] == null) $return['assetsAssigned'][$key]['totals']['status'] = $asset['assetsAssignmentsStatus_name'];
            elseif ($return['assetsAssigned'][$key]['totals']['status'] != $asset['assetsAssignmentsStatus_name']) $return['assetsAssigned'][$key]['totals']['status'] = false; //They aren't all the same
            $return['assetsAssigned'][$key]['totals']['discountPrice'] = $return['assetsAssigned'][$key]['totals']['discountPrice']->add($asset['discountPrice']);
            $return['assetsAssigned'][$key]['totals']['price'] = $return['assetsAssigned'][$key]['totals']['price']->add($asset['price']);
            $return['assetsAssigned'][$key]['totals']['mass'] += ($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']);
        }
        //formatted Totals
        $return['assetsAssigned'][$key]['totals']['formattedDiscountPrice'] = $moneyFormatter->format($return['assetsAssigned'][$key]['totals']['discountPrice']);
        $return['assetsAssigned'][$key]['totals']['formattedPrice'] = $moneyFormatter->format($return['assetsAssigned'][$key]['totals']['price']);
        $return['assetsAssigned'][$key]['totals']['formattedMass'] = number_format($return['assetsAssigned'][$key]['totals']['mass'], 2, '.', '') . "kg";
    }
    foreach ($return['assetsAssignedSUB'] as $instanceid => $instance) {
        if (!isset($return['assetsAssignedSUB'][$instanceid]['instance'])) {
            $DBLIB->where("instances_id",$instanceid);
            $return['assetsAssignedSUB'][$instanceid]['instance'] = $DBLIB->getone("instances",["instances_id","instances_name"]);
        }
        foreach ($return['assetsAssignedSUB'][$instanceid]['assets'] as $key => $type) {
            if (!isset($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals'])) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals'] = ["status" => null,"discountPrice"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"price"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"mass"=>0.0];
            foreach ($type['assets'] as $asset) {
                if ($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] == null) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] = $asset['assetsAssignmentsStatus_name'];
                elseif ($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] != $asset['assetsAssignmentsStatus_name']) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] = false; //They aren't all the same
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice'] = $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice']->add($asset['discountPrice']);
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price'] = $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price']->add($asset['price']);
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['mass'] += ($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']);
            }
            //Formatted totals
            $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['formattedDiscountPrice'] = $moneyFormatter->format($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice']);
            $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['formattedPrice'] = $moneyFormatter->format($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price']);
            $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['formattedMass'] = number_format($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['mass'], 2, '.', '') . "kg";
        }
    }

    $return['payments']['subTotal'] = $return['prices']['total']->add($return['payments']['sales']['total'],$return['payments']['subHire']['total'],$return['payments']['staff']['total']);
    $return['payments']['total'] = $return['payments']['subTotal']->subtract($return['payments']['received']['total']);

    //add formatted values to everything
    $return['formattedValue'] = $moneyFormatter->format($return['value']);
    $return['formattedPrices'] = ["subTotal" => $moneyFormatter->format($return['prices']['subTotal']), "discounts" => $moneyFormatter->format($return['prices']['discounts']), "total" => $moneyFormatter->format($return['prices']['total'])];
    $return['formattedMass'] = number_format($return['mass'], 2, '.', '') . "kg";
    //format payments
    foreach ($return['payments'] as $key => $value) {
        if ($key == "subTotal") {
            $return['payments']['formattedSubTotal'] = $moneyFormatter->format($return['payments']["subTotal"]);
        } elseif ($key == "total"){
            $return['payments']['formattedTotal'] = $moneyFormatter->format($return['payments']["total"]);
        } else {
            $return['payments'][$key]['formattedTotal'] = $moneyFormatter->format($return['payments'][$key]['total']);
        }
    }
    
    return $return;
}
$PAGEDATA['FINANCIALS'] = projectFinancials($PAGEDATA['project']);
$DBLIB->where("projects_id",$PAGEDATA['project']['projects_id']);
$DBLIB->orderBy("projectsFinanceCache_timestamp", "DESC");
$projectFinanceCache = $DBLIB->getone("projectsFinanceCache");
$projectFinancesCacheMismatch = false;
if (!$projectFinanceCache) {
    //Insert a new project finance cache for this project as it doesn't seem to have one hmmm
    $projectFinanceCacheInsert = [
        "projects_id" => $PAGEDATA['project']['projects_id'],
        "projectsFinanceCache_timestamp" => date("Y-m-d H:i:s"),
        "projectsFinanceCache_equipmentSubTotal" =>$PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount(),
        "projectsFinanceCache_equiptmentDiscounts" =>$PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount(),
        "projectsFinanceCache_equiptmentTotal" =>$PAGEDATA['FINANCIALS']['prices']['total']->getAmount(),
        "projectsFinanceCache_salesTotal" =>$PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount(),
        "projectsFinanceCache_staffTotal" =>$PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount(),
        "projectsFinanceCache_externalHiresTotal" => $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount(),
        "projectsFinanceCache_paymentsReceived" =>$PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount(),
        "projectsFinanceCache_grandTotal" =>$PAGEDATA['FINANCIALS']['payments']['total']->getAmount(),
        "projectsFinanceCache_mass"=>$PAGEDATA['FINANCIALS']['mass'],
        "projectsFinanceCache_value"=>$PAGEDATA['FINANCIALS']['value']->getAmount(),
    ];
    $DBLIB->insert("projectsFinanceCache", $projectFinanceCacheInsert); //Add a cache for the finance of the project
//Just check the cache while we're here - shouldn't ever be thrown!
} elseif ($projectFinanceCache["projectsFinanceCache_equipmentSubTotal"] != $PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_equiptmentDiscounts"] != $PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_equiptmentTotal"] != $PAGEDATA['FINANCIALS']['prices']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_salesTotal"] != $PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_staffTotal"] != $PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_externalHiresTotal"] !=  $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_paymentsReceived"] != $PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_grandTotal"] != $PAGEDATA['FINANCIALS']['payments']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_value"] != $PAGEDATA['FINANCIALS']['value']->getAmount()) $projectFinancesCacheMismatch = true;
elseif (round($projectFinanceCache["projectsFinanceCache_mass"]*100000) != round($PAGEDATA['FINANCIALS']['mass']*100000)) $projectFinancesCacheMismatch = true;

if ($projectFinancesCacheMismatch) {
    //So there's a serious project finance mismatch - you can force a rebuild of the cache by passing a get parameter - otherwise throw an error so support are notified
    $projectFinanceCacheInsert = [
        "projects_id" => $PAGEDATA['project']['projects_id'],
        "projectsFinanceCache_timestamp" => date("Y-m-d H:i:s"),
        "projectsFinanceCache_equipmentSubTotal" =>$PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount(),
        "projectsFinanceCache_equiptmentDiscounts" =>$PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount(),
        "projectsFinanceCache_equiptmentTotal" =>$PAGEDATA['FINANCIALS']['prices']['total']->getAmount(),
        "projectsFinanceCache_salesTotal" =>$PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount(),
        "projectsFinanceCache_staffTotal" =>$PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount(),
        "projectsFinanceCache_externalHiresTotal" => $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount(),
        "projectsFinanceCache_paymentsReceived" =>$PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount(),
        "projectsFinanceCache_grandTotal" =>$PAGEDATA['FINANCIALS']['payments']['total']->getAmount(),
        "projectsFinanceCache_mass"=>$PAGEDATA['FINANCIALS']['mass'],
        "projectsFinanceCache_value"=>$PAGEDATA['FINANCIALS']['value']->getAmount(),
    ];
    $newCache = $DBLIB->insert("projectsFinanceCache", $projectFinanceCacheInsert); //Add a cache for the finance of the project
    if(!$newCache) throw new \Exception('Cache reload error');
    else trigger_error("Project finance cache mismatch " . json_encode($projectFinanceCacheInsert) . " vs " . json_encode($projectFinanceCache), E_USER_WARNING);
}



usort($PAGEDATA['FINANCIALS']['payments']['subHire']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});
usort($PAGEDATA['FINANCIALS']['payments']['sales']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});
usort($PAGEDATA['FINANCIALS']['payments']['staff']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});

//Notes
$DBLIB->where("projectsNotes_deleted", 0);
$DBLIB->where("projects_id", $PAGEDATA['project']['projects_id']);
$DBLIB->orderBy("projectsNotes_id", "ASC");
$PAGEDATA['project']['notes'] = $DBLIB->get("projectsNotes");

//Crew
$DBLIB->where("projects_id", $PAGEDATA['project']['projects_id']);
$DBLIB->where("crewAssignments.crewAssignments_deleted", 0);
$DBLIB->join("users", "crewAssignments.users_userid=users.users_userid", "LEFT");
$DBLIB->orderBy("crewAssignments.crewAssignments_rank", "ASC");
$DBLIB->orderBy("crewAssignments.crewAssignments_id", "ASC");
$PAGEDATA['project']['crewAssignments'] = $DBLIB->get("crewAssignments", null, ["crewAssignments.*", "users.users_name1", "users.users_name2", "users.users_email"]);

// Asset Type requirements are planning records only. Physical assets remain in
// assetsAssignments so dispatch, scans and the original board continue to work.
$DBLIB->where("projectAssetRequirements.projects_id", $PAGEDATA['project']['projects_id']);
$DBLIB->where("projectAssetRequirements.projectAssetRequirements_deleted", 0);
$DBLIB->join("assetTypes", "projectAssetRequirements.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
$DBLIB->join("manufacturers", "assetTypes.manufacturers_id=manufacturers.manufacturers_id", "LEFT");
$DBLIB->orderBy("assetTypes.assetTypes_name", "ASC");
$PAGEDATA['project']['assetRequirements'] = $DBLIB->get("projectAssetRequirements", null, [
    "projectAssetRequirements.*", "assetTypes.assetTypes_name", "assetTypes.assetTypes_dayRate", "assetTypes.assetTypes_weekRate", "assetTypes.assetTypes_mass", "assetTypes.assetTypes_value", "assetTypes.manufacturers_id", "manufacturers.manufacturers_name",
]);
$packedRemainingByType = [];
foreach ($PAGEDATA['project']['assetRequirements'] as &$assetRequirement) {
    $periodPrice = $assetRequirement['projectAssetRequirements_periodPrice'];
    if ($periodPrice === null) {
        $periodPrice = ((int) $assetRequirement['assetTypes_dayRate'] * (int) $PAGEDATA['FINANCIALS']['priceMaths']['days'])
            + ((int) $assetRequirement['assetTypes_weekRate'] * (int) $PAGEDATA['FINANCIALS']['priceMaths']['weeks']);
    }
    $assetRequirement['periodPrice'] = new Money((int) $periodPrice, new Currency($AUTH->data['instance']['instances_config_currency']));
    $assetRequirement['totalPrice'] = $assetRequirement['periodPrice']
        ->multiply((int) $assetRequirement['projectAssetRequirements_quantity'])
        ->multiply(1 - ((float) $assetRequirement['projectAssetRequirements_discount'] / 100));
    $assetRequirement['availability'] = projectAssetRequirementAvailability(
        $PAGEDATA['project'],
        (int) $assetRequirement['assetTypes_id']
    );
    $availabilityRowColours = [
        'shortage' => 'rgba(220, 53, 69, 0.18)',
        'turnaround' => 'rgba(253, 126, 20, 0.18)',
        'low-stock' => 'rgba(255, 193, 7, 0.18)',
        'unknown' => 'rgba(108, 117, 125, 0.10)',
        'available' => 'transparent',
    ];
    $assetRequirement['availability']['rowColour'] = $availabilityRowColours[$assetRequirement['availability']['status']] ?? 'transparent';
    $typeId = (int) $assetRequirement['assetTypes_id'];
    if (!isset($packedRemainingByType[$typeId])) {
        $packedRemainingByType[$typeId] = projectAssetRequirementPackedCount((int) $PAGEDATA['project']['projects_id'], $typeId);
    }
}
unset($assetRequirement);

$DBLIB->where('projects_id', $PAGEDATA['project']['projects_id']);
$DBLIB->where('projectAssetRequirementSections_deleted', 0);
$DBLIB->orderBy('projectAssetRequirementSections_rank', 'ASC');
$DBLIB->orderBy('projectAssetRequirementSections_id', 'ASC');
$PAGEDATA['project']['assetRequirementSections'] = $DBLIB->get('projectAssetRequirementSections');

$sectionsByParent = [];
foreach ($PAGEDATA['project']['assetRequirementSections'] as $section) {
    $parentKey = $section['projectAssetRequirementSections_parent_id'] ?: 0;
    $sectionsByParent[$parentKey][] = $section;
}
$requirementsBySection = [];
foreach ($PAGEDATA['project']['assetRequirements'] as $requirement) {
    $sectionKey = $requirement['projectAssetRequirementSections_id'] ?: 0;
    $requirementsBySection[$sectionKey][] = $requirement;
}
foreach ($requirementsBySection as &$sectionRequirements) {
    usort($sectionRequirements, function ($left, $right) {
        $rankComparison = ((int) $left['projectAssetRequirements_rank']) <=> ((int) $right['projectAssetRequirements_rank']);
        return $rankComparison ?: (((int) $left['projectAssetRequirements_id']) <=> ((int) $right['projectAssetRequirements_id']));
    });
}
unset($sectionRequirements);

// Split physical assignments into explicit requirement links and legacy
// type-only assignments. Legacy rows are consumed into matching requirements
// later, which makes existing jobs usable without a data migration.
$assignedAssetsByRequirement = [];
$unlinkedAssignedAssetsByType = [];
$linkedAssetsByParentAssignment = [];
$allAssignedAssetGroups = $PAGEDATA['FINANCIALS']['assetsAssigned'];
foreach ($PAGEDATA['FINANCIALS']['assetsAssignedSUB'] as $subInstance) {
    foreach ($subInstance['assets'] as $typeId => $type) {
        foreach ($type['assets'] ?? [] as &$subAsset) $subAsset['sourceInstanceName'] = $subInstance['instance']['instances_name'] ?? 'Another unit';
        unset($subAsset);
        if (!isset($allAssignedAssetGroups[$typeId])) $allAssignedAssetGroups[$typeId] = ['assets' => []];
        $allAssignedAssetGroups[$typeId]['assets'] = array_merge($allAssignedAssetGroups[$typeId]['assets'] ?? [], $type['assets']);
    }
}
foreach ($allAssignedAssetGroups as $type) {
    foreach ($type['assets'] ?? [] as $assignedAsset) {
        $statusName = (string) ($assignedAsset['assetsAssignmentsStatus_name'] ?? '');
        $normalisedStatusName = strtolower($statusName);
        $assignedAsset['displayStatus'] = $statusName ?: 'Allocated';
        $statusColours = ['prepared' => '#ffc107', 'packed' => '#28a745', 'dispatched' => '#dc3545', 'stored' => '#6c757d'];
        $assignedAsset['statusColour'] = $statusColours[$normalisedStatusName] ?? ($statusName ? '#17a2b8' : '#6c757d');
        $assignedAsset['statusTextColour'] = $normalisedStatusName === 'prepared' ? '#212529' : '#fff';
        if (!empty($assignedAsset['assetsAssignments_linkedTo'])) {
            $linkedAssetsByParentAssignment[(int) $assignedAsset['assetsAssignments_linkedTo']][] = $assignedAsset;
            continue;
        }
        $linkedRequirementId = (int) ($assignedAsset['projectAssetRequirements_id'] ?? 0);
        if ($linkedRequirementId) $assignedAssetsByRequirement[$linkedRequirementId][] = $assignedAsset;
        else $unlinkedAssignedAssetsByType[(int) $assignedAsset['assetTypes_id']][] = $assignedAsset;
    }
}
// Linked accessories are still stored using AdamRMS's physical asset links.
// Flattening them here gives the template a simple ordered list while retaining
// enough depth information to render the parent/accessory relationship.
$flattenAssignedAssetTree = function (array $assets, int $depth = 0) use (&$flattenAssignedAssetTree, &$linkedAssetsByParentAssignment): array {
    $flattened = [];
    foreach ($assets as $asset) {
        $asset['linkedDepth'] = $depth;
        $asset['isLinkedAsset'] = $depth > 0;
        $flattened[] = $asset;
        $children = $linkedAssetsByParentAssignment[(int) $asset['assetsAssignments_id']] ?? [];
        if ($children) $flattened = array_merge($flattened, $flattenAssignedAssetTree($children, $depth + 1));
    }
    return $flattened;
};
foreach ($assignedAssetsByRequirement as &$requirementAssets) $requirementAssets = $flattenAssignedAssetTree($requirementAssets);
unset($requirementAssets);
foreach ($unlinkedAssignedAssetsByType as &$typeAssets) $typeAssets = $flattenAssignedAssetTree($typeAssets);
unset($typeAssets);

$outline = [];
$sectionOptions = [];
$availabilitySeverity = ['available' => 0, 'unknown' => 1, 'low-stock' => 2, 'turnaround' => 3, 'shortage' => 4];
$projectCurrency = new Currency($AUTH->data['instance']['instances_config_currency']);
// Walk the section tree once and calculate every roll-up used by the page.
// Keeping these figures together avoids subtly different definitions of
// "section total" in the table, summary, print view and export.
$walkOutline = function (int $parentId, int $depth, string $parentPath = '') use (&$walkOutline, &$outline, &$sectionOptions, &$sectionsByParent, &$requirementsBySection, &$packedRemainingByType, &$assignedAssetsByRequirement, &$unlinkedAssignedAssetsByType, $availabilitySeverity, $projectCurrency) {
    $totals = ['packed' => 0, 'required' => 0, 'price' => 0, 'value' => 0, 'unknownValue' => 0, 'mass' => 0.0, 'unknownMass' => 0, 'blockedAssets' => 0, 'flaggedAssets' => 0, 'shortages' => 0, 'availability' => ['status' => 'available', 'colour' => '#28a745', 'label' => 'Available']];
    $entries = [];
    foreach ($requirementsBySection[$parentId] ?? [] as $requirement) {
        $entries[] = ['kind' => 'requirement', 'rank' => (int) $requirement['projectAssetRequirements_rank'], 'id' => (int) $requirement['projectAssetRequirements_id'], 'data' => $requirement];
    }
    foreach ($sectionsByParent[$parentId] ?? [] as $section) {
        $entries[] = ['kind' => 'section', 'rank' => (int) $section['projectAssetRequirementSections_rank'], 'id' => (int) $section['projectAssetRequirementSections_id'], 'data' => $section];
    }
    usort($entries, function ($left, $right) {
        $rankComparison = $left['rank'] <=> $right['rank'];
        if ($rankComparison) return $rankComparison;
        $kindComparison = ($left['kind'] === 'requirement' ? 0 : 1) <=> ($right['kind'] === 'requirement' ? 0 : 1);
        return $kindComparison ?: ($left['id'] <=> $right['id']);
    });
    foreach ($entries as $entry) {
        if ($entry['kind'] === 'requirement') {
            $requirement = $entry['data'];
            $typeId = (int) $requirement['assetTypes_id'];
            $quantity = (int) $requirement['projectAssetRequirements_quantity'];
            // A manual packed override represents the total fulfilled quantity,
            // not extra items on top of physical scans. max() prevents the same
            // cable being counted twice when scanning catches up later.
            $physicalPackedCount = min($quantity, $packedRemainingByType[$typeId] ?? 0);
            $manualPackedCount = min($quantity, (int) ($requirement['projectAssetRequirements_packedOverride'] ?? 0));
            $requirement['packedCount'] = max($physicalPackedCount, $manualPackedCount);
            $packedRemainingByType[$typeId] = max(0, ($packedRemainingByType[$typeId] ?? 0) - $physicalPackedCount);
            $requirementId = (int) $requirement['projectAssetRequirements_id'];
            $requirement['assignedAssets'] = $assignedAssetsByRequirement[$requirementId] ?? [];
            $legacySlots = max(0, (int) $requirement['projectAssetRequirements_quantity'] - count($requirement['assignedAssets']));
            if ($legacySlots && !empty($unlinkedAssignedAssetsByType[$typeId])) {
                $requirement['assignedAssets'] = array_merge(
                    $requirement['assignedAssets'],
                    array_splice($unlinkedAssignedAssetsByType[$typeId], 0, $legacySlots)
                );
            }
            // Planning totals use individual overrides for allocated assets and
            // fall back to the Asset Type estimate for unallocated slots. Linked
            // accessories contribute their own mass/value as additional kit.
            $requirement['totalMass'] = 0.0;
            $requirement['unknownMassCount'] = 0;
            $requirement['totalValue'] = 0;
            $requirement['unknownValueCount'] = 0;
            $requirement['blockedAssetCount'] = 0;
            $requirement['flaggedAssetCount'] = 0;
            $allocatedRootCount = 0;
            foreach ($requirement['assignedAssets'] as $assignedAsset) {
                if ((int) ($assignedAsset['linkedDepth'] ?? 0) === 0) $allocatedRootCount++;
                $assetMass = $assignedAsset['assets_mass'] !== null ? $assignedAsset['assets_mass'] : ($assignedAsset['assetTypes_mass'] ?? null);
                if ($assetMass === null || (float) $assetMass <= 0) $requirement['unknownMassCount']++;
                else $requirement['totalMass'] += (float) $assetMass;
                $assetValue = $assignedAsset['assets_value'] !== null ? $assignedAsset['assets_value'] : ($assignedAsset['assetTypes_value'] ?? null);
                if ($assetValue === null || (int) $assetValue <= 0) $requirement['unknownValueCount']++;
                else $requirement['totalValue'] += (int) $assetValue;
                if (!empty($assignedAsset['flagsblocks']['COUNT']['BLOCK'])) $requirement['blockedAssetCount']++;
                if (!empty($assignedAsset['flagsblocks']['COUNT']['FLAG'])) $requirement['flaggedAssetCount']++;
            }
            // Only root assets fulfil requirement slots; linked accessories do
            // not reduce the remaining planned quantity.
            $unallocatedCount = max(0, $quantity - $allocatedRootCount);
            if ($unallocatedCount) {
                if ($requirement['assetTypes_mass'] === null || (float) $requirement['assetTypes_mass'] <= 0) $requirement['unknownMassCount'] += $unallocatedCount;
                else $requirement['totalMass'] += $unallocatedCount * (float) $requirement['assetTypes_mass'];
                if ($requirement['assetTypes_value'] === null || (int) $requirement['assetTypes_value'] <= 0) $requirement['unknownValueCount'] += $unallocatedCount;
                else $requirement['totalValue'] += $unallocatedCount * (int) $requirement['assetTypes_value'];
            }
            $outline[] = ['kind' => 'requirement', 'depth' => $depth, 'requirement' => $requirement];
            $totals['packed'] += (int) $requirement['packedCount'];
            $totals['required'] += (int) $requirement['projectAssetRequirements_quantity'];
            $totals['price'] += (int) $requirement['totalPrice']->getAmount();
            $totals['mass'] += $requirement['totalMass'];
            $totals['unknownMass'] += $requirement['unknownMassCount'];
            $totals['value'] += $requirement['totalValue'];
            $totals['unknownValue'] += $requirement['unknownValueCount'];
            $totals['blockedAssets'] += $requirement['blockedAssetCount'];
            $totals['flaggedAssets'] += $requirement['flaggedAssetCount'];
            if ($requirement['availability']['status'] === 'shortage') $totals['shortages']++;
            if (($availabilitySeverity[$requirement['availability']['status']] ?? 0) > ($availabilitySeverity[$totals['availability']['status']] ?? 0)) {
                $totals['availability'] = $requirement['availability'];
            }
            continue;
        }
        $section = $entry['data'];
        $name = $section['projectAssetRequirementSections_name'];
        $path = $parentPath ? $parentPath . ' › ' . $name : $name;
        $sectionOutlineIndex = count($outline);
        $outline[] = ['kind' => 'section', 'depth' => $depth, 'section' => $section, 'packedCount' => 0, 'requiredCount' => 0, 'totalPrice' => new Money(0, $projectCurrency), 'totalValue' => new Money(0, $projectCurrency), 'unknownValueCount' => 0, 'totalMass' => 0.0, 'unknownMassCount' => 0, 'blockedAssetCount' => 0, 'flaggedAssetCount' => 0, 'availability' => ['status' => 'unknown', 'colour' => '#6c757d', 'label' => 'No planned assets']];
        $sectionOptions[] = ['id' => $section['projectAssetRequirementSections_id'], 'name' => $name, 'path' => $path, 'depth' => $depth];
        $sectionTotals = $walkOutline((int) $section['projectAssetRequirementSections_id'], $depth + 1, $path);
        $outline[$sectionOutlineIndex]['packedCount'] = $sectionTotals['packed'];
        $outline[$sectionOutlineIndex]['requiredCount'] = $sectionTotals['required'];
        $outline[$sectionOutlineIndex]['totalPrice'] = new Money($sectionTotals['price'], $projectCurrency);
        $outline[$sectionOutlineIndex]['totalMass'] = $sectionTotals['mass'];
        $outline[$sectionOutlineIndex]['unknownMassCount'] = $sectionTotals['unknownMass'];
        $outline[$sectionOutlineIndex]['totalValue'] = new Money($sectionTotals['value'], $projectCurrency);
        $outline[$sectionOutlineIndex]['unknownValueCount'] = $sectionTotals['unknownValue'];
        $outline[$sectionOutlineIndex]['blockedAssetCount'] = $sectionTotals['blockedAssets'];
        $outline[$sectionOutlineIndex]['flaggedAssetCount'] = $sectionTotals['flaggedAssets'];
        $outline[$sectionOutlineIndex]['availability'] = $sectionTotals['required'] ? $sectionTotals['availability'] : ['status' => 'unknown', 'colour' => '#6c757d', 'label' => 'No planned assets'];
        $totals['packed'] += $sectionTotals['packed'];
        $totals['required'] += $sectionTotals['required'];
        $totals['price'] += $sectionTotals['price'];
        $totals['mass'] += $sectionTotals['mass'];
        $totals['unknownMass'] += $sectionTotals['unknownMass'];
        $totals['value'] += $sectionTotals['value'];
        $totals['unknownValue'] += $sectionTotals['unknownValue'];
        $totals['blockedAssets'] += $sectionTotals['blockedAssets'];
        $totals['flaggedAssets'] += $sectionTotals['flaggedAssets'];
        $totals['shortages'] += $sectionTotals['shortages'];
        if (($availabilitySeverity[$sectionTotals['availability']['status']] ?? 0) > ($availabilitySeverity[$totals['availability']['status']] ?? 0)) {
            $totals['availability'] = $sectionTotals['availability'];
        }
    }
    return $totals;
};
$outlineTotals = $walkOutline(0, 0);

$directGroupsByCategory = [];
// Assets added through AdamRMS's original assignment screen do not have a
// projectAssetRequirements_id. Matching ones are consumed by planned rows
// above; anything left must still be visible in the replacement assets list.
foreach ($unlinkedAssignedAssetsByType as $typeId => $directAssets) {
    if (!$directAssets) continue;
    $firstAsset = $directAssets[0];
    $rootCount = 0;
    $totalMass = 0.0;
    $unknownMassCount = 0;
    $totalValue = 0;
    $unknownValueCount = 0;
    $blockedAssetCount = 0;
    $flaggedAssetCount = 0;
    $totalPrice = new Money(0, $projectCurrency);
    foreach ($directAssets as $directAsset) {
        if ((int) ($directAsset['linkedDepth'] ?? 0) === 0) $rootCount++;
        $assetMass = $directAsset['assets_mass'] !== null ? $directAsset['assets_mass'] : ($directAsset['assetTypes_mass'] ?? null);
        if ($assetMass === null || (float) $assetMass <= 0) $unknownMassCount++;
        else $totalMass += (float) $assetMass;
        $assetValue = $directAsset['assets_value'] !== null ? $directAsset['assets_value'] : ($directAsset['assetTypes_value'] ?? null);
        if ($assetValue === null || (int) $assetValue <= 0) $unknownValueCount++;
        else $totalValue += (int) $assetValue;
        if (!empty($directAsset['flagsblocks']['COUNT']['BLOCK'])) $blockedAssetCount++;
        if (!empty($directAsset['flagsblocks']['COUNT']['FLAG'])) $flaggedAssetCount++;
        if (isset($directAsset['discountPrice'])) $totalPrice = $totalPrice->add($directAsset['discountPrice']);
    }
    if (!isset($packedRemainingByType[$typeId])) {
        $packedRemainingByType[$typeId] = projectAssetRequirementPackedCount((int) $PAGEDATA['project']['projects_id'], (int) $typeId);
    }
    $packedCount = min($rootCount, $packedRemainingByType[$typeId]);
    $packedRemainingByType[$typeId] = max(0, $packedRemainingByType[$typeId] - $packedCount);
    $directGroup = array_merge($firstAsset, [
        'isDirectAssignmentGroup' => true,
        // Real requirement IDs are positive, so this remains a stable and
        // unambiguous parent key for collapse and bulk-selection behaviour.
        'projectAssetRequirements_id' => -(int) $typeId,
        'projectAssetRequirements_quantity' => $rootCount,
        'projectAssetRequirements_note' => null,
        'projectAssetRequirements_discount' => 0,
        'assignedAssets' => $directAssets,
        'packedCount' => $packedCount,
        'periodPrice' => new Money(0, $projectCurrency),
        'totalPrice' => $totalPrice,
        'totalMass' => $totalMass,
        'unknownMassCount' => $unknownMassCount,
        'totalValue' => $totalValue,
        'unknownValueCount' => $unknownValueCount,
        'blockedAssetCount' => $blockedAssetCount,
        'flaggedAssetCount' => $flaggedAssetCount,
        'availability' => ['status' => 'available', 'colour' => '#28a745', 'label' => 'Directly assigned', 'rowColour' => 'transparent'],
    ]);
    $categoryGroupId = (int) ($firstAsset['assetCategoriesGroups_id'] ?? 0);
    $directGroupsByCategory[$categoryGroupId][] = ['kind' => 'direct', 'depth' => 1, 'requirement' => $directGroup];
    $outlineTotals['packed'] += $packedCount;
    $outlineTotals['required'] += $rootCount;
    $outlineTotals['price'] += (int) $totalPrice->getAmount();
    $outlineTotals['mass'] += $totalMass;
    $outlineTotals['unknownMass'] += $unknownMassCount;
    $outlineTotals['value'] += $totalValue;
    $outlineTotals['unknownValue'] += $unknownValueCount;
    $outlineTotals['blockedAssets'] += $blockedAssetCount;
    $outlineTotals['flaggedAssets'] += $flaggedAssetCount;
}

// Direct assignments have no user-selected section. Reuse only a root-level
// automatic category; a nested category belongs to its manual parent (such as
// Stage A), so using it would make an arbitrary placement decision. If no root
// category exists, render a display-only root category for these assignments.
$placedDirectCategories = [];
$categorisedOutline = [];
foreach ($outline as $item) {
    $categoryGroupId = $item['kind'] === 'section' && $item['depth'] === 0
        ? (int) ($item['section']['assetCategoriesGroups_id'] ?? 0)
        : 0;
    if ($categoryGroupId && !empty($directGroupsByCategory[$categoryGroupId])) {
        foreach ($directGroupsByCategory[$categoryGroupId] as $directItem) {
            $requirement = $directItem['requirement'];
            $item['packedCount'] += $requirement['packedCount'];
            $item['requiredCount'] += $requirement['projectAssetRequirements_quantity'];
            $item['totalPrice'] = $item['totalPrice']->add($requirement['totalPrice']);
            $item['totalMass'] += $requirement['totalMass'];
            $item['unknownMassCount'] += $requirement['unknownMassCount'];
            $item['totalValue'] = $item['totalValue']->add(new Money($requirement['totalValue'], $projectCurrency));
            $item['unknownValueCount'] += $requirement['unknownValueCount'];
            $item['blockedAssetCount'] += $requirement['blockedAssetCount'];
            $item['flaggedAssetCount'] += $requirement['flaggedAssetCount'];
        }
    }
    $categorisedOutline[] = $item;
    if ($categoryGroupId && !empty($directGroupsByCategory[$categoryGroupId])) {
        foreach ($directGroupsByCategory[$categoryGroupId] as $directItem) $categorisedOutline[] = $directItem;
        $placedDirectCategories[$categoryGroupId] = true;
    }
}
foreach ($directGroupsByCategory as $categoryGroupId => $directItems) {
    if (isset($placedDirectCategories[$categoryGroupId])) continue;
    if (!$categoryGroupId) {
        foreach ($directItems as $directItem) {
            $directItem['depth'] = 0;
            $categorisedOutline[] = $directItem;
        }
        continue;
    }
    $firstRequirement = $directItems[0]['requirement'];
    $section = [
        'projectAssetRequirementSections_id' => -(1000000 + (int) $categoryGroupId),
        'projectAssetRequirementSections_name' => $firstRequirement['assetCategoriesGroups_name'] ?: 'Other',
        'projectAssetRequirementSections_parent_id' => null,
        'assetCategoriesGroups_id' => (int) $categoryGroupId,
        'isDirectAssignmentSection' => true,
    ];
    $sectionItem = ['kind' => 'section', 'depth' => 0, 'section' => $section, 'packedCount' => 0, 'requiredCount' => 0, 'totalPrice' => new Money(0, $projectCurrency), 'totalValue' => new Money(0, $projectCurrency), 'unknownValueCount' => 0, 'totalMass' => 0.0, 'unknownMassCount' => 0, 'blockedAssetCount' => 0, 'flaggedAssetCount' => 0, 'availability' => ['status' => 'available', 'colour' => '#28a745', 'label' => 'Directly assigned']];
    foreach ($directItems as $directItem) {
        $requirement = $directItem['requirement'];
        $sectionItem['packedCount'] += $requirement['packedCount'];
        $sectionItem['requiredCount'] += $requirement['projectAssetRequirements_quantity'];
        $sectionItem['totalPrice'] = $sectionItem['totalPrice']->add($requirement['totalPrice']);
        $sectionItem['totalMass'] += $requirement['totalMass'];
        $sectionItem['unknownMassCount'] += $requirement['unknownMassCount'];
        $sectionItem['totalValue'] = $sectionItem['totalValue']->add(new Money($requirement['totalValue'], $projectCurrency));
        $sectionItem['unknownValueCount'] += $requirement['unknownValueCount'];
        $sectionItem['blockedAssetCount'] += $requirement['blockedAssetCount'];
        $sectionItem['flaggedAssetCount'] += $requirement['flaggedAssetCount'];
    }
    $categorisedOutline[] = $sectionItem;
    foreach ($directItems as $directItem) $categorisedOutline[] = $directItem;
}
$outline = $categorisedOutline;
$PAGEDATA['project']['assetRequirementOutline'] = $outline;
$PAGEDATA['project']['assetRequirementSectionOptions'] = $sectionOptions;
$PAGEDATA['project']['assetRequirementSummary'] = [
    'packed' => $outlineTotals['packed'], 'required' => $outlineTotals['required'],
    'mass' => $outlineTotals['mass'], 'unknownMass' => $outlineTotals['unknownMass'],
    'value' => new Money($outlineTotals['value'], $projectCurrency), 'unknownValue' => $outlineTotals['unknownValue'],
    'price' => new Money($outlineTotals['price'], $projectCurrency),
    'blockedAssets' => $outlineTotals['blockedAssets'], 'flaggedAssets' => $outlineTotals['flaggedAssets'],
    'shortages' => $outlineTotals['shortages'],
];

//Files
$PAGEDATA['files'] = $bCMS->s3List(7, $PAGEDATA['project']['projects_id']);

$PAGEDATA['invoices'] = $bCMS->s3List(20, $PAGEDATA['project']['projects_id'],'s3files_meta_uploaded', 'DESC');
$PAGEDATA['quotes'] = $bCMS->s3List(21, $PAGEDATA['project']['projects_id'],'s3files_meta_uploaded', 'DESC');
$PAGEDATA['deliveryNotes'] = $bCMS->s3List(22, $PAGEDATA['project']['projects_id'],'s3files_meta_uploaded', 'DESC');

$DBLIB->orderBy("assetsAssignmentsStatus_order","ASC");
$DBLIB->where("assetsAssignmentsStatus_deleted", 0);
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$PAGEDATA['assetsAssignmentsStatus'] = $DBLIB->get("assetsAssignmentsStatus");


$DATA = [
    "project" => $PAGEDATA['project'],
    "files" => $PAGEDATA['files'],
    "assetsAssignmentsStatus" => $PAGEDATA['assetsAssignmentsStatus'],
    'FINANCIALS' => $PAGEDATA['FINANCIALS']
]; //Data that's safe to return to the app


if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) finish(true, null, $DATA);

/** @OA\Post(
 *     path="/projects/data.php", 
 *     summary="Data", 
 *     description="Get the data of a project  
Requires Instance Permission PROJECTS:VIEW
", 
 *     operationId="data", 
 *     tags={"projects"}, 
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
 *         name="formData",
 *         in="query",
 *         description="Form Data",
 *         required="false", 
 *         @OA\Schema(
 *             type="object", 
 *             ),
 *     ), 
 *     @OA\Parameter(
 *         name="id",
 *         in="query",
 *         description="Project ID",
 *         required="false", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 * )
 */
