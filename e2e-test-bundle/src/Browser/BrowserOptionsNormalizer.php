<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Browser;

final readonly class BrowserOptionsNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(BrowserOptions $options): array
    {
        $normalized = [];

        if (null !== $options->acceptLanguage) {
            $normalized['extraHTTPHeaders'] = ['Accept-Language' => $options->acceptLanguage];
        }

        if (null !== $options->viewportWidth && null !== $options->viewportHeight) {
            $normalized['viewport'] = [
                'width' => $options->viewportWidth,
                'height' => $options->viewportHeight,
            ];
        }

        return $normalized;
    }
}
