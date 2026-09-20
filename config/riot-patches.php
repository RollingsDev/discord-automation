<?php

declare(strict_types=1);

return [
    'state_key' => 'riot-patches',
    'max_posts_per_run' => 4,
    'sources' => [
        [
            'game' => 'League of Legends',
            'icon' => '⚔️',
            'url' => 'https://www.leagueoflegends.com/pt-br/news/game-updates/',
            'host' => 'www.leagueoflegends.com',
            'path_contains' => '/pt-br/news/game-updates/',
        ],
        [
            'game' => 'Teamfight Tactics',
            'icon' => '♟️',
            'url' => 'https://teamfighttactics.leagueoflegends.com/pt-br/news/game-updates/',
            'host' => 'teamfighttactics.leagueoflegends.com',
            'path_contains' => '/pt-br/news/game-updates/',
        ],
    ],
];
