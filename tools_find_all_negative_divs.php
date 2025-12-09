<?php
$lines = file(__DIR__.'/index.php');
$balance = 0;
$negatives = [];
for ($i=0;$i<count($lines);$i++){
    $line = $lines[$i];
    preg_match_all('/<div/i', $line, $m);
    preg_match_all('/<\/div>/i', $line, $n);
    $open = count($m[0]);
    $close = count($n[0]);
    $balance += $open - $close;
    if ($balance < 0) {
        $negatives[] = $i+1;
    }
}
echo "finalBalance:" . $balance . "\n";
if ($negatives) {
    echo "negativeLines: " . implode(',', array_unique($negatives)) . "\n";
    foreach (array_unique($negatives) as $ln) {
        $start = max(1, $ln-3);
        $end = min(count($lines), $ln+3);
        echo "--- Context around line $ln ---\n";
        for ($j=$start;$j<=$end;$j++) echo $j.': '.rtrim($lines[$j-1])."\n";
    }
} else {
    echo "No negative balance points found.\n";
}
