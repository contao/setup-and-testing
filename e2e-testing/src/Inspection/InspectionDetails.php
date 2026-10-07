<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Inspection;

use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;

final readonly class InspectionDetails
{
    /**
     * @param array<string, string> $values
     */
    private function __construct(public array $values)
    {
    }

    public static function forApplication(ApplicationInterface $application): self
    {
        $values = ['url' => $application->uri()];

        if ($application instanceof ManagedEdition) {
            $values['backend'] = $application->uri('/contao');
            $values['directory'] = $application->directory();
            $values['database'] = $application->database()->applicationUrl();
        }

        return new self($values);
    }

    /**
     * @param array<string, int|string> $state
     */
    public static function fromState(array $state): self
    {
        $values = [];

        foreach (['url', 'backend', 'directory', 'database'] as $key) {
            if (isset($state[$key]) && \is_string($state[$key])) {
                $values[$key] = $state[$key];
            }
        }

        return new self($values);
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->values as $key => $value) {
            $rows[] = ['url' === $key ? 'URL' : ucfirst($key) => $value];
        }

        return $rows;
    }
}
