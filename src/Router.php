<?php

declare(strict_types=1);

namespace App;

use App\Http\Response;

/**
 * Minimaler Router mit exakten Pfaden (keine Parameter nötig).
 */
final class Router
{
    /** @var array<string, array<string, callable>> method => path => handler */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        $handler = $this->routes[$method][$path] ?? null;
        if ($handler !== null) {
            $handler();
            return;
        }

        // Pfad existiert mit anderer Methode → 405
        foreach ($this->routes as $routes) {
            if (isset($routes[$path])) {
                Response::error('method_not_allowed', "Methode {$method} ist für {$path} nicht erlaubt.", 405);
                return;
            }
        }

        Response::error('not_found', "Endpunkt {$path} existiert nicht.", 404);
    }
}
