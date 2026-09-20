<?php

declare(strict_types=1);

return [
    'free-games' => [
        'label' => 'Jogos Grátis',
        'webhook_env' => 'WEBHOOK_FREE_GAMES',
        'enabled' => true,
        'source' => 'GamerPower',
        'workflow' => '.github/workflows/free-games.yml',
    ],

    'dev-watch' => [
        'label' => 'Dev Watch',
        'webhook_env' => 'WEBHOOK_DEV',
        'enabled' => true,
        'source' => 'GitHub Tags API',
        'workflow' => '.github/workflows/dev-watch.yml',
    ],

    'riot-patches' => [
        'label' => 'LoL / TFT Patch Watch',
        'webhook_env' => 'WEBHOOK_RIOT',
        'enabled' => true,
        'source' => 'Riot Games',
        'workflow' => '.github/workflows/riot-patches.yml',
    ],

    'fortnite' => [
        'label' => 'Fortnite Updates',
        'webhook_env' => 'WEBHOOK_FORTNITE',
        'enabled' => true,
        'source' => 'Fortnite.com + Epic Games Status',
        'workflow' => '.github/workflows/fortnite.yml',
    ],

    'security-watch' => [
        'label' => 'Security Watch',
        'webhook_env' => 'WEBHOOK_SECURITY',
        'enabled' => true,
        'source' => 'GitHub Advisory Database',
        'workflow' => '.github/workflows/security-watch.yml',
    ],

    'riot-rank' => [
        'label' => 'Riot Rank Tracker',
        'webhook_env' => 'WEBHOOK_RIOT',
        'enabled' => false,
        'requires' => 'RIOT_API_KEY',
    ],

    'delivery-advisor' => [
        'label' => 'Delivery Advisor — Zona Norte',
        'webhook_env' => 'WEBHOOK_DELIVERY',
        'enabled' => true,
        'source' => 'Open-Meteo',
        'workflow' => '.github/workflows/delivery-advisor.yml',
    ],
];
