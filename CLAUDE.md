# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Drupal Site Builder (`drupal_site_builder`) is a Drupal custom module that imports site configuration from CSV exports of a "Build Spec" spreadsheet. It automates creation of bundles, fields, image styles, view modes, views, migrations, workflows, and user roles via Drush commands, eliminating manual site building through the Drupal admin UI.

**Requires:** PHP 8.1+, Drupal 10 or 11, `league/csv` ^9, `drupal/paragraphs`, `drupal/pathauto`.

## Key Drush Commands

- `drush drupal_site_builder:import <file> --type=<type>` (alias: `dsbi`) — Main import command. Reads a CSV file and creates/updates/deletes Drupal configuration.
  - `--type` values: `bundles`, `fields`, `img-styles`, `reimg-styles`, `view-modes`, `views`, `views-displays`, `migrations`, `wf`, `wf-states`, `wf-trans`, `user-roles`
  - `--id` or `--fieldId` — Process a single record by machine name (supports `bundle/field_name` format for fields)
- `drush drupal_site_builder:finish` (alias: `dsbf`) — Post-import finisher that disables base fields in form displays, sets non-translatable fields, and enables Layout Builder on node bundles.

## Architecture

### Record class hierarchy

All config types follow the same pattern rooted in `BuildSpecRecord` (abstract base class):

- `BuildSpecRecord` — Defines the contract: `configExists()`, `createConfig()`, `deleteConfig()`, `relatedConfigs()`, `getReport()`. Also provides `getOperation()` which maps CSV `X` column values to operations (`d` → delete, `x`/`w`/`h` → skip, anything else → create/update).
- Concrete record classes (one per `--type`): `BundleRecord`, `FieldRecord`, `ImgStyleRecord`, `ResImgStyleRecord`, `ViewModeRecord`, `ViewsRecord`, `ViewsDisplaysRecord`, `MigrationRecord`, `WorkflowRecord`, `WorkflowStateRecord`, `WorkflowTransitionRecord`, `UserRoleRecord`.

Each record class:
1. Parses a CSV row in its constructor, mapping spreadsheet column names to typed properties
2. Implements `createConfig()` to create Drupal config entities
3. Implements `relatedConfigs()` to set up associated config (form/view displays, translations, path aliases, etc.)
4. Implements `getReport()` to produce summary output for the Drush table

### Command flow (DrupalSiteBuilderCommands)

`buildSpecImport()` → backs up DB → reads CSV via `league/csv` → iterates rows → instantiates the appropriate Record class based on `--type` → calls `createConfig()`/`deleteConfig()`/`relatedConfigs()` per row → outputs progress bar and summary table.

### Supporting code

- `EcConsoleDebug` — Trait mixed into record classes for console debugging.
- `MachineName` — Static utility to generate Drupal machine names from labels (lowercase, underscored, max 32 chars).
- `config/install/drupal_site_builder.settings.yml` — Default settings defining which base fields to disable per entity type and which fields are non-translatable (used by the `dsbf` finish command).

### CSV column conventions

The Build Spec CSVs use specific column headers that map to record properties. Key columns include `X` (operation), `Machine name`, `Name`/`Field label`, `Type`/`Field type`, `Bundle`, `Bundle machine name`. Spacer rows (empty `Machine name`) and rows with `X=x` are skipped.

## Installation (for development)

This module is installed into a Drupal project either via Composer (`ddev composer require --dev electriccitizen/drupal_site_builder:dev-main`) or as a git submodule, then enabled with `drush en drupal_site_builder`.
