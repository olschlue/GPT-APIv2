<?php

declare(strict_types=1);

namespace App\Upload;

/**
 * Reine Validierungsregeln für Audio-Uploads (ohne Seiteneffekte, testbar).
 */
final class UploadRules
{
    /** Erlaubte Erweiterungen → MIME-Typ (für den OpenAI-Upload). */
    public const ALLOWED = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'webm' => 'audio/webm',
    ];

    public static function extensionOf(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public static function isAllowedExtension(string $filename): bool
    {
        return isset(self::ALLOWED[self::extensionOf($filename)]);
    }

    public static function mimeFor(string $filename): string
    {
        return self::ALLOWED[self::extensionOf($filename)] ?? 'application/octet-stream';
    }

    public static function maxBytes(int $maxMb): int
    {
        return $maxMb * 1024 * 1024;
    }

    public static function isSizeAllowed(int $bytes, int $maxMb): bool
    {
        return $bytes > 0 && $bytes <= self::maxBytes($maxMb);
    }
}
