<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\HtmlLinkExtractor;
use DiscordAutomation\Support\HttpClient;
use DiscordAutomation\Support\StateStore;

$config = require __DIR__ . '/../config/riot-patches.php';
$webhookUrl = trim((string) getenv('WEBHOOK_RIOT'));
$forceLatest = filter_var(getenv('FORCE_LATEST') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($webhookUrl === '') {
    echo "WEBHOOK_RIOT não configurado. Execução ignorada.\n";
    exit(0);
}

$state = new StateStore(__DIR__ . '/../.state');
$stateKey = (string) ($config['state_key'] ?? 'riot-patches');
$maxPosts = max(1, (int) ($config['max_posts_per_run'] ?? 4));
$sources = is_array($config['sources'] ?? null) ? $config['sources'] : [];

function cleanTitle(string $title): string
{
    $title = trim((string) preg_replace('/\s+/u', ' ', $title));

    if (mb_strlen($title, 'UTF-8') > 220) {
        $title = rtrim(mb_substr($title, 0, 219, 'UTF-8')) . '…';
    }

    return $title;
}

function isPatchLike(string $title, string $url): bool
{
    $haystack = mb_strtolower($title . ' ' . $url, 'UTF-8');

    return str_contains($haystack, 'atualiza')
        || str_contains($haystack, 'patch')
        || str_contains($haystack, 'notas-da-atualizacao')
        || str_contains($haystack, 'teamfight-tactics-patch');
}

$items = [];

foreach ($sources as $source) {
    if (!is_array($source) || empty($source['url'])) {
        continue;
    }

    $sourceUrl = (string) $source['url'];
    $host = (string) ($source['host'] ?? '');
    $pathContains = (string) ($source['path_contains'] ?? '/news/game-updates/');
    $game = (string) ($source['game'] ?? 'Riot Games');
    $icon = (string) ($source['icon'] ?? '🎮');

    try {
        $html = HttpClient::getText($sourceUrl, ['Accept: text/html,application/xhtml+xml']);
        $links = HtmlLinkExtractor::extract($html, $sourceUrl);
    } catch (Throwable $e) {
        echo "Falha ao consultar {$game}: {$e->getMessage()}\n";
        continue;
    }

    $count = 0;
    foreach ($links as $link) {
        $url = (string) ($link['url'] ?? '');
        $title = cleanTitle((string) ($link['title'] ?? ''));
        $parts = parse_url($url);

        if (
            $url === ''
            || $title === ''
            || !is_array($parts)
            || ($host !== '' && ($parts['host'] ?? '') !== $host)
            || !str_contains((string) ($parts['path'] ?? ''), $pathContains)
            || rtrim($url, '/') === rtrim($sourceUrl, '/')
            || !isPatchLike($title, $url)
        ) {
            continue;
        }

        $items[] = [
            'id' => hash('sha256', $game . '|' . $url),
            'game' => $game,
            'icon' => $icon,
            'title' => $title,
            'url' => $url,
        ];

        $count++;
        if ($count >= 20) {
            break;
        }
    }
}

$unique = [];
foreach ($items as $item) {
    $unique[$item['id']] = $item;
}
$items = array_values($unique);

if ($items === []) {
    throw new RuntimeException('Nenhuma nota de atualização da Riot foi encontrada nas páginas oficiais.');
}

$current = $state->read($stateKey, []);
$known = array_values(array_unique(array_map('strval', is_array($current['items'] ?? null) ? $current['items'] : [])));
$allIds = array_column($items, 'id');
$discord = new DiscordWebhook($webhookUrl);

$sendItem = static function (array $item, bool $test = false) use ($discord): void {
    $discord->send([
        'embeds' => [[
            'title' => ($test ? '🧪 TESTE • ' : '') . $item['icon'] . ' ' . $item['title'],
            'url' => $item['url'],
            'description' => '**' . $item['game'] . "**\nNova nota de atualização detectada no site oficial da Riot.",
            'color' => $item['game'] === 'Teamfight Tactics' ? 0xC8962E : 0x0AC8B9,
            'fields' => [[
                'name' => '📖 Ler atualização',
                'value' => '[Abrir página oficial](' . $item['url'] . ')',
                'inline' => false,
            ]],
            'footer' => ['text' => 'Riot Updates • fonte oficial'],
            'timestamp' => date(DATE_ATOM),
        ]],
    ]);
};

if (!isset($current['initialized_at'])) {
    if ($forceLatest) {
        $sentGames = [];
        foreach ($items as $item) {
            if (isset($sentGames[$item['game']])) {
                continue;
            }
            $sendItem($item, true);
            $sentGames[$item['game']] = true;
            usleep(350_000);
        }
        echo "Teste Riot enviado.\n";
    }

    $state->write($stateKey, [
        'initialized_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'items' => array_slice($allIds, 0, 300),
    ]);

    echo "Estado inicial Riot criado sem publicar patches antigos.\n";
    exit(0);
}

if ($forceLatest) {
    $sentGames = [];
    foreach ($items as $item) {
        if (isset($sentGames[$item['game']])) {
            continue;
        }
        $sendItem($item, true);
        $sentGames[$item['game']] = true;
        usleep(350_000);
    }
    echo "Teste Riot enviado.\n";
    exit(0);
}

$new = array_values(array_filter($items, static fn (array $item): bool => !in_array($item['id'], $known, true)));

if ($new === []) {
    echo "Nenhum patch novo de LoL/TFT.\n";
    exit(0);
}

$published = [];
foreach (array_slice($new, 0, $maxPosts) as $item) {
    $sendItem($item);
    $published[] = $item['id'];
    usleep(350_000);
}

$state->write($stateKey, [
    'initialized_at' => (string) $current['initialized_at'],
    'updated_at' => date(DATE_ATOM),
    'items' => array_slice(array_values(array_unique(array_merge($published, $known))), 0, 300),
]);

echo count($published) . " atualização(ões) Riot publicada(s).\n";
