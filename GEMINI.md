# Project Overview
**Asset Status** is a custom Drupal module designed to provide structured asset status tracking, logging, and reporting tools for MakeHaven. It introduces a new content entity, `asset_log_entry`, to record history for assets (specifically `item` nodes) such as status changes, maintenance records, and inspections.

## Key Features
*   **Asset Log Entity:** A revisionable content entity (`asset_log_entry`) that references an asset (`item` node) and stores a summary, detailed notes, and status snapshots.
*   **Bundles:** Supports different types of log entries via config entities:
    *   `status_change`: Automatically created when an asset's status changes.
    *   `maintenance`: For logging repairs or upkeep.
    *   `inspection`: For routine checks.
*   **Automatic Logging:** The `StatusChangeLogger` service watches for changes to the `field_item_status` field on `item` nodes and automatically records a `status_change` log entry.
*   **Integration:** Tightly coupled with the `item` content type and `item_status` taxonomy vocabulary.

## Architecture
*   **Entities:**
    *   `AssetLogEntry` (`src/Entity/AssetLogEntry.php`): The main content entity.
    *   `AssetLogEntryType` (`src/Entity/AssetLogEntryType.php`): The bundle configuration entity.
*   **Services:**
    *   `asset_status.status_change_logger` (`src/Service/StatusChangeLogger.php`): Handles the logic for detecting status changes and creating log entries.
*   **Hooks:**
    *   `asset_status_entity_insert` / `asset_status_entity_update` (`asset_status.module`): Trigger the logger service on node saves.

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
4.  **Prerequisites:** Ensure the `item` content type exists and has a `field_item_status` field referencing the `item_status` taxonomy.

# Development Conventions

*   **Coding Standard:** Follow Drupal coding standards (PSR-4, Drupal CS).
*   **Services:** Use dependency injection for all services.
*   **Configuration:** Manage bundles and fields via configuration management (`config/install` or `config/schema`).
*   **Testing:** Verify status change logging by editing an `item` node and changing its status, then checking for a new entry in **Content → Asset log**.

# Directory Structure

*   `src/Entity/`: Entity definitions (`AssetLogEntry`, `AssetLogEntryType`).
*   `src/Form/`: Entity forms (Add, Edit, Delete).
*   `src/Service/`: Business logic services (`StatusChangeLogger`).
*   `src/AccessControl/`: Access control handlers.
*   `config/install/`: Default configuration for bundles (`maintenance`, `inspection`, `status_change`).
*   `asset_status.module`: Hook implementations.
