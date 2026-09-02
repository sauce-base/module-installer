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

    const KNOWN_FRAMEWORKS = ['vue', 'react', 'svelte'];

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
                // $newFile is still the clean upstream copy here — rename happens at line below.
                $this->stageConflictInIndex($stashFile, $baseFile, $newFile, $install, $rel);
            }

            (new SymfonyFilesystem)->rename($merged, $newFile, true);
        }
    }

    /**
     * Register the three pre-merge file versions in git's index at stages 1/2/3 so the
     * working-tree file (with conflict markers) appears as a real conflict in `git status`,
     * VSCode's Source Control panel, and the merge editor.
     *
     * Fails silently — if git is unavailable or $installPath is outside a repo, the conflict
     * markers remain in the file but the index is left unchanged.
     */
    protected function stageConflictInIndex(
        string $ours,
        string $base,
        string $theirs,
        string $installPath,
        string $relativePathname
    ): void {
        try {
            $gitRootProc = new Process(['git', '-C', $installPath, 'rev-parse', '--show-toplevel']);
            $gitRootProc->run();
            if (! $gitRootProc->isSuccessful()) {
                return;
            }
            $root = rtrim($gitRootProc->getOutput(), "\r\n");

            $absFile = $installPath.DIRECTORY_SEPARATOR.$relativePathname;
            $absFile = str_replace('\\', '/', realpath($absFile) ?: $absFile);
            $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
            if (strpos($absFile, $rootNorm.'/') !== 0) {
                return;
            }
            $relFromRoot = substr($absFile, strlen($rootNorm) + 1);

            $hashBase = new Process(['git', '-C', $root, 'hash-object', '-w', $base]);
            $hashOurs = new Process(['git', '-C', $root, 'hash-object', '-w', $ours]);
            $hashTheirs = new Process(['git', '-C', $root, 'hash-object', '-w', $theirs]);
            $hashBase->run();
            $hashOurs->run();
            $hashTheirs->run();

            if (! $hashBase->isSuccessful() || ! $hashOurs->isSuccessful() || ! $hashTheirs->isSuccessful()) {
                return;
            }

            $shaBase = trim($hashBase->getOutput());
            $shaOurs = trim($hashOurs->getOutput());
            $shaTheirs = trim($hashTheirs->getOutput());

            foreach ([$shaBase, $shaOurs, $shaTheirs] as $sha) {
                if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
                    return;
                }
            }

            // --force-remove is required because the working-tree file already exists
            // (written by downloadFresh); plain --remove is a no-op when the file is present.
            $remove = new Process(['git', '-C', $root, 'update-index', '--force-remove', $relFromRoot]);
            $remove->run();
            if (! $remove->isSuccessful()) {
                return;
            }

            $indexInfo = implode("\n", [
                "100644 {$shaBase} 1\t{$relFromRoot}",
                "100644 {$shaOurs} 2\t{$relFromRoot}",
                "100644 {$shaTheirs} 3\t{$relFromRoot}",
            ])."\n";

            $updateIndex = new Process(['git', '-C', $root, 'update-index', '--index-info']);
            $updateIndex->setInput($indexInfo);
            $updateIndex->run();
        } catch (\Throwable $e) {
            // Degrade gracefully — conflict markers remain in the file.
        }
    }

    /**
     * Returns true when the package is served by a local path repository.
     * Path repos have source == install path, so the normal download/delete cycle would
     * wipe the user's files before trying to copy from the now-missing source.
     */
    protected function isPathRepository(PackageInterface $package): bool
    {
        if ($package->getDistType() !== 'path') {
            return false;
        }

        return $this->isLocallyTracked($this->getInstallPath($package));
    }

    /**
     * Returns true when the install path is a locally-managed directory that should never
     * be overwritten by the installer — either an OS symlink (path repo with symlink:true)
     * or a git-tracked dev clone (.git present).
     *
     * Packagist-installed modules have .git removed by DEFAULT_EXCLUDED_DIRS, so they never
     * match this check and remain updateable from Packagist.
     */
    protected function isLocallyTracked(string $path): bool
    {
        return is_link($path) || is_dir($path.'/.git');
    }

    /**
     * Returns true when the install path already holds module files. An existing but empty
     * directory does not count — that is a leftover, not an installed module.
     */
    protected function hasModuleFiles(string $path): bool
    {
        return is_dir($path) && (new \FilesystemIterator($path))->valid();
    }

    /**
     * Returns true when a package has dist.type=path but its install path is NOT locally
     * tracked (no symlink, no .git). This identifies a Packagist-installed module that was
     * re-resolved by the modules/* path repository on a subsequent composer update.
     * The files are already in place; no download should occur.
     */
    protected function isInstalledModuleResolvedAsPath(PackageInterface $package): bool
    {
        return $package->getDistType() === 'path'
            && ! $this->isLocallyTracked($this->getInstallPath($package));
    }

    /**
     * Returns the package to register in installed.json after a path-skip scenario.
     * When initial has non-path dist info (Packagist zip/tar), returns a clone of target
     * with initial's dist metadata copied over so the lock file retains the Packagist
     * source rather than recording dist.type=path.
     * When initial is also path (already-polluted lock), returns target unchanged.
     */
    protected function resolveRegistrationTarget(
        PackageInterface $initial,
        PackageInterface $target
    ): PackageInterface {
        if ($initial->getDistType() !== null && $initial->getDistType() !== 'path') {
            $clone = clone $target;
            $clone->setDistType($initial->getDistType());
            $clone->setDistUrl($initial->getDistUrl());
            $clone->setDistReference($initial->getDistReference());
            $clone->setDistSha1Checksum($initial->getDistSha1Checksum());

            return $clone;
        }

        return $target;
    }

    /**
     * Returns the path to frontend.json. Extracted for testability.
     */
    protected function getFrontendJsonPath(): string
    {
        return getcwd().'/frontend.json';
    }

    /**
     * Reads the selected framework from frontend.json.
     * Returns null when: file missing, invalid JSON, dev mode active, framework not set, or invalid name.
     */
    protected function getSelectedFramework(): ?string
    {
        $path = $this->getFrontendJsonPath();

        if (! file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data) || ($data['dev'] ?? false)) {
            return null;
        }

        $framework = $data['framework'] ?? null;

        if (! is_string($framework) || ! in_array($framework, self::KNOWN_FRAMEWORKS, true)) {
            return null;
        }

        return $framework;
    }

    /**
     * Copies the selected framework's JS files flat into resources/js/ and removes all framework subdirs.
     * Silent-skips when: no resources/js dir (PHP-only module) or no framework selected.
     * Hard-fails when resources/js exists but the selected framework subdir is missing.
     */
    protected function copyFrameworkFiles(PackageInterface $package): void
    {
        $jsRoot = $this->getInstallPath($package).'/resources/js';

        if (! is_dir($jsRoot)) {
            return;
        }

        $framework = $this->getSelectedFramework();

        if (! $framework) {
            return;
        }

        $fwPath = $jsRoot.'/'.$framework;

        if (! is_dir($fwPath)) {
            throw new \RuntimeException(sprintf(
                '%s does not support %s. Check the module\'s documentation for framework support.',
                $package->getName(),
                $framework
            ));
        }

        $this->flattenFrameworkFiles($jsRoot, $framework);
        $this->rewriteCrossModuleImports($jsRoot);
    }

    /**
     * Strips any framework subdirectory segment from cross-module import paths in JS/TS/Vue files.
     * After flattening, imports like @modules/billing/resources/js/vue/Foo.vue become
     *
     * @modules/billing/resources/js/Foo.vue regardless of which framework is active.
     */
    protected function rewriteCrossModuleImports(string $jsRoot): void
    {
        $extensions = ['vue', 'ts', 'tsx', 'js'];
        $fwPattern = implode('|', array_map('preg_quote', self::KNOWN_FRAMEWORKS));

        foreach ((new Finder)->files()->in($jsRoot) as $file) {
            if (! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $rewritten = preg_replace(
                "#(@modules/[^/]+/resources/js/)({$fwPattern})/#",
                '$1',
                $content
            );

            if ($rewritten !== $content) {
                file_put_contents($file->getPathname(), $rewritten);
            }
        }
    }

    /**
     * Copies files from $jsRoot/$framework flat into $jsRoot, then removes all known framework subdirs.
     * Extracted so the same flattening can be applied to any path (e.g. the merge-base temp dir).
     */
    protected function flattenFrameworkFiles(string $jsRoot, string $framework): void
    {
        $targetPaths = [];
        if (is_dir($jsRoot.'/'.$framework)) {
            foreach ((new Finder)->files()->in($jsRoot.'/'.$framework) as $f) {
                $targetPaths[$f->getRelativePathname()] = true;
            }
        }

        $stale = [];
        foreach (self::KNOWN_FRAMEWORKS as $fw) {
            if ($fw === $framework || ! is_dir($jsRoot.'/'.$fw)) {
                continue;
            }
            foreach ((new Finder)->files()->in($jsRoot.'/'.$fw) as $f) {
                $rel = $f->getRelativePathname();
                if (! isset($targetPaths[$rel])) {
                    $stale[] = $rel;
                }
            }
        }

        $this->copyDirectory($jsRoot.'/'.$framework, $jsRoot);

        $fs = new Filesystem;
        foreach (self::KNOWN_FRAMEWORKS as $fw) {
            $fs->removeDirectory($jsRoot.'/'.$fw);
        }

        $sfFs = new SymfonyFilesystem;
        foreach ($stale as $rel) {
            if (file_exists($jsRoot.'/'.$rel)) {
                $sfFs->remove($jsRoot.'/'.$rel);
            }
        }
    }

    /**
     * Recursively copies all files from $source into $dest, preserving relative paths.
     */
    private function copyDirectory(string $source, string $dest): void
    {
        $fs = new SymfonyFilesystem;
        $finder = (new Finder)->files()->in($source);

        foreach ($finder as $file) {
            $fs->copy($file->getPathname(), $dest.'/'.$file->getRelativePathname(), true);
        }
    }

    /**
     * Proxy for parent::install() — extracted for testability.
     */
    protected function parentInstall(InstalledRepositoryInterface $repo, PackageInterface $package): PromiseInterface
    {
        return parent::install($repo, $package);
    }

    /**
     * Override install to remove excluded directories and deploy framework files after installation.
     * Skips entirely for path repositories — their files are already in place.
     *
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): ?PromiseInterface
    {
        if ($this->isPathRepository($package)) {
            $this->io->write("  - <info>Skipping install for path repository:</info> {$package->getPrettyName()}");

            if (! $repo->hasPackage($package)) {
                $repo->addPackage(clone $package);
            }

            return \React\Promise\resolve(null);
        }

        if ($this->isLocallyTracked($this->getInstallPath($package))) {
            $this->io->write("  - <info>Skipping install for locally tracked module:</info> {$package->getPrettyName()}");

            if (! $repo->hasPackage($package)) {
                $repo->addPackage(clone $package);
            }

            return \React\Promise\resolve(null);
        }

        // Module files are already on disk — copy-and-own means the working tree wins.
        // Covers a repeat run re-resolved by the modules/* path repo, and a production deploy
        // where modules/ is committed to the app repo but vendor/ is empty, so Composer treats
        // every module as a fresh install and the download would clobber the user's edits.
        // Version bumps still land: those are update() operations, which merge.
        if ($this->hasModuleFiles($this->getInstallPath($package))) {
            $this->io->write("  - <info>Skipping install (module files already present):</info> {$package->getPrettyName()}");

            if (! $repo->hasPackage($package)) {
                $repo->addPackage(clone $package);
            }

            return \React\Promise\resolve(null);
        }

        $promise = $this->parentInstall($repo, $package);

        return $promise->then(function () use ($package) {
            $this->removeExcludedDirectories($package);
            $this->copyFrameworkFiles($package);
        });
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

            if ($repo->hasPackage($initial)) {
                $repo->removePackage($initial);
            }

            if (! $repo->hasPackage($target)) {
                $repo->addPackage(clone $target);
            }

            return \React\Promise\resolve(null);
        }

        $installPath = $this->getInstallPath($target);

        if ($this->isLocallyTracked($installPath)) {
            $this->io->write("  - <info>Skipping update for locally tracked module:</info> {$target->getPrettyName()}");

            if ($repo->hasPackage($initial)) {
                $repo->removePackage($initial);
            }

            if (! $repo->hasPackage($target)) {
                $repo->addPackage(clone $target);
            }

            return \React\Promise\resolve(null);
        }

        // Target was re-resolved by modules/* path repo (not a dev repo).
        // Files are already in place at the same version — skip download, update tracking only.
        // Copies initial's dist info onto the registered clone so the lock records zip/tar
        // rather than dist.type=path, preventing future composer install failures on fresh clones.
        if ($this->isInstalledModuleResolvedAsPath($target)) {
            $this->io->write("  - <info>Skipping download (module resolved as path by modules/* repo):</info> {$target->getPrettyName()}");
            $registrationTarget = $this->resolveRegistrationTarget($initial, $target);
            $this->binaryInstaller->removeBinaries($initial);
            $this->binaryInstaller->installBinaries($registrationTarget, $installPath);
            if ($repo->hasPackage($initial)) {
                $repo->removePackage($initial);
            }
            if (! $repo->hasPackage($registrationTarget)) {
                $repo->addPackage(clone $registrationTarget);
            }

            return \React\Promise\resolve(null);
        }

        $stashPath = null;
        $basePath = null;

        if ($this->getUpdateStrategy() === self::UPDATE_STRATEGY_MERGE && $initial->getDistType() !== 'path') {
            $stashPath = $this->stashModuleDir($this->getInstallPath($initial));
            if ($stashPath !== null) {
                $basePath = sys_get_temp_dir().'/module-base-'.uniqid('', true);
            }
        } else {
            // Overwrite: stash for rollback safety rather than deleting outright.
            // $basePath stays null, which signals overwrite mode in the callbacks.
            // Also covers initial=path: there's no remote dist to download as merge base,
            // and stashing first would make the path inaccessible to PathDownloader.
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
                    $this->copyFrameworkFiles($target);
                    if ($basePath !== null) {
                        // Merge strategy: flatten the base so all 3 sides share the same file layout.
                        // Without this, mergeStash() would compare flattened stash files (e.g. app.ts)
                        // against unflattened base files (e.g. vue/app.ts), misclassifying every file
                        // as user-added and silently discarding all upstream changes.
                        $framework = $this->getSelectedFramework();
                        $baseJsRoot = $basePath.'/resources/js';
                        if ($framework && is_dir($baseJsRoot.'/'.$framework)) {
                            $this->flattenFrameworkFiles($baseJsRoot, $framework);
                        }
                        // Apply 3-way merge then clean up base temp dir
                        $this->mergeStash($stashPath, $basePath, $installPath);
                        (new Filesystem)->removeDirectory($basePath);
                    }
                    if ($stashPath !== null) {
                        // Both strategies: download succeeded — discard the stash
                        (new Filesystem)->removeDirectory($stashPath);
                    }

                    // Direct repo tracking — equivalent to parent::update() minus the download.
                    $this->binaryInstaller->removeBinaries($initial);
                    $this->binaryInstaller->installBinaries($target, $installPath);
                    if ($repo->hasPackage($initial)) {
                        $repo->removePackage($initial);
                    }
                    if (! $repo->hasPackage($target)) {
                        $repo->addPackage(clone $target);
                    }

                    return \React\Promise\resolve(null);
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
     * Whether to physically delete the module directory on uninstall.
     * Defaults to false (skip deletion) to prevent accidental data loss.
     * Enable via extra['module-delete-on-remove'] = true in root composer.json.
     */
    protected function shouldDeleteOnRemove(): bool
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return false;
        }

        $extra = $this->composer->getPackage()->getExtra();

        return (bool) ($extra['module-delete-on-remove'] ?? false);
    }

    /**
     * Override uninstall to protect path repository files from deletion.
     * A `composer remove` on a path repo must never wipe the working source directory.
     * By default, all module directories are preserved — set module-delete-on-remove: true to enable deletion.
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

        if (! $this->shouldDeleteOnRemove()) {
            $installPath = $this->getInstallPath($package);
            $this->io->write("  - <info>Skipping deletion of module directory (set module-delete-on-remove: true to enable):</info> {$installPath}");

            if ($repo->hasPackage($package)) {
                $repo->removePackage($package);
            }

            return \React\Promise\resolve(null);
        }

        return parent::uninstall($repo, $package);
    }
}
