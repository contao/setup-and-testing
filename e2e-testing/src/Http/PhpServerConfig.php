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

final class PhpServerConfig
{
    /**
     * @var array<string, bool|int|string>
     */
    private array $iniSettings = [];

    /**
     * @param array<string, bool|int|string> $settings
     */
    public function withIniSettings(array $settings): self
    {
        foreach ($settings as $name => $value) {
            if (!\is_string($name) || 1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D', $name)) {
                throw new \InvalidArgumentException('PHP INI setting names must be valid directive names.');
            }

            if ((!\is_bool($value) && !\is_int($value) && !\is_string($value)) || (\is_string($value) && str_contains($value, "\0"))) {
                throw new \InvalidArgumentException('PHP INI values must be booleans, integers or strings without null bytes.');
            }
        }

        $clone = clone $this;
        $clone->iniSettings = array_replace($this->iniSettings, $settings);

        return $clone;
    }

    public function withOpcache(bool $enabled = true): self
    {
        return $this->withIniSettings([
            'opcache.enable' => $enabled,
            'opcache.enable_cli' => $enabled,
            'opcache.validate_timestamps' => true,
            'opcache.revalidate_freq' => 0,
            'opcache.file_cache' => '',
            'opcache.file_cache_only' => false,
        ]);
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        $arguments = [];

        foreach ($this->iniSettings as $name => $value) {
            $arguments[] = '-d';
            $arguments[] = $name.'='.$this->encodeValue($value);
        }

        return $arguments;
    }

    private function encodeValue(bool|int|string $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }
}
