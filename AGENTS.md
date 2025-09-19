# Repository Guidelines

## Project Structure & Module Organization
The Composer plugin lives in `src/` under the `Saucebase\\ModuleInstaller` namespace. Installer behaviour and plugin lifecycle hooks are split between `Installer.php` and `Plugin.php`, with custom exceptions in `src/Exceptions/`. PHPUnit specs are in `tests/`, mirroring the namespace and ending with `Test.php`. Repo-level configuration (Composer metadata, Pint formatting, PHPUnit, licensing) resides in the repository root. `vendor/` is Composer-managed—never commit manual edits there.

## Build, Test, and Development Commands
- `composer install` installs dependencies and autoload configuration for local development.
- `composer test` (alias for `./vendor/bin/phpunit`) runs the full PHPUnit 12 suite defined in `phpunit.xml`.
- `./vendor/bin/pint` formats the codebase using the Laravel preset; add `--test` in CI-style checks.
- `composer validate` ensures `composer.json` remains consistent with plugin requirements.

## Coding Style & Naming Conventions
Follow PSR-12/Laravel Pint conventions: four-space indentation, brace-on-next-line for classes/methods, and strict types where applicable. Class names should be StudlyCase and match filenames, e.g., `Installer` in `src/Installer.php`. Tests fall under the `Tests` namespace and may extend the shared `Tests\TestCase`. Run Pint before opening a PR to keep automated workflows green.

## Testing Guidelines
Author PHPUnit tests alongside new behaviour in `tests/`. Name files after the subject with a `*Test.php` suffix and prefer `final` test classes. Use mocks for Composer collaborators, as demonstrated in `tests/ModuleInstallerTest.php`, and cover both happy paths and error conditions (e.g., missing module vendor prefixes). Ensure new logic runs through `composer test` locally before submission; add regression tests whenever fixing bugs.

## Commit & Pull Request Guidelines
Commits in this repo favour Conventional Commit prefixes (`feat:`, `chore:`, `fix:`). Keep changesets focused, mention relevant Composer behaviour, and include reproduction steps in commit bodies when fixing bugs. Pull requests should link tracking issues, describe installer impacts (e.g., module directory resolution), and note any manual verification (commands run, configs touched). Include screenshots only when output or error logs materially aid reviewers.
