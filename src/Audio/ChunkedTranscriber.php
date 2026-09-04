<?php

declare(strict_types=1);

namespace App\Audio;

use App\Http\ApiException;
use App\OpenAI\OpenAIClient;

/**
 * Transkribiert lange Audio-Dateien, indem sie in Chunks zerlegt,
 * einzeln transkribiert und zusammengeführt werden.
 */
final class ChunkedTranscriber
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly AudioChunker $chunker,
    ) {
    }

    /**
     * Transkribiert eine Audio-Datei, ggf. mit Chunking.
     *
     * @return array{transcript: string, chunks_used: int, duration: float}
     * @throws ApiException
     */
    public function transcribe(string $filePath, string $mimeType): array
    {
        $duration = $this->chunker->getDuration($filePath);
        $chunks = $this->chunker->chunk($filePath, $mimeType);

        if (count($chunks) === 1) {
            // Kein Chunking nötig
            $transcript = $this->client->transcribe($filePath, $mimeType);
            return [
                'transcript' => $transcript,
                'chunks_used' => 1,
                'duration' => $duration,
            ];
        }

        // Chunking: einzeln transkribieren und zusammenführen
        $transcripts = [];
        try {
            foreach ($chunks as $index => $chunk) {
                $chunkTranscript = $this->client->transcribe($chunk['path'], $mimeType);
                $transcripts[] = trim($chunkTranscript);
            }
        } finally {
            // Chunks immer aufräumen
            $this->chunker->cleanup($chunks, $filePath);
        }

        // Zusammenführen mit Zeilenwechsel zwischen Chunks
        $fullTranscript = implode("\n\n", $transcripts);

        return [
            'transcript' => $fullTranscript,
            'chunks_used' => count($chunks),
            'duration' => $duration,
        ];
    }
}
