<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\E2eTesting\Command\E2eApplication;
use Contao\E2eTesting\Command\ServerStartCommand;
use Contao\E2eTesting\Inspection\InspectionSession;
use Contao\E2eTesting\Inspection\InspectionSessionStore;
use Symfony\Component\Console\Input\ArrayInput;

require $argv[1];

// Keep the session lock until shutdown functions have released the Docker leases.
$GLOBALS['contao_inspection_session'] = $session = InspectionSession::open(new InspectionSessionStore($argv[2]), $argv[3]);
$command = new ServerStartCommand($session);
$exitCode = 1;
register_shutdown_function(
    static function () use ($session, &$exitCode): void {
        try {
            $session->finish($exitCode);
        } catch (Throwable $exception) {
            // A status-write failure must not prevent the remaining shutdown functions.
            error_log('Could not write inspection shutdown status: '.$exception->getMessage());
        }
    },
);

if (function_exists('pcntl_signal') && defined('SIGWINCH')) {
    pcntl_async_signals(true);
    pcntl_signal(
        SIGWINCH,
        static function () use ($session, $command): void {
            if ($session->stopRequested()) {
                $command->handleSignal(SIGTERM);
            }
        },
    );
}

$application = new E2eApplication();
$application->addCommands([$command]);
$application->setAutoExit(false);

$exitCode = $application->run(new ArrayInput([
    'command' => 'server:start',
    'inspection-file' => $argv[4],
    '--no-interaction' => true,
]));

exit($exitCode);
