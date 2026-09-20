<?php
$html = file_get_contents('https://tactics.tools/pt/team-compositions/all');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($dom);
$nodes = $xp->query('//text()[normalize-space(.)="Play Rate"]');
$node = $nodes?->item(0);
$current = $node?->parentNode;
for ($d=0; $d<10 && $current; $d++, $current=$current->parentNode) {
    if (!$current instanceof DOMElement) continue;
    $text = trim(preg_replace('/\s+/u',' ', $current->textContent ?? ''));
    $imgs = $current->getElementsByTagName('img');
    if (substr_count($text,'Play Rate')===1 && str_contains($text,'Top 4 %') && str_contains($text,'Win %') && $imgs->length>=3) {
        echo "CARD TEXT:\n".$text."\n\nIMAGES:\n";
        foreach ($imgs as $img) {
            echo json_encode([
                'alt'=>$img->getAttribute('alt'),
                'src'=>$img->getAttribute('src'),
                'class'=>$img->getAttribute('class')
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        }
        echo "\nLINKS:\n";
        foreach ($current->getElementsByTagName('a') as $a) {
            echo json_encode([
                'text'=>trim(preg_replace('/\s+/u',' ', $a->textContent ?? '')),
                'href'=>$a->getAttribute('href')
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        }
        break;
    }
}
