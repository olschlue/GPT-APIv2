<?php

declare(strict_types=1);

namespace App\OpenAI;

use App\Http\ApiException;
use CURLFile;

/**
 * Schlanker cURL-Client für die OpenAI API:
 *  - POST /audio/transcriptions (Multipart, Datei-Upload)
 *  - POST /chat/completions   (JSON-Mode für die Analyse)
 */
final class OpenAIClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly string $transcribeModel = 'gpt-4o-transcribe',
        private readonly string $analysisModel = 'gpt-5-mini',
        private readonly int $timeoutTranscribe = 600,
        private readonly int $timeoutAnalysis = 300,
    ) {
        if ($this->apiKey === '') {
            throw new ApiException('config_missing', 'OPENAI_API_KEY ist nicht gesetzt (siehe .env).', 500);
        }
    }

    /**
     * Transkribiert eine Audiodatei und liefert den reinen Text.
     *
     * @throws ApiException
     */
    public function transcribe(string $filePath, string $mimeType): string
    {
        // Diarize-Modelle benötigen chunking_strategy und verbose_json für Segmente
        $isDiarize = str_contains($this->transcribeModel, 'diarize');

        $postFields = [
            'model' => $this->transcribeModel,
            'response_format' => $isDiarize ? 'verbose_json' : 'json',
            'file' => new CURLFile($filePath, $mimeType, basename($filePath)),
        ];

        if ($isDiarize) {
            $postFields['chunking_strategy'] = 'auto';
        }

        $ch = $this->curl($this->baseUrl . '/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeoutTranscribe,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $postFields,
        ]);

        $body = $this->execute($ch, 'Transkription', [
            'Datei' => basename($filePath),
            'Größe' => round(filesize($filePath) / 1048576, 1) . ' MB',
            'MIME' => $mimeType,
            'Modell' => $this->transcribeModel,
            'Tipp' => 'Datei ggf. mit VLC/Audacity als MP3 neu exportieren',
        ]);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new ApiException('openai_invalid_response', 'Unerwartete Antwort des Transkriptions-Endpunkts.', 502);
        }

        // Bei Diarize-Modellen: Sprecher-Segmente ins Transkript einbauen
        if ($isDiarize) {
            if (isset($data['segments']) && is_array($data['segments']) && $data['segments'] !== []) {
                return $this->formatWithSpeakers($data['segments']);
            }
            // Fallback: Wenn keine Segmente, nutze text-Feld mit Hinweis
            if (isset($data['text']) && is_string($data['text'])) {
                return $data['text'] . "\n\n[Hinweis: Keine Sprecher-Segmente von der API erhalten]";
            }
        }

        if (!isset($data['text']) || !is_string($data['text'])) {
            throw new ApiException('openai_invalid_response', 'Kein Transkript in der API-Antwort.', 502);
        }

        return $data['text'];
    }

    /**
     * Formatiert Segmente mit Sprecher-Labels als lesbares Transkript.
     *
     * @param array<int, array<string, mixed>> $segments
     */
    private function formatWithSpeakers(array $segments): string
    {
        $lines = [];
        $lastSpeaker = null;

        foreach ($segments as $segment) {
            $speaker = $segment['speaker'] ?? 'Sprecher';
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            // Sprecherwechsel markieren
            if ($speaker !== $lastSpeaker) {
                $lines[] = '';
                $lines[] = "**{$speaker}:**";
                $lastSpeaker = $speaker;
            }

            $lines[] = $text;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Führt eine Chat-Completion im JSON-Modus aus und liefert den rohen JSON-String.
     * Hinweis: gpt-5-*-Modelle akzeptieren nur die Default-Temperatur → wird nicht gesendet.
     *
     * @throws ApiException
     */
    public function analyzeJson(string $systemPrompt, string $userPrompt): string
    {
        $payload = json_encode([
            'model' => $this->analysisModel,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = $this->curl($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeoutAnalysis,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $body = $this->execute($ch, 'Analyse');
        $data = json_decode($body, true);
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            throw new ApiException('openai_invalid_response', 'Unerwartete Antwort des Analyse-Modells.', 502);
        }

        return $content;
    }

    /** @return resource|\CurlHandle */
    private function curl(string $url)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('openai_unreachable', 'cURL konnte nicht initialisiert werden.', 502);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        return $ch;
    }

    /**
     * @param resource|\CurlHandle $ch
     * @param array<string, string> $context Zusätzliche Infos für Fehlermeldungen
     * @throws ApiException
     */
    private function execute($ch, string $operation, array $context = []): string
    {
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ApiException('openai_unreachable', "OpenAI API nicht erreichbar ({$operation}): {$error}", 502);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $snippet = mb_strimwidth((string) $body, 0, 500, '…');
            $details = '';
            if ($context !== []) {
                $pairs = [];
                foreach ($context as $key => $value) {
                    $pairs[] = "{$key}: {$value}";
                }
                $details = ' [' . implode(', ', $pairs) . ']';
            }
            throw new ApiException('openai_error', "OpenAI API ({$operation}) antwortete mit HTTP {$status}: {$snippet}{$details}", 502);
        }

        return (string) $body;
    }
}
