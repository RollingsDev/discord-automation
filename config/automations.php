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
        'enabled' => false,
    ],

    'riot-patches' => [
        'label' => 'LoL / TFT Patch Watch',
        'webhook_env' => 'WEBHOOK_RIOT',
        'enabled' => false,
    ],

    'fortnite' => [
        'label' => 'Fortnite Updates',
        'webhook_env' => 'WEBHOOK_FORTNITE',
        'enabled' => false,
    ],

    'security-watch' => [
        'label' => 'Security Watch',
        'webhook_env' => 'WEBHOOK_SECURITY',
        'enabled' => false,
    ],

    'riot-rank' => [
        'label' => 'Riot Rank Tracker',
        'webhook_env' => 'WEBHOOK_RIOT',
        'enabled' => false,
    ],

    'delivery-advisor' => [
        'label' => 'Delivery Advisor',
        'webhook_env' => 'WEBHOOK_DELIVERY',
        'enabled' => false,
    ],
];
