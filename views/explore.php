<?php
// views/explore.php

echo '<h3 class="mb-3">Jelajah Tema Kuis</h3><div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">';
foreach ($themes as $t) {
  echo '<div class="col"><a class="text-decoration-none" href="?page=subthemes&theme_id=' . $t['id'] . '"><div class="card h-100 quiz-card"><div class="card-body"><h5>' . h($t['name']) . '</h5><p class="small text-muted">' . h($t['description']) . '</p></div></div></a></div>';
}
echo '</div>';
