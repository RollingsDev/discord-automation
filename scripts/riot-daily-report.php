<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Riot\AdvancedAnalytics;
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
    echo "Ainda não há dados do Riot Analytics. Execute o sync primeiro.\n";
    exit(0);
}

$discord = new DiscordWebhook($webhookUrl);

function pct(float $value): string
{
    return number_format($value * 100, 1, ',', '.') . '%';
}

function topLolChampions(array $champions): string
{
    if ($champions === []) {
        return 'Sem dados suficientes.';
    }

    $lines = [];
    foreach (array_slice($champions, 0, 4, true) as $name => $stats) {
        $games = (int) ($stats['games'] ?? 0);
        if ($games < 2) {
            continue;
        }

        $lines[] = sprintf(
            '**%s** — %dj • %s WR • KDA %.2f',
            $name,
            $games,
            pct((float) ($stats['winrate'] ?? 0)),
            (float) ($stats['avg_kda'] ?? 0)
        );
    }

    return $lines === [] ? 'Amostra ainda pequena.' : implode("\n", $lines);
}

function topMasteries(array $masteries, int $limit = 5): string
{
    if ($masteries === []) {
        return 'Sem dados de maestria.';
    }

    $lines = [];
    foreach (array_slice($masteries, 0, $limit) as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $lines[] = sprintf(
            '**%d. %s** — M%d • %s pts',
            $index + 1,
            (string) ($entry['champion'] ?? 'Champion'),
            (int) ($entry['level'] ?? 0),
            number_format((int) ($entry['points'] ?? 0), 0, ',', '.')
        );
    }

    return $lines === [] ? 'Sem dados de maestria.' : implode("\n", $lines);
}

function gameplayOverviewText(array $overview): string
{
    $parts = [];

    $rankedGames = (int) ($overview['ranked_games'] ?? 0);
    $aramGames = (int) ($overview['aram_games'] ?? 0);

    if ($rankedGames > 0) {
        $role = (string) ($overview['primary_role'] ?? 'N/A');
        $roleGames = (int) ($overview['primary_role_games'] ?? 0);
        $pool = (int) ($overview['champion_pool'] ?? 0);
        $champion = (string) ($overview['most_played_champion'] ?? 'N/A');
        $championGames = (int) ($overview['most_played_champion_games'] ?? 0);
        $ranked = is_array($overview['ranked'] ?? null) ? $overview['ranked'] : [];

        $parts[] = sprintf(
            'Ranqueada: **%s** em %d/%d jogos • pool de **%d campeões** • mais usado: **%s** (%d)',
            $role,
            $roleGames,
            $rankedGames,
            $pool,
            $champion,
            $championGames
        );

        $parts[] = sprintf(
            'KP **%s** • KDA **%.2f** • CS/min **%.1f** • DPM **%.0f**',
            pct((float) ($ranked['kill_participation'] ?? 0)),
            (float) ($ranked['avg_kda'] ?? 0),
            (float) ($ranked['cs_per_min'] ?? 0),
            (float) ($ranked['damage_per_min'] ?? 0)
        );
    }

    if ($aramGames > 0) {
        $aram = is_array($overview['aram'] ?? null) ? $overview['aram'] : [];

        $parts[] = sprintf(
            'ARAM: **%d jogos** • **%s WR** • KDA **%.2f** • DPM **%.0f**',
            $aramGames,
            pct((float) ($aram['winrate'] ?? 0)),
            (float) ($aram['avg_kda'] ?? 0),
            (float) ($aram['damage_per_min'] ?? 0)
        );
    }

    return $parts === []
        ? 'Amostra recente ainda insuficiente para montar o overview.'
        : implode("\n", $parts);
}

