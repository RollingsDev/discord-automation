<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\HttpClient;
use DiscordAutomation\Support\StateStore;

$config = require __DIR__ . '/../config/delivery-advisor.php';
$webhookUrl = trim((string) getenv('WEBHOOK_DELIVERY'));
$force = filter_var(getenv('FORCE_POST') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if ($webhookUrl === '') {
    echo "WEBHOOK_DELIVERY não configurado. Execução ignorada.\n";
    exit(0);
}

$timezone = (string) ($config['timezone'] ?? 'America/Sao_Paulo');
date_default_timezone_set($timezone);

$state = new StateStore(__DIR__ . '/../.state');
$stateKey = (string) ($config['state_key'] ?? 'delivery-advisor');
$hoursAhead = max(1, min(8, (int) ($config['hours_ahead'] ?? 4)));
$locations = is_array($config['locations'] ?? null) ? $config['locations'] : [];
$slot = date('Y-m-d-H');
$currentState = $state->read($stateKey, []);

if (!$force && ($currentState['last_slot'] ?? null) === $slot) {
    echo "Boletim de entregas deste horário já foi publicado.\n";
    exit(0);
}

function deliveryNumber(mixed $value, int $decimals = 0): string
{
    return is_numeric($value)
        ? number_format((float) $value, $decimals, ',', '.')
        : '—';
}

function deliveryWeatherLabel(int $code): string
{
    return match (true) {
        $code === 0 => 'céu limpo',
        in_array($code, [1, 2], true) => 'parcialmente nublado',
        $code === 3 => 'nublado',
        in_array($code, [45, 48], true) => 'neblina',
        in_array($code, [51, 53, 55, 56, 57], true) => 'garoa',
        in_array($code, [61, 63, 66, 80, 81], true) => 'chuva',
        in_array($code, [65, 67, 82], true) => 'chuva forte',
        in_array($code, [95, 96, 99], true) => 'tempestade',
        default => 'tempo variável',
    };
}

function deliveryCurrentIndex(array $times): int
{
    $target = date('Y-m-d\TH:00');

    foreach ($times as $index => $time) {
        if ((string) $time >= $target) {
            return (int) $index;
        }
    }

    return 0;
}

function deliveryWindowMax(array $values, int $start, int $length): float
{
    $slice = array_values(array_filter(
        array_slice($values, $start, $length),
        'is_numeric'
    ));

    return $slice === [] ? 0.0 : (float) max($slice);
}

function deliveryWindowSum(array $values, int $start, int $length): float
{
    $slice = array_values(array_filter(
        array_slice($values, $start, $length),
        'is_numeric'
    ));

    return $slice === [] ? 0.0 : (float) array_sum($slice);
}

$reports = [];

foreach ($locations as $location) {
    if (!is_array($location)) {
        continue;
    }

    $params = [
        'latitude' => (float) ($location['latitude'] ?? 0),
        'longitude' => (float) ($location['longitude'] ?? 0),
        'timezone' => $timezone,
        'forecast_days' => 2,
        'current' => implode(',', [
            'temperature_2m',
            'apparent_temperature',
            'relative_humidity_2m',
            'precipitation',
            'weather_code',
            'wind_speed_10m',
            'wind_gusts_10m',
        ]),
        'hourly' => implode(',', [
            'temperature_2m',
            'apparent_temperature',
            'precipitation_probability',
            'precipitation',
            'weather_code',
            'wind_gusts_10m',
        ]),
    ];

    $url = 'https://api.open-meteo.com/v1/forecast?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    try {
        $data = HttpClient::getJson($url);
    } catch (Throwable $e) {
        echo 'Falha em '
            . ($location['name'] ?? 'região')
            . ': '
            . $e->getMessage()
            . "\n";
        continue;
    }

    $current = is_array($data['current'] ?? null) ? $data['current'] : [];
    $hourly = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
    $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
    $index = deliveryCurrentIndex($times);
    $codes = array_slice(
        is_array($hourly['weather_code'] ?? null) ? $hourly['weather_code'] : [],
        $index,
        $hoursAhead
    );

    $reports[] = [
        'name' => (string) ($location['name'] ?? 'Zona Norte'),
        'temperature' => (float) ($current['temperature_2m'] ?? 0),
        'apparent' => (float) ($current['apparent_temperature'] ?? 0),
        'humidity' => (float) ($current['relative_humidity_2m'] ?? 0),
        'code' => (int) ($current['weather_code'] ?? 0),
        'rain_probability' => deliveryWindowMax(
            is_array($hourly['precipitation_probability'] ?? null)
                ? $hourly['precipitation_probability']
                : [],
            $index,
            $hoursAhead
        ),
        'rain_sum' => deliveryWindowSum(
            is_array($hourly['precipitation'] ?? null)
                ? $hourly['precipitation']
                : [],
            $index,
            $hoursAhead
        ),
        'gust' => max(
            (float) ($current['wind_gusts_10m'] ?? 0),
            deliveryWindowMax(
                is_array($hourly['wind_gusts_10m'] ?? null)
                    ? $hourly['wind_gusts_10m']
                    : [],
                $index,
                $hoursAhead
            )
        ),
        'max_apparent' => max(
            (float) ($current['apparent_temperature'] ?? 0),
            deliveryWindowMax(
                is_array($hourly['apparent_temperature'] ?? null)
                    ? $hourly['apparent_temperature']
                    : [],
                $index,
                $hoursAhead
            )
        ),
        'storm' => array_intersect(
            [95, 96, 99],
            array_map('intval', $codes)
        ) !== [],
    ];
}

