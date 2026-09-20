<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\StateStore;

$webhookUrl = trim((string) getenv('WEBHOOK_RIOT_RANKING'));

if ($webhookUrl === '') {
    throw new RuntimeException('WEBHOOK_RIOT_RANKING não configurado.');
}

$state = (new StateStore(__DIR__ . '/../.state'))
    ->read('riot-analytics', ['players' => []]);

$players = is_array($state['players'] ?? null) ? $state['players'] : [];

if ($players === []) {
    echo "Sem dados de maestria para publicar.\n";
    exit(0);
}

$ranking = [];

foreach ($players as $id => $player) {
    if (!is_array($player)) {
        continue;
    }

    $riotId = is_array($player['riot_id'] ?? null) ? $player['riot_id'] : [];
    $name = (string) ($riotId['game_name'] ?? $id);
    $tag = (string) ($riotId['tag_line'] ?? '');
    $masteries = is_array($player['masteries'] ?? null) ? $player['masteries'] : [];

    $ranking[] = [
        'name' => $name . ($tag !== '' ? '#' . $tag : ''),
        'total' => (int) ($player['total_mastery_points'] ?? 0),
        'masteries' => $masteries,
    ];
}

usort(
    $ranking,
    static fn (array $a, array $b): int => $b['total'] <=> $a['total']
);

$leaderboardLines = [];

foreach ($ranking as $index => $player) {
    $leaderboardLines[] = sprintf(
        '**%d. %s** — %s pontos',
        $index + 1,
        $player['name'],
        number_format((int) $player['total'], 0, ',', '.')
    );
}

$fields = [];

foreach ($ranking as $player) {
    $lines = [];

    foreach (array_slice($player['masteries'], 0, 5) as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $lines[] = sprintf(
            '%d. **%s** — M%d • %s',
            $index + 1,
            (string) ($entry['champion'] ?? 'Champion'),
            (int) ($entry['level'] ?? 0),
            number_format((int) ($entry['points'] ?? 0), 0, ',', '.')
        );
    }

    $fields[] = [
        'name' => '🏅 ' . $player['name'],
        'value' => $lines === [] ? 'Sem maestrias disponíveis.' : implode("\n", $lines),
        'inline' => false,
    ];
}

$discord = new DiscordWebhook($webhookUrl);

$discord->send([
    'embeds' => [[
        'title' => '🏆 Ranking de Maestrias — Grupo',
        'description' => implode("\n", $leaderboardLines)
            . "\n\nRanking por soma dos pontos oficiais de Champion Mastery.",
        'color' => 0xD4AF37,
        'fields' => $fields,
        'footer' => [
            'text' => 'Champion Mastery • pontos oficiais Riot • não representa ranking de habilidade.',
        ],
        'timestamp' => date(DATE_ATOM),
    ]],
]);

echo "Ranking de maestrias enviado para " . count($ranking) . " jogador(es).\n";
