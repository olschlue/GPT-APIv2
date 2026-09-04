<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\ApiKeyAuth;
use App\Config;
use App\Http\Response;

final class HealthController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function __invoke(): void
    {
        // API-Key-Authentifizierung (optional, nur wenn konfiguriert)
        $authConfig = require APP_ROOT . '/config/auth.php';
        $auth = new ApiKeyAuth($authConfig['api_key']);
        try {
            $auth->authenticate();
        } catch (\App\Http\ApiException $e) {
            // Health-Check auch ohne Key erlauben, aber Status anzeigen
            Response::json([
                'status' => 'ok',
                'service' => 'GPT-APIv2',
                'time' => gmdate('c'),
                'auth_required' => true,
                'auth_hint' => $e->getMessage(),
            ]);
            return;
        }

        Response::json([
            'status' => 'ok',
            'service' => 'GPT-APIv2',
            'time' => gmdate('c'),
            'config' => [
                'openai_key_configured' => $this->config->getString('OPENAI_API_KEY') !== '',
                'transcribe_model' => $this->config->getString('OPENAI_TRANSCRIBE_MODEL'),
                'analysis_model' => $this->config->getString('OPENAI_ANALYSIS_MODEL'),
                'max_upload_mb' => $this->config->getInt('MAX_UPLOAD_MB', 200),
                'php_limits' => [
                    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                    'post_max_size' => (string) ini_get('post_max_size'),
                    'loaded_ini' => php_ini_loaded_file() ?: 'keine',
                    'scanned_ini' => php_ini_scanned_files() ?: 'keine',
                ],
            ],
        ]);
    }
}
