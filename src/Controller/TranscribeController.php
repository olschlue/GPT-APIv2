<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\TranscriptAnalyzer;
use App\Audio\AudioChunker;
use App\Audio\ChunkedTranscriber;
use App\Config;
use App\Database\Database;
use App\Database\MeetingRepository;
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
            // Nur temporäre Chunk-Dateien löschen, Original behalten
            // (Chunks werden bereits in ChunkedTranscriber::transcribe() gelöscht)
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

        // Debug-Infos immer sammeln
        $mysqliLoaded = extension_loaded('mysqli');
        $dbConfig = require APP_ROOT . '/config/database.php';
        $response['_meta']['db_debug'] = [
            'mysqli_extension' => $mysqliLoaded ? 'geladen' : 'NICHT GELADEN',
            'db_host' => $dbConfig['host'],
            'db_name' => $dbConfig['name'],
            'db_user' => $dbConfig['user'],
            'db_pass_set' => $dbConfig['pass'] !== '' ? 'ja' : 'NEIN',
        ];

        // In Datenbank speichern (wenn verfügbar)
        if (Database::isAvailable()) {
            try {
                $repository = new MeetingRepository();
                $meetingId = $this->saveMeeting($repository, $response, $upload, $result);
                $response['_meta']['meeting_id'] = $meetingId;
                $response['_meta']['saved_to_db'] = true;
            } catch (\Throwable $e) {
                // DB-Fehler nicht fatal machen, aber in Response vermerken
                $response['_meta']['saved_to_db'] = false;
                $response['_meta']['db_error'] = $e->getMessage();
                $response['_meta']['db_trace'] = $e->getTraceAsString();
            }
        } else {
            $response['_meta']['saved_to_db'] = false;
            $response['_meta']['db_note'] = 'Datenbank nicht verfügbar';
        }

        // Original-Datei-Pfad in Response aufnehmen
        $response['_meta']['file_path'] = $upload['path'];

        Response::json($response);
    }

    /**
     * Speichert das Meeting in der Datenbank.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $result
     */
    private function saveMeeting(MeetingRepository $repository, array $response, array $upload, array $result): int
    {
        // krisp_meeting_id = aktuelle Zeit in Millisekunden
        $krispMeetingId = (string) (int) (microtime(true) * 1000);

        // Titel = Original-Dateiname (ohne Pfad, mit Extension)
        $title = $upload['original_name'] ?? 'Unbekannt';

        // Start-Zeit aus Dateiname parsen (Format: 2026Sep03-113010-Rec46.mp3)
        $startedAt = $this->parseStartTimeFromFilename($title);
        if ($startedAt === null) {
            // Fallback: jetzt minus Dauer
            $startedAt = date('Y-m-d H:i:s', time() - (int) $result['duration']);
        }

        // Kunde aus Transkript versuchen zu extrahieren (einfache Heuristik)
        $customer = $this->extractCustomer($response['transcript']);

        $now = date('Y-m-d H:i:s');
        $endedAt = date('Y-m-d H:i:s', strtotime($startedAt) + (int) $result['duration']);

        // Recording URL: relativer Pfad zur Datei in uploads
        $recordingUrl = null;
        if (isset($upload['path']) && str_contains($upload['path'], 'storage/uploads/')) {
            // Extrahiere relativen Pfad ab storage/uploads/
            $relativePath = substr($upload['path'], strpos($upload['path'], 'storage/uploads/'));
            // Baue URL: /gptapi/storage/uploads/datei.mp3
            $basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '', 2); // von public/index.php zu /gptapi
            $recordingUrl = rtrim($basePath, '/') . '/' . $relativePath;
        }

        // Recording als JSON-Objekt (wie Krisp-API-Format)
        $recordingData = [
            'id' => $krispMeetingId,
            'size' => $upload['size'] ?? null,
            'type' => 'audio',
            'duration' => round($result['duration'], 3),
            'mime_type' => $upload['mime'] ?? 'audio/mpeg',
            'filename' => $upload['original_name'] ?? null,
            'recording_url' => $recordingUrl,
            'uploaded_at' => date('c'), // ISO 8601
        ];

        $data = [
            'krisp_meeting_id' => $krispMeetingId,
            'title' => $title,
            'customer' => $customer,
            'meeting_url' => null,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => (int) round($result['duration']),
            'transcript' => $response['transcript'],
            'transcript_updated_at' => $now,
            'summary' => $response['summary'],
            'notes' => null,
            'outline' => json_encode($response['outline'], JSON_UNESCAPED_UNICODE),
            'action_items' => json_encode($response['tasks'], JSON_UNESCAPED_UNICODE),
            'key_points' => json_encode($response['decisions'], JSON_UNESCAPED_UNICODE),
            'recording' => json_encode($recordingData, JSON_UNESCAPED_UNICODE), // JSON-Objekt
            'recording_url' => $recordingUrl, // String-URL
            'summary_updated_at' => $now,
            'last_event_type' => 'transcription.completed',
            'last_event_id' => null,
            'raw_payload' => json_encode($response, JSON_UNESCAPED_UNICODE),
        ];

        return $repository->create($data);
    }

    /**
     * Parst den Zeitstempel aus dem Dateinamen.
     * Format: 2026Sep03-113010-Rec46.mp3 → 2026-09-03 11:30:10
     *
     * @return string|null MySQL DATETIME oder null bei Fehler
     */
    private function parseStartTimeFromFilename(string $filename): ?string
    {
        // Pattern: YYYYMmmDD-HHMMSS (z. B. 2026Sep03-113010)
        if (preg_match('/(\d{4})([A-Za-z]{3})(\d{2})-(\d{6})/', $filename, $matches)) {
            [, $year, $monthName, $day, $time] = $matches;

            // Monatsname zu Nummer konvertieren
            $months = [
                'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
                'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
                'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
            ];

            $month = $months[$monthName] ?? null;
            if ($month === null) {
                return null;
            }

            // Zeit formatieren: HHMMSS → HH:MM:SS
            $hour = substr($time, 0, 2);
            $minute = substr($time, 2, 2);
            $second = substr($time, 4, 2);

            return sprintf('%s-%s-%s %s:%s:%s', $year, $month, $day, $hour, $minute, $second);
        }

        return null;
    }

    /**
     * Versucht, einen Kundennamen aus dem Transkript zu extrahieren.
     */
    private function extractCustomer(string $transcript): ?string
    {
        // Einfache Heuristik: Suche nach "Kunde", "Kunden", "Projekt", "Firma" etc.
        if (preg_match('/(?:Kunde|Kunden|Projekt|Firma|Unternehmen|bei|mit)\s+([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+)?)/u', $transcript, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
