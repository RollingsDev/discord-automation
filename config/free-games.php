<?php

declare(strict_types=1);

return [
    'api_url' => 'https://www.gamerpower.com/api/giveaways?type=game&sort-by=date',
    'state_key' => 'free-games',
    'max_posts_per_run' => 6,

    // Mantém o canal focado em jogos para PC. Edite a lista se quiser incluir consoles/mobile.
    'platform_keywords' => [
        'PC',
        'Steam',
        'Epic Games Store',
        'GOG',
        'DRM-Free',
        'itch.io',
        'Battle.net',
        'Ubisoft Connect',
        'Origin',
    ],
];
