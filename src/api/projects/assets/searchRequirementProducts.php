<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectAssetRequirements.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';

if (!$AUTH->instancePermissionCheck('PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN')) die('404');

$projectsId = filter_input(INPUT_POST, 'projects_id', FILTER_VALIDATE_INT);
$page = max(1, filter_input(INPUT_POST, 'page', FILTER_VALIDATE_INT) ?: 1);
$search = trim((string) ($_POST['search'] ?? ''));
if (!$projectsId) finish(false, ['message' => 'Project is required']);

$DBLIB->where('projects_id', $projectsId);
$DBLIB->where('instances_id', $AUTH->data['instance']['instances_id']);
$DBLIB->where('projects_deleted', 0);
$project = $DBLIB->getOne('projects', ['projects_id', 'instances_id', 'projects_dates_deliver_start', 'projects_dates_deliver_end']);
if (!$project) finish(false, ['message' => 'Project not found']);

$DBLIB->where('assetTypes.instances_id', $project['instances_id']);
$DBLIB->join('manufacturers', 'assetTypes.manufacturers_id=manufacturers.manufacturers_id', 'LEFT');
$DBLIB->join('assetCategories', 'assetTypes.assetCategories_id=assetCategories.assetCategories_id', 'LEFT');
$DBLIB->join('assetCategoriesGroups', 'assetCategories.assetCategoriesGroups_id=assetCategoriesGroups.assetCategoriesGroups_id', 'LEFT');
if ($search !== '') {
    $like = '%' . $search . '%';
    $DBLIB->where(
        '(assetTypes.assetTypes_name LIKE ? OR manufacturers.manufacturers_name LIKE ? OR assetCategories.assetCategories_name LIKE ? OR assetCategoriesGroups.assetCategoriesGroups_name LIKE ? OR EXISTS (' .
        'SELECT 1 FROM assets searchAssets INNER JOIN assetGroups ON FIND_IN_SET(assetGroups.assetGroups_id, searchAssets.assets_assetGroups) ' .
        'WHERE searchAssets.assetTypes_id=assetTypes.assetTypes_id AND searchAssets.instances_id=? AND searchAssets.assets_deleted=0 AND assetGroups.instances_id=? AND assetGroups.assetGroups_deleted=0 AND assetGroups.assetGroups_name LIKE ?))',
        [$like, $like, $like, $like, $project['instances_id'], $project['instances_id'], $like]
    );
}
$DBLIB->orderBy('assetTypes.assetTypes_name', 'ASC');
$DBLIB->pageLimit = 6;
$products = $DBLIB->arraybuilder()->paginate('assetTypes', $page, [
    'assetTypes.assetTypes_id', 'assetTypes.assetTypes_name', 'assetTypes.assetTypes_dayRate', 'assetTypes.assetTypes_weekRate',
    'manufacturers.manufacturers_name', 'assetCategories.assetCategories_name', 'assetCategoriesGroups.assetCategoriesGroups_name',
]);
$pageCount = max(1, (int) $DBLIB->totalPages);
$productCount = (int) $DBLIB->totalCount;
$priceMaths = (new projectFinance())->durationMaths($projectsId);

foreach ($products as &$product) {
    $typeId = (int) $product['assetTypes_id'];
    $availability = projectAssetRequirementAvailability($project, $typeId);
    $DBLIB->where('assets.assetTypes_id', $typeId);
    $DBLIB->where('assets.instances_id', $project['instances_id']);
    $DBLIB->where('assets.assets_deleted', 0);
    $DBLIB->where('assets.assets_linkedTo', null, 'IS');
    if ($project['projects_dates_deliver_end']) $DBLIB->where('(assets.assets_endDate IS NULL OR assets.assets_endDate >= ?)', [$project['projects_dates_deliver_end']]);
    $DBLIB->where('EXISTS (SELECT 1 FROM maintenanceJobs WHERE maintenanceJobs.maintenanceJobs_deleted=0 AND maintenanceJobs.maintenanceJobs_blockAssets=1 AND FIND_IN_SET(assets.assets_id, maintenanceJobs.maintenanceJobs_assets))');
    $maintenanceBlocked = (int) $DBLIB->getValue('assets', 'COUNT(assets.assets_id)');
    $product['periodPrice'] = ((int) $product['assetTypes_dayRate'] * (int) $priceMaths['days']) + ((int) $product['assetTypes_weekRate'] * (int) $priceMaths['weeks']);
    $product['stock'] = (int) $availability['stock'];
    $product['maintenanceBlocked'] = $maintenanceBlocked;
    $product['available'] = max(0, (int) $availability['remaining'] - $maintenanceBlocked);
    $product['availabilityLabel'] = $availability['label'];
    if ($maintenanceBlocked && $product['available'] < 1) $product['availabilityLabel'] .= '; stock is unavailable because of maintenance';
    $thumbnail = $bCMS->s3List(2, $typeId, 's3files_meta_uploaded', 'ASC', 1);
    $product['thumbnail'] = $thumbnail ? $bCMS->s3URL($thumbnail[0]['s3files_id'], false, '+1 hour', false, 'medium') : null;
}
unset($product);

finish(true, null, ['products' => $products, 'page' => min($page, $pageCount), 'pages' => $pageCount, 'count' => $productCount]);
