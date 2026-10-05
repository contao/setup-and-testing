# Portable recipe archives

A recipe can be distributed as a ZIP file with this layout:

```text
example-theme.zip
├── recipe.yaml
├── composer.json
├── config/
│   └── theme.yaml
├── fixtures/
│   └── pages.yaml
└── files/
    └── files/example-theme/theme.css
```

`recipe.yaml` is a versioned manifest. All paths are relative to the archive root:

```yaml
format: 1
name: acme/example-theme
composer: composer.json
config:
    - config/theme.yaml
fixtures:
    - fixtures/pages.yaml
files:
    - source: files/files/example-theme
      target: files/example-theme
      overwrite: false
```

The optional `composer.json` is deliberately a fragment rather than a complete Composer project. It may only contain `require` and `require-dev`, preventing a recipe from replacing project metadata or injecting Composer scripts:

```json
{
    "require": {
        "acme/theme-bundle": "^1.0",
        "contao/news-bundle": "^5.3 || ^5.7 || ^6.0"
    }
}
```

## Package and install

Follow [Build a reusable recipe](../guides/build-recipe.md#package-a-portable-recipe) for an example source tree and packaging commands on Unix and Windows. Keep `recipe.yaml` at the ZIP root rather than adding an enclosing directory.

Follow [Apply a recipe](../guides/apply-recipe.md) to install it. Archive validation rejects absolute paths, path traversal, symbolic links, oversized archives, unknown manifest keys and missing files before installation.

## Trust and file permissions

File mappings can install PHP application files such as `contao/dca/tl_content.php`, `config/services.php` and templates. The importer copies these files, and the application loads them as part of its normal operation. Install recipes from publishers you trust, since installed code runs with the application's permissions.

The default host policy protects `composer.json`, `composer.lock`, the merged `config/config.yaml`, `vendor/`, Git metadata and installation journals. The host can replace that protected list, for example to protect only `vendor/` in a Managed Edition. Dependencies and merged configuration use the dedicated manifest sections. Other application destinations are allowed by default and can be restricted further by the host.

Destination symlinks are allowed when their resolved targets stay inside the installation and satisfy the host policy. Overwrites require both the manifest flag and explicit host permission.

The installation plan includes file contents, requested and resolved destinations, overwrite flags, dependencies, configuration and fixtures. Text files are shown as UTF-8 and binary files are represented as base64. Review these changes before applying the plan, and prevent other processes from modifying the installation directory during review and installation.
