<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Dünner Wrapper um die Superglobals des aktuellen HTTP-Requests.
 */
final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Liefert den App-relativen Pfad, z. B. "/api/transcribe".
     * Funktioniert auch, wenn die App in einem Unterverzeichnis liegt
     * (z. B. https://host/gptapi/public/index.php oder .../api/health).
     */
    public function path(): string
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptName !== '' && str_starts_with($path, $scriptName)) {
            // Aufruf über die index.php selbst, ggf. mit PATH_INFO:
            // /base/public/index.php oder /base/public/index.php/api/...
            $path = substr($path, strlen($scriptName));
        } else {
            $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            if ($base !== '' && $base !== '.' && $base !== '/') {
                if ($path === $base) {
                    $path = '/';
                } elseif (str_starts_with($path, $base . '/')) {
                    $path = substr($path, strlen($base));
                }
            }
        }

        $path = rtrim($path, '/');

        // Fallback: irgendwo im Pfad steckt noch ein Skriptname (*.php) –
        // z. B. /gptapi/public/index.php/api/health bei exotischen Rewrite-Setups.
        if (preg_match('~\.php(?=/|$)~i', $path)) {
            $path = (string) preg_replace('~^.*?\.php~i', '', $path);
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public function contentLength(): int
    {
        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    }

    /**
     * Erkennt, ob PHP den Request-Body komplett verworfen hat, weil
     * post_max_size überschritten wurde (dann sind $_POST und $_FILES leer,
     * obwohl der Client Daten geschickt hat – typisch bei XAMPP-Defaults).
     */
    public function bodyDroppedByPhp(): bool
    {
        return $this->contentLength() > 0 && $_FILES === [] && $_POST === [];
    }
}
