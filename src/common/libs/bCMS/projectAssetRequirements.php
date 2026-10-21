<?php

function projectAssetRequirementStatusCount(int $projectsId, int $assetTypesId, string $statusName): int
{
    global $DBLIB;

    // Status IDs are configured per instance, so resolve the named milestone
    // before counting assignments. Counting by name directly would break for
    // projects containing assets borrowed from another unit.
    $DBLIB->where('projects_id', $projectsId);
    $instancesId = (int) $DBLIB->getValue('projects', 'instances_id');
    $DBLIB->where('instances_id', $instancesId);
    $DBLIB->where('assetsAssignmentsStatus_deleted', 0);
    $DBLIB->where('LOWER(assetsAssignmentsStatus_name)', strtolower($statusName));
    $minimumOrder = $DBLIB->getValue('assetsAssignmentsStatus', 'assetsAssignmentsStatus_order');
    if ($minimumOrder === null) return 0;

    $DBLIB->where('assetsAssignments.projects_id', $projectsId);
    $DBLIB->where('assets.assetTypes_id', $assetTypesId);
    $DBLIB->where('assetsAssignments.assetsAssignments_deleted', 0);
    $DBLIB->where('assetsAssignmentsStatus.assetsAssignmentsStatus_deleted', 0);
    $DBLIB->where('assetsAssignmentsStatus.assetsAssignmentsStatus_order', (int) $minimumOrder, '>=');
    $DBLIB->join('assets', 'assetsAssignments.assets_id=assets.assets_id', 'LEFT');
    $DBLIB->join('assetsAssignmentsStatus', 'assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id', 'LEFT');

    return (int) $DBLIB->getValue('assetsAssignments', 'COUNT(assetsAssignments.assetsAssignments_id)');
}

function projectAssetRequirementPackedCount(int $projectsId, int $assetTypesId): int
{
    // "Packed" is a milestone: later states such as Dispatched still count as
    // packed when displaying progress to the warehouse team.
    return projectAssetRequirementStatusCount($projectsId, $assetTypesId, 'packed');
}

/**
 * Calculate type-level stock pressure for a planned project requirement.
 * Planned quantities and existing physical assignments both count as demand.
 */
