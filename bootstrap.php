<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

spl_autoload_register(static function (string $class): void {
    $prefix = 'DiscordAutomation\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
