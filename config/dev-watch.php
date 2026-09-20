<?php

declare(strict_types=1);

return [
    'state_key' => 'dev-watch',
    'max_posts_per_run' => 8,
    'projects' => [
        ['name' => 'Laravel Framework', 'repo' => 'laravel/framework'],
        ['name' => 'Livewire', 'repo' => 'livewire/livewire'],
        ['name' => 'FrankenPHP', 'repo' => 'dunglas/frankenphp'],
        ['name' => 'Laravel Octane', 'repo' => 'laravel/octane'],
        ['name' => 'Laravel Horizon', 'repo' => 'laravel/horizon'],
        ['name' => 'PHP', 'repo' => 'php/php-src'],
        ['name' => 'Docker Compose', 'repo' => 'docker/compose'],
        ['name' => 'Redis', 'repo' => 'redis/redis'],
        ['name' => 'MySQL Server', 'repo' => 'mysql/mysql-server'],
        ['name' => 'Pest', 'repo' => 'pestphp/pest'],
    ],
];