function laneTimelineText(array $advanced): string
{
    $sample = (int) ($advanced['timeline_games'] ?? 0);

    if ($sample === 0) {
        return 'Timelines ainda estão sendo coletadas.';
    }

    $lines = [];

    foreach ([10, 15, 20] as $minute) {
        $point = is_array($advanced['checkpoints'][(string) $minute] ?? null)
            ? $advanced['checkpoints'][(string) $minute]
            : [];

        if ($point === []) {
            continue;
        }

        $gold = (float) ($point['gold_diff'] ?? 0);
        $cs = (float) ($point['cs_diff'] ?? 0);
        $xp = (float) ($point['xp_diff'] ?? 0);

        $lines[] = sprintf(
            '**%d min** • Gold %+.0f • CS %+.1f • XP %+.0f',
            $minute,
            $gold,
            $cs,
            $xp
        );
    }

    $early = is_array($advanced['early'] ?? null) ? $advanced['early'] : [];

    $lines[] = sprintf(
        'Até 10 min/jogo: %.2f K • %.2f D • %.2f A',
        (float) ($early['kills_10_per_game'] ?? 0),
        (float) ($early['deaths_10_per_game'] ?? 0),
        (float) ($early['assists_10_per_game'] ?? 0)
    );

    $ahead = is_array($advanced['ahead_15'] ?? null) ? $advanced['ahead_15'] : [];
    $behind = is_array($advanced['behind_15'] ?? null) ? $advanced['behind_15'] : [];

    if ((int) ($ahead['games'] ?? 0) > 0) {
        $lines[] = sprintf(
            'Com vantagem de gold aos 15: **%s WR** (%dj)',
            pct((float) ($ahead['winrate'] ?? 0)),
            (int) ($ahead['games'] ?? 0)
        );
    }

    if ((int) ($behind['games'] ?? 0) > 0) {
        $lines[] = sprintf(
            'Atrás em gold aos 15: **%s WR** (%dj)',
            pct((float) ($behind['winrate'] ?? 0)),
            (int) ($behind['games'] ?? 0)
        );
    }

    return implode("\n", array_slice($lines, 0, 6));
}

function roleSideText(array $advanced): string
{
    $lines = [];

    foreach (array_slice(
        is_array($advanced['roles'] ?? null) ? $advanced['roles'] : [],
        0,
        3,
        true
    ) as $role => $stats) {
        $lines[] = sprintf(
            '**%s** — %dj • %s WR • KDA %.2f',
            $role,
            (int) ($stats['games'] ?? 0),
            pct((float) ($stats['winrate'] ?? 0)),
            (float) ($stats['avg_kda'] ?? 0)
        );
    }

    $sides = is_array($advanced['sides'] ?? null) ? $advanced['sides'] : [];

    foreach (['BLUE' => 'Azul', 'RED' => 'Vermelho'] as $key => $label) {
        if (!isset($sides[$key])) {
            continue;
        }

        $lines[] = sprintf(
            '%s: %dj • %s WR',
            $label,
            (int) ($sides[$key]['games'] ?? 0),
            pct((float) ($sides[$key]['winrate'] ?? 0))
        );
    }

    return $lines === [] ? 'Amostra ainda insuficiente.' : implode("\n", $lines);
}

function buildText(array $advanced): string
{
    $items = is_array($advanced['items'] ?? null) ? $advanced['items'] : [];
    $lines = [];

    foreach (array_slice($items, 0, 5, true) as $name => $stats) {
        if ((int) ($stats['games'] ?? 0) < 2) {
            continue;
        }

        $timing = isset($stats['avg_timing']) && is_numeric($stats['avg_timing'])
            ? ' • ~' . number_format((float) $stats['avg_timing'], 1, ',', '.') . ' min'
            : '';

        $lines[] = sprintf(
            '**%s** — %dj • %s WR%s',
            $name,
            (int) ($stats['games'] ?? 0),
            pct((float) ($stats['winrate'] ?? 0)),
            $timing
        );
    }

    $objectives = is_array($advanced['objectives'] ?? null)
        ? $advanced['objectives']
        : [];

    if ((int) ($advanced['timeline_games'] ?? 0) > 0) {
        $lines[] = sprintf(
            'Participação/jogo: Dragão %.2f • Barão %.2f • Arauto %.2f',
            (float) ($objectives['dragon_per_game'] ?? 0),
            (float) ($objectives['baron_per_game'] ?? 0),
            (float) ($objectives['herald_per_game'] ?? 0)
        );
    }

    return $lines === [] ? 'Itens/timings ainda sendo acumulados.' : implode("\n", $lines);
}

