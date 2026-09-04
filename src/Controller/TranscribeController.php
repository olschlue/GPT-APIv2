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
            }
        } else {
            $response['_meta']['saved_to_db'] = false;
            $response['_meta']['db_note'] = 'Datenbank nicht konfiguriert oder nicht erreichbar';
        }

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
        // Eindeutige Meeting-ID generieren (oder aus Request übernehmen, falls vorhanden)
        $krispMeetingId = $_POST['meeting_id'] ?? 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        // Titel aus Summary ableiten (erste 100 Zeichen)
        $title = mb_strimwidth($response['summary'] ?? 'Meeting-Transkription', 0, 100, '…');

        // Kunde aus Transkript versuchen zu extrahieren (einfache Heuristik)
        $customer = $this->extractCustomer($response['transcript']);

        $now = date('Y-m-d H:i:s');
        $startedAt = date('Y-m-d H:i:s', time() - (int) $result['duration']);
        $endedAt = $now;

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
            'notes' => null, // könnte später aus Analyse erweitert werden
            'outline' => json_encode($response['outline'], JSON_UNESCAPED_UNICODE),
            'action_items' => json_encode($response['tasks'], JSON_UNESCAPED_UNICODE),
            'key_points' => json_encode($response['decisions'], JSON_UNESCAPED_UNICODE),
            'recording' => $upload['original_name'] ?? null,
            'recording_url' => null,
            'summary_updated_at' => $now,
            'last_event_type' => 'transcription.completed',
            'last_event_id' => null,
            'raw_payload' => json_encode($response, JSON_UNESCAPED_UNICODE),
        ];

        // Update falls Meeting bereits existiert, sonst Insert
        if ($repository->exists($krispMeetingId)) {
            $repository->update($data);
            // ID des bestehenden Datensatzes ermitteln
            $stmt = Database::getConnection()->prepare('SELECT id FROM krisp_meetings WHERE krisp_meeting_id = ?');
            $stmt->bind_param('s', $krispMeetingId);
            $stmt->execute();
            $stmt->bind_result($id);
            $stmt->fetch();
            $stmt->close();
            return (int) $id;
        }

        return $repository->create($data);
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
