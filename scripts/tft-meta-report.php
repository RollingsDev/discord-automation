<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Riot\Analytics;
use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\StateStore;
use DiscordAutomation\Tft\TftMetaSource;

$config = require __DIR__ . '/../config/tft-meta.php';

$stateStore = new StateStore(__DIR__ . '/../.state');
$dryRun = filter_var(getenv('DRY_RUN') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$webhookUrl = trim((string) getenv('WEBHOOK_TFT_META'));

$cached = $stateStore->read('tft-meta', []);
$fresh = false;

try {
    $meta = (new TftMetaSource())->fetch();
    $stateStore->write('tft-meta', $meta);
    $fresh = true;
} catch (Throwable $e) {
    if ($cached === []) {
        throw $e;
    }

    $meta = $cached;
    $meta['fallback_reason'] = $e->getMessage();
}

$riotState = $stateStore->read('riot-analytics', ['players' => []]);
$players = is_array($riotState['players'] ?? null) ? $riotState['players'] : [];

function tftPct(float $value): string
{
    return number_format($value * 100, 1, ',', '.') . '%';
}

function tftRankLabel(?array $rank): string
{
    return Analytics::rankLabel($rank);
}

function compItemsText(array $comp): string
{
    $unitItems = is_array($comp['unit_items'] ?? null)
        ? $comp['unit_items']
        : [];

    $lines = [];

    foreach ($unitItems as $unit => $items) {
        if (!is_array($items) || $items === []) {
            continue;
        }

        $lines[] = '**' . $unit . '** → ' . implode(' / ', array_slice($items, 0, 3));

        if (count($lines) >= 3) {
            break;
        }
    }

    return $lines === []
        ? 'Itens principais não identificados neste snapshot.'
        : implode("\n", $lines);
}

function personalTftRows(array $players): array
{
    $rows = [];

    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }

        $riotId = is_array($player['riot_id'] ?? null)
            ? $player['riot_id']
            : [];

        $name = trim(
            (string) ($riotId['game_name'] ?? '')
            . '#'
            . (string) ($riotId['tag_line'] ?? '')
        );

        $rank = is_array($player['tft_rank'] ?? null)
            ? $player['tft_rank']
            : null;

        $matches = is_array($player['tft_matches'] ?? null)
            ? $player['tft_matches']
            : [];

        if ($rank === null && $matches === []) {
            continue;
        }

        $row = [
            'name' => $name,
            'rank' => $rank,
            'official_games' => 0,
            'official_wins' => 0,
            'league_win_rate' => null,
            'recent' => null,
        ];

        if ($rank !== null) {
            $wins = (int) ($rank['wins'] ?? 0);
            $losses = (int) ($rank['losses'] ?? 0);
            $games = $wins + $losses;

            $row['official_games'] = $games;
            $row['official_wins'] = $wins;
            $row['league_win_rate'] = $games > 0 ? $wins / $games : null;
        }

        if ($matches !== []) {
            $summary = Analytics::tftSummary($matches, 20);
            $row['recent'] = [
                'games' => (int) ($summary['games'] ?? 0),
                'top4_rate' => (float) ($summary['top4_rate'] ?? 0),
                'first_rate' => (float) ($summary['first_rate'] ?? 0),
                'avg_placement' => (float) ($summary['avg_placement'] ?? 0),
            ];
        }

        $rows[] = $row;
    }

    usort(
        $rows,
        static function (array $a, array $b): int {
            $aRanked = $a['rank'] !== null ? 1 : 0;
            $bRanked = $b['rank'] !== null ? 1 : 0;

            if ($aRanked !== $bRanked) {
                return $bRanked <=> $aRanked;
            }

            return ((float) ($b['league_win_rate'] ?? -1))
                <=> ((float) ($a['league_win_rate'] ?? -1));
        }
    );

    return $rows;
}

$patch = trim((string) ($meta['patch'] ?? 'atual'));
$rankFilter = trim((string) ($meta['rank_filter'] ?? 'filtro da fonte'));
$maxComps = max(1, min(8, (int) ($config['max_comps'] ?? 6)));
$maxItems = max(1, min(12, (int) ($config['max_items'] ?? 8)));

$comps = array_slice(
    is_array($meta['comps'] ?? null) ? $meta['comps'] : [],
    0,
    $maxComps
);

$items = array_slice(
    is_array($meta['items'] ?? null) ? $meta['items'] : [],
    0,
    $maxItems
);

