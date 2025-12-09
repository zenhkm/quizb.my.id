<?php
$p = __DIR__ . '/index.php';
$lines = file($p);
$areas = [
  ['name'=>'view_crud_pengajar','start'=>10940,'end'=>11120],
  ['name'=>'view_qmanage_pengajar','start'=>12578,'end'=>12811]
];
foreach($areas as $a){
  $open=0;$close=0;
  for($i=$a['start'];$i<=$a['end'] && $i<=count($lines);$i++){
    $line = $lines[$i-1];
    $open += preg_match_all('/<div/i',$line);
    $close += preg_match_all('/<\/div>/i',$line);
  }
  echo $a['name']." open=$open close=$close\n";
}
