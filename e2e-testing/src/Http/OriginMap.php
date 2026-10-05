<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Http;

use Symfony\Component\Filesystem\Filesystem;

final readonly class OriginMap
{
    public function __construct(public string $file)
    {
    }

    public function register(Origin $origin): string
    {
        $alias = 'contao-e2e-'.substr(hash('sha256', $origin->host."\0".(int) $origin->https), 0, 16).'.localhost';
        $mapping = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        $mapping[$alias] = ['host' => $origin->host, 'https' => $origin->https];
        (new Filesystem())->dumpFile($this->file, json_encode($mapping, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $alias;
    }

    public function prelude(): string
    {
        $mapping = var_export($this->file, true);

        return <<<PHP
            \$mapping = json_decode((string) file_get_contents($mapping), true, 512, JSON_THROW_ON_ERROR);
            \$transportHost = explode(':', \$_SERVER['HTTP_HOST'] ?? '')[0];

            if (isset(\$mapping[\$transportHost])) {
                \$_SERVER['HTTP_HOST'] = \$mapping[\$transportHost]['host'];

                if (\$mapping[\$transportHost]['https']) {
                    \$_SERVER['HTTPS'] = 'on';
                    \$_SERVER['SERVER_PORT'] = '443';
                }
            }
            PHP;
    }
}
