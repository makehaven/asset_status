# Project Overview
**Asset Status** is a custom Drupal module designed to provide structured asset status tracking, logging, and reporting tools for MakeHaven. It introduces a new content entity, `asset_log_entry`, to record history for assets (specifically `item` nodes) such as status changes, maintenance records, and inspections.

## Key Features
*   **Asset Log Entity:** A revisionable content entity (`asset_log_entry`) that references an asset (`item` node) and stores a summary, detailed notes, and status snapshots.
*   **Bundles:** Supports different types of log entries via config entities:
    *   `status_change`: Automatically created when an asset's status changes.
    *   `maintenance`: For logging repairs or upkeep.
    *   `inspection`: For routine checks.
*   **Automatic Logging:** The `StatusChangeLogger` service watches for changes to the `field_item_status` field on `item` nodes and automatically records a `status_change` log entry.
*   **Asset Availability Service:** Provides logic to determine if a status (e.g., "Operational", "Degraded") means the tool is usable.
*   **Webform Integration:** `AssetStatusWebformHandler` bridges user-submitted reports (Webform) to the backend system, updating the asset status and creating logs automatically.
*   **Slack Integration:** Integration with `slack_asset_status_change` to post updates to specific channels. Safe for local development (posts only from production domain).

## Architecture
*   **Entities:**
    *   `AssetLogEntry` (`src/Entity/AssetLogEntry.php`): The main content entity.
    *   `AssetLogEntryType` (`src/Entity/AssetLogEntryType.php`): The bundle configuration entity.
*   **Services:**
    *   `asset_status.status_change_logger` (`src/Service/StatusChangeLogger.php`): Handles the logic for detecting status changes and creating log entries.
    *   `asset_status.availability` (`src/Service/AssetAvailability.php`): Helper for status logic.
*   **Plugins:**
    *   `AssetStatusBlock` (`src/Plugin/Block/AssetStatusBlock.php`): Displays the current status with the latest log message ("Why is it down?").
    *   `AssetStatusWebformHandler` (`src/Plugin/WebformHandler/AssetStatusWebformHandler.php`): Handles submissions from the "Report Issue" webform.
*   **Controllers:**
    *   `AssetLogController` (`src/Controller/AssetLogController.php`): Provides the "Log Maintenance" form and "History" tab.

# Building and Running

This project is a Drupal module and relies on a local Lando environment.

## Key Commands
*   **Enable Module:**
    ```bash
    lando drush en asset_status -y
    ```
*   **Clear Cache:**
    ```bash
    lando drush cr
    ```
*   **Run Updates:**
    ```bash
    lando drush updb
    ```
*   **Export Configuration:**
    ```bash
    lando drush cex
    ```

## Installation & Setup
1.  **Require via Composer:**
    ```bash
    lando composer require makehaven/asset_status:dev-main
    ```
2.  **Enable:** Run the enable command above.
3.  **Permissions:** Assign permissions (`administer asset log entries`, `log asset maintenance events`, etc.) to appropriate roles at `/admin/people/permissions`.
4.  **Webform:** Attach the "Asset Status Updater" handler to the "Report of Broken or Malfunctioning Equipment" webform.
5.  **Blocks:** Place the "Asset Status (with details)" block on the Item content type layout.

# Development Conventions

*   **Coding Standard:** Follow Drupal coding standards (PSR-4, Drupal CS).
*   **Services:** Use dependency injection for all services.
*   **Configuration:** Manage bundles and fields via configuration management (`config/install` or `config/schema`).
*   **Testing:** Verify status change logging by editing an `item` node and changing its status, then checking for a new entry in **Content → Asset log**.

# Directory Structure

*   `src/Entity/`: Entity definitions.
*   `src/Form/`: Entity forms.
*   `src/Service/`: Business logic services.
*   `src/Plugin/Block/`: UI blocks.
*   `src/Plugin/WebformHandler/`: Webform integration.
*   `src/Controller/`: Page controllers.
*   `config/install/`: Default configuration.
*   `asset_status.module`: Hook implementations.