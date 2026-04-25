<?php

namespace Saucebase\ModuleInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\PartialComposer;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * @property PartialComposer $composer
 * @property IOInterface $io
 */
class Installer extends LibraryInstaller
{
    const DEFAULT_ROOT = 'modules';

    const DEFAULT_MODULE_TYPE = 'laravel-module';

    const DEFAULT_EXCLUDED_DIRS = ['.github', '.git'];

    const DEFAULT_UPDATE_STRATEGY = 'merge';

    const UPDATE_STRATEGY_MERGE = 'merge';

    const UPDATE_STRATEGY_OVERWRITE = 'overwrite';

    public function getInstallPath(PackageInterface $package)
    {
        return $this->getBaseInstallationPath().'/'.$this->getModuleName($package);
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
     * Get the module directory name from the package name.
     * "saucebase/something-nice" → "something-nice" (lowercase slug, hyphens preserved).
     *
     * @param  PackageInterface  $package  Composer Package Interface
     * @return string Module directory name
     *
     * @throws ModuleInstallerException
     */
    protected function getModuleName(PackageInterface $package)
    {
        $name = $package->getPrettyName(); // e.g. "saucebase/something-nice"

        if (strpos($name, '/') === false) {
            throw new ModuleInstallerException("Invalid package name: $name");
        }

        // Take only the part after the vendor (index 1) and lowercase it
        [, $packageName] = explode('/', $name, 2);

        return strtolower($packageName);
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
     * Remove excluded directories after package installation.
     *
     * @return void
     */
    protected function removeExcludedDirectories(PackageInterface $package)
    {
        $installPath = $this->getInstallPath($package);
        $excludedDirs = $this->getExcludedDirectories();

        if (empty($excludedDirs)) {
            return;
        }

        $filesystem = new Filesystem;

        foreach ($excludedDirs as $dir) {
            $dirPath = $installPath.'/'.$dir;
            if (is_dir($dirPath)) {
                $filesystem->removeDirectory($dirPath);
                $this->io->write("  - Excluded directory: <info>$dir</info>");
            }
        }
    }

    /**
     * Get the update strategy to use when updating a module.
     * Defaults to 'merge' and can be overridden via extra['module-update-strategy'].
     */
    protected function getUpdateStrategy(): string
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_UPDATE_STRATEGY;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-update-strategy'])) {
            return self::DEFAULT_UPDATE_STRATEGY;
        }

        $strategy = $extra['module-update-strategy'];
        $allowed = [self::UPDATE_STRATEGY_MERGE, self::UPDATE_STRATEGY_OVERWRITE];

        if (! in_array($strategy, $allowed, true)) {
            $this->io->writeError(
                sprintf(
                    '  <warning>Invalid module-update-strategy "%s". Falling back to "%s".</warning>',
                    $strategy,
                    self::DEFAULT_UPDATE_STRATEGY
                )
            );

            return self::DEFAULT_UPDATE_STRATEGY;
        }

        return $strategy;
    }

    /**
     * Restore a stashed module directory back to its original install path.
     * If the original path was re-created (e.g. by a partial update), the stash is discarded.
     */
    protected function restoreStash(string $stashPath, PackageInterface $package): void
    {
        $originalPath = $this->getInstallPath($package);
        if (! is_dir($originalPath)) {
            (new SymfonyFilesystem)->rename($stashPath, $originalPath);
        } else {
            (new Filesystem)->removeDirectory($stashPath);
        }
    }

    /**
     * Rename the module directory to a temp location and return the temp path.
     * Returns null if the directory does not exist.
     */
    protected function stashModuleDir(string $path): ?string
    {
        if (! is_dir($path)) {
            return null;
        }

        $stash = sys_get_temp_dir().'/module-stash-'.uniqid('', true);
        (new SymfonyFilesystem)->rename($path, $stash);

        return $stash;
    }

    /**
     * Download the given package version into $basePath using Composer's DownloadManager
     * so the dist cache is reused. Returns a promise that resolves when ready.
     */
    protected function downloadBase(PackageInterface $initial, string $basePath): PromiseInterface
    {
        $dm = $this->composer->getDownloadManager();

        return $dm->download($initial, $basePath)
            ->then(fn () => $dm->install($initial, $basePath));
    }

    /**
     * 3-way merge stash (user's copy) against base (original version) into install (new version).
     *
     * Decision table:
     *   stash + base + install  → git merge-file (3-way merge)
     *   stash + base, no install → upstream deleted the file — leave it gone
     *   stash only (no base)    → user-added file — copy to install
     *   install only            → upstream-added file — already there, no action needed
     */
    protected function mergeStash(string $stash, string $base, string $install): void
    {
        $finder = (new Finder)->files()->in($stash);

        foreach ($finder as $file) {
            $rel = $file->getRelativePathname();
            $stashFile = $stash.'/'.$rel;
            $baseFile = $base.'/'.$rel;
            $newFile = $install.'/'.$rel;

            if (! file_exists($baseFile)) {
                // User-added file (not in original dist) — always keep
                (new SymfonyFilesystem)->copy($stashFile, $newFile, true);

                continue;
            }

            if (! file_exists($newFile)) {
                // Upstream deleted this file — respect the deletion, do not restore
                continue;
            }

            // All three versions exist — 3-way merge
            $merged = $stashFile.'.merge-work';
            copy($stashFile, $merged);

            $process = new Process(
                ['git', 'merge-file', '-L', 'yours', '-L', 'original', '-L', 'upstream',
                    $merged, $baseFile, $newFile]
            );
            $process->run();

            // exit code >0 = conflict markers inserted, but result is still usable
            if ($process->getExitCode() > 0) {
                $this->io->writeError("  <warning>Merge conflict in $rel — conflict markers inserted</warning>");
            }

            (new SymfonyFilesystem)->rename($merged, $newFile, true);
        }
    }

    /**
     * Override install to remove excluded directories after installation.
     *
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): ?PromiseInterface
    {
        $promise = parent::install($repo, $package);

        return $promise->then(function () use ($package) {
            $this->removeExcludedDirectories($package);
        });
    }

    /**
     * Override update to preserve user customisations (merge strategy) or replace entirely (overwrite).
     *
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target): ?PromiseInterface
    {
        $stashPath = null;
        $basePath = null;

        if ($this->getUpdateStrategy() === self::UPDATE_STRATEGY_MERGE) {
            $stashPath = $this->stashModuleDir($this->getInstallPath($initial));
            if ($stashPath !== null) {
                $basePath = sys_get_temp_dir().'/module-base-'.uniqid('', true);
            }
        }

        $prepareBase = ($basePath !== null)
            ? $this->downloadBase($initial, $basePath)
            : \React\Promise\resolve(null);

        return $prepareBase->then(fn () => parent::update($repo, $initial, $target))->then(
            function () use ($target, $stashPath, $basePath) {
                $this->removeExcludedDirectories($target);
                if ($stashPath !== null) {
                    $installPath = $this->getInstallPath($target);
                    $this->mergeStash($stashPath, $basePath, $installPath);
                    (new Filesystem)->removeDirectory($stashPath);
                    (new Filesystem)->removeDirectory($basePath);
                }
            },
            function (\Throwable $e) use ($stashPath, $basePath, $initial) {
                if ($stashPath !== null) {
                    $this->restoreStash($stashPath, $initial);
                }
                if ($basePath !== null) {
                    (new Filesystem)->removeDirectory($basePath);
                }
                throw $e;
            }
        );
    }
}
