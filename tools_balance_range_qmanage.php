<?php
$lines = file(__DIR__.'/index.php');
$start=12578; $end=12820;
$balance=0;
for($i=1;$i<=count($lines);$i++){
    if($i<$start) continue;
    if($i>$end) break;
    $line=$lines[$i-1];
    $open = preg_match_all('/<div/i',$line);
    $close = preg_match_all('/<\/div>/i',$line);
    $balance += $open - $close;
    echo sprintf("%5d open=%d close=%d balance=%d %s", $i, $open, $close, $balance, rtrim($line))."\n";
}
