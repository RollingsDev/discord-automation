<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\HttpClient;
use DiscordAutomation\Support\StateStore;

$config = require __DIR__ . '/../config/security-watch.php';
$webhookUrl = trim((string) getenv('WEBHOOK_SECURITY'));
$forceLatest = filter_var(getenv('FORCE_LATEST') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($webhookUrl === '') {
    echo "WEBHOOK_SECURITY não configurado. Execução ignorada.\n";
    exit(0);
}

$state = new StateStore(__DIR__ . '/../.state');
$stateKey = (string) ($config['state_key'] ?? 'security-watch');
$maxPosts = max(1, (int) ($config['max_posts_per_run'] ?? 8));
$queries = is_array($config['queries'] ?? null) ? $config['queries'] : [];
$advisories = [];

function securityColor(string $severity): int
{
    return match (strtolower($severity)) {
        'critical' => 0x8B0000,
        'high' => 0xED4245,
        'medium' => 0xFEE75C,
        default => 0x95A5A6,
    };
}

function packageSummary(array $advisory): string
{
    $lines = [];

    foreach (($advisory['vulnerabilities'] ?? []) as $vulnerability) {
        if (!is_array($vulnerability)) {
            continue;
        }

        $package = is_array($vulnerability['package'] ?? null)
            ? $vulnerability['package']
            : [];

        $name = trim((string) ($package['name'] ?? 'pacote desconhecido'));
        $range = trim((string) ($vulnerability['vulnerable_version_range'] ?? ''));
        $patched = is_array($vulnerability['first_patched_version'] ?? null)
            ? trim((string) ($vulnerability['first_patched_version']['identifier'] ?? ''))
            : '';

        $line = '**' . $name . '**';

        if ($range !== '') {
            $line .= "\nAfetado: `{$range}`";
        }

        $line .= $patched !== ''
            ? "\nCorreção: `{$patched}`"
            : "\nCorreção: ainda não informada";

        $lines[] = $line;
    }

    return $lines === []
        ? 'Pacote afetado não informado.'
        : implode("\n\n", array_slice($lines, 0, 4));
}

foreach ($queries as $query) {
    if (!is_array($query) || empty($query['ecosystem'])) {
        continue;
    }

    $ecosystem = (string) $query['ecosystem'];
    $packages = array_values(array_filter(
        is_array($query['packages'] ?? null) ? $query['packages'] : [],
        'is_string'
    ));

    $params = [
        'ecosystem' => $ecosystem,
        'per_page' => 100,
    ];

    if ($packages !== []) {
        $params['affects'] = implode(',', $packages);
    }

    $url = 'https://api.github.com/advisories?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    try {
        $result = HttpClient::getJson($url, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]);
    } catch (Throwable $e) {
        echo "Falha ao consultar advisories {$ecosystem}: {$e->getMessage()}\n";
        continue;
    }

    if (!array_is_list($result)) {
        continue;
    }

    foreach ($result as $advisory) {
        if (!is_array($advisory) || empty($advisory['ghsa_id'])) {
            continue;
        }

        $severity = strtolower((string) ($advisory['severity'] ?? 'unknown'));

        if (!in_array($severity, ['high', 'critical'], true)) {
            continue;
        }

        $advisories[(string) $advisory['ghsa_id']] = $advisory;
    }
}

$items = array_values($advisories);

usort(
    $items,
    static fn (array $a, array $b): int
        => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? ''))
);

$current = $state->read($stateKey, []);
$known = array_values(array_unique(array_map(
    'strval',
    is_array($current['items'] ?? null) ? $current['items'] : []
)));
$allIds = array_values(array_map(
    static fn (array $item): string => (string) $item['ghsa_id'],
    $items
));
$discord = new DiscordWebhook($webhookUrl);

$sendItem = static function (array $item, bool $test = false) use ($discord): void {
    $severity = strtolower((string) ($item['severity'] ?? 'unknown'));
    $ghsa = (string) ($item['ghsa_id'] ?? 'GHSA');
    $cve = trim((string) ($item['cve_id'] ?? ''));
    $summary = trim((string) ($item['summary'] ?? 'Vulnerabilidade de segurança detectada.'));
    $url = (string) ($item['html_url'] ?? ('https://github.com/advisories/' . $ghsa));
    $published = trim((string) ($item['published_at'] ?? ''));
    $publishedText = $published !== ''
        ? date('d/m/Y H:i', strtotime($published) ?: time())
        : 'Não informada';

    $discord->send([
        'embeds' => [[
            'title' => ($test ? '🧪 TESTE • ' : '🛡️ ')
                . strtoupper($severity)
                . ' • '
                . $ghsa,
            'url' => $url,
            'description' => '**' . $summary . '**',
            'color' => securityColor($severity),
            'fields' => [
                [
                    'name' => '📦 Pacotes afetados',
                    'value' => packageSummary($item),
                    'inline' => false,
                ],
                [
                    'name' => '🆔 Identificador',
                    'value' => $cve !== '' ? "{$ghsa}\n{$cve}" : $ghsa,
                    'inline' => true,
                ],
                [
                    'name' => '📅 Publicado',
                    'value' => $publishedText,
                    'inline' => true,
                ],
                [
                    'name' => '🔗 Detalhes',
                    'value' => '[GitHub Advisory](' . $url . ')',
                    'inline' => false,
                ],
            ],
            'footer' => ['text' => 'Security Watch • GitHub Advisory Database'],
            'timestamp' => date(DATE_ATOM),
        ]],
    ]);
};

if (!isset($current['initialized_at'])) {
    if ($forceLatest && $items !== []) {
        $sendItem($items[0], true);
        echo "Teste Security Watch enviado.\n";
    }

    $state->write($stateKey, [
        'initialized_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'items' => array_slice($allIds, 0, 500),
    ]);

    echo "Estado inicial do Security Watch criado. Nenhum advisory antigo foi publicado.\n";
    exit(0);
}

if ($forceLatest) {
    if ($items === []) {
        echo "Nenhum advisory high/critical encontrado para testar.\n";
        exit(0);
    }

    $sendItem($items[0], true);
    echo "Teste Security Watch enviado.\n";
    exit(0);
}

$new = array_values(array_filter(
    $items,
    static fn (array $item): bool
        => !in_array((string) $item['ghsa_id'], $known, true)
));

if ($new === []) {
    echo "Nenhum advisory novo high/critical para o stack monitorado.\n";
    exit(0);
}

$published = [];

foreach (array_slice($new, 0, $maxPosts) as $item) {
    $sendItem($item);
    $published[] = (string) $item['ghsa_id'];
    usleep(350_000);
}

$state->write($stateKey, [
    'initialized_at' => (string) $current['initialized_at'],
    'updated_at' => date(DATE_ATOM),
    'items' => array_slice(
        array_values(array_unique(array_merge($published, $known))),
        0,
        500
    ),
]);

echo count($published) . " advisory(ies) publicado(s).\n";
