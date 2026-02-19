# Asset Status

Composer-managed scaffolding for MakeHaven’s asset status, issue triage, and
maintenance logging module.

## Features in this iteration

- Bundleable content entity (`asset_log_entry`) with revision support and fields
  for asset reference, reported/confirmed status, summary, and detailed notes.
- Config entities for log entry bundles (`status_change`, `maintenance`,
  `inspection`) so workflows can diverge without schema changes.
- Administrative listing pages and forms exposed under **Content → Asset log**
  and **Structure → Asset log entry types** once the module is enabled.
- Permission stubs for staff to administer and triage records.
- Automatic `status_change` entries created whenever an `item` node’s
  `field_item_status` value is created, updated, or cleared. The service class
  lives at `asset_status.status_change_logger` and can be injected wherever
  future tooling (REST endpoints, batch processors) needs to create log entries.

## Install & local development

1. Pull the package through Composer (the main repo already references it):
   ```bash
   lando composer require makehaven/asset_status:dev-main
   ```
2. Remove any previously checked-in copy of `web/modules/custom/asset_status`
   that Composer might report as “would overwrite existing directory”.
3. Clone the dedicated GitHub repository so the module lives outside the
   Pantheon repo’s ignored tree:
   ```bash
   rm -rf web/modules/custom/asset_status
   git clone git@github.com:makehaven/asset_status.git web/modules/custom/asset_status
   ```
   Work on feature branches in that repository, then tag releases or merge to
   `main` so Composer delivers updates downstream.
4. Install the module:
   ```bash
   lando drush en asset_status -y
   ```
   The default bundles in `config/install` will populate automatically.
5. Assign permissions (`admin/people/permissions`) to the appropriate staff
   roles:
   - `review asset status reports`
   - `log asset maintenance events`
   - `administer asset log entries`
6. Configure module settings at `/admin/config/content/asset-status`:
   - Choose whether maintenance history is visible to all authenticated users
     or restricted to asset-status permissions.

## Mandatory configuration checklist

Run through these items the first time the module is enabled on an environment:

1. **Verify taxonomy + node fields** – Ensure `field_item_status` exists on the
   `item` content type and that its allowed vocabulary (`item_status`) contains
   the “Up/Impaired/Down/Storage/etc.” values you expect to expose publicly.
2. **Expose the status block** – The legacy `views.view.asset_status` block can
   stay in breadcrumb regions for now, but plan to swap it for a formatter that
   also surfaces recent `asset_log_entry` notes.
3. **Grant permissions** – Assign the three provided permissions to whichever
   staff roles should triage logs and run maintenance workflows.
4. **Set history visibility mode** – At
   `/admin/config/content/asset-status`, choose whether maintenance history is
   collaborative (all authenticated users) or permission-gated.
5. **Clear caches** – Run `lando drush cr` any time the module definitions
   change so Drupal can discover the new entity type and services.
6. **Smoke test logging** – Edit an `item` node, flip `field_item_status`, and
   confirm a new `status_change` entry appears under **Content → Asset log**.

## Manual data model extensions

This module intentionally ships lean fields so each makerspace can tailor the
log entries they need. Additions you can make through the UI without touching
code:

1. **Repair metadata** – Add entity reference/decimal fields to the
   `maintenance` bundle for “work order #”, “parts used”, “time to repair”, and
   “cost”. Use the Field UI on each bundle (`/admin/structure/asset-log-entry-types`).
2. **Workflow states** – If you need multi-step repairs, create a workflow in
   Drupal core and attach it via the `default_workflow_state` property on each
   bundle (config export lives at `asset_status.asset_log_entry_type.*`).
3. **Public displays** – Build a View of `asset_log_entry` filtered by asset and
   expose it on `node/%/asset-log` or embed it within the existing asset page
   display. Members should be able to see at least the last resolved issue and
   current open work.
4. **Slack routing fields** – Ensure every tool has either
   `field_item_slack_channel` populated or inherits one from its area-of-interest
   taxonomy term (`field_interest_slack_channel`). The upcoming Slack queue
   worker will read these values to decide where to broadcast updates.
5. **Webform handler mapping** – The `Asset Status Updater` webform handler now
   supports configurable submission keys and issue-value mappings, so adopters
   can reuse the module even when webform element machine names differ.
6. **Slack routing audit (optional)** – Use
   `drush asset-status:slack-audit` to report which `item` nodes resolve a Slack
   channel via `field_item_slack_channel` or area-interest fallback.

Document any bespoke fields you add in this README so downstream deployers (or
future AI assistants) know the canonical data structures.

## Next build steps

- Replace the `/report-mess` and `/equipment/issue` flows with directed
  controllers that create member-submitted log entries.
- Add dashboards/REST outputs for uptime KPIs powered by `asset_log_entry`
  revisions instead of raw node data.
- Fold Slack notifications into a queue-backed service so changes to log
  entries, not just node edits, notify the relevant channel.

Keep module comments and docs aligned with Drupal coding standards (PSR-4,
Drupal CS) and capture future behaviour changes in this README as the module
evolves.

## Follow-up backlog

Higher-risk integration work is tracked in `TODO.md` (queue-based Slack posting,
delivery retries, secret handling, and channel coverage operations).
