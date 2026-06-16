<?php

declare(strict_types=1);

namespace Tests;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\PartialComposer;
use Composer\Repository\InstalledRepositoryInterface;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;
use Saucebase\ModuleInstaller\Installer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Shim that avoids LibraryInstaller's heavy constructor.
 *
 * @property PartialComposer $composer
 * @property IOInterface $io
 */
final class TestableInstaller extends Installer
{
    public function __construct(?IOInterface $io = null, ?Composer $composer = null)
    {
        $this->io = $io;
        $this->composer = $composer;
        // Provide a no-op binaryInstaller since we bypass LibraryInstaller's constructor.
        $this->binaryInstaller = new class extends BinaryInstaller
        {
            public function __construct() {}

            public function removeBinaries(PackageInterface $package): void {}

            public function installBinaries(PackageInterface $package, string $installPath, bool $warnOnOverwrite = true): void {}
        };
    }

    public function callGetModuleName(PackageInterface $package): string
    {
        return parent::getModuleName($package);
    }

    public function callGetBaseInstallationPath(): string
    {
        return parent::getBaseInstallationPath();
    }

    public function callGetUpdateStrategy(): string
    {
        return parent::getUpdateStrategy();
    }

    public function callStashModuleDir(string $path): ?string
    {
        return parent::stashModuleDir($path);
    }

    public function callMergeStash(string $stash, string $base, string $install): void
    {
        parent::mergeStash($stash, $base, $install);
    }

    public function callStageConflictInIndex(
        string $ours,
        string $base,
        string $theirs,
        string $installPath,
        string $relativePathname
    ): void {
        parent::stageConflictInIndex($ours, $base, $theirs, $installPath, $relativePathname);
    }

    public function callRestoreStash(string $stashPath, PackageInterface $package): void
    {
        parent::restoreStash($stashPath, $package);
    }

    public function callIsPathRepository(PackageInterface $package): bool
    {
        return parent::isPathRepository($package);
    }

    public function callIsInstalledModuleResolvedAsPath(PackageInterface $package): bool
    {
        return parent::isInstalledModuleResolvedAsPath($package);
    }

    public function callResolveRegistrationTarget(PackageInterface $initial, PackageInterface $target): PackageInterface
    {
        return parent::resolveRegistrationTarget($initial, $target);
    }

    // ---- Framework-aware file copying ----

    private string $frontendJsonPath = '';

    public function setFrontendJsonPath(string $path): void
    {
        $this->frontendJsonPath = $path;
    }

    protected function getFrontendJsonPath(): string
    {
        return $this->frontendJsonPath !== '' ? $this->frontendJsonPath : parent::getFrontendJsonPath();
    }

    public function callGetSelectedFramework(): ?string
    {
        return parent::getSelectedFramework();
    }

    public function callCopyFrameworkFiles(PackageInterface $package): void
    {
        parent::copyFrameworkFiles($package);
    }

    public function callFlattenFrameworkFiles(string $jsRoot, string $framework): void
    {
        parent::flattenFrameworkFiles($jsRoot, $framework);
    }

    public ?bool $forcePathRepository = null;

    protected function isPathRepository(PackageInterface $package): bool
    {
        if ($this->forcePathRepository !== null) {
            return $this->forcePathRepository;
        }

        return parent::isPathRepository($package);
    }

    public function callShouldDeleteOnRemove(): bool
    {
        return parent::shouldDeleteOnRemove();
    }

    public bool $copyFrameworkFilesInvoked = false;

    protected function copyFrameworkFiles(PackageInterface $package): void
    {
        $this->copyFrameworkFilesInvoked = true;
        parent::copyFrameworkFiles($package);
    }

    public bool $parentInstallInvoked = false;

    protected function parentInstall(InstalledRepositoryInterface $repo, PackageInterface $package): PromiseInterface
    {
        $this->parentInstallInvoked = true;

        return \React\Promise\resolve(null);
    }

    public bool $downloadBaseInvoked = false;

    protected function downloadBase(PackageInterface $initial, string $basePath): PromiseInterface
    {
        $this->downloadBaseInvoked = true;
        mkdir($basePath, 0755, true);

        return \React\Promise\resolve(null);
    }

    protected function downloadFresh(PackageInterface $package, string $path): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }
}

final class ModuleInstallerTest extends TestCase
{
    public function test_supports_default_module_type(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $installer = new TestableInstaller($io, $composer);

        $this->assertTrue($installer->supports('laravel-module'));
        $this->assertFalse($installer->supports('library'));
        $this->assertFalse($installer->supports('composer-plugin'));
    }

    public function test_supports_custom_module_type_from_extra(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-type' => 'saucebase-module']);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $this->assertTrue($installer->supports('saucebase-module'));
        $this->assertFalse($installer->supports('laravel-module'));
    }

    public function test_get_install_path_uses_default_modules_dir_when_no_composer(): void
    {
        // Composer is null -> should fall back to DEFAULT_ROOT ("modules")
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $pkg = new Package('saucebase/something-nice', '1.0.0.0', '1.0.0');

        $this->assertSame('modules/something-nice', $installer->getInstallPath($pkg));
    }

    public function test_get_install_path_uses_default_when_no_module_dir_in_extra(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        // Root package present, but without extra['module-dir']
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra([]); // nothing set
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);
        $pkg = new Package('vendor/awesome-toolkit', '1.0.0.0', '1.0.0');

