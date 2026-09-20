<?php

declare(strict_types=1);

namespace DiscordAutomation\Riot;

use DiscordAutomation\Support\HttpClient;
use RuntimeException;

final class DataDragon
{
    private ?string $version = null;
    private ?array $champions = null;
    private ?array $items = null;
    private ?array $completedItems = null;

    /** @return array<int, string> */
    public function championNames(): array
    {
        if ($this->champions !== null) {
            return $this->champions;
        }

        $payload = HttpClient::getJson(
            'https://ddragon.leagueoflegends.com/cdn/'
            . rawurlencode($this->version())
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

    /** @return array<int, string> */
    public function itemNames(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $payload = HttpClient::getJson(
            'https://ddragon.leagueoflegends.com/cdn/'
            . rawurlencode($this->version())
            . '/data/pt_BR/item.json'
        );

        $data = is_array($payload['data'] ?? null)
            ? $payload['data']
            : [];

        $map = [];

        foreach ($data as $id => $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = (int) $id;
            $name = trim((string) ($item['name'] ?? ''));

            if ($itemId > 0 && $name !== '') {
                $map[$itemId] = $name;
            }
        }

        if ($map === []) {
            throw new RuntimeException('Data Dragon não retornou itens utilizáveis.');
        }

        $this->items = $map;

        return $map;
    }

    /** @return array<int, bool> */
    public function completedItemIds(): array
    {
        if ($this->completedItems !== null) {
            return $this->completedItems;
        }

        $payload = HttpClient::getJson(
            'https://ddragon.leagueoflegends.com/cdn/'
            . rawurlencode($this->version())
            . '/data/pt_BR/item.json'
        );

        $data = is_array($payload['data'] ?? null)
            ? $payload['data']
            : [];

        $result = [];

        foreach ($data as $id => $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = (int) $id;
            $name = trim((string) ($item['name'] ?? ''));
            $purchasable = (bool) ($item['gold']['purchasable'] ?? true);
            $totalGold = (int) ($item['gold']['total'] ?? 0);
            $into = is_array($item['into'] ?? null) ? $item['into'] : [];
            $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];

            $blockedTag = count(array_intersect(
                ['Consumable', 'Trinket'],
                $tags
            )) > 0;

            if (
                $itemId > 0
                && $name !== ''
                && $purchasable
                && $into === []
                && !$blockedTag
                && $totalGold >= 800
            ) {
                $result[$itemId] = true;
            }
        }

        $this->completedItems = $result;

        return $result;
    }

    private function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $versions = HttpClient::getJson(
            'https://ddragon.leagueoflegends.com/api/versions.json'
        );

        $version = trim((string) ($versions[0] ?? ''));

        if ($version === '') {
            throw new RuntimeException('Data Dragon não retornou uma versão válida.');
        }

        $this->version = $version;

        return $version;
    }
}
