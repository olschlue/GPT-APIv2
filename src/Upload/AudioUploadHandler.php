<?php

declare(strict_types=1);

namespace App\Upload;

use App\Http\ApiException;

/**
 * Validiert eingehende Audio-Uploads und speichert sie temporär in storage/uploads.
 */
final class AudioUploadHandler
{
    public function __construct(
        private readonly string $uploadDir,
        private readonly int $maxMb,
    ) {
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file Eintrag aus $_FILES
     * @return array{path: string, original_name: string, mime: string, size: int}
     * @throws ApiException
     */
    public function store(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ApiException('file_too_large', "Die Datei überschreitet das Limit von {$this->maxMb} MB.", 413);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ApiException('upload_failed', "Upload fehlgeschlagen (Fehlercode {$error}).", 400);
        }

        $originalName = (string) ($file['name'] ?? '');
        if (!UploadRules::isAllowedExtension($originalName)) {
            $allowed = implode(', ', array_map('strtoupper', array_keys(UploadRules::ALLOWED)));
            throw new ApiException('unsupported_media_type', "Nicht unterstütztes Format. Erlaubt: {$allowed}.", 415);
        }

        $size = (int) ($file['size'] ?? 0);
        if (!UploadRules::isSizeAllowed($size, $this->maxMb)) {
            throw new ApiException('file_too_large', "Die Datei überschreitet das Limit von {$this->maxMb} MB.", 413);
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            throw new ApiException('storage_unavailable', "Upload-Verzeichnis kann nicht angelegt werden: {$this->uploadDir}", 500);
        }
        if (!is_writable($this->uploadDir)) {
            throw new ApiException('storage_unavailable', "Upload-Verzeichnis ist nicht beschreibbar: {$this->uploadDir}", 500);
        }

        $extension = UploadRules::extensionOf($originalName);
        $target = rtrim($this->uploadDir, '/')
            . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        $tmpName = (string) $file['tmp_name'];
        // Kaskade: move_uploaded_file (echter HTTP-Upload) → rename (Tests) → copy
        if (!move_uploaded_file($tmpName, $target) && !rename($tmpName, $target) && !copy($tmpName, $target)) {
            throw new ApiException(
                'storage_failed',
                'Datei konnte nicht gespeichert werden. tmp: ' . $tmpName
                    . (is_file($tmpName) ? ' (existiert)' : ' (existiert NICHT)')
                    . ' → Ziel: ' . $target
                    . ' | Verzeichnis beschreibbar: ' . (is_writable($this->uploadDir) ? 'ja' : 'nein'),
                500
            );
        }
        // bei copy() bleibt die tmp-Datei liegen – wegräumen
        if (is_file($tmpName) && $tmpName !== $target && !is_uploaded_file($tmpName)) {
            unlink($tmpName);
        }

        // MIME-Type aus dem Dateiinhalt ermitteln (nicht nur Extension)
        $detectedMime = $this->detectMimeType($target);
        $extensionMime = UploadRules::mimeFor($originalName);
        $finalMime = $extensionMime;
        $mimeWarning = null;

        // Bei Abweichung: erkannten Typ bevorzugen, wenn er plausibel ist
        if ($detectedMime !== null && $detectedMime !== $extensionMime) {
            $plausible = match ($detectedMime) {
                'audio/mpeg', 'audio/mp3' => 'audio/mpeg',
                'audio/mp4', 'video/mp4' => 'audio/mp4', // M4A wird oft als video/mp4 erkannt
                'audio/x-wav', 'audio/wav', 'audio/wave' => 'audio/wav',
                'audio/webm', 'video/webm' => 'audio/webm',
                default => null,
            };
            if ($plausible !== null && $plausible !== $extensionMime) {
                $finalMime = $plausible;
                $mimeWarning = sprintf(
                    'Extension sagt %s, aber Datei ist %s – verwende %s',
                    $extensionMime,
                    $detectedMime,
                    $finalMime
                );
            }
        }

        return [
            'path' => $target,
            'original_name' => $originalName,
            'mime' => $finalMime,
            'size' => $size,
            'mime_detected' => $detectedMime,
            'mime_warning' => $mimeWarning,
        ];
    }

    /**
     * Ermittelt den MIME-Type aus dem Dateiinhalt (nicht der Extension).
     */
    private function detectMimeType(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) ? $mime : null;
    }
}
