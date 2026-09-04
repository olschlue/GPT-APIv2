<?php

declare(strict_types=1);

define('APP_ROOT', __DIR__);

// PSR-4 Autoloader für App\ — kein Composer erforderlich.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
