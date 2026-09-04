<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Http\Response;

final class HealthController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function __invoke(): void
    {
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
                ],
            ],
        ]);
    }
}
