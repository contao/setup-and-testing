<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Installation;

use Contao\InstallationRecipe\Composer\ComposerMerger;
use Contao\InstallationRecipe\Configuration\ConfigMerger;

final readonly class InstallationDocumentInstallers
{
    public function __construct(
        public ComposerMerger $composer = new ComposerMerger(),
        public ConfigMerger $config = new ConfigMerger(),
        public InstallationJournalWriter $journal = new InstallationJournalWriter(),
    ) {
    }
}
