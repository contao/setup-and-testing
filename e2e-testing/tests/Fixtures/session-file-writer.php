<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

$file = fopen($argv[1], 'c+');

if (false === $file || !flock($file, LOCK_EX)) {
    exit(1);
}

ftruncate($file, 0);
fwrite($file, 'partial data');
fflush($file);
fwrite(STDOUT, 'locked');
usleep(500000);
ftruncate($file, 0);
rewind($file);
fwrite($file, 'complete data');
fclose($file);
