<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

if ('/state' !== parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    echo 'ready';

    return;
}

session_start();
$_SESSION['visits'] = ($_SESSION['visits'] ?? 0) + 1;
echo $_SESSION['visits'];
