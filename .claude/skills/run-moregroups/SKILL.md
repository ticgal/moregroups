---
name: run-moregroups
description: Build, install, and drive the More Groups GLPI plugin in a real running GLPI 11 instance — deactivate/reactivate a group member both via the single-row buttons and via the massive actions, through the actual UI, with a screenshot after each step. Use when asked to run, test, smoke-test, or screenshot More Groups, or to confirm a code change actually works in the browser (not just phpunit/phpcs).
---

Paths below are relative to the repo root (`moregroups/`), not this skill directory.

More Groups is a classic-style (`inc/`) + modern (`src/Controller`) GLPI 11 plugin. There is
no standalone dev server — "running" it means a live GLPI 11 container with the plugin files
mounted, installed and activated, driven with Playwright through the actual `/front/group.form.php`
UI. Don't just `php -l` the change — this skill proves it renders and functions.

## Prerequisites (already verified in this container)

- Podman, with a GLPI 11 container already up (see below).
- `node_modules/@playwright/test` at repo root: `npm install` (root `package.json` already
  pins `"@playwright/test": "1.62.1"`).
- Chromium browser binary: `npx playwright install chromium` (works without `--with-deps` —
  the OS libs this needs are already present on this host; `--with-deps` fails here because it
  needs passwordless `sudo`, which isn't available).

## Getting a running GLPI instance

This repo doesn't ship a compose file for a throwaway instance. In this environment there is
already a long-running GLPI 11.0.8 test stack usable for this: `glpi-65000-web` /
`glpi-65000-db` (podman), published at `http://localhost:65000`, default creds `glpi`/`glpi`.
If it's not running, any GLPI 11 container with the plugin's files mounted at
`/var/www/glpi/plugins/moregroups` and activated (Setup → Plugins) works the same way — update
`BASE_URL` below accordingly.

**Sync your working tree into the running container** (this container mounts plugin code from
a host path, not directly from this repo checkout):

```bash
rsync -a --exclude=vendor --exclude=.git --exclude=node_modules --exclude=tests --exclude=tools \
  inc src hook.php setup.php locales \
  /home/oscar/containers/all-plugins/moregroups/
```

Then, if you only changed PHP under `inc/`/`src/`, no reinstall is needed — GLPI reads plugin
classes on every request. If you added a new migration/table, reinstall from Setup → Plugins.

**PHP-file edits can hit a stale opcache** even after `rsync` updates the file on disk
(confirmed pattern for this stack — see the `glpi-plugin-builder` skill's Trap 7). If a fix
"isn't working" right after editing PHP, restart the container before concluding the fix is
wrong:

```bash
podman restart glpi-65000-web
```

## Run (agent path) — the driver

```bash
cd moregroups   # repo root
npm install                              # once
npx playwright install chromium          # once (cached after first run)
node .claude/skills/run-moregroups/driver.mjs http://localhost:65000 1
```

The `1` is a `Group` id that already has ≥1 active `Group_User` member on this test instance
(group "TEST", id 1). The driver:

1. Logs in as `glpi`/`glpi`.
2. Opens `/front/group.form.php?id=<GROUP_ID>` and the **Users** tab.
3. Waits for the **Deactivate user** button to render next to the first active member — this
   is the exact assertion that catches the malformed-jQuery-selector regression fixed on
   2026-08-26 (`inc/group.class.php`'s `input[name^='item[Group_User]']` selector).
4. Clicks it, confirms the member now appears in the **Deactivated users** panel.
5. Clicks that row's **Activate user** button, confirms the member is back in the active list.
6. Selects that same member's checkbox in the active-members table, opens the **Actions**
   modal, picks **Deactivate users** (the `PluginMoregroupsGroup:deactivate` massive action
   wired via `plugin_moregroups_MassiveActions` in `hook.php`), submits, confirms the member
   is now in the **Deactivated users** panel, and confirms the page reloaded back onto the
   same `group.form.php?id=<GROUP_ID>` (not some other URL).
7. Selects that member's checkbox in the **Deactivated users** panel, opens its own **Actions**
   modal, picks **Activate users** (`getSpecificMassiveActions` in `inc/group.class.php`),
   submits, confirms the member is back in the active list, and confirms the same reload check.
8. Screenshots after every step into `.claude/skills/run-moregroups/screenshots/`
   (`01-logged-in.png` … `06-massive-activated.png`, or `NN-FAILURE.png` on a thrown step).

Exit code is non-zero on failure; read stdout for which step failed and check the `FAILURE`
screenshot.

**The DB state this driver leaves behind is idempotent** — deactivate/reactivate and the
massive-action round trip both net out to the same active/deactivated row counts — safe to
re-run without manual cleanup; verified by running the full 6-step sequence twice back to back.

## Direct invocation (no browser) — checking PHP syntax/logic fast

For a quick sanity pass on a PHP edit before spinning up the browser driver:

```bash
php -l inc/group.class.php
php -l src/Controller/GroupActionController.php
```

This does **not** catch the JS-selector class of bug that broke the deactivate button — that
only shows up by actually rendering the page and clicking, i.e. the driver above.

## Gotchas

- **The malformed-selector bug is invisible to `php -l`, phpcs, and PHPStan.** It's embedded
  PHP-heredoc JavaScript (`Html::scriptBlock($script)` in `showDeactivated()`). Nothing in the
  PHP toolchain parses it. The only way to catch it is rendering the page and asserting the
  button is actually present in the DOM — which is why the driver's `waitFor` on the
  deactivate button (not just clicking it) is the load-bearing assertion.
- **`rsync --delete` would wipe files unique to the container copy** (it has no `.git`,
  `.phpcs.xml`, etc. — those are dev-only files that were never meant to ship). Don't add
  `--delete` to the sync command above.
- **`localhost` from inside a Playwright browser launched on the host reaches the container
  fine** here because the container publishes `0.0.0.0:65000->80/tcp` — no
  `host.containers.internal` dance needed, unlike the Playwright-inside-a-container setup used
  by `tools/manual-generator/run.sh` (a separate, heavier pipeline for generating the published
  user manual — see the `glpi-plugin-manual-generator` skill for that one; this skill is for
  fast local smoke-testing, not documentation screenshots).
- **`npx playwright install chromium --with-deps` fails** in this container (`sudo: a
  terminal is required`). Omit `--with-deps` — the browser launches fine without it here.

## Troubleshooting

- `DRIVER FAILED: locator.waitFor: Timeout ... button[title="Deactivate user"]` on step 3 →
  the JS-selector regression is back (or a new one like it). Check
  `Html::scriptBlock($script)` in `inc/group.class.php`'s `showDeactivated()` for a syntax
  error in the embedded jQuery, and check browser console errors (the driver prints any
  `pageerror`/console-error events it captured after `ALL STEPS COMPLETED` or right before a
  failure).
- `no active Group_User rows found` → the seed group has no members left (e.g. previous run's
  cleanup didn't happen). Reset via the DB directly:
  ```bash
  podman exec glpi-65000-db sh -c "mariadb -u65000_db_user -p65000_db_password 65000_db_name \
    -e \"INSERT IGNORE INTO glpi_groups_users (users_id, groups_id) VALUES (3,1);\""
  ```
- `DRIVER FAILED: ... did not reload back onto the group form` on step 6 or 7 → the massive
  action's redirect target regressed (compare against `MassiveAction`'s own redirect handling;
  this plugin doesn't override it, so a failure here usually points at something upstream of
  the plugin, e.g. a stale/incorrect `Referer`). Check the matching `NN-massive-*` screenshot
  for what page it actually landed on.
- The massive-action steps select the option by its **visible label** (`selectOption({label:
  ...})`), not its value — if the button label text changes (e.g. a locale/translation string
  edit to `__('Deactivate users', 'moregroups')` / `__('Activate users', 'moregroups')` in
  `inc/group.class.php`), update the driver's `selectOption` calls to match.
