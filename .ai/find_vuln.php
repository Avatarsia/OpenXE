<?php
$lines = file('C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php');
$in_block = false;
$results = [];
foreach ($lines as $i => $line) {
    $ln = $i + 1;
    $s = trim($line);
    if (strpos($s, '/*') !== false && strpos($s, '*/') === false) { $in_block = true; continue; }
    if (strpos($s, '*/') !== false) { $in_block = false; continue; }
    if ($in_block) continue;
    if (strpos($s, '//') === 0) continue;
    if (strpos($line, 'this->app->DB->') === false) continue;
    if (strpos($line, 'GetInsertID') !== false || strpos($line, 'affected_rows') !== false || strpos($line, 'error()') !== false) continue;
    // Check for variable interpolation
    if (preg_match('/["\'].*\$[a-zA-Z_]/', $line) || strpos($line, 'real_escape_string') !== false || strpos($line, 'sprintf') !== false || preg_match('/"\s*\.\s*\$/', $line) || preg_match('/\'\s*\.\s*\$/', $line)) {
        $results[] = $ln . ': ' . substr($s, 0, 180);
    }
}
foreach ($results as $r) echo $r . "\n";
echo "\nTotal: " . count($results) . "\n";
