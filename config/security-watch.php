<?php

declare(strict_types=1);

return [
    'state_key' => 'security-watch',
    'max_posts_per_run' => 8,
    'queries' => [
        [
            'ecosystem' => 'composer',
            'packages' => [
                'laravel/framework',
                'livewire/livewire',
                'symfony/http-foundation',
                'symfony/http-kernel',
                'symfony/process',
                'guzzlehttp/guzzle',
                'monolog/monolog',
                'nesbot/carbon',
                'phpunit/phpunit',
                'pestphp/pest',
            ],
        ],
        [
            'ecosystem' => 'npm',
            'packages' => [
                'vite',
                'axios',
                'laravel-vite-plugin',
                'tailwindcss',
            ],
        ],
        [
            'ecosystem' => 'actions',
            'packages' => [
                'actions/checkout',
                'shivammathur/setup-php',
            ],
        ],
    ],
];
