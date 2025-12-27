
---

```markdown
# SafeBackup & Repair WP – PRO Version Plan

## 1. Overview

**Plugin Name:** SafeBackup & Repair WP (Pro Add-On)  
**Edition:** Pro (extends Free)  

Pro builds on the Free architecture and adds:

1. **Remote backups + scheduling.**
2. **Safe Update Tester** (mock updates on staging clone).
3. **Conflict Scanner** (automated conflict testing on a clone).
4. **AI-assisted diagnostics & email drafting.**
5. **Advanced Recovery Portal tools:**
   - Full restore from ANY backup
   - **Selective plugin rollback** from backups
   - AI log analysis.

---

## 2. Pro Feature Set

### 2.1 Remote Backup Destinations

**Initial Providers:**

1. **Google Drive**
2. **DigitalOcean Spaces** (S3-compatible)

Features:

- Configure remote credentials in Pro-only settings panel.
- Choose backup destinations:
  - Local only
  - Local + Google Drive
  - Local + DO Spaces
- Upload backup archives to remote after local creation.
- Mark each backup with:
  - `storage_location` (`local`, `gdrive`, `do_spaces`, `multi`).
- Download & restore from remote:
  - If needed, fetch remote archive back to local temp before restore.

**Where it plugs in:**

- Extends `SBWP_Backup_Engine`:
  - Add per-destination upload handlers.
- Extends `wp_sb_backups` table:
  - Add `remote_reference` (JSON) field.
- Admin UI:
  - **Backups** page: show remote location icons (GDrive, DO Spaces).
  - **Settings → Remote Storage (Pro)** tab for credentials.

---

### 2.2 Scheduled Backups (Pro)

Features:

- Create multiple **backup schedules**:
  - Frequency: daily, weekly, custom cron expression.
  - Type: DB-only, full.
  - Destination: local, local + remote.
  - Simple retention rules per schedule (e.g. keep last 7).

Implementation:

- Use **Action Scheduler** or custom cron jobs.
- Store schedules in `sbwp_backup_schedules` option (array).

**Where it plugs in:**

- New Admin UI tab: **Backups → Schedules (Pro)**
- Extends `SBWP_Backup_Engine`:
  - `run_scheduled_backup( $schedule_id )`

---

### 2.3 Safe Update Tester (Mock Updates on Clone)

Goal: Test updates on a **temporary staged clone** before touching live.

Features:

- Create a temporary clone of:
  - DB (prefixed tables or separate DB)
  - Plugins/themes (copy or shallow copy)
  - Optionally reuse live `uploads` via symlink/passthrough.
- Run **plugin/theme/core updates** on the clone **only**.
- Perform **health checks**:
  - Hit important URLs (front page, admin, login, user-defined test URLs).
  - Inspect `debug.log` for new errors.
- Generate report:
  - Which items were updated.
  - Errors detected.
  - Whether the clone site responded successfully.
- If safe:
  - In wp-admin, offer **“Apply updates to live site (with fresh backup)”**.

Implementation highlights:

- New class: `SBWP_Clone_Manager`
  - Handles DB copy, file cloning, bootstrap environment.
- New class: `SBWP_Update_Tester`
  - Uses clone, runs updates, collects logs & results.

**Where it plugs in:**

- New wp-admin page:
  - **SafeBackup & Repair WP → Safe Update Tester (Pro)**.
- Free’s existing **“Backup before update”** stays as simple fallback.
- Pro: adds extra section to Dashboard:
  - “Last mock update test: [status].”

---

### 2.4 Conflict Scanner (Pro)

Goal: Automate conflict-testing behavior devs normally do manually.

Features:

- Uses `SBWP_Clone_Manager` to create a staging environment.
- Deactivate all non-essential plugins in the clone.
- Define **test URLs** (default + user-specified).
- Activate plugins one by one (or in batches):
  - After each activation:
    - Hit test URLs.
    - Check `debug.log`, HTTP responses.
- Build conflict report:
  - Plugins that correlate with errors, slowdowns, or 500s.
  - Error snippets linked to each plugin.

Pro-only additions:

- AI summary of conflict results.
- Auto-generated email text to send to plugin developers.

**Where it plugs in:**

- New wp-admin page:
  - **SafeBackup & Repair WP → Conflict Scanner (Pro)**.
- Recovery Portal can show **latest conflict scan summaries** for extra context (nice-to-have v2).

---

### 2.5 AI Integration (Pro)

AI is used to:

1. Analyze logs (debug.log + context) for:
   - Recovery Portal
   - Update Tester
   - Conflict Scanner
2. Produce:
   - Human-friendly summaries.
   - Suggested actions.
   - Draft emails to plugin/theme developers.

Implementation details:

- New class: `SBWP_AI_Analyzer`
  - `analyze_logs( $context, $logs, $env )`
  - `summarize_update_test( $data )`
  - `summarize_conflict_scan( $data )`
  - `draft_support_email( $plugin, $errors, $env )`
- Config:
  - Pro-only **Settings → AI** tab to configure provider and API key.
  - Toggles for where AI is used (Update Tester, Conflict Scanner, Recovery Portal).

**Where it plugs in:**

- Safe Update Tester: “AI Summary” panel in report.
- Conflict Scanner: “AI Conclusion” + “Draft Email” button.
- Recovery Portal: “Ask AI to explain this error” on Logs panel.

---

### 2.6 Advanced Recovery Features (Pro)

#### 2.6.1 Full Restore from Any Backup

Free: restore only **latest backup** via Recovery Portal.  
Pro: restore **any backup** and choose restore type.

Features:

- In Recovery Portal (Pro mode):
  - **Full list of available backups**:
    - Date, type, size, storage (local/remote).
  - Choose one backup.
  - Choose restore mode:
    - DB-only
    - Files-only
    - Full site (DB + files)
- On restore:
  - Use `SBWP_Restore_Manager` (extended) to:
    - Fetch remote file if necessary.
    - Put site in maintenance mode.
    - Run restore + health checks.
  - Log event with backup details and type.

**Where it plugs in:**

- Extends Recovery Portal UI:
  - “Restore Latest Backup” tab becomes “Restore Backups”:
    - Free: single latest view.
    - Pro: full list + restore options if Pro is active.

---

#### 2.6.2 Selective Plugin Rollback (Per-plugin Restore)

Goal: When a specific plugin update crashes the site, allow a **quick rollback** from a backup, even if wp-admin is dead.

Features:

- Track plugin states in each backup:
  - Add `plugins_snapshot` (JSON) to `wp_sb_backups`.
- Track update events in new table `wp_sb_update_events`:
  - Which plugin was updated from which version to which.
  - Which backup was taken right before the update.

In Recovery Portal (Pro):

- New section: **Repairs & Rollbacks → Plugin Rollback**
- Show recent plugin updates:

  | Plugin      | From → To   | Updated At            | Backup | Actions       |
  |-------------|------------|------------------------|--------|---------------|
  | Plugin A    | 1.2 → 1.3  | 2025-12-26 14:32       | #42    | Rollback      |

- On rollback:
  - Extract plugin dir from backup archive to temp.
  - Put site into maintenance mode.
  - Swap plugin directory atomically:
    - Rename current to `{slug}-sbwp-backup-{timestamp}`.
    - Move restored version into `wp-content/plugins/{slug}`.
  - Health check:
    - Hit front page + login page.
  - If successful:
    - Leave old plugin dir as fallback, offer “Delete old copy”.
  - If worse:
    - Offer “Undo rollback” (swap back to previous directory).

**Where it plugs in:**

- New Pro-only Recovery Portal area:
  - Tab: **“Plugin Rollback (Pro)”** under “Repairs & Rollbacks”.
- Also optionally:
  - A quick rollback option in wp-admin → Backups → specific backup view (for non-crash scenarios).

---

### 2.7 Enhanced Logging & Job Management (Pro)

While Free can get by with simpler logging, Pro will:

- Extend `wp_sb_jobs` and `wp_sb_logs` to track:
  - Mock update sessions.
  - Conflict scans.
  - AI analyses.
  - Remote backup upload failures.

These logs will power:

- Better status panels in Dashboard:
  - Last mock update session result.
  - Last conflict scan.
  - Last scheduled backup status.

**Where it plugs in:**

- Extends Dashboard page with Pro-only panels.
- Extends internal logger & job system.

---

## 3. UI Placement Summary (Where Pro Features Live)

### 3.1 In wp-admin

**Menu: SafeBackup & Repair WP**

- **Dashboard**
  - Free:
    - Status cards, quick actions.
  - Pro:
    - Adds:
      - Last mock update result.
      - Last conflict scan summary.
      - Last scheduled backup result.

- **Backups**
  - Free:
    - Backup list, manual backup, restore, delete.
  - Pro:
    - Adds:
      - Destination indicators (local/remote).
      - Actions for downloading from remote.
    - Sub-tab: **Schedules (Pro)** for scheduled backups.

- **Safe Update Tester (Pro)**
  - New dedicated page.
  - UI:
    - Select what to test (plugins/themes/core).
    - Start mock update.
    - View results + AI summary.
    - Button to **Apply safe updates to live (with new backup)**.

- **Conflict Scanner (Pro)**
  - New page.
  - UI:
    - Configure test URLs.
    - Start scan.
    - View result table + AI summary + draft email(s).

- **Recovery & Tools**
  - Free:
    - Generate recovery token, open Recovery Portal link.
  - Pro:
    - Adds:
      - Shortcuts to:
        - “Open Recovery Portal (Pro features active)”
        - “Last Recovery Portal actions” (log info).

- **Settings**
  - Free:
    - General settings (retention, etc.).
  - Pro:
    - New tabs:
      - **Remote Storage (Pro)** – GDrive, DO Spaces.
      - **AI (Pro)** – provider, API key, feature toggles.

- **License (Pro)**
  - Separate page or tab under Settings.
  - License key input, plan info.

---

### 3.2 In Recovery Portal

Tabs / Sections (Pro vs Free):

1. **Plugins & Themes**
   - Same core UX for Free & Pro.
   - Pro could show “suspect plugins” tagged from Conflict Scanner (future nice-to-have).

2. **Logs**
   - Free: raw `debug.log` tail + search.
   - Pro:
     - “Ask AI to Explain” button:
       - Sends relevant log excerpt to AI, shows summary + suggested steps.

3. **Restore Backups**
   - Free:
     - Only shows **latest backup** and “Restore latest full backup”.
   - Pro:
     - List all backups:
       - Actions:
         - DB-only, Files-only, Full restore.
     - If remote:
       - Badges and remote fetch logic.

4. **Repairs & Rollbacks (Pro area)**
   - **Plugin Rollback (Pro)**:
     - List recent plugin updates tied to backups.
     - Rollback flow as described above.
   - (Future) Additional repair tools.

---

## 4. Shared vs Pro-Only Classes

**Shared (Free + Pro)**

- `SBWP_Backup_Engine`
- `SBWP_Restore_Manager`
- `SBWP_Recovery_Portal`
- `SBWP_Admin_UI`
- `SBWP_Logger`
- `SBWP_REST_API` (if used)

**Pro-Only (in /pro or similar directory)**

- `SBWP_Pro_Loader` (bootstrap for Pro features)
- `SBWP_Remote_GDrive`
- `SBWP_Remote_DOSpaces`
- `SBWP_Clone_Manager`
- `SBWP_Update_Tester`
- `SBWP_Conflict_Scanner`
- `SBWP_AI_Analyzer`
- `SBWP_Schedules_Manager`
- `SBWP_Pro_Admin_UI_Extensions` (adds tabs/sections)
- `SBWP_License_Manager`

---

## 5. Pro Implementation Phases

**Note on UI/UX:** All Pro features will use the shared **React + shadcn/ui** stack defined in the Free plan. Pro components (like the "Safe Update Tester") will simply be new React views/components injected into the main Admin app.

1. **Phase P1 – Remote & Schedules**
   - Remote backup support (GDrive, DO Spaces).
   - Scheduled backups.
   - UI integration in Backups + Settings.

2. **Phase P2 – Clone Manager & Safe Update Tester**
   - Implement `SBWP_Clone_Manager`.
   - Implement `SBWP_Update_Tester`.
   - Basic report view (no AI yet).
   - “Apply safe updates to live” with backup.

3. **Phase P3 – Conflict Scanner**
   - Build `SBWP_Conflict_Scanner`.
   - Basic reporting, leverage clone + health checks.

4. **Phase P4 – AI Integration**
   - `SBWP_AI_Analyzer` and AI Settings tab.
   - AI summaries for:
     - Update Tester.
     - Conflict Scanner.
     - Recovery Portal logs.

5. **Phase P5 – Advanced Recovery**
   - Full restore from **any backup** via Recovery Portal.
   - Plugin Rollback from backups.
   - Extra polish & safety checks.

---

With this split:

- `plan-free.md` gives you a lean but powerful core plugin.
- `plan-pro.md` clearly shows **what Pro adds** and **where each feature lives** in wp-admin and the Recovery Portal.

When you’re ready, we can take one of these (e.g., **Free version Phase 1**) and start drafting actual class skeletons / code structure to feed into Cursor.
