<?php
$lines = file(__DIR__.'/index.php');
for ($i=0;$i<count($lines);$i++){
    $line = $lines[$i];
    preg_match_all('/<div/i', $line, $m);
    preg_match_all('/<\/div>/i', $line, $n);
    $open = count($m[0]);
    $close = count($n[0]);
    if ($close > $open) {
        echo ($i+1) . ': open=' . $open . ' close=' . $close . ' => ' . rtrim($line) . "\n";
    }
}
