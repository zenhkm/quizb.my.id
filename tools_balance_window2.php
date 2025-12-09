<?php
$lines = file(__DIR__.'/index.php');
$balance = 0;
$windowStart = 6140; $windowEnd = 6260;
for ($i=0;$i<count($lines);$i++){
    $line = $lines[$i];
    preg_match_all('/<div/i', $line, $m);
    preg_match_all('/<\/div>/i', $line, $n);
    $open = count($m[0]);
    $close = count($n[0]);
    $balance += $open - $close;
    $ln = $i+1;
    if ($ln >= $windowStart && $ln <= $windowEnd) {
        echo sprintf("%5d open=%d close=%d balance=%d  %s", $ln, $open, $close, $balance, rtrim($line))."\n";
    }
}
