<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\Cache;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

final class SourceFingerprint implements SourceFingerprintInterface
{
    public function calculate(string $path): string
    {
        $root = $this->runGit($path, ['rev-parse', '--show-toplevel']);

        if (null === $root) {
            return $this->hashPath($path);
        }

        $relativePath = Path::makeRelative(Path::canonicalize($path), Path::canonicalize($root));
        $relativePath = '' === $relativePath ? '.' : $relativePath;

        $treeish = '.' === $relativePath ? 'HEAD' : 'HEAD:'.$relativePath;
        $head = $this->runGit($root, ['rev-parse', $treeish]);
        $diff = $this->runGit($root, ['diff', '--binary', 'HEAD', '--', $relativePath]) ?? '';
        $untracked = $this->runGit($root, ['ls-files', '--others', '--exclude-standard', '-z', '--', $relativePath]) ?? '';

        if (null === $head && '' === $untracked) {
            return $this->hashPath($path);
        }

        $untrackedHash = $this->hashUntracked($root, $untracked);

        return hash('sha256', ($head ?? 'no-head')."\0".$diff."\0".$untrackedHash);
    }

    /**
     * @param list<string> $arguments
     */
    private function runGit(string $path, array $arguments): string|null
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->run();

        return $process->isSuccessful() ? rtrim($process->getOutput(), "\r\n") : null;
    }

    private function hashUntracked(string $root, string $files): string
    {
        $context = hash_init('sha256');

        foreach (array_filter(explode("\0", $files)) as $file) {
            hash_update($context, $file."\0");
            hash_update_file($context, $root.'/'.$file);
        }

        return hash_final($context);
    }

    private function hashPath(string $path): string
    {
        if (is_file($path)) {
            return hash_file('sha256', $path);
        }

        $context = hash_init('sha256');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        foreach ($files as $file) {
            hash_update($context, Path::makeRelative($file, $path));
            hash_update_file($context, $file);
        }

        return hash_final($context);
    }
}
