<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Riot\Analytics;
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
    echo "Sem dados Riot para o painel do grupo.\n";
    exit(0);
}

function officialRankScore(?array $rank): int
{
    if ($rank === null) {
        return -1;
    }

    $tiers = [
        'IRON' => 0,
        'BRONZE' => 1,
        'SILVER' => 2,
        'GOLD' => 3,
        'PLATINUM' => 4,
        'EMERALD' => 5,
        'DIAMOND' => 6,
        'MASTER' => 7,
        'GRANDMASTER' => 8,
        'CHALLENGER' => 9,
    ];

    $divisions = ['IV' => 0, 'III' => 1, 'II' => 2, 'I' => 3];

    return (($tiers[(string) ($rank['tier'] ?? '')] ?? -1) * 10000)
        + (($divisions[(string) ($rank['rank'] ?? '')] ?? 0) * 1000)
        + (int) ($rank['lp'] ?? 0);
}

function boardPct(float $value): string
{
    return number_format($value * 100, 1, ',', '.') . '%';
}

$rows = [];

foreach ($players as $player) {
    if (!is_array($player)) {
        continue;
    }

    $riotId = is_array($player['riot_id'] ?? null) ? $player['riot_id'] : [];
    $name = (string) ($riotId['game_name'] ?? 'Jogador');
    $tag = (string) ($riotId['tag_line'] ?? '');

    $lolMatches = is_array($player['lol_matches'] ?? null) ? $player['lol_matches'] : [];
    $aramMatches = is_array($player['aram_matches'] ?? null) ? $player['aram_matches'] : [];
    $tftMatches = is_array($player['tft_matches'] ?? null) ? $player['tft_matches'] : [];

    $rows[] = [
        'name' => $name . ($tag !== '' ? '#' . $tag : ''),
        'lol_rank' => is_array($player['lol_solo'] ?? null) ? $player['lol_solo'] : null,
        'tft_rank' => is_array($player['tft_rank'] ?? null) ? $player['tft_rank'] : null,
        'lol' => Analytics::lolSummary($lolMatches, 20),
        'aram' => Analytics::lolSummary($aramMatches, 20),
        'tft' => Analytics::tftSummary($tftMatches, 20),
    ];
}

$lolBoard = $rows;
usort(
    $lolBoard,
    static fn (array $a, array $b): int
        => officialRankScore($b['lol_rank']) <=> officialRankScore($a['lol_rank'])
);

$tftBoard = $rows;
usort(
    $tftBoard,
    static fn (array $a, array $b): int
        => officialRankScore($b['tft_rank']) <=> officialRankScore($a['tft_rank'])
);

$lolLines = [];
foreach ($lolBoard as $index => $row) {
    $stats = is_array($row['lol'] ?? null) ? $row['lol'] : [];
    $suffix = (int) ($stats['games'] ?? 0) > 0
        ? sprintf(
            ' • %s WR/%dj • KDA %.2f',
            boardPct((float) ($stats['winrate'] ?? 0)),
            (int) ($stats['games'] ?? 0),
            (float) ($stats['avg_kda'] ?? 0)
        )
        : '';

    $lolLines[] = sprintf(
        '**%d. %s** — %s%s',
        $index + 1,
        $row['name'],
        Analytics::rankLabel($row['lol_rank']),
        $suffix
    );
}

$tftLines = [];
foreach ($tftBoard as $index => $row) {
    $stats = is_array($row['tft'] ?? null) ? $row['tft'] : [];
    $suffix = (int) ($stats['games'] ?? 0) > 0
        ? sprintf(
            ' • Top4 %s • média %.2f',
            boardPct((float) ($stats['top4_rate'] ?? 0)),
            (float) ($stats['avg_placement'] ?? 0)
        )
        : '';

    $tftLines[] = sprintf(
        '**%d. %s** — %s%s',
        $index + 1,
        $row['name'],
        Analytics::rankLabel($row['tft_rank']),
        $suffix
    );
}

$aramRows = array_values(array_filter(
    $rows,
    static fn (array $row): bool => (int) ($row['aram']['games'] ?? 0) > 0
));

usort(
    $aramRows,
    static fn (array $a, array $b): int
        => [
            (float) ($b['aram']['winrate'] ?? 0),
            (int) ($b['aram']['games'] ?? 0),
        ] <=> [
            (float) ($a['aram']['winrate'] ?? 0),
            (int) ($a['aram']['games'] ?? 0),
        ]
);

$aramLines = [];

foreach ($aramRows as $row) {
    $aramLines[] = sprintf(
        '**%s** — %s WR • %dj • KDA %.2f',
        $row['name'],
        boardPct((float) ($row['aram']['winrate'] ?? 0)),
        (int) ($row['aram']['games'] ?? 0),
        (float) ($row['aram']['avg_kda'] ?? 0)
    );
}

$discord = new DiscordWebhook($webhookUrl);

$discord->send([
    'embeds' => [[
        'title' => '🏆 Painel Riot — Grupo',
        'description' => 'Ranks oficiais + recorte recente. Sem MMR ou score alternativo.',
        'color' => 0x8E44AD,
        'fields' => [
            [
                'name' => '⚔️ LoL Solo/Duo — rank oficial',
                'value' => implode("\n", $lolLines),
                'inline' => false,
            ],
            [
                'name' => '♟️ TFT — rank oficial',
                'value' => implode("\n", $tftLines),
                'inline' => false,
            ],
            [
                'name' => '🎲 ARAM — recorte recente',
                'value' => $aramLines === []
                    ? 'Sem ARAM recente no histórico coletado.'
                    : implode("\n", $aramLines),
                'inline' => false,
            ],
        ],
        'footer' => [
            'text' => 'Ordenação LoL/TFT usa somente o rank oficial retornado pela Riot.',
        ],
        'timestamp' => date(DATE_ATOM),
    ]],
]);

echo "Painel Riot do grupo enviado para " . count($rows) . " jogador(es).\n";
