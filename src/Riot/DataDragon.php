<?php

declare(strict_types=1);

namespace DiscordAutomation\Riot;

use DiscordAutomation\Support\HttpClient;
use RuntimeException;

final class DataDragon
{
    private ?array $champions = null;

    /** @return array<int, string> */
    public function championNames(): array
    {
        if ($this->champions !== null) {
            return $this->champions;
        }

        $versions = HttpClient::getJson(
            'https://ddragon.leagueoflegends.com/api/versions.json'
        );

        $version = trim((string) ($versions[0] ?? ''));

        if ($version === '') {
            throw new RuntimeException('Data Dragon não retornou uma versão válida.');
        }

        $payload = HttpClient::getJson(
            'https://ddragon.leagueoflegends.com/cdn/'
            . rawurlencode($version)
            . '/data/pt_BR/champion.json'
        );

        $data = is_array($payload['data'] ?? null)
            ? $payload['data']
            : [];

        $map = [];

        foreach ($data as $champion) {
            if (!is_array($champion)) {
                continue;
            }

            $id = (int) ($champion['key'] ?? 0);
            $name = trim((string) ($champion['name'] ?? ''));

            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        if ($map === []) {
            throw new RuntimeException('Data Dragon não retornou campeões utilizáveis.');
        }

        $this->champions = $map;

        return $map;
    }
}
