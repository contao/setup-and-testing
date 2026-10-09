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

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Browser\BackendBrowser;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;
use Playwright\Network\RequestInterface;

final class ManagedEditionLoginIntegrationTest extends TestCase
{
    public function testReusesAuthenticationAfterDatabaseResetAndInstallationRelease(): void
    {
        $version = getenv('CONTAO_E2E_LOGIN_CONTAO_VERSION');

        if (false === $version || '' === $version) {
            $this->markTestSkipped('Set CONTAO_E2E_LOGIN_CONTAO_VERSION to test a real Managed Edition.');
        }

        $recipe = $this->recipe($version);
        $config = ManagedEditionConfig::create($recipe, \dirname(__DIR__, 2));
        $runtime = ApplicationRuntime::create();
        $edition = $config->createApplication($runtime);

        try {
            $edition->resetDatabase();
            $first = $edition->createBackendBrowser()->loginOrReuseSessionAs('k.jones');
            $this->assertCount(1, $this->loginRequests($first));
            $edition->resetDatabase();
            $this->assertReusedLogin($edition);
            $directory = $edition->directory();
            $edition->release();
            $edition = $config->createApplication($runtime);
            $this->assertSame($directory, $edition->directory());
            $edition->resetDatabase();
            $this->assertReusedLogin($edition);
            $this->assertRenamedUserDoesNotReuseAuthentication($edition);
        } finally {
            $edition->release();
            $runtime->close();
        }
    }

    private function recipe(string $version): InstallationRecipe
    {
        $composer = ComposerConfig::managedEdition($version);
        $dbal = getenv('CONTAO_E2E_LOGIN_DBAL_VERSION');

        if (false !== $dbal && '' !== $dbal) {
            $composer = $composer->require('doctrine/dbal', $dbal);
        }

        return InstallationRecipe::create($composer)->withFixtureFile(__DIR__.'/Fixtures/backend-login/users.yaml');
    }

    private function assertReusedLogin(ManagedEdition $edition): void
    {
        $backend = $edition->createBackendBrowser()->loginOrReuseSessionAs('k.jones');
        $this->assertSame(1, $backend->page()->locator('a[href*="/contao/logout"]')->count());
        $this->assertSame([], $this->loginRequests($backend));
        $backend->visit('/contao?do=article');
        $this->assertSame(0, $backend->page()->locator('button[name="login"]')->count());
        $profile = $backend->page()->locator('#tmenu a[href*="do=login"]')->first()->getAttribute('href');
        $this->assertNotNull($profile);
        $backend->visit($profile);
        $backend->waitForNavigation(static fn () => $backend->page()->locator('button[name="save"]')->click());
        $this->assertSame(1, $backend->page()->locator('a[href*="/contao/logout"]')->count());
        $this->assertSame(0, $backend->page()->locator('.tl_error')->count());
    }

    private function assertRenamedUserDoesNotReuseAuthentication(ManagedEdition $edition): void
    {
        $edition->database()->connection()->executeStatement('UPDATE tl_user SET username = ? WHERE username = ?', ['renamed', 'k.jones']);
        $backend = $edition->createBackendBrowser();

        try {
            $backend->loginOrReuseSessionAs('k.jones');
            $this->fail('A cached session must not authenticate a different username.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Could not log into', $exception->getMessage());
        }

        $backend->loginOrReuseSessionAs('renamed');
        $this->assertStringContainsString('renamed', $backend->page()->locator('#tmenu')->textContent());
    }

    /**
     * @return array<RequestInterface>
     */
    private function loginRequests(BackendBrowser $backend): array
    {
        return array_filter(
            $backend->page()->requests(),
            static fn (RequestInterface $request): bool => 'POST' === $request
                ->method() && '/contao/login' === parse_url($request->url(), PHP_URL_PATH),
        );
    }
}
