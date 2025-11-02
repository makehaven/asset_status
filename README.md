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

## Next build steps

- Integrate status-change automation so updates on `item` nodes create log
  entries and keep the node’s status fields in sync.
- Replace the `/report-mess` and `/equipment/issue` flows with directed
  controllers that create member-submitted log entries.
- Add dashboards/REST outputs for uptime KPIs.

Keep module comments and docs aligned with Drupal coding standards (PSR-4,
Drupal CS) and capture future behaviour changes in this README as the module
evolves.
