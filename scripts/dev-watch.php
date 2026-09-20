<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\HttpClient;
use DiscordAutomation\Support\StateStore;

$config = require __DIR__ . '/../config/dev-watch.php';
$webhookUrl = trim((string) getenv('WEBHOOK_DEV'));
$forceLatest = filter_var(getenv('FORCE_LATEST') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($webhookUrl === '') {
    echo "WEBHOOK_DEV não configurado. Execução ignorada.\n";
    exit(0);
}

$state = new StateStore(__DIR__ . '/../.state');
$stateKey = (string) ($config['state_key'] ?? 'dev-watch');
$maxPosts = max(1, (int) ($config['max_posts_per_run'] ?? 8));
$projects = is_array($config['projects'] ?? null) ? $config['projects'] : [];

$items = [];

foreach ($projects as $project) {
    if (!is_array($project) || empty($project['repo'])) {
        continue;
    }

    $repo = (string) $project['repo'];
    $name = (string) ($project['name'] ?? $repo);
    $url = 'https://api.github.com/repos/' . rawurlencode(explode('/', $repo)[0]) . '/'
        . rawurlencode(explode('/', $repo)[1] ?? '') . '/tags?per_page=5';

    try {
        $tags = HttpClient::getJson($url, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]);
    } catch (Throwable $e) {
        echo "Falha ao consultar {$repo}: {$e->getMessage()}\n";
        continue;
    }

    if (!array_is_list($tags)) {
        continue;
    }

    foreach ($tags as $tag) {
        if (!is_array($tag) || empty($tag['name'])) {
            continue;
        }

        $version = (string) $tag['name'];
        $items[] = [
            'id' => $repo . '@' . $version,
            'project' => $name,
            'repo' => $repo,
            'version' => $version,
            'url' => 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode($version),
        ];
    }
}

if ($items === []) {
    throw new RuntimeException('Nenhum projeto do Dev Watch pôde ser consultado.');
}

$current = $state->read($stateKey, []);
$known = array_values(array_unique(array_map('strval', is_array($current['items'] ?? null) ? $current['items'] : [])));
$allIds = array_values(array_unique(array_column($items, 'id')));
$discord = new DiscordWebhook($webhookUrl);

$sendItem = static function (array $item, bool $test = false) use ($discord): void {
    $title = ($test ? '🧪 TESTE • ' : '🚀 ') . $item['project'] . ' ' . $item['version'];
    $discord->send([
        'embeds' => [[
            'title' => $title,
            'url' => $item['url'],
            'description' => 'Nova versão/tag detectada no repositório oficial.',
            'color' => 0x5865F2,
            'fields' => [
                ['name' => '📦 Projeto', 'value' => $item['project'], 'inline' => true],
                ['name' => '🏷️ Versão', 'value' => '`' . $item['version'] . '`', 'inline' => true],
                ['name' => '🔗 Repositório', 'value' => 'https://github.com/' . $item['repo'], 'inline' => false],
            ],
            'footer' => ['text' => 'Dev Watch • GitHub'],
            'timestamp' => date(DATE_ATOM),
        ]],
    ]);
};

if (!isset($current['initialized_at'])) {
    if ($forceLatest) {
        $sendItem($items[0], true);
        echo "Teste do Dev Watch enviado.\n";
    }

    $state->write($stateKey, [
        'initialized_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'items' => array_slice($allIds, 0, 300),
    ]);

    echo "Estado inicial do Dev Watch criado sem publicar versões antigas.\n";
    exit(0);
}

if ($forceLatest) {
    $sendItem($items[0], true);
    echo "Teste do Dev Watch enviado.\n";
    exit(0);
}

$new = array_values(array_filter($items, static fn (array $item): bool => !in_array($item['id'], $known, true)));

if ($new === []) {
    echo "Nenhuma nova versão detectada.\n";
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

echo count($published) . " atualização(ões) de desenvolvimento publicada(s).\n";
