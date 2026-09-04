<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;

/**
 * Prüft den API-Key aus Header oder Query-Parameter.
 */
final class ApiKeyAuth
{
    private const HEADER_NAME = 'X-API-Key';
    private const QUERY_PARAM = 'api_key';

    public function __construct(private readonly string $validKey)
    {
    }

    /**
     * Prüft, ob der Request einen gültigen API-Key enthält.
     *
     * @throws ApiException bei fehlendem oder ungültigem Key
     */
    public function authenticate(): void
    {
        if ($this->validKey === '') {
            // Kein Key konfiguriert → kein Schutz aktiv (Development-Modus)
            return;
        }

        $providedKey = $this->extractKey();

        if ($providedKey === null) {
            throw new ApiException(
                'unauthorized',
                'API-Key fehlt. Bitte als Header "X-API-Key" oder Query-Parameter "api_key" mitgeben.',
                401
            );
        }

        if (!hash_equals($this->validKey, $providedKey)) {
            throw new ApiException(
                'forbidden',
                'Ungültiger API-Key.',
                403
            );
        }
    }

    /**
     * Extrahiert den API-Key aus Header oder Query-Parameter.
     */
    private function extractKey(): ?string
    {
        // 1. Header: X-API-Key
        $headers = $this->getAllHeaders();
        if (isset($headers[self::HEADER_NAME])) {
            return $headers[self::HEADER_NAME];
        }

        // 2. Query-Parameter: ?api_key=...
        if (isset($_GET[self::QUERY_PARAM])) {
            return (string) $_GET[self::QUERY_PARAM];
        }

        // 3. POST-Parameter (für Form-Uploads)
        if (isset($_POST[self::QUERY_PARAM])) {
            return (string) $_POST[self::QUERY_PARAM];
        }

        return null;
    }

    /**
     * Liefert alle HTTP-Header (kompatibel mit verschiedenen Server-Umgebungen).
     *
     * @return array<string, string>
     */
    private function getAllHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        // Fallback: Manuelles Parsing aus $_SERVER
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                // HTTP_X_API_KEY → X-API-KEY → X-Api-Key
                $header = str_replace('_', '-', substr($key, 5));
                $header = ucwords(strtolower($header), '-');
                $headers[$header] = (string) $value;
            }
        }
        return $headers;
    }
}
