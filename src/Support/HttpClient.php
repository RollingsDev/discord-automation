<?php

declare(strict_types=1);

namespace DiscordAutomation\Support;

use JsonException;
use RuntimeException;

final class HttpClient
{
    public static function getText(string $url, array $headers = []): string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar o cURL.');
        }

        $defaultHeaders = [
            'User-Agent: discord-automation/1.0',
            'Cache-Control: no-cache',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                sprintf('Erro HTTP ao consultar %s. Status %d. %s', $url, $status, $error)
            );
        }

        return (string) $response;
    }

    public static function getJson(string $url, array $headers = []): array
    {
        $response = self::getText(
            $url,
            array_merge(['Accept: application/json'], $headers)
        );

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('A resposta HTTP não contém JSON válido.', 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('A resposta JSON possui formato inesperado.');
        }

        return $data;
    }
}