function tftDeepText(array $advanced): string
{
    $lines = [];

    foreach (array_slice(
        is_array($advanced['carries'] ?? null) ? $advanced['carries'] : [],
        0,
        3,
        true
    ) as $name => $stats) {
        if ((int) ($stats['games'] ?? 0) < 2) {
            continue;
        }

        $lines[] = sprintf(
            '**Carry %s** — %dj • Top4 %s • média %.2f',
            $name,
            (int) ($stats['games'] ?? 0),
            pct((float) ($stats['top4_rate'] ?? 0)),
            (float) ($stats['avg_placement'] ?? 0)
        );
    }

    foreach (array_slice(
        is_array($advanced['item_pairs'] ?? null) ? $advanced['item_pairs'] : [],
        0,
        3,
        true
    ) as $pair => $stats) {
        if ((int) ($stats['games'] ?? 0) < 2) {
            continue;
        }

        $lines[] = sprintf(
            '%s — %dj • Top4 %s',
            $pair,
            (int) ($stats['games'] ?? 0),
            pct((float) ($stats['top4_rate'] ?? 0))
        );
    }

    return $lines === [] ? 'Amostra de carry/item ainda pequena.' : implode("\n", $lines);
}

function topTftRows(array $rows, int $limit = 4): string
{
    if ($rows === []) {
        return 'Sem dados suficientes.';
    }

    $lines = [];

    foreach (array_slice($rows, 0, $limit, true) as $name => $stats) {
        $games = (int) ($stats['games'] ?? 0);

        if ($games < 2) {
            continue;
        }

        $lines[] = sprintf(
            '**%s** — %dj • Top4 %s • 1º %s • média %.2f',
            $name,
            $games,
            pct((float) ($stats['top4_rate'] ?? 0)),
            pct((float) ($stats['first_rate'] ?? 0)),
            (float) ($stats['avg_placement'] ?? 0)
        );
    }

    return $lines === [] ? 'Amostra ainda pequena.' : implode("\n", $lines);
}

