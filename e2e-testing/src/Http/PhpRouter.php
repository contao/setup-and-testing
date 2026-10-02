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

final class PhpRouter
{
    public static function generate(string $index, string $prelude = ''): string
    {
        $index = var_export($index, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            $prelude

            \$path = rawurldecode(parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

            if ('/' !== \$path && is_file(\$_SERVER['DOCUMENT_ROOT'].\$path)) {
                return false;
            }

            require $index;
            PHP;
    }
}
