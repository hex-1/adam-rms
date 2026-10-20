import { assign, type Dates, newAsset, newProject } from "../projects";
import { dbQuery, expect, test } from "../tenants";

/**
 * Asset requirements describe the equipment a project needs before particular
 * physical assets are picked. These tests cover the database rules behind the
 * planner; the existing project-assets suite continues to cover assignments.
 */

const DATES: Dates = { start: "2036-08-01 09:00:00", end: "2036-08-03 18:00:00" };

test("an asset assigned without a planned requirement is shown in the new assets list", async ({ asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, DATES);
  const asset = await newAsset(asA, a);
  const [{ tag }] = dbQuery<{ tag: string }>("SELECT assets_tag tag FROM assets WHERE assets_id = ?", [asset]);
  await expect(assign(asA, a, project, asset)).resolves.toMatchObject({ json: { result: true } });

  const page = await asA.page(`/project/?id=${project}`);
  expect(page.status).toBe(200);
  expect(page.body).toContain("Directly assigned");
  expect(page.body).toContain(tag);
  const category = `data-kind="section" data-id="-${1000000 + a.categoryGroupId}" data-depth="0"`;
  const assetType = `data-kind="direct" data-id="-${a.assetTypeId}" data-depth="1"`;
  expect(page.body).toContain(category);
  expect(page.body).toContain(assetType);
  expect(page.body.indexOf(category)).toBeLessThan(page.body.indexOf(assetType));
  expect(page.body).toContain(`data-requirement-id="-${a.assetTypeId}" aria-expanded="true" title="Collapse allocated assets"`);

  const promoted = await asA.api("/api/projects/assets/sortRequirementOutline.php", {
    instances_id: a.instanceId,
    projects_id: project,
    outline: JSON.stringify([
      { kind: "section", id: -(1000000 + a.categoryGroupId), depth: 0 },
      { kind: "direct", id: -a.assetTypeId, depth: 1 },
    ]),
  });
  expect(promoted.json).toMatchObject({ result: true, response: { promoted: true } });
  const [requirement] = dbQuery<{ id: number; quantity: number; section: number }>(
    `SELECT projectAssetRequirements_id id, projectAssetRequirements_quantity quantity,
            projectAssetRequirementSections_id section
       FROM projectAssetRequirements WHERE projects_id = ? AND assetTypes_id = ? AND projectAssetRequirements_deleted = 0`,
    [project, a.assetTypeId],
  );
  expect(requirement).toMatchObject({ quantity: 1 });
  expect(requirement.section).toBeTruthy();
  expect(dbQuery<{ requirement: number }>(
    "SELECT projectAssetRequirements_id requirement FROM assetsAssignments WHERE projects_id = ? AND assets_id = ?",
    [project, asset],
  )).toEqual([{ requirement: requirement.id }]);
});

test("repeated additions merge into one requirement and reuse its automatic category", async ({ asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, DATES);
  const add = (quantity: number) => asA.api("/api/projects/assets/addRequirement.php", {
    instances_id: a.instanceId,
    projects_id: project,
    assetTypes_id: a.assetTypeId,
    quantity,
  });

  await expect(add(2)).resolves.toMatchObject({
    json: { result: true, response: { projectAssetRequirements_quantity: 2 } },
  });
  await expect(add(3)).resolves.toMatchObject({
    json: { result: true, response: { projectAssetRequirements_quantity: 5 } },
  });

  expect(dbQuery<{ quantity: number }>(
    `SELECT projectAssetRequirements_quantity quantity
       FROM projectAssetRequirements
      WHERE projects_id = ? AND assetTypes_id = ? AND projectAssetRequirements_deleted = 0`,
    [project, a.assetTypeId],
  )).toEqual([{ quantity: 5 }]);
  expect(dbQuery<{ count: number }>(
    `SELECT COUNT(*) count
       FROM projectAssetRequirementSections
      WHERE projects_id = ? AND assetCategoriesGroups_id = ? AND projectAssetRequirementSections_deleted = 0`,
    [project, a.categoryGroupId],
  )).toEqual([{ count: 1 }]);
});

test("a manually packed requirement is protected until it is unpacked", async ({ asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, DATES);
  const created = await asA.api("/api/projects/assets/addRequirement.php", {
    instances_id: a.instanceId,
    projects_id: project,
    assetTypes_id: a.assetTypeId,
    quantity: 4,
  });
  expect(created.json).toMatchObject({ result: true });
  const requirement = created.json.response.projectAssetRequirements_id as number;

  await expect(asA.api("/api/projects/assets/setRequirementPacked.php", {
    instances_id: a.instanceId,
    projectAssetRequirements_ids: [requirement],
    packed: true,
  })).resolves.toMatchObject({ json: { result: true } });
  expect(dbQuery<{ packed: number }>(
    "SELECT projectAssetRequirements_packedOverride packed FROM projectAssetRequirements WHERE projectAssetRequirements_id = ?",
    [requirement],
  )).toEqual([{ packed: 4 }]);

  await expect(asA.api("/api/projects/assets/deleteRequirement.php", {
    instances_id: a.instanceId,
    projectAssetRequirements_id: requirement,
  })).resolves.toMatchObject({ json: { result: false } });

  await asA.api("/api/projects/assets/setRequirementPacked.php", {
    instances_id: a.instanceId,
    projectAssetRequirements_ids: [requirement],
    packed: false,
  });
  await expect(asA.api("/api/projects/assets/deleteRequirement.php", {
    instances_id: a.instanceId,
    projectAssetRequirements_id: requirement,
    finalize: 1,
  })).resolves.toMatchObject({ json: { result: true } });
  expect(dbQuery<{ deleted: number }>(
    "SELECT projectAssetRequirements_deleted deleted FROM projectAssetRequirements WHERE projectAssetRequirements_id = ?",
    [requirement],
  )).toEqual([{ deleted: 1 }]);
});

test("deleting a section promotes its children instead of deleting them", async ({ asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, DATES);
  const addSection = (name: string, parent?: number) => asA.api("/api/projects/assets/addRequirementSection.php", {
    instances_id: a.instanceId,
    projects_id: project,
    name,
    ...(parent ? { parent_id: parent } : {}),
  });
  const parentResponse = await addSection("Stage A");
  const parent = parentResponse.json.response.projectAssetRequirementSections_id as number;
  const childResponse = await addSection("Audio", parent);
  const child = childResponse.json.response.projectAssetRequirementSections_id as number;

  await expect(asA.api("/api/projects/assets/deleteRequirementSection.php", {
    instances_id: a.instanceId,
    projectAssetRequirementSections_id: parent,
  })).resolves.toMatchObject({ json: { result: true } });

  expect(dbQuery<{ parent: number | null; deleted: number }>(
    `SELECT projectAssetRequirementSections_parent_id parent, projectAssetRequirementSections_deleted deleted
       FROM projectAssetRequirementSections WHERE projectAssetRequirementSections_id = ?`,
    [child],
  )).toEqual([{ parent: null, deleted: 0 }]);
});