foreach ($players as $player) {
    if (!is_array($player)) {
        continue;
    }

    $riotId = is_array($player['riot_id'] ?? null) ? $player['riot_id'] : [];
    $name = (string) ($riotId['game_name'] ?? 'Jogador');
    $tag = (string) ($riotId['tag_line'] ?? '');

    $lol = Analytics::lolSummary(
        is_array($player['lol_matches'] ?? null) ? $player['lol_matches'] : [],
        20
    );

    $aram = Analytics::lolSummary(
        is_array($player['aram_matches'] ?? null) ? $player['aram_matches'] : [],
        20
    );

    $tft = Analytics::tftSummary(
        is_array($player['tft_matches'] ?? null) ? $player['tft_matches'] : [],
        20
    );

    $overview = Analytics::gameplayOverview(
        is_array($player['lol_matches'] ?? null) ? $player['lol_matches'] : [],
        is_array($player['aram_matches'] ?? null) ? $player['aram_matches'] : [],
        20
    );

    $lolAdvanced = AdvancedAnalytics::lolAdvancedSummary(
        is_array($player['lol_matches'] ?? null) ? $player['lol_matches'] : [],
        20
    );

    $tftAdvanced = AdvancedAnalytics::tftAdvancedSummary(
        is_array($player['tft_matches'] ?? null) ? $player['tft_matches'] : [],
        50
    );

    $lolValue = Analytics::rankLabel(
        is_array($player['lol_solo'] ?? null) ? $player['lol_solo'] : null
    );

    if (($lol['games'] ?? 0) > 0) {
        $lolValue .= sprintf(
            "\n**%dW/%dL • %s WR**\nKDA %.2f • CS/min %.1f • DPM %.0f\nGold/min %.0f • Visão/min %.2f • KP %s",
            (int) ($lol['wins'] ?? 0),
            (int) ($lol['losses'] ?? 0),
            pct((float) ($lol['winrate'] ?? 0)),
            (float) ($lol['avg_kda'] ?? 0),
            (float) ($lol['cs_per_min'] ?? 0),
            (float) ($lol['damage_per_min'] ?? 0),
            (float) ($lol['gold_per_min'] ?? 0),
            (float) ($lol['vision_per_min'] ?? 0),
            pct((float) ($lol['kill_participation'] ?? 0))
        );
    }

    $aramValue = ($aram['games'] ?? 0) > 0
        ? sprintf(
            '**%dW/%dL • %s WR**\nKDA %.2f • DPM %.0f • KP %s',
            (int) ($aram['wins'] ?? 0),
            (int) ($aram['losses'] ?? 0),
            pct((float) ($aram['winrate'] ?? 0)),
            (float) ($aram['avg_kda'] ?? 0),
            (float) ($aram['damage_per_min'] ?? 0),
            pct((float) ($aram['kill_participation'] ?? 0))
        )
        : 'Sem ARAM recente no histórico coletado.';

    $tftValue = Analytics::rankLabel(
        is_array($player['tft_rank'] ?? null) ? $player['tft_rank'] : null
    );

    if (($tft['games'] ?? 0) > 0) {
        $tftValue .= sprintf(
            "\n**Top 4 %s • 1º %s**\nPosição média: %.2f em %d partidas",
            pct((float) ($tft['top4_rate'] ?? 0)),
            pct((float) ($tft['first_rate'] ?? 0)),
            (float) ($tft['avg_placement'] ?? 0),
            (int) ($tft['games'] ?? 0)
        );
    }

    $discord->send([
        'embeds' => [[
            'title' => '📊 Riot Daily • ' . $name . ($tag !== '' ? '#' . $tag : ''),
            'description' => 'Resumo pós-jogo das partidas ranqueadas recentes.',
            'color' => 0xC99B3B,
            'fields' => [
                [
                    'name' => '⚔️ League of Legends',
                    'value' => $lolValue,
                    'inline' => false,
                ],
                [
                    'name' => '🔥 Campeões recentes',
                    'value' => topLolChampions(
                        is_array($lol['champions'] ?? null) ? $lol['champions'] : []
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '🎲 ARAM',
                    'value' => $aramValue,
                    'inline' => false,
                ],
                [
                    'name' => '🧠 Overview da gameplay',
                    'value' => gameplayOverviewText($overview),
                    'inline' => false,
                ],
                [
                    'name' => '🏅 Top maestrias',
                    'value' => topMasteries(
                        is_array($player['masteries'] ?? null) ? $player['masteries'] : []
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '⏱️ Lane / early game',
                    'value' => laneTimelineText($lolAdvanced),
                    'inline' => false,
                ],
                [
                    'name' => '🗺️ Role e lado do mapa',
                    'value' => roleSideText($lolAdvanced),
                    'inline' => false,
                ],
                [
                    'name' => '🧰 Builds e objetivos',
                    'value' => buildText($lolAdvanced),
                    'inline' => false,
                ],
                [
                    'name' => '♟️ Teamfight Tactics',
                    'value' => $tftValue,
                    'inline' => false,
                ],
                [
                    'name' => '🧩 Comps mais usadas',
                    'value' => topTftRows(
                        is_array($tft['comps'] ?? null) ? $tft['comps'] : []
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '🧰 Itens TFT mais recorrentes',
                    'value' => topTftRows(
                        is_array($tft['items'] ?? null) ? $tft['items'] : []
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '🎯 TFT carry + item',
                    'value' => tftDeepText($tftAdvanced),
                    'inline' => false,
                ],
            ],
            'footer' => [
                'text' => 'Top4/1º de comps e itens são estatísticas observadas no histórico do próprio jogador.',
            ],
            'timestamp' => date(DATE_ATOM),
        ]],
    ]);

    usleep(450_000);
}

echo "Relatório diário Riot enviado para " . count($players) . " jogador(es).\n";
