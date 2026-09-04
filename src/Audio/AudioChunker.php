<?php

declare(strict_types=1);

namespace App\Audio;

use App\Http\ApiException;

/**
 * Zerlegt lange Audio-Dateien in zeitbasierte Chunks via FFmpeg.
 * Nutzt 20-Minuten-Blöcke (unter dem 1400s-Limit von gpt-4o-transcribe).
 */
final class AudioChunker
{
    /** Max. Dauer pro Chunk in Sekunden (unter 1400s-Limit) */
    private const CHUNK_DURATION = 1200; // 20 Minuten

    /** Überlappung zwischen Chunks in Sekunden (für fließende Übergänge) */
    private const CHUNK_OVERLAP = 5;

    public function __construct(private readonly string $tempDir)
    {
    }

    /**
     * Prüft, ob FFmpeg verfügbar ist.
     */
    public static function isAvailable(): bool
    {
        $output = [];
        $code = 0;
        exec('which ffmpeg 2>/dev/null', $output, $code);
        return $code === 0 && $output !== [];
    }

    /**
     * Ermittelt die Dauer einer Audio-Datei in Sekunden.
     *
     * @throws ApiException
     */
    public function getDuration(string $filePath): float
    {
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($filePath)
        );
        $output = shell_exec($cmd);
        if ($output === null) {
            throw new ApiException('audio_analysis_failed', 'Konnte Audio-Dauer nicht ermitteln (ffprobe fehlt?)', 500);
        }
        $duration = (float) trim($output);
        if ($duration <= 0) {
            throw new ApiException('audio_invalid', 'Audio-Datei hat keine gültige Dauer.', 400);
        }
        return $duration;
    }

    /**
     * Zerlegt eine Audio-Datei in Chunks.
     *
     * @return array<int, array{path: string, start: float, duration: float}> Liste der Chunk-Dateien
     * @throws ApiException
     */
    public function chunk(string $inputPath, string $mimeType): array
    {
        if (!self::isAvailable()) {
            throw new ApiException('ffmpeg_missing', 'FFmpeg ist nicht installiert (erforderlich für lange Dateien)', 500);
        }

        $duration = $this->getDuration($inputPath);
        if ($duration <= self::CHUNK_DURATION) {
            // Kein Chunking nötig
            return [['path' => $inputPath, 'start' => 0.0, 'duration' => $duration]];
        }

        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0775, true) && !is_dir($this->tempDir)) {
            throw new ApiException('storage_unavailable', 'Chunk-Verzeichnis kann nicht angelegt werden.', 500);
        }

        $extension = pathinfo($inputPath, PATHINFO_EXTENSION);
        $chunks = [];
        $chunkIndex = 0;
        $start = 0.0;

        while ($start < $duration) {
            $chunkDuration = min(self::CHUNK_DURATION + self::CHUNK_OVERLAP, $duration - $start);
            $chunkPath = sprintf('%s/chunk_%03d.%s', $this->tempDir, $chunkIndex, $extension);

            $cmd = sprintf(
                'ffmpeg -y -i %s -ss %s -t %s -c copy %s 2>&1',
                escapeshellarg($inputPath),
                escapeshellarg((string) $start),
                escapeshellarg((string) $chunkDuration),
                escapeshellarg($chunkPath)
            );

            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            if ($code !== 0 || !is_file($chunkPath) || filesize($chunkPath) === 0) {
                // Cleanup bei Fehler
                foreach ($chunks as $chunk) {
                    if (is_file($chunk['path']) && $chunk['path'] !== $inputPath) {
                        unlink($chunk['path']);
                    }
                }
                throw new ApiException(
                    'chunking_failed',
                    'FFmpeg-Chunking fehlgeschlagen: ' . implode("\n", array_slice($output, -5)),
                    500
                );
            }

            $chunks[] = [
                'path' => $chunkPath,
                'start' => $start,
                'duration' => $chunkDuration,
            ];

            $start += self::CHUNK_DURATION;
            $chunkIndex++;
        }

        return $chunks;
    }

    /**
     * Löscht alle Chunk-Dateien (außer der Original-Datei).
     *
     * @param array<int, array{path: string}> $chunks
     */
    public function cleanup(array $chunks, string $originalPath): void
    {
        foreach ($chunks as $chunk) {
            if (is_file($chunk['path']) && $chunk['path'] !== $originalPath) {
                unlink($chunk['path']);
            }
        }
    }
}
