# Test-specific DCA

Use `withDcaFile()` to add a PHP DCA file from the test suite to the Managed Edition. The file is copied to the project's `contao/dca/` directory before Contao setup and database migration. Its basename determines the DCA file name, so a source named `tl_content.php` configures `tl_content`:

```php
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;

$config = ManagedEditionConfig::create($recipe, dirname(__DIR__))
    ->withDcaFile(__DIR__.'/dca/tl_content.php');
```

Import `ManagedEditionConfig` at the top of your test file and add `withDcaFile()` when creating its configuration. Adjust the paths for your test directory. Create the DCA file at the supplied path. It can define a field for a widget supplied by the package under test, including `eval` options that no core field uses:

```php
<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

$GLOBALS['TL_DCA']['tl_content']['fields']['widget_test'] = [
    'inputType' => 'myWidget',
    'eval' => ['myOption' => 'variant-a'],
    'sql' => ['type' => 'string', 'default' => ''],
];

PaletteManipulator::create()
    ->addField('widget_test', 'text')
    ->applyToPalette('text', 'tl_content');
```

Use another field or a different DCA file for another `eval` variant. DCA file changes invalidate the cached application setup, so the next test run installs the updated definition. The method returns a new configuration and leaves the original recipe unchanged.
