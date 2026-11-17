<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\ManagedEdition;

use Contao\E2eTesting\Application\ApplicationTestTrait;
use Contao\E2eTesting\Mail\MailAssertionsTrait;
use Contao\E2eTesting\Mail\Mailbox;

trait ManagedEditionTestTrait
{
    use ApplicationTestTrait;
    use MailAssertionsTrait;

    protected function mailbox(): Mailbox
    {
        return self::managedEdition()->mailbox();
    }

    abstract protected static function createApplicationConfig(): ManagedEditionConfig;

    protected static function managedEdition(): ManagedEdition
    {
        $application = self::application();

        if (!$application instanceof ManagedEdition) {
            throw new \LogicException('The application is not a managed Contao edition.');
        }

        return $application;
    }
}
