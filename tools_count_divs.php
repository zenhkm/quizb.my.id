<?php
$c = file_get_contents(__DIR__ . '/index.php');
preg_match_all('/<div/i', $c, $m);
$open = count($m[0]);
preg_match_all('/<\/div>/i', $c, $n);
$close = count($n[0]);
echo "open_divs:$open\nclose_divs:$close\n";
