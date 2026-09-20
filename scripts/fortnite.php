<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
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

function appendFortniteNewsItems(array &$items, array $section, string $mode): void
{
    $motds = is_array($section['motds'] ?? null) ? $section['motds'] : [];
    $messages = is_array($section['messages'] ?? null) ? $section['messages'] : [];

    foreach ($motds as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $id = trim((string) ($entry['id'] ?? ''));
        $title = fortniteCompactText((string) ($entry['title'] ?? $entry['tabTitle'] ?? 'Fortnite News'), 220);
        $body = fortniteCompactText((string) ($entry['body'] ?? ''), 900);
        $image = trim((string) ($entry['image'] ?? $entry['tileImage'] ?? ''));

        if ($title === '') {
            continue;
        }

        $idBase = $id !== '' ? $id : hash('sha256', $mode . '|' . $title . '|' . $body);

        $items[] = [
            'id' => 'news:' . $mode . ':' . $idBase,
            'kind' => 'news',
            'title' => $title,
            'description' => $body !== '' ? $body : 'Nova mensagem detectada no feed do Fortnite.',
            'url' => 'https://www.fortnite.com/news?lang=pt-BR',
            'image' => $image,
            'mode' => $mode,
        ];
    }

    foreach ($messages as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $title = fortniteCompactText((string) ($entry['title'] ?? 'Fortnite News'), 220);
        $body = fortniteCompactText((string) ($entry['body'] ?? ''), 900);
        $image = trim((string) ($entry['image'] ?? ''));

        if ($title === '') {
            continue;
        }

        $items[] = [
            'id' => 'message:' . $mode . ':' . hash('sha256', $title . '|' . $body . '|' . $image),
            'kind' => 'news',
            'title' => $title,
            'description' => $body !== '' ? $body : 'Nova mensagem detectada no feed do Fortnite.',
            'url' => 'https://www.fortnite.com/news?lang=pt-BR',
            'image' => $image,
            'mode' => $mode,
        ];
    }
}

try {
    $news = HttpClient::getJson((string) $config['news_api_url']);
    $data = is_array($news['data'] ?? null) ? $news['data'] : [];

    foreach ([
        'br' => 'Battle Royale',
        'stw' => 'Salve o Mundo',
        'creative' => 'Criativo',
    ] as $key => $mode) {
        $section = is_array($data[$key] ?? null) ? $data[$key] : [];
        appendFortniteNewsItems($items, $section, $mode);
    }
} catch (Throwable $e) {
    echo "Falha ao consultar Fortnite-API news: {$e->getMessage()}\n";
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
                'image' => '',
                'mode' => 'Status',
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

    $embed = [
        'title' => ($test ? '🧪 TESTE • ' : '') . ($isStatus ? '🚨 Fortnite Status' : '🎮 Fortnite News'),
        'description' => '**' . $item['title'] . "**\n\n" . $item['description'],
        'url' => $item['url'],
        'color' => $isStatus ? 0xED4245 : 0x9B59B6,
        'fields' => [
            [
                'name' => '🎯 Categoria',
                'value' => (string) ($item['mode'] ?? 'Fortnite'),
                'inline' => true,
            ],
            [
                'name' => '🔗 Fonte',
                'value' => $isStatus
                    ? '[Epic Games Status](' . $item['url'] . ')'
                    : '[Fortnite](' . $item['url'] . ')',
                'inline' => true,
            ],
        ],
        'footer' => [
            'text' => $isStatus
                ? 'Epic Games Status'
                : 'Dados in-game: Fortnite-API.com',
        ],
        'timestamp' => date(DATE_ATOM),
    ];

    $image = trim((string) ($item['image'] ?? ''));

    if (preg_match('~^https?://~i', $image) === 1) {
        $embed['thumbnail'] = ['url' => $image];
    }

    $discord->send(['embeds' => [$embed]]);
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
