<?php

namespace Saucebase\ModuleInstaller;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;

class Installer extends LibraryInstaller
{
    const DEFAULT_ROOT = "modules";
    const SAUCEBASE_TYPE = "saucebase-module";

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
        if (!$this->composer || !$this->composer->getPackage()) {
            return self::DEFAULT_ROOT;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (!$extra || empty($extra['module-dir'])) {
            return self::DEFAULT_ROOT;
        }

        return $extra['module-dir'];
    }

    /**
     * Get the module name, i.e. "saucebase/something-nice" will be transformed into "SomethingNice"
     *
     * @param PackageInterface $package Compose Package Interface
     *
     * @return string Module Name
     *
     * @throws ModuleInstallerException
     */
    protected function getModuleName(PackageInterface $package)
    {
        $name = $package->getPrettyName(); // e.g. "saucebase/something-nice"

        if (strpos($name, "/") === false) {
            throw new ModuleInstallerException("Invalid package name: $name");
        }

        // Take only the part after the vendor (index 1)
        [$vendor, $packageName] = explode("/", $name, 2);

        // Split by "-" and convert each segment to ucfirst
        $parts = explode("-", $packageName);

        return implode('', array_map('ucfirst', $parts));
    }

    public function supports($packageType)
    {
        return self::SAUCEBASE_TYPE === $packageType;
    }
}
