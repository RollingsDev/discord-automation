<?php

declare(strict_types=1);

namespace DiscordAutomation\Riot;

use JsonException;
use RuntimeException;

final class RiotClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $region = 'americas',
        private readonly string $platform = 'br1',
    ) {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('RIOT_API_KEY não configurada.');
        }
    }

    public function accountByRiotId(string $gameName, string $tagLine): array
    {
        return $this->regional(
            '/riot/account/v1/accounts/by-riot-id/'
            . rawurlencode($gameName)
            . '/'
            . rawurlencode($tagLine)
        );
    }

    public function lolRanks(string $puuid): array
    {
        return $this->platform(
            '/lol/league/v4/entries/by-puuid/' . rawurlencode($puuid)
        );
    }

    public function tftRanks(string $puuid): array
    {
        return $this->platform(
            '/tft/league/v1/by-puuid/' . rawurlencode($puuid)
        );
    }

    public function lolMatchIds(string $puuid, int $count = 10): array
    {
        return $this->regional(
            '/lol/match/v5/matches/by-puuid/' . rawurlencode($puuid) . '/ids',
            [
                'start' => 0,
                'count' => max(1, min(100, $count)),
                'type' => 'ranked',
            ]
        );
    }

    public function aramMatchIds(string $puuid, int $count = 10): array
    {
        return $this->regional(
            '/lol/match/v5/matches/by-puuid/' . rawurlencode($puuid) . '/ids',
            [
                'start' => 0,
                'count' => max(1, min(100, $count)),
                'queue' => 450,
            ]
        );
    }

    public function championMasteries(string $puuid): array
    {
        return $this->platform(
            '/lol/champion-mastery/v4/champion-masteries/by-puuid/'
            . rawurlencode($puuid)
        );
    }

    public function lolMatch(string $matchId): array
    {
        return $this->regional(
            '/lol/match/v5/matches/' . rawurlencode($matchId)
        );
    }

    public function tftMatchIds(string $puuid, int $count = 10): array
    {
        return $this->regional(
            '/tft/match/v1/matches/by-puuid/' . rawurlencode($puuid) . '/ids',
            [
                'start' => 0,
                'count' => max(1, min(100, $count)),
            ]
        );
    }

    public function tftMatch(string $matchId): array
    {
        return $this->regional(
            '/tft/match/v1/matches/' . rawurlencode($matchId)
        );
    }

    private function regional(string $path, array $query = []): array
    {
        return $this->request(
            'https://' . $this->region . '.api.riotgames.com' . $path,
            $query
        );
    }

    private function platform(string $path, array $query = []): array
    {
        return $this->request(
            'https://' . $this->platform . '.api.riotgames.com' . $path,
            $query
        );
    }

    private function request(string $url, array $query = []): array
    {
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $ch = curl_init($url);

            if ($ch === false) {
                throw new RuntimeException('Não foi possível iniciar cURL para a Riot API.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'X-Riot-Token: ' . $this->apiKey,
                    'User-Agent: discord-automation-riot/1.0',
                ],
                CURLOPT_HEADER => true,
            ]);

            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                if ($attempt < 5) {
                    sleep($attempt);
                    continue;
                }

                throw new RuntimeException('Falha de rede ao consultar a Riot API: ' . $error);
            }

            $headers = substr((string) $raw, 0, $headerSize);
            $body = substr((string) $raw, $headerSize);

            if ($status >= 200 && $status < 300) {
                try {
                    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException('Riot API retornou JSON inválido.', 0, $e);
                }

                if (!is_array($data)) {
                    throw new RuntimeException('Riot API retornou formato inesperado.');
                }

                usleep(120_000);

                return $data;
            }

            if ($status === 429 && $attempt < 5) {
                $retryAfter = 2;

                if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $match) === 1) {
                    $retryAfter = max(1, (int) $match[1]);
                }

                sleep($retryAfter + 1);
                continue;
            }

            if ($status === 403) {
                throw new RuntimeException(
                    'Riot API recusou a chave (HTTP 403). A Development Key pode ter expirado.'
                );
            }

            if ($status === 404) {
                throw new RuntimeException('Recurso Riot não encontrado (HTTP 404): ' . $url);
            }

            if ($status >= 500 && $attempt < 5) {
                sleep($attempt);
                continue;
            }

            throw new RuntimeException(
                sprintf('Erro Riot API HTTP %d em %s.', $status, $url)
            );
        }

        throw new RuntimeException('Número máximo de tentativas à Riot API excedido.');
    }
}
