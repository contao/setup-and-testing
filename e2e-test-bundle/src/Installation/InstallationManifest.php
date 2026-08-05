<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\Installation;

use Symfony\Component\Filesystem\Filesystem;

final readonly class InstallationManifest
{
    /**
     * @param list<string> $mappedTargets
     */
    public function __construct(
        public string $dependency,
        public string|null $application = null,
        public array $mappedTargets = [],
    ) {
    }

    public static function read(string $path): self|null
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !\is_string($data['dependency'] ?? null)) {
            return null;
        }

        return new self($data['dependency'], $data['application'] ?? null, $data['mappedTargets'] ?? []);
    }

    public function write(string $path): void
    {
        (new Filesystem())->dumpFile($path, json_encode([
            'dependency' => $this->dependency,
            'application' => $this->application,
            'mappedTargets' => $this->mappedTargets,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
