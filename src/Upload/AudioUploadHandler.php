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
            throw new ApiException('storage_unavailable', 'Upload-Verzeichnis ist nicht verfügbar.', 500);
        }

        $extension = UploadRules::extensionOf($originalName);
        $target = rtrim($this->uploadDir, '/')
            . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        $tmpName = (string) $file['tmp_name'];
        // rename() als Fallback, damit der Handler auch ohne echten HTTP-Upload testbar bleibt.
        if (!move_uploaded_file($tmpName, $target) && !rename($tmpName, $target)) {
            throw new ApiException('storage_failed', 'Die Datei konnte nicht gespeichert werden.', 500);
        }

        return [
            'path' => $target,
            'original_name' => $originalName,
            'mime' => UploadRules::mimeFor($originalName),
            'size' => $size,
        ];
    }
}
