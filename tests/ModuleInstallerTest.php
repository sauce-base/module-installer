<?php

declare(strict_types=1);

namespace Tests;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\PartialComposer;
use PHPUnit\Framework\TestCase;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;
use Saucebase\ModuleInstaller\Installer;
use Symfony\Component\Filesystem\Filesystem;

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

    public function callRestoreStash(string $stashPath, PackageInterface $package): void
    {
        parent::restoreStash($stashPath, $package);
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
}
