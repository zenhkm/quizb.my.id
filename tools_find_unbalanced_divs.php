<?php
$lines = file(__DIR__.'/index.php');
$balance = 0;
$firstNegative = null;
for ($i=0;$i<count($lines);$i++){
    $line = $lines[$i];
    preg_match_all('/<div/i', $line, $m);
    preg_match_all('/<\/div>/i', $line, $n);
    $open = count($m[0]);
    $close = count($n[0]);
    $balance += $open - $close;
    if ($balance < 0 && $firstNegative === null) {
        $firstNegative = $i+1; // 1-based
    }
}
echo "firstNegativeLine:" . ($firstNegative ?? 'none') . "\n";
echo "finalBalance:" . $balance . "\n";
// Also output nearby context around firstNegative
if ($firstNegative) {
    $start = max(1, $firstNegative-5);
    $end = min(count($lines), $firstNegative+5);
    echo "--- Context around first negative line ($firstNegative) ---\n";
    for ($j=$start;$j<=$end;$j++) echo $j.': '.rtrim($lines[$j-1])."\n";
}
