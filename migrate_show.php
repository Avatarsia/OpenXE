<?php
$filepath = 'www/lib/class.erpapi.php';
$content = file_get_contents($filepath);
$search = 'INSERT INTO aufgabe (id,adresse,initiator';
$pos = strpos($content, $search);
$chunk = substr($content, $pos - 20, 300);
for ($i = 0; $i < strlen($chunk); $i++) {
    $c = $chunk[$i];
    if (ord($c) < 32) echo '[' . ord($c) . ']';
    else echo $c;
}
