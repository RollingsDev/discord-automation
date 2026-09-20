<?php

declare(strict_types=1);

namespace DiscordAutomation\Tft;

use DiscordAutomation\Support\HttpClient;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class TftMetaSource
{
    private const COMPS_URL = 'https://tactics.tools/pt/team-compositions/all';
    private const ITEMS_URL = 'https://tactics.tools/pt/items';
    private const CHAMPIONS_URL = 'https://raw.communitydragon.org/latest/plugins/rcp-be-lol-game-data/global/pt_br/v1/tftchampions.json';
    private const TFT_ITEMS_URL = 'https://raw.communitydragon.org/latest/plugins/rcp-be-lol-game-data/global/pt_br/v1/tftitems.json';

    public function fetch(): array
    {
        $championNames = $this->entityNames(
            HttpClient::getJson(self::CHAMPIONS_URL),
            'champion'
        );

        $itemNames = $this->entityNames(
            HttpClient::getJson(self::TFT_ITEMS_URL),
            'item'
        );

        $compsHtml = HttpClient::getText(
            self::COMPS_URL,
            [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.7',
            ]
        );

        $itemsHtml = HttpClient::getText(
            self::ITEMS_URL,
            [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.7',
            ]
        );

        [$patch, $rank] = $this->pageContext($compsHtml);

        $comps = $this->parseComps($compsHtml, $championNames, $itemNames);
        $items = $this->parseItems($itemsHtml, $championNames, $itemNames);

        if ($comps === []) {
            throw new RuntimeException('Não foi possível extrair composições do meta TFT.');
        }

        return [
            'source' => 'tactics.tools',
            'source_url' => self::COMPS_URL,
            'items_url' => self::ITEMS_URL,
            'patch' => $patch,
            'rank_filter' => $rank,
            'comps' => $comps,
            'items' => $items,
            'fetched_at' => date(DATE_ATOM),
        ];
    }

    private function parseComps(
        string $html,
        array $championNames,
        array $itemNames
    ): array {
        [$dom, $xpath] = $this->dom($html);
        $nodes = $xpath->query('//text()[normalize-space(.)="Play Rate"]');

        if ($nodes === false) {
            return [];
        }

        $seen = [];
        $rows = [];

        foreach ($nodes as $node) {
            $card = $this->findMetricContainer($node, 'Play Rate', 3);

            if (!$card instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizeText($card->textContent ?? '');
            $metrics = $this->metrics($text);

            if (
                $metrics['play_rate'] === null
                || $metrics['place'] === null
                || $metrics['top4'] === null
                || $metrics['win'] === null
            ) {
                continue;
            }

            $corePosition = mb_stripos($text, 'Core');
            $name = $corePosition !== false
                ? trim(mb_substr($text, 0, $corePosition))
                : '';

            if ($name === '' || mb_strlen($name) > 160) {
                $name = $this->firstUsefulText($card);
            }

            if ($name === '') {
                continue;
            }

            $images = $this->imageAlts($card);
            [$units, $unitItems] = $this->classifyImages(
                $images,
                $championNames,
                $itemNames
            );

            $key = $this->normalizeName($name);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $rows[] = [
                'name' => $name,
                'play_rate' => $metrics['play_rate'],
                'avg_place' => $metrics['place'],
                'top4_rate' => $metrics['top4'] / 100,
                'win_rate' => $metrics['win'] / 100,
                'units' => $units,
                'unit_items' => $unitItems,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int
                => [$a['avg_place'], -$a['win_rate']]
                <=> [$b['avg_place'], -$b['win_rate']]
        );

        return array_slice($rows, 0, 12);
    }

    private function parseItems(
        string $html,
        array $championNames,
        array $itemNames
    ): array {
        [$dom, $xpath] = $this->dom($html);
        $candidates = [];

        $rows = $xpath->query('//tr');

        if ($rows !== false) {
            foreach ($rows as $row) {
                if (!$row instanceof DOMElement) {
                    continue;
                }

                $parsed = $this->parseItemContainer($row, $championNames, $itemNames);
                if ($parsed !== null) {
                    $candidates[] = $parsed;
                }
            }
        }

        if ($candidates === []) {
            $images = $xpath->query('//img[@alt]');

            if ($images !== false) {
                foreach ($images as $image) {
                    if (!$image instanceof DOMElement) {
                        continue;
                    }

                    $alt = trim($image->getAttribute('alt'));
                    $normalized = $this->normalizeName($alt);

                    if (!isset($itemNames[$normalized])) {
                        continue;
                    }

                    $container = $this->findNumericContainer($image);

                    if (!$container instanceof DOMElement) {
                        continue;
                    }

                    $parsed = $this->parseItemContainer(
                        $container,
                        $championNames,
                        $itemNames
                    );

                    if ($parsed !== null) {
                        $candidates[] = $parsed;
                    }
                }
            }
        }

        $unique = [];

        foreach ($candidates as $row) {
            $key = $this->normalizeName((string) ($row['name'] ?? ''));

            if ($key === '' || isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $row;
        }

        $rows = array_values($unique);

        usort(
            $rows,
            static fn (array $a, array $b): int
                => [$b['play_rate'], $b['top4_rate']]
                <=> [$a['play_rate'], $a['top4_rate']]
        );

        return array_slice($rows, 0, 15);
    }

    private function parseItemContainer(
        DOMElement $container,
        array $championNames,
        array $itemNames
    ): ?array {
        $images = $this->imageAlts($container);
        $item = null;
        $topUsers = [];

        foreach ($images as $alt) {
            $normalized = $this->normalizeName($alt);

            if ($item === null && isset($itemNames[$normalized])) {
                $item = $itemNames[$normalized];
                continue;
            }

            if ($item !== null && isset($championNames[$normalized])) {
                $topUsers[] = $championNames[$normalized];
            }
        }

        if ($item === null) {
            return null;
        }

        $text = $this->normalizeText($container->textContent ?? '');
        $numbers = [];

        if (preg_match_all('/(?<![A-Za-z])([0-9]+(?:[.,][0-9]+)?)(?:%|\/8)?/u', $text, $m) > 0) {
            foreach ($m[1] as $value) {
                $numbers[] = (float) str_replace(',', '.', (string) $value);
            }
        }

        if (count($numbers) < 4) {
            return null;
        }

        $play = $numbers[0];
        $place = $numbers[1];
        $top4 = $numbers[2];
        $win = $numbers[3];

        if ($place < 1 || $place > 8 || $top4 < 0 || $top4 > 100 || $win < 0 || $win > 100) {
            return null;
        }

        return [
            'name' => $item,
            'play_rate' => $play,
            'avg_place' => $place,
            'top4_rate' => $top4 / 100,
            'win_rate' => $win / 100,
            'top_users' => array_slice(array_values(array_unique($topUsers)), 0, 5),
        ];
    }

    private function metrics(string $text): array
    {
        return [
            'play_rate' => $this->numberAfter($text, 'Play Rate'),
            'place' => $this->numberAfter($text, 'Place'),
            'top4' => $this->numberAfter($text, 'Top 4 %'),
            'win' => $this->numberAfter($text, 'Win %'),
        ];
    }

    private function numberAfter(string $text, string $label): ?float
    {
        $pattern = '/'
            . preg_quote($label, '/')
            . '\s*([0-9]+(?:[.,][0-9]+)?)/iu';

        if (preg_match($pattern, $text, $match) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $match[1]);
    }

    private function findMetricContainer(
        DOMNode $node,
        string $label,
        int $minimumImages
    ): ?DOMElement {
        $current = $node->parentNode;

        for ($depth = 0; $depth < 10 && $current !== null; $depth++) {
            if ($current instanceof DOMElement) {
                $text = $this->normalizeText($current->textContent ?? '');
                $count = substr_count($text, $label);

                if (
                    $count === 1
                    && str_contains($text, 'Top 4 %')
                    && str_contains($text, 'Win %')
                    && str_contains($text, 'Place')
                    && count($this->imageAlts($current)) >= $minimumImages
                ) {
                    return $current;
                }
            }

            $current = $current->parentNode;
        }

        return null;
    }

    private function findNumericContainer(DOMElement $image): ?DOMElement
    {
        $current = $image->parentNode;

        for ($depth = 0; $depth < 8 && $current !== null; $depth++) {
            if ($current instanceof DOMElement) {
                $text = $this->normalizeText($current->textContent ?? '');

                if (
                    preg_match_all('/[0-9]+(?:[.,][0-9]+)?%?/u', $text) >= 4
                    && count($this->imageAlts($current)) >= 1
                ) {
                    return $current;
                }
            }

            $current = $current->parentNode;
        }

        return null;
    }

    private function classifyImages(
        array $images,
        array $championNames,
        array $itemNames
    ): array {
        $units = [];
        $unitItems = [];
        $currentChampion = null;

        foreach ($images as $alt) {
            $normalized = $this->normalizeName($alt);

            if (isset($championNames[$normalized])) {
                $currentChampion = $championNames[$normalized];

                if (!in_array($currentChampion, $units, true)) {
                    $units[] = $currentChampion;
                }

                $unitItems[$currentChampion] ??= [];
                continue;
            }

            if (
                $currentChampion !== null
                && isset($itemNames[$normalized])
                && count($unitItems[$currentChampion]) < 3
            ) {
                $item = $itemNames[$normalized];

                if (!in_array($item, $unitItems[$currentChampion], true)) {
                    $unitItems[$currentChampion][] = $item;
                }
            }
        }

        return [$units, $unitItems];
    }

    private function imageAlts(DOMElement $container): array
    {
        $alts = [];

        foreach ($container->getElementsByTagName('img') as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }

            $alt = trim($image->getAttribute('alt'));

            if ($alt !== '') {
                $alts[] = $alt;
            }
        }

        return $alts;
    }

    private function entityNames(array $data, string $type): array
    {
        $names = [];

        $walk = function (mixed $node) use (&$walk, &$names, $type): void {
            if (!is_array($node)) {
                return;
            }

            $name = trim((string) ($node['name'] ?? ''));
            $apiName = strtoupper(trim((string) ($node['apiName'] ?? '')));

            if ($name !== '' && ($apiName === '' || str_contains($apiName, 'TFT'))) {
                $normalized = $this->normalizeName($name);

                if ($normalized !== '') {
                    $names[$normalized] = $name;
                }
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        $walk($data);

        if ($names === []) {
            throw new RuntimeException(
                'CommunityDragon não retornou nomes TFT para ' . $type . '.'
            );
        }

        return $names;
    }

    private function pageContext(string $html): array
    {
        [$dom] = $this->dom($html);
        $text = $this->normalizeText($dom->textContent ?? '');

        $patch = null;
        $rank = null;

        if (preg_match('/\b([0-9]{1,2}\.[0-9]+[a-z]?)\b/i', $text, $m) === 1) {
            $patch = $m[1];
        }

        if (
            preg_match(
                '/\b(Iron\+|Bronze\+|Silver\+|Gold\+|Platinum\+|Emerald\+|Diamond\+|Master\+|GM\+|Challenger)\b/i',
                $text,
                $m
            ) === 1
        ) {
            $rank = $m[1];
        }

        return [$patch, $rank];
    }

    private function firstUsefulText(DOMElement $container): string
    {
        foreach ($container->childNodes as $child) {
            $text = $this->normalizeText($child->textContent ?? '');

            if (
                $text !== ''
                && !in_array($text, ['Core', 'Flex', 'Details'], true)
                && !str_contains($text, 'Play Rate')
                && mb_strlen($text) <= 140
            ) {
                return $text;
            }
        }

        return '';
    }

    private function dom(string $html): array
    {
        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('Não foi possível interpretar HTML da fonte TFT.');
        }

        return [$dom, new DOMXPath($dom)];
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($ascii !== false) {
            $value = $ascii;
        }

        $value = preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';

        return strtolower($value);
    }
}
