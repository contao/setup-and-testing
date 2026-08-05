<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Composer;

final readonly class ComposerRunConfig
{
    public function __construct(
        public string $executable = 'composer',
        public bool $preferLowest = false,
        public bool $preferStable = true,
    ) {
    }

    public function withPreferLowest(bool $preferLowest = true): self
    {
        return new self($this->executable, $preferLowest, $this->preferStable);
    }

    public function withPreferStable(bool $preferStable = true): self
    {
        return new self($this->executable, $this->preferLowest, $preferStable);
    }
}
