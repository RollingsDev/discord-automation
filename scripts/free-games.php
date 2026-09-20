<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\HttpClient;
use DiscordAutomation\Support\StateStore;

$config = require __DIR__ . '/../config/free-games.php';

$webhookUrl = trim((string) getenv('WEBHOOK_FREE_GAMES'));
$forceLatest = filter_var(
    getenv('FORCE_LATEST') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
);

if ($webhookUrl === '') {
    echo "WEBHOOK_FREE_GAMES não configurado. Execução ignorada.\n";
    exit(0);
}

$state = new StateStore(__DIR__ . '/../.state');
$stateKey = (string) ($config['state_key'] ?? 'free-games');
$maxPostsPerRun = max(1, (int) ($config['max_posts_per_run'] ?? 6));
$platformKeywords = is_array($config['platform_keywords'] ?? null)
    ? $config['platform_keywords']
    : [];

function truncateText(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, max(1, $limit - 1), 'UTF-8')) . '…';
}

function isHttpUrl(mixed $value): bool
{
    if (!is_string($value) || $value === '') {
        return false;
    }

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}

function platformAllowed(string $platforms, array $keywords): bool
{
    if ($keywords === []) {
        return true;
    }

    foreach ($keywords as $keyword) {
        if (is_string($keyword) && $keyword !== '' && mb_stripos($platforms, $keyword, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

function giveawayId(array $game): string
{
    if (isset($game['id']) && (string) $game['id'] !== '') {
        return (string) $game['id'];
    }

    return hash(
        'sha256',
        implode('|', [
            (string) ($game['title'] ?? ''),
            (string) ($game['open_giveaway_url'] ?? ''),
            (string) ($game['published_date'] ?? ''),
        ])
    );
}

function publishedTimestamp(array $game): int
{
    $value = trim((string) ($game['published_date'] ?? ''));

    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? 0 : $timestamp;
}

function formatEndDate(mixed $value): string
{
    if (!is_string($value) || trim($value) === '' || strtoupper(trim($value)) === 'N/A') {
        return 'Não informada';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return truncateText($value, 80);
    }

    return date('d/m/Y H:i', $timestamp);
}

function buildPayload(array $game, bool $isTest = false): array
{
    $title = truncateText((string) ($game['title'] ?? 'Jogo grátis'), 220);
    $platforms = truncateText((string) ($game['platforms'] ?? 'Não informado'), 900);
    $description = truncateText((string) ($game['description'] ?? ''), 1200);
    $worth = trim((string) ($game['worth'] ?? ''));
    $endDate = formatEndDate($game['end_date'] ?? null);

    $claimUrl = isHttpUrl($game['open_giveaway_url'] ?? null)
        ? (string) $game['open_giveaway_url']
        : '';

    $gamerPowerUrl = isHttpUrl($game['gamerpower_url'] ?? null)
        ? (string) $game['gamerpower_url']
        : 'https://www.gamerpower.com/';

    $embed = [
        'title' => ($isTest ? '🧪 TESTE • ' : '🎁 ') . $title,
        'description' => $description !== ''
            ? $description
            : 'Um jogo está disponível gratuitamente por tempo limitado.',
        'color' => 0x57F287,
        'fields' => [
            [
                'name' => '🎮 Plataforma',
                'value' => $platforms !== '' ? $platforms : 'Não informada',
                'inline' => true,
            ],
            [
                'name' => '💰 Valor informado',
                'value' => $worth !== '' && strtoupper($worth) !== 'N/A'
                    ? "{$worth} → **GRÁTIS**"
                    : '**GRÁTIS**',
                'inline' => true,
            ],
            [
                'name' => '⏳ Disponível até',
                'value' => $endDate,
                'inline' => true,
            ],
        ],
        'footer' => [
            'text' => 'Dados agregados por GamerPower • confira as condições antes de resgatar',
        ],
        'timestamp' => date(DATE_ATOM),
    ];

    if ($claimUrl !== '') {
        $embed['url'] = $claimUrl;
        $embed['fields'][] = [
            'name' => '🔗 Resgatar',
            'value' => "[Abrir oferta]({$claimUrl})",
            'inline' => true,
        ];
    }

    $embed['fields'][] = [
        'name' => '🔎 Fonte',
        'value' => "[GamerPower]({$gamerPowerUrl})",
        'inline' => true,
    ];

    if (isHttpUrl($game['thumbnail'] ?? null)) {
        $embed['thumbnail'] = ['url' => (string) $game['thumbnail']];
    } elseif (isHttpUrl($game['image'] ?? null)) {
        $embed['thumbnail'] = ['url' => (string) $game['image']];
    }

    return ['embeds' => [$embed]];
}

$response = HttpClient::getJson((string) $config['api_url']);

if (!array_is_list($response)) {
    echo "A GamerPower não retornou uma lista de jogos ativos. Nada a publicar.\n";
    exit(0);
}

$games = array_values(array_filter(
    $response,
    static function (mixed $game) use ($platformKeywords): bool {
        if (!is_array($game)) {
            return false;
        }

        $type = mb_strtolower(trim((string) ($game['type'] ?? '')), 'UTF-8');

        if ($type !== '' && $type !== 'game') {
            return false;
        }

        $platforms = (string) ($game['platforms'] ?? '');

        return platformAllowed($platforms, $platformKeywords);
    }
));

if ($games === []) {
    echo "Nenhum jogo grátis compatível com os filtros está ativo no momento.\n";
    exit(0);
}

usort(
    $games,
    static fn (array $a, array $b): int => publishedTimestamp($a) <=> publishedTimestamp($b)
);

$currentState = $state->read($stateKey, []);
$knownIds = array_values(array_unique(array_map(
    'strval',
    is_array($currentState['items'] ?? null) ? $currentState['items'] : []
)));

$allCurrentIds = array_values(array_unique(array_map(
    static fn (array $game): string => giveawayId($game),
    $games
)));

$discord = new DiscordWebhook($webhookUrl);

if (!isset($currentState['initialized_at'])) {
    $latest = $games[array_key_last($games)];

    if ($forceLatest) {
        $discord->send(buildPayload($latest, true));
        echo "Teste enviado com o jogo mais recente.\n";
    }

    $state->write($stateKey, [
        'initialized_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'items' => array_slice(array_reverse($allCurrentIds), 0, 300),
    ]);

    echo "Estado inicial criado com " . count($allCurrentIds) . " ofertas ativas. "
        . ($forceLatest ? "O item mais recente foi enviado como teste.\n" : "Nenhuma oferta antiga foi publicada.\n");

    exit(0);
}

if ($forceLatest) {
    $latest = $games[array_key_last($games)];
    $discord->send(buildPayload($latest, true));
    echo "Teste enviado com o jogo mais recente.\n";
    exit(0);
}

$newGames = array_values(array_filter(
    $games,
    static fn (array $game): bool => !in_array(giveawayId($game), $knownIds, true)
));

if ($newGames === []) {
    echo "Nenhum jogo grátis novo desde a última execução.\n";
    exit(0);
}

$toPublish = array_slice($newGames, 0, $maxPostsPerRun);
$publishedIds = [];

foreach ($toPublish as $game) {
    $discord->send(buildPayload($game));
    $publishedIds[] = giveawayId($game);

    // Pequeno intervalo para reduzir chance de rate limit quando várias ofertas aparecem juntas.
    usleep(400_000);
}

$state->write($stateKey, [
    'initialized_at' => (string) $currentState['initialized_at'],
    'updated_at' => date(DATE_ATOM),
    'items' => array_slice(
        array_values(array_unique(array_merge(array_reverse($publishedIds), $knownIds))),
        0,
        300
    ),
]);

echo count($publishedIds) . " jogo(s) grátis novo(s) publicado(s) no Discord.\n";

if (count($newGames) > count($toPublish)) {
    echo "Ainda há " . (count($newGames) - count($toPublish))
        . " oferta(s) pendente(s), que serão processadas na próxima execução.\n";
}
