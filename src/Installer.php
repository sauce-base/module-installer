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

    protected bool $skipUpdateCode = false;

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
     * Returns true when the package is served by a local path repository.
     * Path repos have source == install path, so the normal download/delete cycle would
     * wipe the user's files before trying to copy from the now-missing source.
     */
    protected function isPathRepository(PackageInterface $package): bool
    {
        return $package->getDistType() === 'path';
    }

    /**
     * Override install to remove excluded directories after installation.
     * Skips entirely for path repositories — their files are already in place.
     *
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): ?PromiseInterface
    {
        if ($this->isPathRepository($package)) {
            $this->io->write("  - <info>Skipping install for path repository:</info> {$package->getPrettyName()}");

            return \React\Promise\resolve(null);
        }

        $promise = parent::install($repo, $package);

        return $promise->then(function () use ($package) {
            $this->removeExcludedDirectories($package);
        });
    }

    /**
     * Call parent::update() solely for its installed.json tracking side-effects.
     * Sets the skip flag so updateCode() is a no-op, then resets it regardless of outcome.
     */
    protected function delegateRepoTracking(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target): PromiseInterface
    {
        $this->skipUpdateCode = true;

        $resetFlag = function (): void { $this->skipUpdateCode = false; };

        return parent::update($repo, $initial, $target)->then(
            $resetFlag,
            function (\Throwable $e) use ($resetFlag): never { $resetFlag(); throw $e; }
        );
    }

    /**
     * No-op hook for parent::update() — skipped when we have already handled the download ourselves.
     * Overriding this lets parent::update() run only for its repo-tracking side-effects.
     *
     * {@inheritDoc}
     */
    protected function updateCode(PackageInterface $initial, PackageInterface $target): PromiseInterface
    {
        if ($this->skipUpdateCode) {
            return \React\Promise\resolve(null);
        }

        return $this->invokeParentUpdateCode($initial, $target);
    }

    protected function invokeParentUpdateCode(PackageInterface $initial, PackageInterface $target): PromiseInterface
    {
        return parent::updateCode($initial, $target);
    }

    /**
     * Download a specific package version to the given path using Composer's DownloadManager.
     * Used for both base (original) and target (new) version fetches during update.
     */
    protected function downloadFresh(PackageInterface $package, string $path): PromiseInterface
    {
        $dm = $this->composer->getDownloadManager();

        return $dm->download($package, $path)
            ->then(fn () => $dm->install($package, $path));
    }

    /**
     * Override update to preserve user customisations (merge strategy) or replace entirely (overwrite).
     * Skips entirely for path repositories — their files are managed by git/the path repo mechanism.
     *
     * Does NOT call parent::update() because that uses GitDownloader::update() which requires a .git
     * directory. Since we remove .git after initial install (copy-and-own model), we instead do a
     * fresh download of the target version directly to the install path.
     *
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target): ?PromiseInterface
    {
        if ($this->isPathRepository($target)) {
            $this->io->write("  - <info>Skipping update for path repository:</info> {$target->getPrettyName()}");

            return \React\Promise\resolve(null);
        }

        $installPath = $this->getInstallPath($target);
        $stashPath = null;
        $basePath = null;

        if ($this->getUpdateStrategy() === self::UPDATE_STRATEGY_MERGE) {
            $stashPath = $this->stashModuleDir($this->getInstallPath($initial));
            if ($stashPath !== null) {
                $basePath = sys_get_temp_dir().'/module-base-'.uniqid('', true);
            }
        } else {
            // Overwrite: stash for rollback safety rather than deleting outright.
            // $basePath stays null, which signals overwrite mode in the callbacks.
            $stashPath = $this->stashModuleDir($installPath);
        }

        $prepareBase = ($basePath !== null)
            ? $this->downloadBase($initial, $basePath)
            : \React\Promise\resolve(null);

        return $prepareBase
            ->then(fn () => $this->downloadFresh($target, $installPath))
            ->then(
                function () use ($repo, $initial, $target, $installPath, $stashPath, $basePath) {
                    $this->removeExcludedDirectories($target);
                    if ($basePath !== null) {
                        // Merge strategy: apply 3-way merge then clean up base temp dir
                        $this->mergeStash($stashPath, $basePath, $installPath);
                        (new Filesystem)->removeDirectory($basePath);
                    }
                    if ($stashPath !== null) {
                        // Both strategies: download succeeded — discard the stash
                        (new Filesystem)->removeDirectory($stashPath);
                    }
                    // Delegate repo tracking (installed.json) to parent — skip the download step
                    // since we have already placed the files ourselves.
                    return $this->delegateRepoTracking($repo, $initial, $target);
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

    /**
     * Override uninstall to protect path repository files from deletion.
     * A `composer remove` on a path repo must never wipe the working source directory.
     *
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package): ?PromiseInterface
    {
        if ($this->isPathRepository($package)) {
            $this->io->write("  - <info>Skipping uninstall for path repository:</info> {$package->getPrettyName()}");

            if ($repo->hasPackage($package)) {
                $repo->removePackage($package);
            }

            return \React\Promise\resolve(null);
        }

        return parent::uninstall($repo, $package);
    }
}
