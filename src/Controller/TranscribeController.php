<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\TranscriptAnalyzer;
use App\Audio\AudioChunker;
use App\Audio\ChunkedTranscriber;
use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\OpenAI\OpenAIClient;
use App\Upload\AudioUploadHandler;
use App\Upload\UploadRules;

/**
 * POST /api/transcribe
 * Multipart-Feld "file" → validieren → temporär speichern → transkribieren
 * → analysieren → temporäre Datei löschen → strukturiertes JSON liefern.
 */
final class TranscribeController
{
    public function __construct(
        private readonly Config $config,
        private readonly Request $request,
    ) {
    }

    public function __invoke(): void
    {
        $maxMb = $this->config->getInt('MAX_UPLOAD_MB', 200);

        // Früher Größen-Check anhand von Content-Length (1 MB Puffer für Multipart-Overhead),
        // damit übergroße Requests gar nicht erst komplett eingelesen werden.
        $contentLength = $this->request->contentLength();
        if ($contentLength > UploadRules::maxBytes($maxMb) + 1024 * 1024) {
            throw new ApiException('file_too_large', "Die Datei überschreitet das Limit von {$maxMb} MB.", 413);
        }

        $file = $this->request->file('file');
        if ($file === null) {
            if ($this->request->bodyDroppedByPhp()) {
                throw new ApiException(
                    'upload_blocked_by_php',
                    sprintf(
                        'PHP hat den Upload verworfen, weil er das php.ini-Limit übersteigt (post_max_size: %s, upload_max_filesize: %s). Werte in der php.ini erhöhen und Apache neu starten.',
                        (string) ini_get('post_max_size'),
                        (string) ini_get('upload_max_filesize'),
                    ),
                    413
                );
            }
            throw new ApiException('file_missing', 'Multipart-Formdata-Feld "file" fehlt.', 400);
        }

        $handler = new AudioUploadHandler($this->config->getString('UPLOAD_DIR'), $maxMb);
        $upload = $handler->store($file);

        try {
            $client = new OpenAIClient(
                apiKey: $this->config->getString('OPENAI_API_KEY'),
                baseUrl: $this->config->getString('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                transcribeModel: $this->config->getString('OPENAI_TRANSCRIBE_MODEL', 'gpt-4o-transcribe'),
                analysisModel: $this->config->getString('OPENAI_ANALYSIS_MODEL', 'gpt-5-mini'),
                timeoutTranscribe: $this->config->getInt('HTTP_TIMEOUT_TRANSCRIBE', 600),
                timeoutAnalysis: $this->config->getInt('HTTP_TIMEOUT_ANALYSIS', 300),
            );

            // Chunking für lange Dateien (über 20 Minuten)
            $chunkTempDir = $this->config->getString('UPLOAD_DIR') . '/chunks';
            $chunker = new AudioChunker($chunkTempDir);
            $transcriber = new ChunkedTranscriber($client, $chunker);
            $result = $transcriber->transcribe($upload['path'], $upload['mime']);

            $transcript = $result['transcript'];
            $analysis = (new TranscriptAnalyzer($client))->analyze($transcript);
        } finally {
            // Temporäre Datei in jedem Fall wieder entfernen
            if (is_file($upload['path'])) {
                unlink($upload['path']);
            }
        }

        $response = [
            'transcript' => $transcript,
            'summary' => $analysis->summary,
            'outline' => $analysis->outline,
            'tasks' => $analysis->tasks,
            'decisions' => $analysis->decisions,
        ];

        // Metadaten hinzufügen
        $response['_meta'] = [
            'duration_seconds' => round($result['duration'], 1),
            'chunks_used' => $result['chunks_used'],
        ];

        // MIME-Warnung hinzufügen, wenn Extension und Inhalt abweichen
        if (!empty($upload['mime_warning'])) {
            $response['_warning'] = $upload['mime_warning'];
        }

        Response::json($response);
    }
}
