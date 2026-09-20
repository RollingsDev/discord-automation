<?php

declare(strict_types=1);

namespace DiscordAutomation\Support;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class HtmlLinkExtractor
{
    /** @return array<int, array{title:string,url:string}> */
    public static function extract(string $html, string $baseUrl): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('Extensão DOM do PHP não disponível.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $loaded = $document->loadHTML(
                '<?xml encoding="utf-8" ?>' . $html,
                LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if ($loaded === false) {
                throw new RuntimeException('Não foi possível interpretar o HTML recebido.');
            }

            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//a[@href]');

            if ($nodes === false) {
                return [];
            }

            $items = [];
            $seen = [];

            foreach ($nodes as $node) {
                $href = trim((string) $node->getAttribute('href'));

                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                    continue;
                }

                $title = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));

                if ($title === '') {
                    $title = trim((string) $node->getAttribute('aria-label'));
                }

                if ($title === '') {
                    $title = trim((string) $node->getAttribute('title'));
                }

                if ($title === '') {
                    continue;
                }

                $url = self::absoluteUrl($href, $baseUrl);

                if ($url === '' || isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $items[] = ['title' => $title, 'url' => $url];
            }

            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function absoluteUrl(string $href, string $baseUrl): string
    {
        if (preg_match('~^https?://~i', $href) === 1) {
            return $href;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || empty($base['host'])) {
            return '';
        }

        $scheme = (string) ($base['scheme'] ?? 'https');
        $origin = $scheme . '://' . $base['host'];

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin . ($directory !== '' ? $directory : '') . '/' . ltrim($href, '/');
    }
}
