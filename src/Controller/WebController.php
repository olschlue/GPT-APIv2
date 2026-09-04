<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Http\ApiException;
use App\Http\Response;

/**
 * GET / – liefert die Web-UI zum Testen der API (Upload-Formular + Ergebnisansicht).
 */
final class WebController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function __invoke(): void
    {
        $view = APP_ROOT . '/src/View/upload.php';
        if (!is_file($view)) {
            throw new ApiException('view_missing', 'Das UI-Template wurde nicht gefunden.', 500);
        }

        $maxUploadMb = $this->config->getInt('MAX_UPLOAD_MB', 200);

        ob_start();
        require $view;
        $html = (string) ob_get_clean();

        Response::html($html);
    }
}
