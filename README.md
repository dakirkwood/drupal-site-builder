# Drupal Site Builder

## Overview

Drupal Site Builder is a Drupal custom module that automates site building from a Build Spec spreadsheet. Instead of manually creating bundles, fields, image styles, and other configuration through the Drupal admin UI, the Build Spec is exported as CSV files and imported via a Drush command that creates the corresponding Drupal configuration.

## How It Works

The Build Spec is a Google Sheets document with separate tabs for each configuration type (bundles, fields, image styles, etc.). Each tab is exported as a CSV file and saved to a location accessible to Drush (typically somewhere within the project directory). The CSV files are then imported one at a time using the `dsbi` command with the appropriate `--type` option.

Each row in the CSV has an `X` column that controls what happens:
- **blank/other** — Create or update the configuration
- **d** — Delete the configuration
- **x**, **w**, **h** — Skip the row (no action)

The command backs up the database before each import run and outputs a summary table showing what was created, updated, or deleted.

### Import order

The CSVs should be imported in this order, since later types depend on earlier ones:

1. Bundles
2. Fields
3. Image Styles
4. Responsive Image Styles
5. View Modes

Beyond these five, the remaining types (views, views displays, migrations, workflows, workflow states, workflow transitions, user roles) can be imported in whatever order makes sense based on their dependencies.

### Finishing up

After all imports are complete, run `drush dsbf` to apply standard base field overrides: disabling certain fields from form displays, marking fields as non-translatable, and enabling Layout Builder on node bundles. The fields affected are configured in `config/install/drupal_site_builder.settings.yml`.

## Installation

### Via Composer (recommended)

1. Create a ticket branch:
   ```bash
   git checkout -b [client-abbreviation]-[ticket-number]
   ```

2. Add the repository to the project's `composer.json` repositories array:
   ```json
   {
       "type": "git",
       "url": "git@github.com:dakirkwood/drupal-site-builder.git"
   }
   ```

3. Require the module as a dev dependency:
   ```bash
   ddev composer require --dev dakirkwood/drupal_site_builder:dev-main
   ```

4. Enable the module:
   ```bash
   ddev drush en drupal_site_builder -y
   ```

### Via git submodule

1. Add the submodule:
   ```bash
   git submodule add git@github.com:dakirkwood/drupal-site-builder.git web/modules/custom/drupal_site_builder
   ```

2. Install the League/CSV dependency:
   ```bash
   ddev composer require league/csv
   ```

3. Enable the module:
   ```bash
   ddev drush en drupal_site_builder -y
   ```

## Commands

### `drupal_site_builder:import` (alias: `dsbi`)

Imports site configuration from a Build Spec CSV export.

```bash
drush dsbi /path/to/bundles.csv --type=bundles
```

**Argument:**
- `file` — Path to the CSV file (relative to the Drupal root).

**Options:**
- `--type` — The configuration type being imported. One of:
  - `bundles` (default)
  - `fields`
  - `img-styles`
  - `reimg-styles`
  - `view-modes`
  - `views`
  - `views-displays`
  - `migrations`
  - `wf`
  - `wf-states`
  - `wf-trans`
  - `user-roles`
- `--id` — Process a single record by machine name instead of the full CSV.
- `--fieldId` — Process a single field by `bundle/field_name` format.

### `drupal_site_builder:finish` (alias: `dsbf`)

Applies post-import base field overrides across all content entity bundles.

```bash
drush dsbf
```

## Error Codes

| Code | Description |
| ---- | ----------- |
| err-D | Error deleting the configuration |
| err-F | Error creating the Field instance configuration |
| err-FS | Error creating the Field Storage configuration |

## Development

See [MANIFESTO.md](MANIFESTO.md) for the module's contract (purpose, guarantees,
and non-goals).

The module ships with a quality-gate toolchain. The dev dependencies
(`drupal/coder`, `phpstan/phpstan`, `mglaman/phpstan-drupal`, `drupal/core-dev`)
are declared in `composer.json` under `require-dev`.

### Coding standards (PHPCS)

Drupal + DrupalPractice standards are configured in `phpcs.xml.dist` and enforced
in CI:

```bash
composer phpcs    # check
composer phpcbf   # auto-fix
```

### Static analysis (PHPStan)

PHPStan is configured in `phpstan.neon.dist`. It requires a bootstrapped Drupal,
so run it from a site that contains the module:

```bash
vendor/bin/phpstan analyse web/modules/custom/drupal_site_builder/src --memory-limit=2G
```

### Tests

Tests live in `tests/` (`tests/src/Unit` and `tests/src/Kernel`). Kernel tests
need a database, so run them inside the Drupal site (e.g. with ddev):

```bash
ddev exec php vendor/bin/phpunit -c phpunit.xml web/modules/custom/drupal_site_builder/tests
```
