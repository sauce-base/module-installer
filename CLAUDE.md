# module-installer

A Composer plugin that installs `saucebase-module` packages into `modules/<name>` (lowercase, hyphenated). Lives at `packages/module-installer` in the Saucebase workspace.

## Key Files

| File | Purpose |
|---|---|
| `src/Plugin.php` | Plugin lifecycle: `activate`, `deactivate`, `uninstall`. Registers/unregisters the Installer with Composer's installation manager. |
| `src/Installer.php` | All installer logic (~780 lines). Extends `LibraryInstaller`. Overrides `install`, `update`, `uninstall`. |
| `tests/ModuleInstallerTest.php` | Full PHPUnit 12 suite. Uses `TestableInstaller` (a subclass that exposes protected methods via `call*` proxies and public override flags). |

## Configuration Keys

All read from the root `composer.json` `extra` section. Constants in `Installer.php`:

| Key | Constant | Default |
|---|---|---|
| `module-dir` | `DEFAULT_ROOT` | `modules` |
| `module-type` | `DEFAULT_MODULE_TYPE` | `laravel-module` |
| `module-exclude-dirs` | `DEFAULT_EXCLUDED_DIRS` | `['.github', '.git']` |
| `module-update-strategy` | `DEFAULT_UPDATE_STRATEGY` | `merge` |
| `module-delete-on-remove` | *(no constant)* | `false` |

The Saucebase app (`saucebase/composer.json`) uses `module-type: saucebase-module` and `module-dir: modules`.

## Core Behaviours

**Install** (`install()`): Downloads the package, removes excluded directories, and deploys frontend framework files if `frontend.json` is present. Skips entirely for path-repository packages.

**Update** (`update()`): Two strategies:
- `merge` (default) — stashes the user's copy, downloads the new version, downloads the original version as a base, runs a 3-way `git merge-file`. Conflicts are staged in the git index (stages 1/2/3) so they appear in `git status`.
- `overwrite` — replaces the directory entirely.
Skips for path-repository packages.

**Uninstall** (`uninstall()`): Two guards fire in order:
1. Path repository → skip (never delete a dev working copy).
2. `module-delete-on-remove` not set → skip by default (folder stays, package removed from Composer tracking).
Only calls `parent::uninstall()` (which deletes the folder) when `module-delete-on-remove: true` is explicitly set.

**Frontend framework support**: Reads `frontend.json` from the project root. Copies files from the matching framework subdirectory (`vue/`, `react/`, or `svelte/`) flat into `resources/js/` and removes all framework subdirs. Silent-skips when `frontend.json` is absent or `"dev": true`.

## Development Commands

```bash
composer test          # run PHPUnit 12 suite
./vendor/bin/pint      # format with Laravel Pint
./vendor/bin/pint --test  # CI-style check (no writes)
composer validate      # verify composer.json
```

## Testing Patterns

- Test class: `Tests\ModuleInstallerTest` (final, extends `Tests\TestCase`).
- `TestableInstaller` subclass exposes protected methods via `call*` proxies (e.g. `callShouldDeleteOnRemove()`) and override flags (e.g. `$forcePathRepository`). Use this pattern when testing new protected methods — avoid reflection.
- Mock Composer collaborators (`IOInterface`, `InstalledRepositoryInterface`, `PackageInterface`, `Composer`) with PHPUnit stubs/mocks. Avoid hitting the filesystem unless the test specifically verifies file operations.
- Group related tests with a comment banner: `// ---- section name ----`.

## Integration with the Saucebase App

The package is a `require-dev` dependency in `saucebase/composer.json` at `^2.6.0`. To test local changes against the app, add a path repository entry in `saucebase/composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/module-installer"
        }
    ]
}
```

Then run `composer update saucebase/module-installer` in the `saucebase/` directory.
