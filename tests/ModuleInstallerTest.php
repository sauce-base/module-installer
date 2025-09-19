<?php

declare(strict_types=1);

namespace Tests;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use PHPUnit\Framework\TestCase;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;
use Saucebase\ModuleInstaller\Installer;

/**
 * Shim that avoids LibraryInstaller's heavy constructor.
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
}

final class ModuleInstallerTest extends TestCase
{
    public function test_supports_default_module_type(): void
    {
        $io = $this->createMock(IOInterface::class);
        $composer = $this->createMock(Composer::class);

        $installer = new TestableInstaller($io, $composer);

        $this->assertTrue($installer->supports('laravel-module'));
        $this->assertFalse($installer->supports('library'));
        $this->assertFalse($installer->supports('composer-plugin'));
    }

    public function test_supports_custom_module_type_from_extra(): void
    {
        $io = $this->createMock(IOInterface::class);
        $composer = $this->createMock(Composer::class);

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
        $io = $this->createMock(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $pkg = new Package('saucebase/something-nice', '1.0.0.0', '1.0.0');

        $this->assertSame('modules/SomethingNice', $installer->getInstallPath($pkg));
    }

    public function test_get_install_path_uses_default_when_no_module_dir_in_extra(): void
    {
        $io = $this->createMock(IOInterface::class);
        $composer = $this->createMock(Composer::class);

        // Root package present, but without extra['module-dir']
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra([]); // nothing set
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);
        $pkg = new Package('vendor/awesome-toolkit', '1.0.0.0', '1.0.0');

        $this->assertSame('modules/AwesomeToolkit', $installer->getInstallPath($pkg));
    }

    public function test_get_install_path_honors_extra_module_dir(): void
    {
        $io = $this->createMock(IOInterface::class);
        $composer = $this->createMock(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => 'Modules']); // custom dir
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);
        $pkg = new Package('vendor/awesome-toolkit', '1.0.0.0', '1.0.0');

        $this->assertSame('Modules/AwesomeToolkit', $installer->getInstallPath($pkg));
    }

    public function test_get_module_name_throws_on_invalid_pretty_name(): void
    {
        $io = $this->createMock(IOInterface::class);
        $composer = $this->createMock(Composer::class);
        $installer = new TestableInstaller($io, $composer);

        /** @var \PHPUnit\Framework\MockObject\MockObject&PackageInterface $bad */
        $bad = $this->createMock(PackageInterface::class);
        $bad->method('getPrettyName')->willReturn('invalidname'); // no slash

        $this->expectException(ModuleInstallerException::class);
        $installer->callGetModuleName($bad);
    }
}