        $this->assertSame('modules/awesome-toolkit', $installer->getInstallPath($pkg));
    }

    public function test_get_install_path_honors_extra_module_dir(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => 'CustomModules']); // custom dir
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);
        $pkg = new Package('vendor/awesome-toolkit', '1.0.0.0', '1.0.0');

        $this->assertSame('CustomModules/awesome-toolkit', $installer->getInstallPath($pkg));
    }

    public function test_get_module_name_returns_lowercase_slug(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $cases = [
            'saucebase/auth' => 'auth',
            'saucebase/billing' => 'billing',
            'saucebase/my-module' => 'my-module',
            'saucebase/some-CAPS' => 'some-caps',
            'vendor/awesome-toolkit' => 'awesome-toolkit',
        ];

        foreach ($cases as $packageName => $expected) {
            $pkg = $this->createStub(PackageInterface::class);
            $pkg->method('getPrettyName')->willReturn($packageName);
            $this->assertSame($expected, $installer->callGetModuleName($pkg), "Failed for: $packageName");
        }
    }

    public function test_get_module_name_throws_on_invalid_pretty_name(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);
        $installer = new TestableInstaller($io, $composer);

        $bad = $this->createStub(PackageInterface::class);
        $bad->method('getPrettyName')->willReturn('invalidname'); // no slash

        $this->expectException(ModuleInstallerException::class);
        $installer->callGetModuleName($bad);
    }

    // -------------------------------------------------------------------------
    // getUpdateStrategy
    // -------------------------------------------------------------------------

    public function test_get_update_strategy_returns_merge_by_default(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $this->assertSame('merge', $installer->callGetUpdateStrategy());
    }

    public function test_get_update_strategy_returns_overwrite_when_configured(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-update-strategy' => 'overwrite']);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $this->assertSame('overwrite', $installer->callGetUpdateStrategy());
    }

    public function test_get_update_strategy_falls_back_on_invalid_value_and_warns(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError');

        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-update-strategy' => 'bogus']);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $this->assertSame('merge', $installer->callGetUpdateStrategy());
    }

    // -------------------------------------------------------------------------
    // stashModuleDir
    // -------------------------------------------------------------------------

    public function test_stash_renames_dir_to_temp_location(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $source = sys_get_temp_dir().'/module-stash-test-src-'.uniqid('', true);
        mkdir($source);
        file_put_contents($source.'/custom.txt', 'hello');

        $stash = $installer->callStashModuleDir($source);

        $this->assertNotNull($stash);
        $this->assertDirectoryDoesNotExist($source);
        $this->assertDirectoryExists($stash);
        $this->assertFileExists($stash.'/custom.txt');

        // Cleanup
        (new Filesystem)->remove($stash);
    }

    public function test_stash_returns_null_when_dir_does_not_exist(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $this->assertNull($installer->callStashModuleDir('/nonexistent/path/'.uniqid('', true)));
    }

    // -------------------------------------------------------------------------
    // mergeStash
    // -------------------------------------------------------------------------

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir().'/merge-test-'.uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
    }

    public function test_merge_stash_applies_upstream_changes_to_unedited_file(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $stash = $this->makeTempDir();
        $base = $this->makeTempDir();
        $install = $this->makeTempDir();

        // base == stash (user made no edits), upstream changed the file
        file_put_contents($stash.'/api.php', "line1\nline2\n");
        file_put_contents($base.'/api.php', "line1\nline2\n");
        file_put_contents($install.'/api.php', "line1\nline2-upstream\n");

        $installer->callMergeStash($stash, $base, $install);

        $this->assertSame("line1\nline2-upstream\n", file_get_contents($install.'/api.php'));

        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($base);
        $fs->remove($install);
    }

    public function test_merge_stash_preserves_user_edits_when_upstream_unchanged(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $stash = $this->makeTempDir();
        $base = $this->makeTempDir();
        $install = $this->makeTempDir();

        // User edited line 2; upstream kept the file as-is
        file_put_contents($stash.'/api.php', "line1\nline2-user\n");
        file_put_contents($base.'/api.php', "line1\nline2\n");
        file_put_contents($install.'/api.php', "line1\nline2\n");

        $installer->callMergeStash($stash, $base, $install);

        $this->assertSame("line1\nline2-user\n", file_get_contents($install.'/api.php'));

        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($base);
        $fs->remove($install);
    }

    public function test_merge_stash_merges_non_overlapping_edits(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $stash = $this->makeTempDir();
        $base = $this->makeTempDir();
        $install = $this->makeTempDir();

        $baseContent = "line1\nline2\nline3\nline4\nline5\n";
        $userContent = "line1-user\nline2\nline3\nline4\nline5\n";   // user changed line 1
        $upstreamContent = "line1\nline2\nline3\nline4\nline5-up\n";  // upstream changed line 5

        file_put_contents($stash.'/api.php', $userContent);
        file_put_contents($base.'/api.php', $baseContent);
        file_put_contents($install.'/api.php', $upstreamContent);

        $installer->callMergeStash($stash, $base, $install);

        $result = file_get_contents($install.'/api.php');
        $this->assertStringContainsString('line1-user', $result);
        $this->assertStringContainsString('line5-up', $result);
        $this->assertStringNotContainsString('<<<<<<<', $result);

        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($base);
        $fs->remove($install);
    }

    public function test_merge_stash_inserts_conflict_markers_on_overlapping_edits(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError');

        $installer = new TestableInstaller($io, null);

        $stash = $this->makeTempDir();
        $base = $this->makeTempDir();
        $install = $this->makeTempDir();

        // Both sides changed the same line
        file_put_contents($stash.'/api.php', "original\n");
        file_put_contents($base.'/api.php', "original\n");
        file_put_contents($install.'/api.php', "upstream-change\n");

        // Make the stash differ from base on the same line
        file_put_contents($stash.'/api.php', "user-change\n");

        $installer->callMergeStash($stash, $base, $install);

        $result = file_get_contents($install.'/api.php');
        $this->assertStringContainsString('<<<<<<<', $result);

        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($base);
        $fs->remove($install);
    }

    public function test_stage_conflict_in_index_registers_git_conflict_stages(): void
    {
        $repo = sys_get_temp_dir().'/conflict-index-test-'.uniqid('', true);
        mkdir($repo.'/modules/auth', 0755, true);

        (new Process(['git', 'init'], $repo))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@test.com'], $repo))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Test'], $repo))->mustRun();

        // Commit an initial file so the index has a known baseline
        file_put_contents($repo.'/modules/auth/api.php', "original\n");
        (new Process(['git', 'add', '.'], $repo))->mustRun();
        (new Process(['git', 'commit', '-m', 'init'], $repo))->mustRun();

        // Simulate the three versions available at conflict time
        $oursFile = sys_get_temp_dir().'/ours-'.uniqid('', true).'.php';
        $baseFile = sys_get_temp_dir().'/base-'.uniqid('', true).'.php';
        file_put_contents($oursFile, "user-change\n");
        file_put_contents($baseFile, "original\n");
        // The install-path file holds upstream content (not yet overwritten by merge result)
        file_put_contents($repo.'/modules/auth/api.php', "upstream-change\n");

        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->callStageConflictInIndex(
            $oursFile,
            $baseFile,
            $repo.'/modules/auth/api.php',
            $repo.'/modules/auth',
            'api.php'
        );

        $lsFiles = new Process(['git', 'ls-files', '--stage', 'modules/auth/api.php'], $repo);
        $lsFiles->run();
        $output = $lsFiles->getOutput();

        // ls-files --stage format: "<mode> <sha> <stage>\t<path>"
        $this->assertStringContainsString(" 1\t", $output, 'Stage 1 (base) should be registered');
        $this->assertStringContainsString(" 2\t", $output, 'Stage 2 (ours) should be registered');
        $this->assertStringContainsString(" 3\t", $output, 'Stage 3 (theirs) should be registered');

        unlink($oursFile);
        unlink($baseFile);
        (new Filesystem)->remove($repo);
    }

    public function test_merge_stash_keeps_user_added_files(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $stash = $this->makeTempDir();
        $base = $this->makeTempDir();
        $install = $this->makeTempDir();

        // File only in stash (user added it, never in original dist)
        file_put_contents($stash.'/user-added.php', '<?php // user file');

        $installer->callMergeStash($stash, $base, $install);

        $this->assertFileExists($install.'/user-added.php');
        $this->assertSame('<?php // user file', file_get_contents($install.'/user-added.php'));

        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($base);
        $fs->remove($install);
    }

    public function test_merge_stash_respects_upstream_deletions(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $stash = $this->makeTempDir();
        $base = $this->makeTempDir();
        $install = $this->makeTempDir();

        // File exists in stash and base but upstream removed it (not in install)
        file_put_contents($stash.'/deleted-upstream.php', 'old content');
        file_put_contents($base.'/deleted-upstream.php', 'old content');
        // Intentionally NOT present in $install

        $installer->callMergeStash($stash, $base, $install);

        $this->assertFileDoesNotExist($install.'/deleted-upstream.php');

        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($base);
        $fs->remove($install);
    }

    // -------------------------------------------------------------------------
    // restoreStash
    // -------------------------------------------------------------------------

    public function test_restore_stash_renames_stash_back_when_original_path_missing(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => sys_get_temp_dir()]);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $stash = sys_get_temp_dir().'/module-stash-restore-test-'.uniqid('', true);
        mkdir($stash);
        file_put_contents($stash.'/custom.php', '<?php // user file');

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');

        // The original install path does not exist (was cleared during update)
        $originalPath = $installer->getInstallPath($pkg);
        $this->assertDirectoryDoesNotExist($originalPath);

        $installer->callRestoreStash($stash, $pkg);

        $this->assertDirectoryDoesNotExist($stash);
        $this->assertDirectoryExists($originalPath);
        $this->assertFileExists($originalPath.'/custom.php');

        (new Filesystem)->remove($originalPath);
    }

    public function test_restore_stash_discards_stash_when_original_path_already_exists(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => sys_get_temp_dir()]);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $pkg = new Package('saucebase/already-there', '1.0.0.0', '1.0.0');

        // Pre-create the original path (partial update left it behind)
        $originalPath = $installer->getInstallPath($pkg);
        mkdir($originalPath, 0755, true);
        file_put_contents($originalPath.'/partial.php', 'partial');

        $stash = sys_get_temp_dir().'/module-stash-discard-test-'.uniqid('', true);
        mkdir($stash);
        file_put_contents($stash.'/custom.php', '<?php // user file');

        $installer->callRestoreStash($stash, $pkg);

        // Stash was discarded, original path (with partial content) remains
        $this->assertDirectoryDoesNotExist($stash);
        $this->assertDirectoryExists($originalPath);
        $this->assertFileExists($originalPath.'/partial.php');

        (new Filesystem)->remove($originalPath);
    }

    // -------------------------------------------------------------------------
    // isPathRepository
    // -------------------------------------------------------------------------

    public function test_is_path_repository_returns_true_when_dist_type_is_path_and_install_path_is_symlink(): void
    {
        $baseDir = sys_get_temp_dir().'/path-repo-symlink-'.uniqid('', true);
        $target = $baseDir.'/source';
        mkdir($target, 0755, true);
        symlink($target, $baseDir.'/test-module');

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $this->assertTrue($installer->callIsPathRepository($pkg));

        (new Filesystem)->remove($baseDir);
    }

    public function test_is_path_repository_returns_false_when_dist_type_is_path_but_install_path_is_real_directory(): void
    {
        // Bug scenario: module installed from Packagist lands in modules/ as a real directory.
        $baseDir = sys_get_temp_dir().'/path-repo-realdir-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $this->assertFalse($installer->callIsPathRepository($pkg));

        (new Filesystem)->remove($baseDir);
    }

    public function test_is_path_repository_returns_false_when_dist_type_is_path_but_install_path_does_not_exist(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $pkg = new Package('saucebase/nonexistent-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $this->assertFalse($installer->callIsPathRepository($pkg));
    }

    public function test_is_path_repository_returns_true_when_dist_type_is_path_and_install_path_has_git_dir(): void
    {
        $baseDir = sys_get_temp_dir().'/path-repo-gitclone-'.uniqid('', true);
        mkdir($baseDir.'/test-module/.git', 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $this->assertTrue($installer->callIsPathRepository($pkg));

        (new Filesystem)->remove($baseDir);
    }

    public function test_is_path_repository_returns_false_for_non_path_dist_types(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);

        foreach (['zip', 'tar', null] as $distType) {
            $pkg = $this->createStub(PackageInterface::class);
            $pkg->method('getDistType')->willReturn($distType);
            $this->assertFalse($installer->callIsPathRepository($pkg), "Expected false for distType=$distType");
        }
    }

    // -------------------------------------------------------------------------
    // install() path-repository guard
    // -------------------------------------------------------------------------

    public function test_install_logs_skip_message_and_returns_promise_for_path_repo(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Skipping install'));

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createStub(InstalledRepositoryInterface::class);
        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;

        $promise = $installer->install($repo, $pkg);

        $this->assertNotNull($promise);
    }

    public function test_install_registers_package_in_repo_when_not_already_present_for_path_repo(): void
    {
        $io = $this->createStub(IOInterface::class);

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;
        $installer->install($repo, $pkg);
    }

    public function test_install_does_not_register_package_in_repo_when_already_present_for_path_repo(): void
    {
        $io = $this->createStub(IOInterface::class);

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(true);
        $repo->expects($this->never())->method('addPackage');

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;
        $installer->install($repo, $pkg);
    }

    // -------------------------------------------------------------------------
    // install() locally-tracked guard (distType='zip', physical .git check)
    // -------------------------------------------------------------------------

    public function test_install_skips_when_install_path_is_locally_tracked(): void
    {
        $baseDir = sys_get_temp_dir().'/install-tracked-'.uniqid('', true);
        mkdir($baseDir.'/test-module/.git', 0755, true);

        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Skipping install for locally tracked'));

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('zip');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($io, $composer);
        $promise = $installer->install($repo, $pkg);

        $this->assertNotNull($promise);

        (new Filesystem)->remove($baseDir);
    }

    // -------------------------------------------------------------------------
    // update() path-repository guard
    // -------------------------------------------------------------------------

    public function test_update_logs_skip_message_and_returns_promise_for_path_repo(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Skipping update'));

        $initial = $this->createStub(PackageInterface::class);

        $target = $this->createStub(PackageInterface::class);
        $target->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createStub(InstalledRepositoryInterface::class);
        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;

        $promise = $installer->update($repo, $initial, $target);

        $this->assertNotNull($promise);
    }

    public function test_update_registers_package_in_repo_when_not_already_present_for_path_repo(): void
    {
        $io = $this->createStub(IOInterface::class);

        $initial = $this->createStub(PackageInterface::class);

        $target = $this->createStub(PackageInterface::class);
        $target->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);
        $repo->expects($this->never())->method('removePackage');
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;
        $installer->update($repo, $initial, $target);
    }

    public function test_update_replaces_initial_with_target_in_repo_for_path_repo(): void
    {
        $io = $this->createStub(IOInterface::class);

        $initial = $this->createStub(PackageInterface::class);

        $target = $this->createStub(PackageInterface::class);
        $target->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')
            ->willReturnCallback(fn (PackageInterface $pkg) => $pkg === $initial);
        $repo->expects($this->once())->method('removePackage')->with($initial);
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;
        $installer->update($repo, $initial, $target);
    }

    public function test_update_does_not_register_package_in_repo_when_already_present_for_path_repo(): void
    {
        $io = $this->createStub(IOInterface::class);

        $initial = $this->createStub(PackageInterface::class);

        $target = $this->createStub(PackageInterface::class);
        $target->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(true);
        $repo->expects($this->once())->method('removePackage')->with($initial);
        $repo->expects($this->never())->method('addPackage');

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;
        $installer->update($repo, $initial, $target);
    }

    // -------------------------------------------------------------------------
    // uninstall() path-repository guard
    // -------------------------------------------------------------------------

    public function test_uninstall_logs_skip_message_and_removes_package_from_repo(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Skipping uninstall'));

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(true);
        $repo->expects($this->once())->method('removePackage')->with($pkg);

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;
        $installer->uninstall($repo, $pkg);
    }

    public function test_uninstall_skips_remove_package_when_package_not_in_repo(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('write');

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getPrettyName')->willReturn('saucebase/test');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);
        $repo->expects($this->never())->method('removePackage');

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = true;
        $installer->uninstall($repo, $pkg);
    }

    // -------------------------------------------------------------------------
    // update() locally-tracked guard (distType='zip', physical .git check)
    // -------------------------------------------------------------------------

    public function test_update_skips_when_install_path_is_locally_tracked(): void
    {
        $baseDir = sys_get_temp_dir().'/update-tracked-'.uniqid('', true);
        mkdir($baseDir.'/test-module/.git', 0755, true);

        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Skipping update for locally tracked'));

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $target = new Package('saucebase/test-module', '2.0.0.0', '2.0.0');
        $target->setDistType('zip');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturnOnConsecutiveCalls(true, false);
        $repo->expects($this->once())->method('removePackage');
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($io, $composer);
        $promise = $installer->update($repo, $initial, $target);

        $this->assertNotNull($promise);

        (new Filesystem)->remove($baseDir);
    }

    // -------------------------------------------------------------------------
    // update() merge-strategy guard for path-type initial packages
    // -------------------------------------------------------------------------

    public function test_update_skips_base_download_and_delegates_repo_manually_when_initial_is_path_type(): void
    {
        $baseDir = sys_get_temp_dir().'/update-path-initial-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('path');

        $target = new Package('saucebase/test-module', '2.0.0.0', '2.0.0');
        $target->setDistType('zip');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturnCallback(fn ($p) => $p === $initial);
        $repo->expects($this->once())->method('removePackage')->with($initial);
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($this->createStub(IOInterface::class), $composer);
        $installer->update($repo, $initial, $target);

        $this->assertFalse($installer->downloadBaseInvoked);

        (new Filesystem)->remove($baseDir);
    }

    public function test_update_downloads_base_and_does_direct_repo_tracking_when_initial_is_not_path_type(): void
    {
        $baseDir = sys_get_temp_dir().'/update-zip-initial-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('zip');

        $target = new Package('saucebase/test-module', '2.0.0.0', '2.0.0');
        $target->setDistType('zip');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturnCallback(fn ($p) => $p === $initial);
        $repo->expects($this->once())->method('removePackage')->with($initial);
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($this->createStub(IOInterface::class), $composer);
        $installer->update($repo, $initial, $target);

        $this->assertTrue($installer->downloadBaseInvoked);

        (new Filesystem)->remove($baseDir);
    }

    // -------------------------------------------------------------------------
    // isInstalledModuleResolvedAsPath
    // -------------------------------------------------------------------------

    public function test_is_installed_module_resolved_as_path_returns_true_for_real_dir_with_path_dist(): void
    {
        $baseDir = sys_get_temp_dir().'/is-installed-real-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $this->assertTrue($installer->callIsInstalledModuleResolvedAsPath($pkg));

        (new Filesystem)->remove($baseDir);
    }

    public function test_is_installed_module_resolved_as_path_returns_false_for_symlink(): void
    {
        $baseDir = sys_get_temp_dir().'/is-installed-symlink-'.uniqid('', true);
        $target = sys_get_temp_dir().'/is-installed-symlink-src-'.uniqid('', true);
        mkdir($target, 0755, true);
        mkdir($baseDir, 0755, true);
        symlink($target, $baseDir.'/test-module');

        $installer = $this->makeInstallerWithModuleDir($baseDir);

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $this->assertFalse($installer->callIsInstalledModuleResolvedAsPath($pkg));

        (new Filesystem)->remove($baseDir);
        (new Filesystem)->remove($target);
    }

    public function test_is_installed_module_resolved_as_path_returns_false_for_git_dir(): void
    {
        $baseDir = sys_get_temp_dir().'/is-installed-git-'.uniqid('', true);
        mkdir($baseDir.'/test-module/.git', 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $this->assertFalse($installer->callIsInstalledModuleResolvedAsPath($pkg));

        (new Filesystem)->remove($baseDir);
    }

    public function test_is_installed_module_resolved_as_path_returns_false_for_non_path_dist(): void
    {
        $baseDir = sys_get_temp_dir().'/is-installed-zip-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('zip');

        $this->assertFalse($installer->callIsInstalledModuleResolvedAsPath($pkg));

        (new Filesystem)->remove($baseDir);
    }

    // -------------------------------------------------------------------------
    // resolveRegistrationTarget
    // -------------------------------------------------------------------------

    public function test_resolve_registration_target_copies_non_path_dist_from_initial(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('zip');
        $initial->setDistUrl('https://api.github.com/repos/saucebase-dev/blog/zipball/abc123');
        $initial->setDistReference('abc123');

        $target = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $target->setDistType('path');
        $target->setDistUrl('modules/test-module');

        $result = $installer->callResolveRegistrationTarget($initial, $target);

        $this->assertSame('zip', $result->getDistType());
        $this->assertSame($initial->getDistUrl(), $result->getDistUrl());
        $this->assertSame($initial->getDistReference(), $result->getDistReference());
        $this->assertNotSame($target, $result, 'should return a clone, not mutate the original');
    }

    public function test_resolve_registration_target_returns_target_unchanged_when_initial_is_also_path(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('path');

        $target = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $target->setDistType('path');
        $target->setDistUrl('modules/test-module');

        $result = $installer->callResolveRegistrationTarget($initial, $target);

        $this->assertSame($target, $result);
        $this->assertSame('path', $result->getDistType());
    }

    // -------------------------------------------------------------------------
    // update() — isInstalledModuleResolvedAsPath guard
    // -------------------------------------------------------------------------

    public function test_update_does_not_crash_when_target_is_installed_module_resolved_as_path(): void
    {
        $baseDir = sys_get_temp_dir().'/update-path-crash-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('zip');

        $target = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $target->setDistType('path');
        $target->setDistUrl($baseDir.'/test-module');

        $repo = $this->createStub(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);

        $installer = new TestableInstaller($this->createStub(IOInterface::class), $composer);

        $resolved = false;
        $installer->update($repo, $initial, $target)->then(function () use (&$resolved) {
            $resolved = true;
        });

        $this->assertTrue($resolved);
        $this->assertFalse($installer->downloadBaseInvoked, 'should not attempt to download base');

        (new Filesystem)->remove($baseDir);
    }

    public function test_update_does_not_stash_module_dir_when_target_is_installed_module_resolved_as_path(): void
    {
        $baseDir = sys_get_temp_dir().'/update-path-nostash-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);
        file_put_contents($baseDir.'/test-module/file.php', '<?php // user edit');

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('zip');

        $target = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $target->setDistType('path');

        $repo = $this->createStub(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);

        $installer = new TestableInstaller($this->createStub(IOInterface::class), $composer);
        $installer->update($repo, $initial, $target);

        $this->assertDirectoryExists($baseDir.'/test-module', 'module dir must not be stashed or removed');
        $this->assertFileExists($baseDir.'/test-module/file.php', 'user files must survive');

        (new Filesystem)->remove($baseDir);
    }

    public function test_update_preserves_dist_info_from_initial_when_target_is_installed_module_resolved_as_path(): void
    {
        $baseDir = sys_get_temp_dir().'/update-path-distfix-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('zip');
        $initial->setDistUrl('https://api.github.com/repos/saucebase-dev/test/zipball/abc');
        $initial->setDistReference('abc');

        $target = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $target->setDistType('path');
        $target->setDistUrl($baseDir.'/test-module');

        $registered = null;
        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);
        $repo->expects($this->once())->method('addPackage')->willReturnCallback(
            function (PackageInterface $p) use (&$registered) {
                $registered = $p;
            }
        );

        $installer = new TestableInstaller($this->createStub(IOInterface::class), $composer);
        $installer->update($repo, $initial, $target);

        $this->assertNotNull($registered);
        $this->assertSame('zip', $registered->getDistType(), 'lock must record zip dist, not path');
        $this->assertSame($initial->getDistUrl(), $registered->getDistUrl());

        (new Filesystem)->remove($baseDir);
    }

    public function test_update_keeps_path_dist_when_initial_is_also_path_type(): void
    {
        $baseDir = sys_get_temp_dir().'/update-path-both-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        $initial = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $initial->setDistType('path');

        $target = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $target->setDistType('path');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);
        $repo->expects($this->once())->method('addPackage');

        $installer = new TestableInstaller($this->createStub(IOInterface::class), $composer);

        $resolved = false;
        $installer->update($repo, $initial, $target)->then(function () use (&$resolved) {
            $resolved = true;
        });

        $this->assertTrue($resolved, 'must not throw when both initial and target are path type');

        (new Filesystem)->remove($baseDir);
    }

    // -------------------------------------------------------------------------
    // install() — installed-module-as-path guard
    // -------------------------------------------------------------------------

    public function test_install_skips_download_when_module_dir_exists_with_path_dist(): void
    {
        $baseDir = sys_get_temp_dir().'/install-path-exists-'.uniqid('', true);
        mkdir($baseDir.'/test-module', 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);
        $repo->expects($this->once())->method('addPackage');

        $resolved = false;
        $installer->install($repo, $pkg)->then(function () use (&$resolved) {
            $resolved = true;
        });

        $this->assertTrue($resolved);
        $this->assertFalse($installer->parentInstallInvoked, 'parentInstall must not be called when dir already exists');

        (new Filesystem)->remove($baseDir);
    }

    public function test_install_falls_through_to_parent_when_module_dir_absent_with_path_dist(): void
    {
        $baseDir = sys_get_temp_dir().'/install-path-absent-'.uniqid('', true);
        mkdir($baseDir, 0755, true); // base exists but test-module subdir does NOT

        $installer = $this->makeInstallerWithModuleDir($baseDir);

        $pkg = new Package('saucebase/test-module', '1.0.0.0', '1.0.0');
        $pkg->setDistType('path');

        $repo = $this->createStub(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);

        $installer->install($repo, $pkg);

        $this->assertTrue($installer->parentInstallInvoked, 'parentInstall must be called when dir is absent');

        (new Filesystem)->remove($baseDir);
    }

    // -------------------------------------------------------------------------
    // getSelectedFramework
    // -------------------------------------------------------------------------

    public function test_get_selected_framework_returns_null_when_frontend_json_missing(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->setFrontendJsonPath('/nonexistent/path/frontend.json');

        $this->assertNull($installer->callGetSelectedFramework());
    }

    public function test_get_selected_framework_returns_null_when_framework_key_is_null(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => null]));

        $this->assertNull($installer->callGetSelectedFramework());
    }

    public function test_get_selected_framework_returns_null_when_json_is_invalid(): void
    {
        $path = sys_get_temp_dir().'/frontend-invalid-'.uniqid('', true).'.json';
        file_put_contents($path, 'not-valid-json{{{');

        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->setFrontendJsonPath($path);

        $this->assertNull($installer->callGetSelectedFramework());

        unlink($path);
    }

    public function test_get_selected_framework_returns_null_when_dev_mode_is_true(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => 'vue', 'dev' => true]));

        $this->assertNull($installer->callGetSelectedFramework());
    }

    public function test_get_selected_framework_returns_vue_when_set(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => 'vue']));

        $this->assertSame('vue', $installer->callGetSelectedFramework());
    }

    public function test_get_selected_framework_returns_react_when_set(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => 'react']));

        $this->assertSame('react', $installer->callGetSelectedFramework());
    }

    public function test_get_selected_framework_returns_null_for_unknown_framework(): void
    {
        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);

        foreach (['angular', 'solid', '../etc', '/abs', 'Vue', ''] as $unknown) {
            $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => $unknown]));
            $this->assertNull($installer->callGetSelectedFramework(), "Expected null for framework='$unknown'");
        }
    }

    // -------------------------------------------------------------------------
    // copyFrameworkFiles
    // -------------------------------------------------------------------------

    public function test_copy_framework_files_silent_skips_when_no_resources_js_dir(): void
    {
        $baseDir = sys_get_temp_dir();
        // Package 'saucebase/no-js' → install path = $baseDir/no-js (no resources/js inside)
        $moduleDir = $baseDir.'/no-js';
        mkdir($moduleDir, 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => 'vue']));

        $pkg = new Package('saucebase/no-js', '1.0.0.0', '1.0.0');

        // No resources/js dir — should not throw and should not create anything
        $installer->callCopyFrameworkFiles($pkg);

        $this->assertDirectoryDoesNotExist($moduleDir.'/resources/js');

        (new Filesystem)->remove($moduleDir);
    }

    public function test_copy_framework_files_silent_skips_when_framework_is_null(): void
    {
        $baseDir = sys_get_temp_dir();
        // Package 'saucebase/skip-module' → install path = $baseDir/skip-module
        $moduleDir = $baseDir.'/skip-module';
        $jsRoot = $moduleDir.'/resources/js';
        mkdir($jsRoot.'/vue', 0755, true);
        file_put_contents($jsRoot.'/vue/app.ts', 'content');

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $installer->setFrontendJsonPath('/nonexistent/frontend.json'); // framework = null

        $pkg = new Package('saucebase/skip-module', '1.0.0.0', '1.0.0');

        $installer->callCopyFrameworkFiles($pkg);

        // Framework subdirs must be untouched — no flattening occurred
        $this->assertDirectoryExists($jsRoot.'/vue');
        $this->assertFileDoesNotExist($jsRoot.'/app.ts');

        (new Filesystem)->remove($moduleDir);
    }

    public function test_copy_framework_files_hard_fails_when_framework_subdir_missing(): void
    {
        $baseDir = sys_get_temp_dir();
        // Package 'saucebase/vue-only' → module dir 'vue-only' → install path = $baseDir/vue-only
        $moduleDir = $baseDir.'/vue-only';
        mkdir($moduleDir.'/resources/js', 0755, true);

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => 'react']));

        $pkg = new Package('saucebase/vue-only', '1.0.0.0', '1.0.0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not support react/');

        try {
            $installer->callCopyFrameworkFiles($pkg);
        } finally {
            (new Filesystem)->remove($moduleDir);
        }
    }

    public function test_copy_framework_files_flattens_files_and_removes_framework_subdirs(): void
    {
        $baseDir = sys_get_temp_dir();
        // Package 'saucebase/flatten-test' → module dir 'flatten-test' → install path = $baseDir/flatten-test
        $moduleDir = $baseDir.'/flatten-test';
        $jsRoot = $moduleDir.'/resources/js';
        mkdir($jsRoot.'/vue/pages', 0755, true);
        mkdir($jsRoot.'/react', 0755, true);
        file_put_contents($jsRoot.'/vue/app.ts', 'vue app');
        file_put_contents($jsRoot.'/vue/pages/Login.vue', 'login page');
        file_put_contents($jsRoot.'/react/app.tsx', 'react app');

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => 'vue']));

        $pkg = new Package('saucebase/flatten-test', '1.0.0.0', '1.0.0');

        $installer->callCopyFrameworkFiles($pkg);

        $this->assertFileExists($jsRoot.'/app.ts');
        $this->assertSame('vue app', file_get_contents($jsRoot.'/app.ts'));
        $this->assertFileExists($jsRoot.'/pages/Login.vue');
        $this->assertDirectoryDoesNotExist($jsRoot.'/vue');
        $this->assertDirectoryDoesNotExist($jsRoot.'/react');

        (new Filesystem)->remove($moduleDir);
    }

    public function test_copy_framework_files_removes_all_known_framework_subdirs(): void
    {
        $baseDir = sys_get_temp_dir();
        // Package 'saucebase/multi-fw' → install path = $baseDir/multi-fw
        $moduleDir = $baseDir.'/multi-fw';
        $jsRoot = $moduleDir.'/resources/js';
        mkdir($jsRoot.'/vue', 0755, true);
        mkdir($jsRoot.'/react', 0755, true);
        mkdir($jsRoot.'/svelte', 0755, true);
        file_put_contents($jsRoot.'/vue/app.ts', 'vue app');
        file_put_contents($jsRoot.'/react/app.tsx', 'react app');
        file_put_contents($jsRoot.'/svelte/app.svelte', 'svelte app');

        $installer = $this->makeInstallerWithModuleDir($baseDir);
        $installer->setFrontendJsonPath($this->writeFrontendJson(['framework' => 'vue']));

        $pkg = new Package('saucebase/multi-fw', '1.0.0.0', '1.0.0');
        $installer->callCopyFrameworkFiles($pkg);

        $this->assertFileExists($jsRoot.'/app.ts');
        $this->assertDirectoryDoesNotExist($jsRoot.'/vue');
        $this->assertDirectoryDoesNotExist($jsRoot.'/react');
        $this->assertDirectoryDoesNotExist($jsRoot.'/svelte');

        (new Filesystem)->remove($moduleDir);
    }

    public function test_flatten_framework_files_is_callable_on_arbitrary_path(): void
    {
        $jsRoot = sys_get_temp_dir().'/flatten-direct-'.uniqid('', true);
        mkdir($jsRoot.'/vue/pages', 0755, true);
        mkdir($jsRoot.'/react', 0755, true);
        file_put_contents($jsRoot.'/vue/app.ts', 'vue app');
        file_put_contents($jsRoot.'/vue/pages/Login.vue', 'login');
        file_put_contents($jsRoot.'/react/app.tsx', 'react app');

        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->callFlattenFrameworkFiles($jsRoot, 'vue');

        $this->assertFileExists($jsRoot.'/app.ts');
        $this->assertFileExists($jsRoot.'/pages/Login.vue');
        $this->assertDirectoryDoesNotExist($jsRoot.'/vue');
        $this->assertDirectoryDoesNotExist($jsRoot.'/react');

        (new Filesystem)->remove($jsRoot);
    }

    // -------------------------------------------------------------------------
    // Integration: install() invokes copyFrameworkFiles
    // -------------------------------------------------------------------------

    public function test_install_invokes_copy_framework_files_for_non_path_repo(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);
        $installer->setFrontendJsonPath('/nonexistent/frontend.json'); // returns null → skips copy

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getDistType')->willReturn('zip');
        $pkg->method('getPrettyName')->willReturn('saucebase/auth');

        $repo = $this->createStub(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(false);

        $resolved = false;
        $installer->install($repo, $pkg)->then(function () use (&$resolved) {
            $resolved = true;
        });

        $this->assertTrue($resolved);
        $this->assertTrue($installer->copyFrameworkFilesInvoked);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function writeFrontendJson(array $data): string
    {
        $path = sys_get_temp_dir().'/frontend-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    private function makeInstallerWithModuleDir(string $baseDir): TestableInstaller
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => $baseDir]);
        $composer->method('getPackage')->willReturn($root);

        return new TestableInstaller($io, $composer);
    }

    // -------------------------------------------------------------------------
    // uninstall() skip-deletion-by-default guard (module-delete-on-remove)
    // -------------------------------------------------------------------------

    public function test_uninstall_skips_folder_deletion_by_default_and_removes_from_repo(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Skipping deletion of module directory'));

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getPrettyName')->willReturn('saucebase/test-module');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(true);
        $repo->expects($this->once())->method('removePackage')->with($pkg);

        $installer = new TestableInstaller($io, null);
        $installer->forcePathRepository = false;
        $installer->uninstall($repo, $pkg);
    }

    public function test_should_delete_on_remove_returns_false_when_flag_absent(): void
    {
        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller(null, $composer);
        $this->assertFalse($installer->callShouldDeleteOnRemove());
    }

    public function test_should_delete_on_remove_returns_true_when_flag_enabled(): void
    {
        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-delete-on-remove' => true]);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller(null, $composer);
        $this->assertTrue($installer->callShouldDeleteOnRemove());
    }

    public function test_uninstall_path_repository_guard_takes_precedence_over_delete_flag(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Skipping uninstall for path repository'));

        $pkg = $this->createStub(PackageInterface::class);
        $pkg->method('getPrettyName')->willReturn('saucebase/test-module');

        $repo = $this->createMock(InstalledRepositoryInterface::class);
        $repo->method('hasPackage')->willReturn(true);
        $repo->expects($this->once())->method('removePackage')->with($pkg);

        $composer = $this->createStub(Composer::class);
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-delete-on-remove' => true]);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);
        $installer->forcePathRepository = true;
        $installer->uninstall($repo, $pkg);
    }

    public function test_flatten_framework_files_removes_stale_root_file_from_other_framework(): void
    {
        $jsRoot = sys_get_temp_dir().'/flatten-stale-'.uniqid('', true);
        mkdir($jsRoot.'/vue', 0755, true);
        mkdir($jsRoot.'/react', 0755, true);
        file_put_contents($jsRoot.'/app.ts', 'stale vue root file');
        file_put_contents($jsRoot.'/vue/app.ts', 'vue app');
        file_put_contents($jsRoot.'/react/app.tsx', 'react app');

        $installer = new TestableInstaller($this->createStub(IOInterface::class), null);
        $installer->callFlattenFrameworkFiles($jsRoot, 'react');

        $this->assertFileExists($jsRoot.'/app.tsx');
        $this->assertFileDoesNotExist($jsRoot.'/app.ts');
        $this->assertDirectoryDoesNotExist($jsRoot.'/vue');
        $this->assertDirectoryDoesNotExist($jsRoot.'/react');

        (new Filesystem)->remove($jsRoot);
    }
}
