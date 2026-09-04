<?php

declare(strict_types=1);

namespace App;

/**
 * Lädt Konfiguration aus config/config.php, .env und Umgebungsvariablen.
 * Priorität: Umgebungsvariable > .env > Default aus config/config.php.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $root): self
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require $root . '/config/config.php';
        $envFile = self::parseEnvFile($root . '/.env');

        $values = $envFile;
        foreach ($defaults as $key => $default) {
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                $values[$key] = $env;
            } elseif (!array_key_exists($key, $values)) {
                $values[$key] = $default;
            }
        }

        $values['MAX_UPLOAD_MB'] = (int) ($values['MAX_UPLOAD_MB'] ?? 200);

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, string> */
    private static function parseEnvFile(string $path): array
    {
        $env = [];
        if (!is_file($path)) {
            return $env;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $env;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // umschließende Anführungszeichen entfernen
            if (strlen($value) >= 2 && ($value[0] === '"' && $value[-1] === '"' || $value[0] === "'" && $value[-1] === "'")) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                $env[$key] = $value;
            }
        }
        return $env;
    }
}
