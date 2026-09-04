<?php

declare(strict_types=1);

use App\Config;
use App\Controller\HealthController;
use App\Controller\TranscribeController;
use App\Controller\WebController;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Router;

require dirname(__DIR__) . '/bootstrap.php';

$config = Config::load(APP_ROOT);
$request = new Request();

$router = new Router();
$router->add('GET', '/', new WebController($config));
$router->add('GET', '/api/health', new HealthController($config));
$router->add('POST', '/api/transcribe', new TranscribeController($config, $request));

try {
    $router->dispatch($request->method(), $request->path());
} catch (ApiException $e) {
    Response::error($e->errorCode(), $e->getMessage(), $e->httpStatus());
} catch (Throwable $e) {
    Response::error('internal_error', 'Interner Fehler: ' . $e->getMessage(), 500);
}
