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

    public function fetch(): array
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.7',
        ];

        $compsHtml = HttpClient::getText(self::COMPS_URL, $headers);
        $itemsHtml = HttpClient::getText(self::ITEMS_URL, $headers);

        [$patch, $rank] = $this->pageContext($compsHtml);

        $comps = $this->parseComps($compsHtml);
        $items = $this->parseItems($itemsHtml);

        if ($comps === []) {
            throw new RuntimeException('Não foi possível extrair composições do meta TFT.');
        }

        if ($items === []) {
            throw new RuntimeException('Não foi possível extrair estatísticas de itens TFT.');
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

    private function parseComps(string $html): array
    {
        [, $xpath] = $this->dom($html);
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

            [$units, $unitItems] = $this->classifyCompImages(
                $this->imageData($card)
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

    private function parseItems(string $html): array
    {
        [, $xpath] = $this->dom($html);

        $nodes = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " tbl-row-md ")]'
        );

        if ($nodes === false) {
            return [];
        }

        $names = [];
        $stats = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $images = $this->imageData($node);
            $text = $this->normalizeText($node->textContent ?? '');

            $itemImage = null;

            foreach ($images as $image) {
                if (str_contains($image['src'], '/items_s14/')) {
                    $itemImage = $image;
                    break;
                }
            }

            if (
                $itemImage !== null
                && count($images) === 1
                && trim($itemImage['alt']) !== ''
            ) {
                $names[] = trim($itemImage['alt']);
                continue;
            }

            $compact = preg_replace('/\s+/u', '', $text) ?? '';

            if (
                preg_match(
                    '/^([0-9]+(?:[.,][0-9]+)?)\/8'
                    . '([1-8][.,][0-9]{2})'
                    . '([0-9]{1,2}(?:[.,][0-9]+)?)%'
                    . '([0-9]{1,2}(?:[.,][0-9]+)?)%$/u',
                    $compact,
                    $match
                ) !== 1
            ) {
                continue;
            }

            $topUsers = [];

            foreach ($images as $image) {
                if (
                    str_contains($image['src'], '/face/')
                    && trim($image['alt']) !== ''
                ) {
                    $topUsers[] = trim($image['alt']);
                }
            }

            $stats[] = [
                'play_rate' => $this->float($match[1]),
                'avg_place' => $this->float($match[2]),
                'top4_rate' => $this->float($match[3]) / 100,
                'win_rate' => $this->float($match[4]) / 100,
                'top_users' => array_slice(
                    array_values(array_unique($topUsers)),
                    0,
                    5
                ),
            ];
        }

        $count = min(count($names), count($stats));
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = array_merge(
                ['name' => $names[$index]],
                $stats[$index]
            );
        }

        return array_slice($rows, 0, 15);
    }

    private function classifyCompImages(array $images): array
    {
        $units = [];
        $unitItems = [];
        $currentChampion = null;

        foreach ($images as $image) {
            $alt = trim((string) ($image['alt'] ?? ''));
            $src = (string) ($image['src'] ?? '');

            if ($alt === '') {
                continue;
            }

            if (str_contains($src, '/face/')) {
                $currentChampion = $alt;

                if (!in_array($currentChampion, $units, true)) {
                    $units[] = $currentChampion;
                }

                $unitItems[$currentChampion] ??= [];
                continue;
            }

            if (
                $currentChampion !== null
                && str_contains($src, '/items_s14/')
                && count($unitItems[$currentChampion]) < 3
                && !in_array($alt, $unitItems[$currentChampion], true)
            ) {
                $unitItems[$currentChampion][] = $alt;
            }
        }

        $unitItems = array_filter(
            $unitItems,
            static fn (array $items): bool => $items !== []
        );

        return [$units, $unitItems];
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

        return $this->float($match[1]);
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
                    && count($this->imageData($current)) >= $minimumImages
                ) {
                    return $current;
                }
            }

            $current = $current->parentNode;
        }

        return null;
    }

    private function imageData(DOMElement $container): array
    {
        $images = [];

        foreach ($container->getElementsByTagName('img') as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }

            $images[] = [
                'alt' => trim($image->getAttribute('alt')),
                'src' => trim($image->getAttribute('src')),
            ];
        }

        return $images;
    }

    private function pageContext(string $html): array
    {
        [$dom] = $this->dom($html);
        $text = $this->normalizeText($dom->textContent ?? '');

        $patch = null;
        $rank = null;

        if (
            preg_match(
                '/Ranked\s*'
                . '(Iron\+|Bronze\+|Silver\+|Gold\+|Platinum\+|Emerald\+|Diamond\+|Master\+|GM\+|Challenger)'
                . '\s*([0-9]{1,2}\.[0-9]+[a-z]?)/iu',
                $text,
                $match
            ) === 1
        ) {
            $rank = $match[1];
            $patch = $match[2];
        }

        if ($rank === null) {
            if (
                preg_match(
                    '/\b(Iron\+|Bronze\+|Silver\+|Gold\+|Platinum\+|Emerald\+|Diamond\+|Master\+|GM\+|Challenger)\b/iu',
                    $text,
                    $match
                ) === 1
            ) {
                $rank = $match[1];
            }
        }

        if ($patch === null) {
            if (
                preg_match('/\b(1[0-9]\.[0-9]+[a-z]?)\b/i', $text, $match) === 1
            ) {
                $patch = $match[1];
            }
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

    private function float(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}
