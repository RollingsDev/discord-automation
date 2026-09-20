<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Riot\Analytics;
use DiscordAutomation\Riot\RiotClient;
use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\StateStore;

$config = require __DIR__ . '/../config/riot-players.php';

$apiKey = trim((string) getenv('RIOT_API_KEY'));
$webhookUrl = trim((string) getenv('WEBHOOK_RIOT'));
$forceReport = filter_var(getenv('FORCE_REPORT') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($apiKey === '') {
    throw new RuntimeException('RIOT_API_KEY não configurada.');
}

if ($webhookUrl === '') {
    throw new RuntimeException('WEBHOOK_RIOT não configurado.');
}

$client = new RiotClient(
    $apiKey,
    (string) ($config['region'] ?? 'americas'),
    (string) ($config['platform'] ?? 'br1'),
);

$stateStore = new StateStore(__DIR__ . '/../.state');
$stateKey = 'riot-analytics';
$state = $stateStore->read($stateKey, [
    'players' => [],
    'updated_at' => null,
]);

$players = is_array($config['players'] ?? null) ? $config['players'] : [];
$historyLimit = max(20, (int) ($config['history_limit'] ?? 100));
$backfill = max(1, min(20, (int) ($config['backfill_per_game'] ?? 8)));
$discord = new DiscordWebhook($webhookUrl);

foreach ($players as $playerConfig) {
    if (!is_array($playerConfig)) {
        continue;
    }

    $id = (string) ($playerConfig['id'] ?? '');
    $gameName = (string) ($playerConfig['game_name'] ?? '');
    $tagLine = (string) ($playerConfig['tag_line'] ?? '');

    if ($id === '' || $gameName === '' || $tagLine === '') {
        continue;
    }

    echo "Sincronizando {$gameName}#{$tagLine}...\n";

    $account = $client->accountByRiotId($gameName, $tagLine);
    $puuid = (string) ($account['puuid'] ?? '');

    if ($puuid === '') {
        throw new RuntimeException("PUUID não encontrado para {$gameName}#{$tagLine}.");
    }

    $previous = is_array($state['players'][$id] ?? null)
        ? $state['players'][$id]
        : [];

    $lolRanksRaw = $client->lolRanks($puuid);
    $tftRanksRaw = $client->tftRanks($puuid);

    $lolSolo = Analytics::rankEntry($lolRanksRaw, 'RANKED_SOLO_5x5');
    $lolFlex = Analytics::rankEntry($lolRanksRaw, 'RANKED_FLEX_SR');
    $tftRank = Analytics::rankEntry($tftRanksRaw, 'RANKED_TFT');

    $lolMatches = is_array($previous['lol_matches'] ?? null)
        ? $previous['lol_matches']
        : [];
    $tftMatches = is_array($previous['tft_matches'] ?? null)
        ? $previous['tft_matches']
        : [];

    $knownLol = array_fill_keys(array_column($lolMatches, 'id'), true);
    $knownTft = array_fill_keys(array_column($tftMatches, 'id'), true);

    foreach ($client->lolMatchIds($puuid, $backfill) as $matchId) {
        $matchId = (string) $matchId;

        if ($matchId === '' || isset($knownLol[$matchId])) {
            continue;
        }

        $compact = Analytics::compactLolMatch(
            $client->lolMatch($matchId),
            $puuid
        );

        if ($compact !== null && $compact['id'] !== '') {
            array_unshift($lolMatches, $compact);
            $knownLol[$compact['id']] = true;
        }
    }

    foreach ($client->tftMatchIds($puuid, $backfill) as $matchId) {
        $matchId = (string) $matchId;

        if ($matchId === '' || isset($knownTft[$matchId])) {
            continue;
        }

        $compact = Analytics::compactTftMatch(
            $client->tftMatch($matchId),
            $puuid
        );

        if ($compact !== null && $compact['id'] !== '') {
            array_unshift($tftMatches, $compact);
            $knownTft[$compact['id']] = true;
        }
    }

    usort(
        $lolMatches,
        static fn (array $a, array $b): int
            => ((int) ($b['timestamp'] ?? 0)) <=> ((int) ($a['timestamp'] ?? 0))
    );
    usort(
        $tftMatches,
        static fn (array $a, array $b): int
            => ((int) ($b['timestamp'] ?? 0)) <=> ((int) ($a['timestamp'] ?? 0))
    );

    $lolMatches = array_slice($lolMatches, 0, $historyLimit);
    $tftMatches = array_slice($tftMatches, 0, $historyLimit);

    $previousSolo = is_array($previous['lol_solo'] ?? null)
        ? $previous['lol_solo']
        : null;
    $previousTft = is_array($previous['tft_rank'] ?? null)
        ? $previous['tft_rank']
        : null;

    $soloChanged = $previousSolo !== null && $lolSolo !== null
        && Analytics::rankLabel($previousSolo) !== Analytics::rankLabel($lolSolo);
    $tftChanged = $previousTft !== null && $tftRank !== null
        && Analytics::rankLabel($previousTft) !== Analytics::rankLabel($tftRank);

    $snapshots = is_array($previous['rank_snapshots'] ?? null)
        ? $previous['rank_snapshots']
        : [];

    array_unshift($snapshots, [
        'timestamp' => time(),
        'lol_solo' => $lolSolo,
        'lol_flex' => $lolFlex,
        'tft' => $tftRank,
    ]);

    $snapshots = array_slice($snapshots, 0, 168);

    $state['players'][$id] = [
        'riot_id' => [
            'game_name' => (string) ($account['gameName'] ?? $gameName),
            'tag_line' => (string) ($account['tagLine'] ?? $tagLine),
        ],
        'puuid' => $puuid,
        'lol_solo' => $lolSolo,
        'lol_flex' => $lolFlex,
        'tft_rank' => $tftRank,
        'lol_matches' => $lolMatches,
        'tft_matches' => $tftMatches,
        'rank_snapshots' => $snapshots,
        'updated_at' => date(DATE_ATOM),
    ];

    if ($soloChanged || $tftChanged || $forceReport) {
        $lol = Analytics::lolSummary($lolMatches, 20);
        $tft = Analytics::tftSummary($tftMatches, 20);

        $fields = [
            [
                'name' => '⚔️ LoL Solo/Duo',
                'value' => Analytics::rankLabel($lolSolo)
                    . (($lol['games'] ?? 0) > 0
                        ? "\nÚltimas {$lol['games']}: "
                            . number_format(($lol['winrate'] ?? 0) * 100, 1, ',', '.')
                            . '% WR'
                        : ''),
                'inline' => true,
            ],
            [
                'name' => '♟️ TFT',
                'value' => Analytics::rankLabel($tftRank)
                    . (($tft['games'] ?? 0) > 0
                        ? "\nTop 4: "
                            . number_format(($tft['top4_rate'] ?? 0) * 100, 1, ',', '.')
                            . '% • 1º: '
                            . number_format(($tft['first_rate'] ?? 0) * 100, 1, ',', '.')
                            . '%'
                        : ''),
                'inline' => true,
            ],
        ];

        $discord->send([
            'embeds' => [[
                'title' => ($forceReport ? '🧪 Riot Analytics • ' : '📈 Rank Update • ')
                    . $gameName . '#' . $tagLine,
                'description' => $soloChanged || $tftChanged
                    ? 'Mudança detectada no ranking oficial.'
                    : 'Resumo manual do perfil.',
                'color' => 0xC99B3B,
                'fields' => $fields,
                'footer' => [
                    'text' => 'Riot Analytics • LoL + TFT • dados oficiais Riot',
                ],
                'timestamp' => date(DATE_ATOM),
            ]],
        ]);
    }
}

$state['updated_at'] = date(DATE_ATOM);
$stateStore->write($stateKey, $state);

echo "Sincronização Riot concluída para " . count($players) . " jogador(es).\n";
