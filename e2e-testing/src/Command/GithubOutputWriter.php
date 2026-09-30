<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Command;

use Contao\E2eTesting\Exception\E2eTestException;

final readonly class GithubOutputWriter
{
    /**
     * @param array<string, string> $values
     */
    public function write(string $path, array $values): void
    {
        $output = '';

        foreach ($values as $name => $value) {
            if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                throw new E2eTestException(\sprintf('The GitHub Actions output name "%s" is invalid.', $name));
            }

            $delimiter = $this->delimiter($name, $value);
            $output .= $name.'<<'.$delimiter."\n".$value."\n".$delimiter."\n";
        }

        if (false === file_put_contents($path, $output, FILE_APPEND | LOCK_EX)) {
            throw new E2eTestException(\sprintf('Could not write GitHub Actions outputs to "%s".', $path));
        }
    }

    private function delimiter(string $name, string $value): string
    {
        $delimiter = 'CONTAO_E2E_'.strtoupper(substr(hash('sha256', $name."\0".$value), 0, 16));

        while (\in_array($delimiter, preg_split('/\R/', $value) ?: [], true)) {
            $delimiter .= '_X';
        }

        return $delimiter;
    }
}
