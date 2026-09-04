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
        $ch = $this->curl($this->baseUrl . '/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeoutTranscribe,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => [
                'model' => $this->transcribeModel,
                'response_format' => 'json',
                'file' => new CURLFile($filePath, $mimeType, basename($filePath)),
            ],
        ]);

        $body = $this->execute($ch, 'Transkription');
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['text']) || !is_string($data['text'])) {
            throw new ApiException('openai_invalid_response', 'Unerwartete Antwort des Transkriptions-Endpunkts.', 502);
        }

        return $data['text'];
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
     * @throws ApiException
     */
    private function execute($ch, string $operation): string
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
            throw new ApiException('openai_error', "OpenAI API ({$operation}) antwortete mit HTTP {$status}: {$snippet}", 502);
        }

        return (string) $body;
    }
}
