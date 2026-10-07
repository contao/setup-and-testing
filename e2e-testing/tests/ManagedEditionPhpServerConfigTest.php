<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use Contao\E2eTesting\Cache\FingerprintCalculator;
use Contao\E2eTesting\Cache\SourceFingerprint;
use Contao\E2eTesting\Database\DatabaseResetMode;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Http\PhpServerConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ManagedEditionPhpServerConfigTest extends TestCase
{
    public function testManagedEditionsEnableOpcacheByDefaultAndAllowOptingOut(): void
    {
        $original = $this->config();
        $disabled = $original->withPhpServer($original->phpServer()->withOpcache(false));

        $this->assertContains('opcache.memory_consumption=128', $original->phpServer()->arguments());
        $this->assertContains('opcache.max_accelerated_files=20000', $original->phpServer()->arguments());
        $this->assertContains('opcache.interned_strings_buffer=32', $original->phpServer()->arguments());
        $this->assertContains('realpath_cache_size="4096K"', $original->phpServer()->arguments());
        $this->assertContains('realpath_cache_ttl=600', $original->phpServer()->arguments());
        $this->assertContains('opcache.validate_timestamps=1', $original->phpServer()->arguments());
        $this->assertContains('opcache.enable=0', $disabled->phpServer()->arguments());
        $this->assertContains('opcache.enable_cli=0', $disabled->phpServer()->arguments());
        $this->assertContains('opcache.enable=1', $original->phpServer()->arguments());
    }

    public function testPhpSettingsSurviveOtherConfigurationChanges(): void
    {
        $original = $this->config();
        $originalArguments = $original->phpServer()->arguments();
        $php = (new PhpServerConfig())->withOpcache()->withIniSettings(['memory_limit' => '256M']);
        $configured = $original->withPhpServer($php);
        $dca = sys_get_temp_dir().'/php-settings-'.bin2hex(random_bytes(6)).'.php';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($dca, '<?php');

        try {
            $changed = $configured
                ->withEnvironment($configured->environment)
                ->withDatabase(DockerDatabaseConfig::mysql())
                ->withResetMode(DatabaseResetMode::RECREATE_SCHEMA)
                ->withAppEnvironment('dev')
                ->withDcaFile($dca)
                ->withSimulatedOrigins()
            ;

            $this->assertSame($originalArguments, $original->phpServer()->arguments());
            $this->assertNotSame($original, $configured);
            $this->assertSame($original->recipe, $configured->recipe);
            $this->assertSame($php, $changed->phpServer());
            $this->assertSame('prod', $configured->appEnvironment);
            $this->assertSame('dev', $changed->appEnvironment);
            $this->assertSame(DatabaseResetMode::TRUNCATE, $configured->resetMode);
        } finally {
            $filesystem->remove($dca);
        }
    }

    public function testServerSettingsDoNotInvalidateThePreparedInstallation(): void
    {
        $config = $this->config();
        $configured = $config->withPhpServer($config->phpServer()->withOpcache(false)->withIniSettings(['memory_limit' => '256M']));
        $calculator = new FingerprintCalculator(new SourceFingerprint());

        $original = $calculator->calculate($config);
        $changed = $calculator->calculate($configured);

        $this->assertSame($original->dependency, $changed->dependency);
        $this->assertSame($original->application, $changed->application);
        $this->assertSame($original->data, $changed->data);
    }

    private function config(): ManagedEditionConfig
    {
        return ManagedEditionConfig::create(InstallationRecipe::create(ComposerConfig::managedEdition('^5.7')), sys_get_temp_dir());
    }
}
