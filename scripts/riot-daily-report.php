<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Riot\Analytics;
use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\StateStore;

$webhookUrl = trim((string) getenv('WEBHOOK_RIOT'));

if ($webhookUrl === '') {
    throw new RuntimeException('WEBHOOK_RIOT não configurado.');
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

    $tft = Analytics::tftSummary(
        is_array($player['tft_matches'] ?? null) ? $player['tft_matches'] : [],
        20
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
