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
    echo "Sem histórico Riot para relatório semanal.\n";
    exit(0);
}

$cutoffMs = (time() - (7 * 86400)) * 1000;
$discord = new DiscordWebhook($webhookUrl);

function weeklyPct(float $value): string
{
    return number_format($value * 100, 1, ',', '.') . '%';
}

function weeklyAdvancedText(array $advanced): string
{
    if ((int) ($advanced['timeline_games'] ?? 0) === 0) {
        return 'Timelines ainda sendo acumuladas.';
    }

    $point15 = is_array($advanced['checkpoints']['15'] ?? null)
        ? $advanced['checkpoints']['15']
        : [];

    $early = is_array($advanced['early'] ?? null) ? $advanced['early'] : [];
    $objectives = is_array($advanced['objectives'] ?? null) ? $advanced['objectives'] : [];

    $text = sprintf(
        '15 min: Gold %+.0f • CS %+.1f • XP %+.0f',
        (float) ($point15['gold_diff'] ?? 0),
        (float) ($point15['cs_diff'] ?? 0),
        (float) ($point15['xp_diff'] ?? 0)
    );

    $text .= sprintf(
        "\nAté 10 min/jogo: %.2f K • %.2f D • %.2f A",
        (float) ($early['kills_10_per_game'] ?? 0),
        (float) ($early['deaths_10_per_game'] ?? 0),
        (float) ($early['assists_10_per_game'] ?? 0)
    );

    $text .= sprintf(
        "\nParticipação/jogo: Dragão %.2f • Barão %.2f • Arauto %.2f",
        (float) ($objectives['dragon_per_game'] ?? 0),
        (float) ($objectives['baron_per_game'] ?? 0),
        (float) ($objectives['herald_per_game'] ?? 0)
    );

    return $text;
}


foreach ($players as $player) {
    if (!is_array($player)) {
        continue;
    }

    $riotId = is_array($player['riot_id'] ?? null) ? $player['riot_id'] : [];
    $name = (string) ($riotId['game_name'] ?? 'Jogador');
    $tag = (string) ($riotId['tag_line'] ?? '');

    $lolMatches = array_values(array_filter(
        is_array($player['lol_matches'] ?? null) ? $player['lol_matches'] : [],
        static fn (array $match): bool => (int) ($match['timestamp'] ?? 0) >= $cutoffMs
    ));

    $aramMatches = array_values(array_filter(
        is_array($player['aram_matches'] ?? null) ? $player['aram_matches'] : [],
        static fn (array $match): bool => (int) ($match['timestamp'] ?? 0) >= $cutoffMs
    ));

    $tftMatches = array_values(array_filter(
        is_array($player['tft_matches'] ?? null) ? $player['tft_matches'] : [],
        static fn (array $match): bool => (int) ($match['timestamp'] ?? 0) >= $cutoffMs
    ));

    $lol = Analytics::lolSummary($lolMatches, 100);
    $aram = Analytics::lolSummary($aramMatches, 100);
    $tft = Analytics::tftSummary($tftMatches, 100);
    $advanced = AdvancedAnalytics::lolAdvancedSummary($lolMatches, 100);

    $snapshots = is_array($player['rank_snapshots'] ?? null)
        ? $player['rank_snapshots']
        : [];

    $oldest = null;
    foreach (array_reverse($snapshots) as $snapshot) {
        if ((int) ($snapshot['timestamp'] ?? 0) >= time() - (7 * 86400)) {
            $oldest = $snapshot;
            break;
        }
    }

    $lolDirection = Analytics::rankDirection(
        is_array($oldest['lol_solo'] ?? null) ? $oldest['lol_solo'] : null,
        is_array($player['lol_solo'] ?? null) ? $player['lol_solo'] : null
    );

    $tftDirection = Analytics::rankDirection(
        is_array($oldest['tft'] ?? null) ? $oldest['tft'] : null,
        is_array($player['tft_rank'] ?? null) ? $player['tft_rank'] : null
    );

    $discord->send([
        'embeds' => [[
            'title' => '📈 Riot Weekly • ' . $name . ($tag !== '' ? '#' . $tag : ''),
            'description' => 'Últimos 7 dias • análise histórica, não estimativa de MMR.',
            'color' => 0x3498DB,
            'fields' => [
                [
                    'name' => '⚔️ LoL',
                    'value' => $lolDirection . ' '
                        . Analytics::rankLabel(
                            is_array($player['lol_solo'] ?? null)
                                ? $player['lol_solo']
                                : null
                        )
                        . (($lol['games'] ?? 0) > 0
                            ? sprintf(
                                "\n%d partidas • %s WR • KDA %.2f • DPM %.0f",
                                (int) ($lol['games'] ?? 0),
                                weeklyPct((float) ($lol['winrate'] ?? 0)),
                                (float) ($lol['avg_kda'] ?? 0),
                                (float) ($lol['damage_per_min'] ?? 0)
                            )
                            : "\nSem partidas ranqueadas registradas na semana."),
                    'inline' => false,
                ],
                [
                    'name' => '⏱️ LoL — camada 2',
                    'value' => weeklyAdvancedText($advanced),
                    'inline' => false,
                ],
                [
                    'name' => '🎲 ARAM',
                    'value' => (($aram['games'] ?? 0) > 0
                        ? sprintf(
                            "%d partidas • %s WR • KDA %.2f • DPM %.0f",
                            (int) ($aram['games'] ?? 0),
                            weeklyPct((float) ($aram['winrate'] ?? 0)),
                            (float) ($aram['avg_kda'] ?? 0),
                            (float) ($aram['damage_per_min'] ?? 0)
                        )
                        : 'Sem ARAM registrado na semana.'),
                    'inline' => false,
                ],
                [
                    'name' => '♟️ TFT',
                    'value' => $tftDirection . ' '
                        . Analytics::rankLabel(
                            is_array($player['tft_rank'] ?? null)
                                ? $player['tft_rank']
                                : null
                        )
                        . (($tft['games'] ?? 0) > 0
                            ? sprintf(
                                "\n%d partidas • Top4 %s • 1º %s • média %.2f",
                                (int) ($tft['games'] ?? 0),
                                weeklyPct((float) ($tft['top4_rate'] ?? 0)),
                                weeklyPct((float) ($tft['first_rate'] ?? 0)),
                                (float) ($tft['avg_placement'] ?? 0)
                            )
                            : "\nSem partidas ranqueadas registradas na semana."),
                    'inline' => false,
                ],
                [
                    'name' => '🧭 Tendência',
                    'value' => "LoL: {$lolDirection} • TFT: {$tftDirection}\n"
                        . '↑ subiu no ranking oficial • ↓ desceu • → estável',
                    'inline' => false,
                ],
            ],
            'footer' => [
                'text' => 'Riot Weekly • tendência baseada apenas em rank/LP oficial observado.',
            ],
            'timestamp' => date(DATE_ATOM),
        ]],
    ]);

    usleep(450_000);
}

echo "Riot Weekly enviado.\n";
