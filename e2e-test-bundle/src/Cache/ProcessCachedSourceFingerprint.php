<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Cache;

use Symfony\Component\Filesystem\Path;

final class ProcessCachedSourceFingerprint implements SourceFingerprintInterface
{
    /**
     * @var array<string, string>
     */
    private static array $fingerprints = [];

    public function __construct(private readonly SourceFingerprintInterface $fingerprint = new SourceFingerprint())
    {
    }

    public function calculate(string $path): string
    {
        $path = Path::canonicalize($path);

        return self::$fingerprints[$path] ??= $this->fingerprint->calculate($path);
    }

    public static function reset(): void
    {
        self::$fingerprints = [];
    }
}
