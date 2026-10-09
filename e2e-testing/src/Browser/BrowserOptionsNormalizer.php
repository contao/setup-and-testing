<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser;

use Playwright\Configuration\PlaywrightConfig;

final readonly class BrowserOptionsNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(BrowserOptions $options, PlaywrightConfig|null $config = null): array
    {
        $normalized = [];

        if (null !== $options->acceptLanguage()) {
            $normalized['extraHTTPHeaders'] = ['Accept-Language' => $options->acceptLanguage()];
        }

        if (null !== $options->viewportWidth() && null !== $options->viewportHeight()) {
            $normalized['viewport'] = [
                'width' => $options->viewportWidth(),
                'height' => $options->viewportHeight(),
            ];
        }

        if (null !== $config?->videosDir) {
            $normalized['recordVideo'] = ['dir' => $config->videosDir, 'size' => $this->videoSize($options)];
        }

        return $normalized;
    }

    /**
     * @return array{width: int, height: int}
     */
    private function videoSize(BrowserOptions $options): array
    {
        if (null !== $options->videoWidth() && null !== $options->videoHeight()) {
            return ['width' => $options->videoWidth(), 'height' => $options->videoHeight()];
        }

        $width = getenv('PW_VIDEO_WIDTH');
        $height = getenv('PW_VIDEO_HEIGHT');
        $width = false === $width || '' === $width ? null : $width;
        $height = false === $height || '' === $height ? null : $height;

        if (null !== $width || null !== $height) {
            return [
                'width' => $this->environmentDimension($width),
                'height' => $this->environmentDimension($height),
            ];
        }

        // Match Playwright's default viewport when no explicit viewport was supplied.
        return ['width' => $options->viewportWidth() ?? 1280, 'height' => $options->viewportHeight() ?? 720];
    }

    private function environmentDimension(string|null $value): int
    {
        if (null === $value || !ctype_digit($value) || false === filter_var(ltrim($value, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            throw new \InvalidArgumentException('PW_VIDEO_WIDTH and PW_VIDEO_HEIGHT must both be positive integers.');
        }

        return (int) $value;
    }
}
