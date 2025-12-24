<?php

namespace Saucebase\ModuleInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;

class Installer extends LibraryInstaller
{
    const DEFAULT_ROOT = 'Modules';

    const DEFAULT_MODULE_TYPE = 'laravel-module';

    const DEFAULT_EXCLUDED_DIRS = ['.github', '.git'];

    public function getInstallPath(PackageInterface $package)
    {
        return $this->getBaseInstallationPath() . '/' . $this->getModuleName($package);
    }

    /**
     * Get the base path that the module should be installed into.
     * Defaults to Modules/ and can be overridden in the module's composer.json.
     *
     * @return string
     */
    protected function getBaseInstallationPath()
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_ROOT;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-dir'])) {
            return self::DEFAULT_ROOT;
        }

        return $extra['module-dir'];
    }

    /**
     * Get the module name, i.e. "saucebase/something-nice" will be transformed into "SomethingNice"
     *
     * @param  PackageInterface  $package  Compose Package Interface
     * @return string Module Name
     *
     * @throws ModuleInstallerException
     */
    protected function getModuleName(PackageInterface $package)
    {
        $name = $package->getPrettyName(); // e.g. "saucebase/something-nice"

        if (strpos($name, '/') === false) {
            throw new ModuleInstallerException("Invalid package name: $name");
        }

        // Take only the part after the vendor (index 1)
        [$vendor, $packageName] = explode('/', $name, 2);

        // Split by "-" and convert each segment to ucfirst
        $parts = explode('-', $packageName);

        return implode('', array_map('ucfirst', $parts));
    }

    public function supports($packageType)
    {
        return $packageType === $this->getSupportedModuleType();
    }

    protected function getSupportedModuleType()
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_MODULE_TYPE;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-type'])) {
            return self::DEFAULT_MODULE_TYPE;
        }

        return $extra['module-type'];
    }

    /**
     * Get the list of directories to exclude during installation.
     * Can be configured via the 'module-exclude-dirs' key in composer.json extra.
     *
     * @return array
     */
    protected function getExcludedDirectories()
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_EXCLUDED_DIRS;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-exclude-dirs'])) {
            return self::DEFAULT_EXCLUDED_DIRS;
        }

        return $extra['module-exclude-dirs'];
    }

    /**
     * Override installCode to filter out excluded directories.
     *
     * @return void
     */
    protected function installCode(PackageInterface $package)
    {
        parent::installCode($package);

        return;

        // $installPath = $this->getInstallPath($package);
        // $excludedDirs = $this->getExcludedDirectories();

        // if (empty($excludedDirs)) {
        //     return;
        // }

        // $filesystem = new Filesystem();

        // foreach ($excludedDirs as $dir) {
        //     $dirPath = $installPath . '/' . $dir;
        //     if (is_dir($dirPath)) {
        //         $filesystem->removeDirectory($dirPath);
        //         $this->io->write("  - Excluded directory: <info>$dir</info>");
        //     }
        // }
    }
}
