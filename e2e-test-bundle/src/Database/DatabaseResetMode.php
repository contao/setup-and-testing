<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Database;

enum DatabaseResetMode
{
    case TRUNCATE;
    case RECREATE_SCHEMA;
}
