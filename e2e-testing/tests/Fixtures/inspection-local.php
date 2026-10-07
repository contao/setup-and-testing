<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Http\HttpRequest;
use Symfony\Component\Filesystem\Filesystem;

$directory = (string) getenv('CONTAO_INSPECTION_TEST_DIRECTORY');
$mode = (string) getenv('CONTAO_INSPECTION_TEST_MODE');

if ('external' === $mode) {
    return ApplicationConfig::create((string) getenv('CONTAO_INSPECTION_TEST_URL'));
}

(new Filesystem())->dumpFile($directory.'/public/index.php', '<?php echo file_get_contents(__DIR__."/state");');
file_put_contents($directory.'/public/state', 'Local inspection state');
$config = 'command' === $mode
    ? LocalApplicationConfig::command([PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public'], $directory)
    : LocalApplicationConfig::php($directory);
$config = $config->withTraceDirectory($directory.'/traces');

if (in_array($mode, ['prepared', 'failed', 'foreign'], true)) {
    return static function (ApplicationRuntime $runtime) use ($config, $directory, $mode): ApplicationInterface {
        $application = ('foreign' === $mode ? ApplicationRuntime::create() : $runtime)->createApplication($config);
        file_put_contents($directory.'/url', $application->uri());

        if ('failed' === $mode) {
            throw new RuntimeException('Local inspection preparation failed');
        }
        $application->send(HttpRequest::get('/'));
        file_put_contents($directory.'/public/state', 'Prepared local inspection state');

        return $application;
    };
}

if ('factory' === $mode) {
    return static fn (ApplicationRuntime $runtime): LocalApplicationConfig => $config;
}

return $config;
