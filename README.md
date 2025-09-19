# Saucebase Module Installer

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](#requirements)
[![Composer](https://img.shields.io/badge/Composer-2.x-885630?logo=composer&logoColor=white)](#requirements)
[![Tests](https://github.com/sauce-base/module-installer/actions/workflows/php.yml/badge.svg)](https://github.com/sauce-base/module-installer/actions/workflows/php.yml)
[![License](https://img.shields.io/badge/License-MIT-0A7EA4)](#license)

This Composer plugin installs Sauce Base modules into the correct directory. It ships with `sauce-base/core`, so every module that your project requires is placed where Sauce Base can find and load it. The installer stays compatible with [nWidart/laravel-modules](https://github.com/nWidart/laravel-modules) and offers a Sauce Base-focused alternative to [joshbrw/laravel-module-installer](https://github.com/joshbrw/laravel-module-installer).

## How It Works

- Registers a Composer installer for the module package type (defaults to `laravel-module`).
- Installs each module inside the Sauce Base modules directory (`Modules/` by default).
- Turns package names such as `saucebase/example-module` into StudlyCase directory names (`ExampleModule`).
- Lets the root package override the install path with the `extra.module-dir` option.

## Requirements

- PHP 8.4 or newer
- Composer 2.x
- A project based on `sauce-base/core` (the core already requires this plugin)

## Installation

`sauce-base/core` already requires this package. When you install the core, Composer pulls in the plugin and activates it through the `Saucebase\\ModuleInstaller\\Plugin` class, so a typical Sauce Base project needs no extra configuration.

Need the installer for a different Composer project? Require it directly:

```bash
composer require saucebase/module-installer
```

## Configuring the Module Type

The installer registers the `laravel-module` package type by default. If your application needs a different type, declare it in the root package `extra` section:

```json
{
    "extra": {
        "module-type": "saucebase-module"
    }
}
```

Any modules you install must set their `composer.json` `type` to the same value.

## Configuring the Install Location

By default, modules are installed under `Modules/` at the project root. You can change this by adding a `module-dir` key to your application `extra` section:

```json
{
    "extra": {
        "module-dir": "MyModules"
    }
}
```

With the configuration above, a module published as `saucebase/example-module` installs to `MyModules/ExampleModule`.

## Creating Sauce Base Modules

To ship a module that works with this installer:

1. Set the package `type` in the module `composer.json` to whatever your application expects (defaults to `laravel-module`).
2. Follow the Sauce Base module folder conventions; your module code should live inside the directory created by the installer.
3. Ask consumers to install the module through Composer:

   ```bash
   composer require vendor/example-module
   ```

   During installation, the plugin converts the package slug into the final directory name. For example, `vendor/example-module` becomes `Modules/ExampleModule` unless `module-dir` overrides it.

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
