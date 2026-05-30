# Drupal Site Builder — Project Manifesto

## Purpose

Drupal Site Builder turns a structured "Build Spec" spreadsheet into live Drupal
configuration. It exists so that a site's information architecture can be authored
once, in a spreadsheet, and applied deterministically to a Drupal site —
eliminating repetitive, error-prone manual configuration through the admin UI.

## Scope

The module reads CSV exports of the Build Spec and creates, updates, or deletes
Drupal **configuration** (not content) for these types: bundles, fields, image
styles, responsive image styles, view modes, views, views displays, migrations,
workflows, workflow states, workflow transitions, and user roles. A finishing
command applies standard base-field overrides and enables Layout Builder on node
bundles.

## The operation contract (the `X` column)

Every Build Spec row carries an `X` column that decides the action, and this
mapping is the core contract:

- blank / any other value → **create or update** the configuration
- `d` → **delete** the configuration
- `x`, `w`, `h` → **skip** the row (no action)

Rows with an empty `Machine name` (spacer rows) are skipped.

## Guarantees

- **Safety first.** Before any import run the module offers to back up the
  database; if the backup fails the user is asked to confirm before continuing.
- **Idempotent.** Re-running an import does not duplicate configuration. A row
  whose configuration already exists is reported as `exists`, not recreated.
- **Transparent.** Every run prints a summary table reporting, per row, what was
  created, updated, deleted, skipped, or errored — with specific error codes
  (`err-F`, `err-FS`, `err-D`, …).
- **Loud failures.** When an operation fails, the module logs a specific,
  actionable message and reflects it in the summary; it never silently swallows
  an error.
- **Targeted runs.** A single record can be processed with `--id` (machine name)
  or `--fieldId` (`bundle/field_name`) without running the whole file.

## Inputs the module trusts (and their boundary)

- CSV files exported from the Build Spec, addressable by a path relative to the
  Drupal root, with the documented column headers per type.
- The operator is a trusted developer/site-builder running Drush on the command
  line. The module performs no web-facing actions and exposes no routes, forms,
  or permissions of its own.
- Inputs are validated at the boundary: an unknown `--type` is rejected with a
  list of allowed values; a missing or unreadable file fails loudly; malformed
  rows are reported rather than applied blindly.

## Required import order

Because later types depend on earlier ones, imports must run in this order for the
first five: Bundles → Fields → Image Styles → Responsive Image Styles → View
Modes. The remaining types may be imported in any dependency-sensible order.

## Non-goals

- It does **not** create or migrate content — only configuration.
- It is **not** a general-purpose config sync replacement for Drupal's CMI; it is
  a one-directional spreadsheet→site builder used during site construction.
- It is **not** a web UI; it is Drush-only and intended for developers.
- It does **not** guarantee round-tripping (Drupal config back to spreadsheet).
- It is an **internal, proprietary** tool, not a drupal.org contrib project.

## Requirements

PHP 8.1+, Drupal 10 or 11, `league/csv` ^9, `drupal/paragraphs`,
`drupal/pathauto`. Run via Drush 12+.

## Diagnostics

Verbose progress is shown via a progress bar and the per-row summary table.
Failures are written to the Drupal/Drush logger with class- and operation-specific
context so a run can be diagnosed after the fact.