$fields = [];

foreach ($comps as $index => $comp) {
    if (!is_array($comp)) {
        continue;
    }

    $fields[] = [
        'name' => sprintf(
            '%d. 🧩 %s',
            $index + 1,
            (string) ($comp['name'] ?? 'Comp')
        ),
        'value' => sprintf(
            '**Win %s • Top 4 %s • Média %.2f • Play %.2f**'
                . "\n%s",
            tftPct((float) ($comp['win_rate'] ?? 0)),
            tftPct((float) ($comp['top4_rate'] ?? 0)),
            (float) ($comp['avg_place'] ?? 0),
            (float) ($comp['play_rate'] ?? 0),
            compItemsText($comp)
        ),
        'inline' => false,
    ];
}

if ($items !== []) {
    $itemLines = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $users = is_array($item['top_users'] ?? null)
            ? array_slice($item['top_users'], 0, 3)
            : [];

        $line = sprintf(
            '**%s** — Win %s • Top4 %s • média %.2f • uso %.2f/8',
            (string) ($item['name'] ?? 'Item'),
            tftPct((float) ($item['win_rate'] ?? 0)),
            tftPct((float) ($item['top4_rate'] ?? 0)),
            (float) ($item['avg_place'] ?? 0),
            (float) ($item['play_rate'] ?? 0)
        );

        if ($users !== []) {
            $line .= "\n↳ " . implode(', ', $users);
        }

        $itemLines[] = $line;
    }

    $fields[] = [
        'name' => '🧰 Itens do meta',
        'value' => implode("\n", $itemLines),
        'inline' => false,
    ];
}

$personal = personalTftRows($players);

if ($personal !== []) {
    $personalLines = [];

    foreach ($personal as $row) {
        $line = '**' . $row['name'] . '** — ' . tftRankLabel($row['rank']);

        if ($row['league_win_rate'] !== null) {
            $line .= sprintf(
                "\nLeague W/L: **%s** (%dW/%dL)",
                tftPct((float) $row['league_win_rate']),
                (int) $row['official_wins'],
                max(0, (int) $row['official_games'] - (int) $row['official_wins'])
            );
        }

        $recent = is_array($row['recent'] ?? null) ? $row['recent'] : [];

        if ((int) ($recent['games'] ?? 0) > 0) {
            $line .= sprintf(
                "\nRecorte coletado: Top4 %s • 1º %s • média %.2f (%dj)",
                tftPct((float) ($recent['top4_rate'] ?? 0)),
                tftPct((float) ($recent['first_rate'] ?? 0)),
                (float) ($recent['avg_placement'] ?? 0),
                (int) ($recent['games'] ?? 0)
            );
        }

        $personalLines[] = $line;
    }

    $fields[] = [
        'name' => '👥 TFT da galera',
        'value' => implode("\n\n", $personalLines),
        'inline' => false,
    ];
}

$description = sprintf(
    'Patch **%s** • **%s** • snapshot estático para consulta rápida.',
    $patch !== '' ? $patch : 'atual',
    $rankFilter !== '' ? $rankFilter : 'filtro da fonte'
);

if (!$fresh) {
    $description .= "\n⚠️ A fonte externa falhou; usando o último snapshot salvo.";
}

$payload = [
    'embeds' => [[
        'title' => '♟️ TFT Meta — Comps, Itens e Jogadores',
        'url' => (string) ($meta['source_url'] ?? 'https://tactics.tools/pt/team-compositions/all'),
        'description' => $description,
        'color' => 0x7C4DFF,
        'fields' => $fields,
        'footer' => [
            'text' => 'Meta: tactics.tools • jogadores: Riot API • Top4/1º vêm do Match-V1',
        ],
        'timestamp' => date(DATE_ATOM),
    ]],
];

if ($dryRun) {
    echo json_encode(
        [
            'patch' => $patch,
            'rank_filter' => $rankFilter,
            'comps' => $comps,
            'items' => $items,
            'personal' => $personal,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;

    exit(0);
}

if ($webhookUrl === '') {
    echo "WEBHOOK_TFT_META ainda não configurado; snapshot atualizado sem publicar.\n";
    exit(0);
}

(new DiscordWebhook($webhookUrl))->send($payload);

echo "TFT Meta publicado com " . count($comps)
    . " comp(s), " . count($items)
    . " item(ns) e " . count($personal)
    . " jogador(es).\n";
