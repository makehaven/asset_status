# Asset Status TODO

This file captures higher-risk or broader integration work that should follow
the low-risk hardening already completed.

## Priority 1 (Important, larger changes)

- Replace `slack_asset_status_change` hook/cURL posting with an
  `asset_status` Slack service using Drupal HTTP client and a queue worker.
- Post from `asset_log_entry` create/update events (status_change,
  maintenance, inspection) so Slack reflects full workflow, not only node
  status flips.
- Add a module setting for fallback/default Slack channel when no item/area
  channel is found.
- Add delivery retry + dead-letter logging (failed channel/webhook sends).

## Priority 2 (Data quality + operations)

- Backfill Slack channel coverage for uncovered item nodes (currently many items
  have neither `field_item_slack_channel` nor an area-interest fallback with
  `field_interest_slack_channel`).
- Add an admin report page for unresolved Slack routing (or export from Drush
  command) and assign content ownership for cleanup.
- Define policy for member report notifications:
  always post inspection reports vs only post escalations.

## Priority 3 (Security + portability)

- Move Slack secrets out of exported config and into environment-specific
  settings/secrets management.
- Remove production host hardcoding and replace with explicit config-based
  enablement flag for Slack posting.
- Add functional browser tests for history tab visibility once BrowserTestBase
  environment/plugin bootstrap issue is resolved.
