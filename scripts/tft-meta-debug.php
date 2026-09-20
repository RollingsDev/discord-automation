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

echo "\n\nITEM PAGE DEBUG:\n";
$html = file_get_contents('https://tactics.tools/pt/items');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($dom);
foreach ($xp->query('//tr') ?: [] as $tr) {
    if (!$tr instanceof DOMElement) continue;
    $imgs = $tr->getElementsByTagName('img');
    $hasItem = false;
    foreach ($imgs as $img) {
        if (str_contains($img->getAttribute('src'), '/items_s14/')) {
            $hasItem = true;
            break;
        }
    }
    if (!$hasItem) continue;
    echo "ROW TEXT:\n".trim(preg_replace('/\\s+/u',' ', $tr->textContent ?? ''))."\nIMAGES:\n";
    foreach ($imgs as $img) {
        echo json_encode([
            'alt'=>$img->getAttribute('alt'),
            'src'=>$img->getAttribute('src')
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    }
    echo "CELLS:\n";
    foreach ($tr->getElementsByTagName('td') as $td) {
        echo "[".trim(preg_replace('/\\s+/u',' ', $td->textContent ?? ''))."]\n";
    }
    break;
}

echo "\nITEM ANCESTOR DEBUG:\n";
$html = file_get_contents('https://tactics.tools/pt/items');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($dom);
$imgs = $xp->query('//img[contains(@src,"/items_s14/")]');
$img = $imgs?->item(0);
$current = $img;
for ($d=0; $d<8 && $current; $d++, $current=$current->parentNode) {
    if (!$current instanceof DOMElement) continue;
    echo "DEPTH {$d} TAG=".$current->tagName." CLASS=".$current->getAttribute('class')."\n";
    echo "TEXT=".substr(trim(preg_replace('/\\s+/u',' ', $current->textContent ?? '')),0,1200)."\n";
    echo "IMGS=".$current->getElementsByTagName('img')->length." LINKS=".$current->getElementsByTagName('a')->length."\n---\n";
}

echo "\nITEM ROWS DEBUG:\n";
$html = file_get_contents('https://tactics.tools/pt/items');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($dom);
$rows = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " tbl-row-md ")]');
echo "COUNT=".$rows->length."\n";
for ($i=0; $i<min(20,$rows->length); $i++) {
    $row=$rows->item($i);
    if (!$row instanceof DOMElement) continue;
    echo "ROW {$i} CLASS=".$row->getAttribute('class')."\n";
    echo "TEXT=".trim(preg_replace('/\\s+/u',' ', $row->textContent ?? ''))."\n";
    echo "IMAGES:";
    foreach ($row->getElementsByTagName('img') as $img) {
      echo " ".$img->getAttribute('alt')."|".$img->getAttribute('src');
    }
    echo "\n---\n";
}
