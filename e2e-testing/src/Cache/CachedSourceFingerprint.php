<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Cache;

use Contao\InstallationRecipe\Cache\InMemoryCache;
use Symfony\Component\Filesystem\Path;

final readonly class CachedSourceFingerprint implements SourceFingerprintInterface
{
    public function __construct(
        private SourceFingerprintInterface $fingerprint,
        private InMemoryCache $cache,
    ) {
    }

    public function calculate(string $path): string
    {
        $path = Path::canonicalize($path);
        $key = 'source.fingerprint:'.$path;
        $cached = $this->cache->get($key);

        if (\is_string($cached)) {
            return $cached;
        }

        $fingerprint = $this->fingerprint->calculate($path);
        $this->cache->set($key, $fingerprint);

        return $fingerprint;
    }
}