function projectAssetRequirementAvailability(array $project, int $assetTypesId): array
{
    global $DBLIB;

    if (!$project['projects_dates_deliver_start'] || !$project['projects_dates_deliver_end']) {
        return [
            'status' => 'unknown',
            'colour' => '#6c757d',
            'label' => 'Project delivery dates are required to calculate availability',
            'stock' => 0,
            'peakDemand' => 0,
            'remaining' => 0,
        ];
    }

    $projectsId = (int) $project['projects_id'];
    $instancesId = (int) $project['instances_id'];
    $projectStart = strtotime($project['projects_dates_deliver_start']);
    $projectEnd = strtotime($project['projects_dates_deliver_end']);

    $DBLIB->where('assets.assetTypes_id', $assetTypesId);
    $DBLIB->where('assets.instances_id', $instancesId);
    $DBLIB->where('assets.assets_deleted', 0);
    $DBLIB->where('assets.assets_linkedTo', null, 'IS');
    $DBLIB->where("(assets.assets_endDate IS NULL OR assets.assets_endDate >= '" . $project['projects_dates_deliver_end'] . "')");
    $stock = (int) $DBLIB->getValue('assets', 'COUNT(assets.assets_id)');

    // Build demand from both the new planning records and legacy physical-only
    // assignments. This keeps old projects meaningful during the transition.
    $requirements = $DBLIB->rawQuery(
        "SELECT projects.projects_id, projects.projects_dates_deliver_start, projects.projects_dates_deliver_end,
                SUM(projectAssetRequirements.projectAssetRequirements_quantity) AS demand
         FROM projectAssetRequirements
         INNER JOIN projects ON projectAssetRequirements.projects_id = projects.projects_id
         LEFT JOIN projectsStatuses ON projects.projectsStatuses_id = projectsStatuses.projectsStatuses_id
         WHERE projectAssetRequirements.assetTypes_id = ?
           AND projectAssetRequirements.projectAssetRequirements_deleted = 0
           AND projects.instances_id = ?
           AND projects.projects_deleted = 0
           AND projects.projects_dates_deliver_start IS NOT NULL
           AND projects.projects_dates_deliver_end IS NOT NULL
           AND (projectsStatuses.projectsStatuses_assetsReleased = 0 OR projects.projects_id = ?)
         GROUP BY projects.projects_id, projects.projects_dates_deliver_start, projects.projects_dates_deliver_end",
        [$assetTypesId, $instancesId, $projectsId]
    );

    $assignments = $DBLIB->rawQuery(
        "SELECT projects.projects_id, projects.projects_dates_deliver_start, projects.projects_dates_deliver_end,
                COUNT(assetsAssignments.assetsAssignments_id) AS demand
         FROM assetsAssignments
         INNER JOIN assets ON assetsAssignments.assets_id = assets.assets_id
         INNER JOIN projects ON assetsAssignments.projects_id = projects.projects_id
         LEFT JOIN projectsStatuses ON projects.projectsStatuses_id = projectsStatuses.projectsStatuses_id
         WHERE assets.assetTypes_id = ?
           AND assetsAssignments.assetsAssignments_deleted = 0
           AND assets.assets_deleted = 0
           AND projects.instances_id = ?
           AND projects.projects_deleted = 0
           AND projects.projects_dates_deliver_start IS NOT NULL
           AND projects.projects_dates_deliver_end IS NOT NULL
           AND (projectsStatuses.projectsStatuses_assetsReleased = 0 OR projects.projects_id = ?)
         GROUP BY projects.projects_id, projects.projects_dates_deliver_start, projects.projects_dates_deliver_end",
        [$assetTypesId, $instancesId, $projectsId]
    );

    $demandByProject = [];
    foreach ($requirements ?: [] as $demand) {
        $demandProjectId = (int) $demand['projects_id'];
        $demandByProject[$demandProjectId] = [
            'start' => strtotime($demand['projects_dates_deliver_start']),
            'end' => strtotime($demand['projects_dates_deliver_end']),
            'demand' => (int) $demand['demand'],
        ];
    }
    foreach ($assignments ?: [] as $demand) {
        $demandProjectId = (int) $demand['projects_id'];
        if (!isset($demandByProject[$demandProjectId])) {
            $demandByProject[$demandProjectId] = [
                'start' => strtotime($demand['projects_dates_deliver_start']),
                'end' => strtotime($demand['projects_dates_deliver_end']),
                'demand' => (int) $demand['demand'],
            ];
        } else {
            //Assignments fulfil planned demand; they do not create a second copy of it.
            $demandByProject[$demandProjectId]['demand'] = max($demandByProject[$demandProjectId]['demand'], (int) $demand['demand']);
        }
    }

    // Demand only changes at project boundaries. Checking these event points
    // is cheaper and more exact than walking every day in the project window.
    $eventPoints = [$projectStart, $projectEnd];
    foreach ($demandByProject as $demand) {
        if ($demand['start'] >= $projectStart && $demand['start'] <= $projectEnd) $eventPoints[] = $demand['start'];
        if ($demand['end'] >= $projectStart && $demand['end'] <= $projectEnd) $eventPoints[] = $demand['end'];
    }

    $peakDemand = 0;
    foreach (array_unique($eventPoints) as $point) {
        $demandAtPoint = 0;
        foreach ($demandByProject as $demand) {
            if ($demand['start'] <= $point && $demand['end'] >= $point) $demandAtPoint += $demand['demand'];
        }
        $peakDemand = max($peakDemand, $demandAtPoint);
    }

    // The two-day checkpoint models the practical warehouse question: will
    // enough stock actually be back on the shelf in time to prep this job?
    $checkpoint = strtotime('-2 days', $projectStart);
    $currentProjectDemand = $demandByProject[$projectsId]['demand'] ?? 0;
    $checkpointDemand = $currentProjectDemand;
    foreach ($demandByProject as $demandProjectId => $demand) {
        if ($demandProjectId !== $projectsId && $demand['start'] <= $checkpoint && $demand['end'] >= $checkpoint) {
            $checkpointDemand += $demand['demand'];
        }
    }
    $checkpointShelfStock = $stock - ($checkpointDemand - $currentProjectDemand);

    $remaining = $stock - $peakDemand;
    if ($peakDemand > $stock) {
        $status = 'shortage';
        $colour = '#dc3545';
        $label = 'Shortage: peak demand is ' . $peakDemand . ' with ' . $stock . ' in stock';
    } elseif ($checkpointDemand > $stock) {
        $status = 'turnaround';
        $colour = '#fd7e14';
        $label = 'Tight turnaround: ' . max(0, $checkpointShelfStock) . ' are expected on the shelf 2 days before delivery, but this project needs ' . $currentProjectDemand;
    } elseif ($stock > 0 && ($remaining / $stock) < 0.05) {
        $status = 'low-stock';
        $colour = '#ffc107';
        $label = 'Low stock: ' . $remaining . ' of ' . $stock . ' remain at peak demand';
    } else {
        $status = 'available';
        $colour = '#28a745';
        $label = 'Available: ' . $remaining . ' of ' . $stock . ' remain at peak demand';
    }

    return [
        'status' => $status,
        'colour' => $colour,
        'label' => $label,
        'stock' => $stock,
        'peakDemand' => $peakDemand,
        'checkpointDemand' => $checkpointDemand,
        'checkpointShelfStock' => $checkpointShelfStock,
        'remaining' => $remaining,
    ];
}