if ($reports === []) {
    throw new RuntimeException(
        'Nenhuma região da Zona Norte retornou previsão utilizável.'
    );
}

$maxRainProbability = max(array_column($reports, 'rain_probability'));
$maxRainSum = max(array_column($reports, 'rain_sum'));
$maxGust = max(array_column($reports, 'gust'));
$maxApparent = max(array_column($reports, 'max_apparent'));
$minHumidity = min(array_column($reports, 'humidity'));
$hasStorm = in_array(true, array_column($reports, 'storm'), true);

$score = 100;
$alerts = [];

if ($hasStorm) {
    $score -= 45;
    $alerts[] = '⛈️ **Tempestade prevista:** priorize segurança e evite permanecer em áreas expostas.';
}

if ($maxRainSum >= 15) {
    $score -= 35;
    $alerts[] = '🌧️ **Chuva muito forte:** acumulado de até '
        . deliveryNumber($maxRainSum, 1)
        . ' mm nas próximas horas.';
} elseif ($maxRainSum >= 7) {
    $score -= 25;
    $alerts[] = '🌧️ **Chuva forte:** acumulado de até '
        . deliveryNumber($maxRainSum, 1)
        . ' mm nas próximas horas.';
} elseif ($maxRainProbability >= 80) {
    $score -= 15;
    $alerts[] = '☔ **Chuva muito provável:** pico de '
        . deliveryNumber($maxRainProbability)
        . '% na Zona Norte.';
} elseif ($maxRainProbability >= 60) {
    $score -= 8;
    $alerts[] = '🌦️ **Possibilidade relevante de chuva:** pico de '
        . deliveryNumber($maxRainProbability)
        . '%.';
}

if ($maxGust >= 60) {
    $score -= 25;
    $alerts[] = '💨 **Rajadas fortes:** até '
        . deliveryNumber($maxGust)
        . ' km/h; atenção extra para moto e bicicleta.';
} elseif ($maxGust >= 45) {
    $score -= 12;
    $alerts[] = '💨 **Vento moderado/forte:** rajadas de até '
        . deliveryNumber($maxGust)
        . ' km/h.';
}

if ($maxApparent >= 35) {
    $score -= 15;
    $alerts[] = '🥵 **Calor intenso:** sensação térmica pode chegar a '
        . deliveryNumber($maxApparent, 1)
        . '°C.';
}

if ($minHumidity <= 30) {
    $score -= 10;
    $alerts[] = '🏜️ **Umidade baixa:** mínimo próximo de '
        . deliveryNumber($minHumidity)
        . '%.';
}

$score = max(0, min(100, $score));

[$status, $color] = match (true) {
    $score >= 80 => ['🟢 Condições meteorológicas favoráveis', 0x57F287],
    $score >= 60 => ['🟡 Atenção moderada', 0xFEE75C],
    $score >= 40 => ['🟠 Cuidado ao rodar', 0xE67E22],
    default => ['🔴 Condições meteorológicas ruins', 0xED4245],
};

$regionLines = [];

foreach ($reports as $report) {
    $regionLines[] = sprintf(
        '**%s** — %s°C • chuva %s%% • rajadas %s km/h • %s',
        $report['name'],
        deliveryNumber($report['temperature'], 1),
        deliveryNumber($report['rain_probability']),
        deliveryNumber($report['gust']),
        deliveryWeatherLabel((int) $report['code'])
    );
}

if ($alerts === []) {
    $alerts[] = '✅ Nenhum alerta meteorológico relevante identificado para as próximas horas.';
}

$discord = new DiscordWebhook($webhookUrl);

$discord->send([
    'embeds' => [[
        'title' => '🏍️ Entregas SP • Zona Norte — ' . date('H:i'),
        'description' => $status
            . "\nMonitoramento de **Santana, Tucuruvi, Vila Maria e Casa Verde**.",
        'color' => $color,
        'fields' => [
            [
                'name' => '🗺️ Panorama por região',
                'value' => implode("\n", $regionLines),
                'inline' => false,
            ],
            [
                'name' => '⏱️ Próximas ' . $hoursAhead . ' horas',
                'value' => 'Maior chance de chuva: **'
                    . deliveryNumber($maxRainProbability)
                    . "%**\nMaior acumulado previsto: **"
                    . deliveryNumber($maxRainSum, 1)
                    . " mm**\nMaior rajada prevista: **"
                    . deliveryNumber($maxGust)
                    . " km/h**",
                'inline' => true,
            ],
            [
                'name' => '📊 Índice operacional',
                'value' => '**' . $score
                    . "/100**\nBaseado somente em condições meteorológicas.",
                'inline' => true,
            ],
            [
                'name' => '⚠️ Atenções',
                'value' => implode("\n", $alerts),
                'inline' => false,
            ],
        ],
        'footer' => [
            'text' => 'Dados: Open-Meteo • não estima demanda, ganhos nem substitui alertas oficiais',
        ],
        'timestamp' => date(DATE_ATOM),
    ]],
]);

$state->write($stateKey, [
    'updated_at' => date(DATE_ATOM),
    'last_slot' => $slot,
    'score' => $score,
]);

echo "Boletim de entregas da Zona Norte enviado com sucesso.\n";
