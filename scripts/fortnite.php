<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\HtmlLinkExtractor;
use DiscordAutomation\Support\HttpClient;
use DiscordAutomation\Support\StateStore;

$config = require __DIR__ . '/../config/fortnite.php';
$webhookUrl = trim((string) getenv('WEBHOOK_FORTNITE'));
$forceLatest = filter_var(getenv('FORCE_LATEST') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($webhookUrl === '') {
    echo "WEBHOOK_FORTNITE não configurado. Execução ignorada.\n";
    exit(0);
}

$state = new StateStore(__DIR__ . '/../.state');
$stateKey = (string) ($config['state_key'] ?? 'fortnite');
$maxPosts = max(1, (int) ($config['max_posts_per_run'] ?? 6));
$items = [];

function fortniteCompactText(string $text, int $limit = 900): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

    return mb_strlen($text, 'UTF-8') <= $limit
        ? $text
        : rtrim(mb_substr($text, 0, max(1, $limit - 1), 'UTF-8')) . '…';
}

try {
    $newsUrl = (string) $config['news_url'];
    $html = HttpClient::getText($newsUrl, ['Accept: text/html,application/xhtml+xml']);
    $links = HtmlLinkExtractor::extract($html, $newsUrl);
    $seenNews = [];

    foreach ($links as $link) {
        $url = (string) ($link['url'] ?? '');
        $title = fortniteCompactText((string) ($link['title'] ?? ''), 220);
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

        if (
            $url === ''
            || $title === ''
            || !is_array($parts)
            || ($parts['host'] ?? '') !== 'www.fortnite.com'
            || !str_starts_with($path, '/news/')
            || str_contains($path, '/news/tag/')
            || isset($seenNews[$url])
        ) {
            continue;
        }

        $seenNews[$url] = true;
        $items[] = [
            'id' => 'news:' . hash('sha256', $url),
            'kind' => 'news',
            'title' => $title,
            'description' => 'Nova publicação detectada nas notícias oficiais do Fortnite.',
            'url' => $url,
        ];

        if (count($seenNews) >= 30) {
            break;
        }
    }
} catch (Throwable $e) {
    echo "Falha ao consultar notícias do Fortnite: {$e->getMessage()}\n";
}

try {
    $rss = HttpClient::getText(
        (string) $config['status_rss_url'],
        ['Accept: application/rss+xml,application/xml,text/xml']
    );

    $previous = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_string($rss, 'SimpleXMLElement', LIBXML_NOCDATA);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if ($xml !== false && isset($xml->channel->item)) {
        $count = 0;

        foreach ($xml->channel->item as $entry) {
            $title = fortniteCompactText((string) $entry->title, 220);
            $description = fortniteCompactText((string) $entry->description, 800);
            $link = trim((string) $entry->link);
            $guid = trim((string) $entry->guid);
            $haystack = mb_strtolower($title . ' ' . $description, 'UTF-8');

            if (!str_contains($haystack, 'fortnite')) {
                continue;
            }

            $idBase = $guid !== '' ? $guid : ($link !== '' ? $link : $title);
            $items[] = [
                'id' => 'status:' . hash('sha256', $idBase),
                'kind' => 'status',
                'title' => $title,
                'description' => $description !== ''
                    ? $description
                    : 'Atualização do status dos serviços Epic/Fortnite.',
                'url' => $link !== '' ? $link : 'https://status.epicgames.com/',
            ];

            if (++$count >= 15) {
                break;
            }
        }
    }
} catch (Throwable $e) {
    echo "Falha ao consultar status Epic: {$e->getMessage()}\n";
}

$unique = [];
foreach ($items as $item) {
    $unique[$item['id']] = $item;
}
$items = array_values($unique);

if ($items === []) {
    throw new RuntimeException('Nenhuma fonte do Fortnite retornou conteúdo utilizável.');
}

$current = $state->read($stateKey, []);
$known = array_values(array_unique(array_map(
    'strval',
    is_array($current['items'] ?? null) ? $current['items'] : []
)));
$allIds = array_column($items, 'id');
$discord = new DiscordWebhook($webhookUrl);

$sendItem = static function (array $item, bool $test = false) use ($discord): void {
    $isStatus = $item['kind'] === 'status';

    $discord->send([
        'embeds' => [[
            'title' => ($test ? '🧪 TESTE • ' : '') . ($isStatus ? '🚨 Fortnite Status' : '🎮 Fortnite News'),
            'description' => '**' . $item['title'] . "**\n\n" . $item['description'],
            'url' => $item['url'],
            'color' => $isStatus ? 0xED4245 : 0x9B59B6,
            'fields' => [[
                'name' => '🔗 Fonte oficial',
                'value' => '[Abrir atualização](' . $item['url'] . ')',
                'inline' => false,
            ]],
            'footer' => ['text' => $isStatus ? 'Epic Games Status' : 'Fortnite.com'],
            'timestamp' => date(DATE_ATOM),
        ]],
    ]);
};

if (!isset($current['initialized_at'])) {
    if ($forceLatest) {
        $sendItem($items[0], true);
        echo "Teste Fortnite enviado.\n";
    }

    $state->write($stateKey, [
        'initialized_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'items' => array_slice($allIds, 0, 300),
    ]);

    echo "Estado inicial Fortnite criado sem publicar conteúdo antigo.\n";
    exit(0);
}

if ($forceLatest) {
    $sendItem($items[0], true);
    echo "Teste Fortnite enviado.\n";
    exit(0);
}

$new = array_values(array_filter(
    $items,
    static fn (array $item): bool => !in_array($item['id'], $known, true)
));

if ($new === []) {
    echo "Nenhuma novidade do Fortnite.\n";
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
    'items' => array_slice(
        array_values(array_unique(array_merge($published, $known))),
        0,
        300
    ),
]);

echo count($published) . " atualização(ões) Fortnite publicada(s).\n";
