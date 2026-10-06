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

final class HttpBrowserOptions
{
    private SimulatedOrigin|null $simulatedOrigin = null;

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function withSimulatedOrigin(string $origin): self
    {
        $clone = clone $this;
        $clone->simulatedOrigin = SimulatedOrigin::fromUri($origin);

        return $clone;
    }

    public function simulatedOrigin(): SimulatedOrigin|null
    {
        return $this->simulatedOrigin;
    }
}
