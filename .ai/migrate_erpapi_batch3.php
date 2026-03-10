<?php
$file = 'C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php';
$content = file_get_contents($file);
$original = $content;
$changes = [];

function rep(&$content, $old, $new, $label, &$changes) {
    $count = substr_count($content, $old);
    if ($count > 0) {
        $content = str_replace($old, $new, $content);
        $changes[] = "Fixed ($count): $label";
    } else {
        $changes[] = "NOT FOUND: $label";
    }
}

// Read exact content via lines
$lines = explode("\n", $content);

// Fix ArtikelImLagerOhneSperrlager (lines 14314-14320)
$newLines = [];
$skip = 0;
for ($i = 0; $i < count($lines); $i++) {
    if ($skip > 0) {
        $skip--;
        continue;
    }
    // Detect the pattern at line 14314 (0-indexed: 14313)
    if (strpos($lines[$i], 'return $this->app->DB->Select("SELECT trim(ifnull(SUM(lpi.menge),0))+0 FROM lager_platz_inhalt lpi') !== false
        && isset($lines[$i+1]) && strpos($lines[$i+1], 'INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND (ifnull(lp.sperrlager,0) = 0 OR lp.allowproduction)') !== false
        && isset($lines[$i+2]) && strpos($lines[$i+2], "WHERE lpi.artikel='") !== false) {
        $newLines[] = '      return $this->app->DatabaseService->selectValue(';
        $newLines[] = '        "SELECT trim(ifnull(SUM(lpi.menge),0))+0 FROM lager_platz_inhalt lpi';
        $newLines[] = '        INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND (ifnull(lp.sperrlager,0) = 0 OR lp.allowproduction)';
        $newLines[] = '        WHERE lpi.artikel = :artikel",';
        $newLines[] = "        ['artikel' => \$artikel]";
        $newLines[] = '      );';
        $skip = 2;
        $changes[] = "Fixed: ArtikelImLagerOhneSperrlager format branch";
        continue;
    }
    if (strpos($lines[$i], '$summe_im_lager = $this->app->DB->Select("SELECT ifnull(SUM(lpi.menge),0) FROM lager_platz_inhalt lpi') !== false
        && isset($lines[$i+1]) && strpos($lines[$i+1], 'INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND ifnull(lp.sperrlager,0) = 0') !== false
        && isset($lines[$i+2]) && strpos($lines[$i+2], "WHERE lpi.artikel='") !== false) {
        $newLines[] = '    $summe_im_lager = $this->app->DatabaseService->selectValue(';
        $newLines[] = '      "SELECT ifnull(SUM(lpi.menge),0) FROM lager_platz_inhalt lpi';
        $newLines[] = '      INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND ifnull(lp.sperrlager,0) = 0';
        $newLines[] = '      WHERE lpi.artikel = :artikel",';
        $newLines[] = "      ['artikel' => \$artikel]";
        $newLines[] = '    );';
        $skip = 2;
        $changes[] = "Fixed: ArtikelImLagerOhneSperrlager non-format branch";
        continue;
    }
    $newLines[] = $lines[$i];
}
$content = implode("\n", $newLines);

// Now read remaining DB-> patterns in 13000-26000 range
$lines2 = explode("\n", $content);
$remaining = [];
for ($i = 0; $i < count($lines2); $i++) {
    $lineNum = $i + 1;
    if ($lineNum >= 13000 && $lineNum <= 26000 && strpos($lines2[$i], '$this->app->DB->') !== false) {
        $remaining[] = "$lineNum: " . trim($lines2[$i]);
    }
}

// Now check lines in range 14300-15200
$lineNums = [];
foreach ($remaining as $r) {
    $num = (int)explode(':', $r)[0];
    $lineNums[] = $num;
}

echo "Remaining DB-> patterns count: " . count($remaining) . "\n";
echo "First 30:\n";
foreach (array_slice($remaining, 0, 30) as $r) {
    echo $r . "\n";
}

// Write if changed
if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\nFile written successfully\n";
} else {
    echo "\nNo changes made\n";
}

foreach ($changes as $c) {
    echo $c . "\n";
}
