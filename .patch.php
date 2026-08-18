<?php
$file = $argv[1];
$pairs = require $argv[2];
$c = file_get_contents($file);
foreach ($pairs as $i => [$from, $to]) {
    $n = substr_count($c, $from);
    if ($n !== 1) { fwrite(STDERR, "PATCH {$i}: ancla {$n} veces\n"); exit(1); }
    $c = str_replace($from, $to, $c);
}
file_put_contents($file, $c);
echo "ok\n";
