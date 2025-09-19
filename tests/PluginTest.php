<?php

declare(strict_types=1);

namespace Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\RootPackage;
use PHPUnit\Framework\MockObject\MockObject;
use Saucebase\ModuleInstaller\Installer;
use Saucebase\ModuleInstaller\Plugin;

final class PluginTest extends TestCase
{
    public function test_activate_adds_installer_to_installation_manager(): void
    {
        $io = $this->createMock(IOInterface::class);
        $installationManager = $this->createMock(InstallationManager::class);

        $composer = $this->buildComposer($installationManager);

        $installationManager->expects($this->once())
            ->method('addInstaller')
            ->with($this->isInstanceOf(Installer::class));

        $plugin = new Plugin;
        $plugin->activate($composer, $io);
    }

    public function test_deactivate_removes_installer_from_installation_manager(): void
    {
        $io = $this->createMock(IOInterface::class);
        $installationManager = $this->createMock(InstallationManager::class);

        $composer = $this->buildComposer($installationManager);

        $installationManager->expects($this->once())
            ->method('removeInstaller')
            ->with($this->isInstanceOf(Installer::class));

        $plugin = new Plugin;
        $plugin->deactivate($composer, $io);
    }

    public function test_uninstall_removes_installer_from_installation_manager(): void
    {
        $io = $this->createMock(IOInterface::class);
        $installationManager = $this->createMock(InstallationManager::class);

        $composer = $this->buildComposer($installationManager);

        $installationManager->expects($this->once())
            ->method('removeInstaller')
            ->with($this->isInstanceOf(Installer::class));

        $plugin = new Plugin;
        $plugin->uninstall($composer, $io);
    }

    /**
     * @param  InstallationManager&MockObject  $installationManager
     */
    private function buildComposer(InstallationManager $installationManager): Composer
    {
        $composer = new Composer;

        $config = new Config(false, getcwd());
        $composer->setConfig($config);

        $composer->setDownloadManager($this->createMock(DownloadManager::class));
        $composer->setInstallationManager($installationManager);

        $rootPackage = new RootPackage('test/app', '1.0.0.0', '1.0.0');
        $composer->setPackage($rootPackage);

        return $composer;
    }
}
