# Saucebase Module Installer

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](#requirements)
[![Composer](https://img.shields.io/badge/Composer-2.x-885630?logo=composer&logoColor=white)](#requirements)
[![Tests](https://github.com/sauce-base/module-installer/actions/workflows/php.yml/badge.svg)](https://github.com/sauce-base/module-installer/actions/workflows/php.yml)
[![License](https://img.shields.io/badge/License-MIT-0A7EA4)](#license)

Composer plugin that powers module installation inside [Sauce Base](https://github.com/sauce-base/core) projects. The plugin ships with `sauce-base/core`, ensuring every configured module package lands in the correct modules directory for auto-discovery and bootstrap. It remains fully compatible with [nWidart/laravel-modules](https://github.com/nWidart/laravel-modules) and serves as a Sauce Base-focused alternative to [joshbrw/laravel-module-installer](https://github.com/joshbrw/laravel-module-installer).

## What it Does

- Registers a Composer installer for the configured module package type (defaults to `laravel-module`).
- Installs every module into the configured Sauce Base modules directory (`modules/` by default).
- Converts package names (for example `saucebase/example-module`) into StudlyCase directory names (`ExampleModule`).
- Supports overriding the installation root with the root package's `extra.module-dir` setting.

## Requirements

- PHP 8.4 or newer
- Composer 2.x
- A project based on `sauce-base/core` (the core already requires this plugin)

## Installation

This installer is bundled with `sauce-base/core` and activates automatically via the `Saucebase\\ModuleInstaller\\Plugin` class. No extra setup is needed in a standard Sauce Base application.

Need the installer for a different Composer project? Require it explicitly:

```bash
composer require saucebase/module-installer
```

## Configuring the Module Type

By default the installer registers the `laravel-module` package type. If your application needs a different module type, declare it on the root package's `extra` section:

```json
{
    "extra": {
        "module-type": "saucebase-module"
    }
}
```

Any modules you install must set their `composer.json` `type` to match this value.

## Configuring the Install Location

By default, modules are installed under `modules/` at the project root. You can change this by adding a `module-dir` key to your application's `extra` section:

```json
{
    "extra": {
        "module-dir": "Modules"
    }
}
```

With the configuration above, a module published as `saucebase/example-module` will be installed to `Modules/ExampleModule`.

## Authoring Sauce Base Modules

To ship a module that works with this installer:

1. Set the package type in the module's `composer.json` to whatever your application configured (defaults to `laravel-module`).
2. Follow the Sauce Base module folder conventions (your module code will live inside the directory created by the installer).
3. Advise consumers to install the module via Composer:

   ```bash
   composer require vendor/example-module
   ```

   During installation, this plugin computes the final directory name from the package slug—`vendor/example-module` becomes `modules/ExampleModule` unless `module-dir` overrides it.

## Local Development

Clone the repository and install dependencies:

```bash
composer install
```

Useful scripts:

- `composer test` – run the PHPUnit suite (`tests/`).
- `./vendor/bin/pint` – apply Laravel Pint formatting (add `--test` for CI-style checks).
- `composer validate` – verify Composer metadata.

## License

Licensed under the [MIT License](./LICENSE).
