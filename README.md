# Saucebase Module Installer

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](#requirements)
[![Composer](https://img.shields.io/badge/Composer-2.x-885630?logo=composer&logoColor=white)](#requirements)
[![Tests](https://github.com/saucebase-dev/module-installer/actions/workflows/php.yml/badge.svg)](https://github.com/saucebase-dev/module-installer/actions/workflows/php.yml)
[![License](https://img.shields.io/badge/License-MIT-0A7EA4)](#license)

A Composer plugin that installs Saucebase modules into the correct directory. It ships with [`saucebase-dev/saucebase`](https://github.com/saucebase-dev/saucebase), so every module your project requires is placed where the app can find and load it.

## How It Works

- Registers a Composer installer for a configurable module package type (defaults to `laravel-module`; the Saucebase app uses `saucebase-module`).
- Installs each module inside a configurable modules directory (`modules/` by default, lowercase).
- Derives the directory name from the package slug: `saucebase/example-module` → `modules/example-module`.
- On `composer update`, **merges** the new version into your existing module directory by default, preserving local edits. Conflicts are staged in the git index for review.
- Removes configurable directories (e.g. `.github`, `.git`) after each install or update.
- Deploys frontend framework files (Vue / React / Svelte) based on the project's `frontend.json` selection.
- Protects path-repository modules (symlinks or local `.git` clones) from being overwritten or deleted.
- Skips folder deletion by default when a module is removed via `composer remove` — the directory stays on disk for manual review.

## Requirements

- PHP 8.4 or newer
- Composer 2.x
- A project based on `saucebase-dev/saucebase` (the core already requires this plugin)

## Installation

`saucebase-dev/saucebase` already requires this package. When you install the core, Composer pulls in the plugin and activates it through the `Saucebase\\ModuleInstaller\\Plugin` class — no extra configuration is needed for a typical Saucebase project.

Need the installer for a different Composer project? Require it directly:

```bash
composer require saucebase/module-installer
```

## Configuration Reference

All options live in the `extra` section of the **root** `composer.json`. Every key is optional.

| Key | Default | Description |
|---|---|---|
| `module-type` | `laravel-module` | Composer package type the installer handles. |
| `module-dir` | `modules` | Directory modules are installed into. |
| `module-exclude-dirs` | `[".github", ".git"]` | Directories removed from each module after install/update. |
| `module-update-strategy` | `merge` | `merge` preserves local edits; `overwrite` replaces entirely. |
| `module-delete-on-remove` | `false` | Set to `true` to delete the module folder on `composer remove`. |

Example (the Saucebase app configuration):

```json
{
    "extra": {
        "module-type": "saucebase-module",
        "module-dir": "modules"
    }
}
```

## Configuring the Module Type

The installer registers the `laravel-module` package type by default. Override it in the root package `extra` section:

```json
{
    "extra": {
        "module-type": "saucebase-module"
    }
}
```

Any module packages must set their `composer.json` `type` to the same value.

## Configuring the Install Location

By default, modules are installed under `modules/` at the project root. Change this with the `module-dir` key:

```json
{
    "extra": {
        "module-dir": "custom-modules"
    }
}
```

With the configuration above, `saucebase/example-module` installs to `custom-modules/example-module`.

## Configuring Excluded Directories

After each install or update, the installer removes directories that should not land in production. The default list is `.github` and `.git`. Override it with `module-exclude-dirs`:

```json
{
    "extra": {
        "module-exclude-dirs": [".github", ".git", ".vscode", "tests"]
    }
}
```

Set it to `[]` to skip all removal. The configured list replaces the default entirely.

## Configuring Update Behaviour

By default, when you run `composer update`, the installer **merges** the new package version into your existing module directory rather than replacing it.

| Strategy | What happens on `composer update` |
|---|---|
| `merge` *(default)* | New files from the package are added. Your edited files are kept as-is. Conflicts between your edits and upstream changes are staged in the git index (conflict markers in the file, both sides visible in `git diff`). |
| `overwrite` | The module directory is replaced entirely with the new package contents. All local edits are lost. |

### Keeping your customisations (default)

No configuration needed. Running `composer update` adds new files without touching files you have already edited.

> **Note:** Because your local files take precedence, bug fixes or updates to files you have customised will **not** be applied automatically. To pick up an upstream change to a specific file, delete it and re-run `composer update`.

### Merge conflicts

When both you and the upstream package changed the same file, the installer stages a 3-way conflict in the git index (stages 1/2/3). The working-tree file contains standard conflict markers and `git status` shows it as a conflict for normal resolution via `git mergetool` or your editor.

### Full overwrite (CI / reproducible builds)

Set the strategy to `overwrite` to produce an exact copy of the package on every update:

```json
{
    "extra": {
        "module-update-strategy": "overwrite"
    }
}
```

This is recommended for CI pipelines and staging environments where reproducibility matters more than preserving local changes.

### Getting a completely fresh copy of a module

With the `merge` strategy (default), delete the module directory and run `composer update`:

```bash
rm -rf modules/example-module
composer update vendor/example-module
```

The installer performs a clean install into the now-empty path.

## Skipping Module Deletion on Remove

By default, running `composer remove` on a module **does not delete the module directory**. The package is removed from `composer.lock` and Composer's tracking, but the folder stays on disk for manual review.

```
- Skipping deletion of module directory (set module-delete-on-remove: true to enable): modules/example-module
```

To restore the old behaviour and delete the folder automatically:

```json
{
    "extra": {
        "module-delete-on-remove": true
    }
}
```

> **Why skip by default?** A `composer remove` followed by `composer install` in CI will not re-download an untracked folder — but it also will not delete your work. The conservative default prevents accidental data loss; opt in to deletion explicitly.

## Frontend Framework Support

When a module ships JavaScript resources for multiple frameworks (Vue, React, or Svelte), the installer copies only the files for the active framework flat into `resources/js/` and removes the other framework subdirectories.

The active framework is read from `frontend.json` in the project root:

```json
{
    "framework": "vue"
}
```

Valid values are `vue`, `react`, and `svelte`. If `frontend.json` is absent, unreadable, or has `"dev": true`, no framework files are copied and all framework subdirectories are left in place.

Module packages should ship framework files under subdirectories matching the framework name:

```
resources/
  js/
    vue/
      Component.vue
    react/
      Component.tsx
```

After install with `"framework": "vue"` active, the result is:

```
resources/
  js/
    Component.vue   ← copied flat from vue/
```

## Creating Sauce Base Modules

To ship a module that works with this installer:

1. Set the package `type` in the module `composer.json` to whatever the application expects (e.g. `saucebase-module`).
2. Organise module code inside the directory the installer will create (e.g. `modules/your-module-name`).
3. Have consumers install the module through Composer:

   ```bash
   composer require vendor/your-module-name
   ```

   The plugin derives the install directory from the package slug. `vendor/your-module-name` installs to `modules/your-module-name` (or the configured `module-dir`).

## Local Development

Clone the repository and install dependencies:

```bash
composer install
```

Useful scripts:

- `composer test` — run the PHPUnit 12 suite (`tests/`).
- `./vendor/bin/pint` — apply Laravel Pint formatting (add `--test` for CI-style checks).
- `composer validate` — verify Composer metadata.

## License

Licensed under the [MIT License](./LICENSE).
