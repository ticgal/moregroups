#!/usr/bin/env node
// driver.mjs — smoke-drives the More Groups plugin against a running GLPI 11 instance.
//
// Usage:
//   node .claude/skills/run-moregroups/driver.mjs <BASE_URL> <GROUP_ID>
//
// Example (against the glpi-65000-web test container used to build this skill):
//   node .claude/skills/run-moregroups/driver.mjs http://localhost:65000 1
//
// Logs in as glpi/glpi, opens the group's Users tab, deactivates the first active
// member found via the single-row button, verifies they land in the "Deactivated users"
// panel, reactivates them via the single-row button, verifies they're back in the active
// list, then repeats the same round trip through the massive-action UI (checkbox row +
// "Actions" modal + "Deactivate users"/"Activate users") and confirms each submit reloads
// back onto the same group's Users tab. Screenshots after each step land in
// .claude/skills/run-moregroups/screenshots/.

import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const shotsDir = join(here, 'screenshots');
mkdirSync(shotsDir, { recursive: true });

const BASE_URL = process.argv[2] || 'http://localhost:65000';
const GROUP_ID = process.argv[3] || '1';

let shotN = 0;
async function shot(page, name) {
  shotN += 1;
  const path = join(shotsDir, `${String(shotN).padStart(2, '0')}-${name}.png`);
  await page.screenshot({ path, fullPage: true });
  console.log('screenshot:', path);
}

async function main() {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

  try {
    console.log('==> login');
    await page.goto(`${BASE_URL}/`, { timeout: 60_000 });
    await page.locator('input[name="login_name"]').fill('glpi');
    await page.locator('input[name="login_password"]').fill('glpi');
    await page.locator('button[name="submit"], input[name="submit"]').first().click();
    await page.waitForLoadState('networkidle');
    await shot(page, 'logged-in');

    console.log('==> open group Users tab');
    await page.goto(`${BASE_URL}/front/group.form.php?id=${GROUP_ID}`, { timeout: 30_000 });
    await page.getByRole('tab', { name: /^Users/ }).click();
    await page.getByText('Deactivated users').waitFor({ state: 'visible', timeout: 30_000 });
    await shot(page, 'users-tab');

    console.log('==> single-row deactivate');
    const activeRows = page.locator('tr[data-itemtype="Group_User"]');
    const activeCountBefore = await activeRows.count();
    if (activeCountBefore === 0) throw new Error('no active Group_User rows found — seed the group with a member first');
    const targetRow = activeRows.first();
    const deactivateBtn = targetRow.locator('button[title="Deactivate user"]');
    await deactivateBtn.waitFor({ state: 'visible', timeout: 10_000 }); // <- fails if the JS selector bug regresses
    const memberText = (await targetRow.locator('td').nth(1).innerText()).trim();
    await deactivateBtn.click();

    const panel = page.locator('.card.m-n2', { has: page.locator('.card-title', { hasText: 'Deactivated users' }) });
    await panel.locator('tr', { hasText: memberText }).waitFor({ state: 'visible', timeout: 30_000 });
    await page.waitForLoadState('networkidle');
    console.log('   deactivated OK:', memberText.trim());
    await shot(page, 'deactivated');

    console.log('==> single-row reactivate');
    const deactivatedRow = panel.locator('tr', { hasText: memberText });
    await deactivatedRow.locator('button[title="Activate user"]').click();
    await page.locator('tr[data-itemtype="Group_User"]', { hasText: memberText }).waitFor({ state: 'visible', timeout: 30_000 });
    await page.waitForLoadState('networkidle');
    console.log('   reactivated OK:', memberText.trim());
    await shot(page, 'reactivated');

    console.log('==> massive action: deactivate');
    const panel2 = page.locator('.card.m-n2', { has: page.locator('.card-title', { hasText: 'Deactivated users' }) });
    const activeRow = page.locator('tr[data-itemtype="Group_User"]', { hasText: memberText });
    await activeRow.locator('input[type="checkbox"]').check();
    await page.locator('a[href*="modal_massaction_content"]').first().click();
    const maSelect = page.locator('.modal.show select[name="massiveaction"]');
    await maSelect.waitFor({ state: 'visible', timeout: 10_000 });
    await maSelect.selectOption({ label: 'Deactivate users' }); // <- fails if the massive "deactivate" action (hook.php's plugin_moregroups_MassiveActions) regresses
    await page.locator('.modal.show button[name="massiveaction"], .modal.show input[name="massiveaction"]').first().click();
    await panel2.locator('tr', { hasText: memberText }).waitFor({ state: 'visible', timeout: 30_000 });
    if (!page.url().includes(`group.form.php?id=${GROUP_ID}`)) {
      throw new Error(`massive deactivate did not reload back onto the group form (landed on ${page.url()})`);
    }
    console.log('   massive-deactivated OK, reloaded on', page.url());
    await shot(page, 'massive-deactivated');

    console.log('==> massive action: activate');
    const deactivatedRow2 = panel2.locator('tr', { hasText: memberText });
    await deactivatedRow2.locator('input[type="checkbox"]').check();
    await panel2.locator('a[href*="modal_massaction_content"]').first().click();
    const maSelect2 = page.locator('.modal.show select[name="massiveaction"]');
    await maSelect2.waitFor({ state: 'visible', timeout: 10_000 });
    await maSelect2.selectOption({ label: 'Activate users' }); // <- fails if the specific massive "activate" action (getSpecificMassiveActions in inc/group.class.php) regresses
    await page.locator('.modal.show button[name="massiveaction"], .modal.show input[name="massiveaction"]').first().click();
    await page.locator('tr[data-itemtype="Group_User"]', { hasText: memberText }).waitFor({ state: 'visible', timeout: 30_000 });
    if (!page.url().includes(`group.form.php?id=${GROUP_ID}`)) {
      throw new Error(`massive activate did not reload back onto the group form (landed on ${page.url()})`);
    }
    console.log('   massive-activated OK, reloaded on', page.url());
    await shot(page, 'massive-activated');

    console.log('ALL STEPS COMPLETED');
    if (errors.length) {
      console.log('--- console/page errors seen during the run ---');
      errors.forEach((e) => console.log(e));
    }
  } catch (err) {
    await shot(page, 'FAILURE');
    console.error('DRIVER FAILED:', err.message);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

main();
