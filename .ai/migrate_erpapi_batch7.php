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

$R = "\r\n";

// ---- ReserviertLieferschein ----
$old = "    return \$this->app->DB->Select(\r\n      \"SELECT trim(SUM(ifnull(r.menge,0)))+0\r\n  FROM \`lager_reserviert\` AS \`r\` INNER JOIN\r\n  (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as \`menge\` \r\n  FROM \`lieferschein_position\` AS \`lp\` \r\n  INNER JOIN \`lieferschein\` AS \`l\` ON l.id = lp.lieferschein \r\n  WHERE lp.artikel = '\$artikel'  AND l.status = 'freigegeben'\"\r\n      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\")\r\n      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' \r\n  \" : \"\") . \" GROUP BY lp.lieferschein, lp.artikel) AS \`lb\` ON r.parameter = lb.lieferschein AND r.artikel = '\$artikel' AND r.objekt = 'lieferschein'\"\r\n    );\r\n  }";
$new = "    \$vonWhereRL = (\$von && \$von != '0000-00-00') ? \" AND l.datum >= :von \" : \"\";\r\n    \$bisWhereRL = (\$bis && \$bis != '0000-00-00') ? \" AND l.datum <= :bis \" : \"\";\r\n    \$paramsRL = ['artikel' => \$artikel];\r\n    if (\$von && \$von != '0000-00-00') \$paramsRL['von'] = \$von;\r\n    if (\$bis && \$bis != '0000-00-00') \$paramsRL['bis'] = \$bis;\r\n    return \$this->app->DatabaseService->selectValue(\r\n      \"SELECT trim(SUM(ifnull(r.menge,0)))+0\r\n      FROM \`lager_reserviert\` AS \`r\` INNER JOIN\r\n      (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as \`menge\`\r\n      FROM \`lieferschein_position\` AS \`lp\`\r\n      INNER JOIN \`lieferschein\` AS \`l\` ON l.id = lp.lieferschein\r\n      WHERE lp.artikel = :artikel AND l.status = 'freigegeben' \$vonWhereRL \$bisWhereRL\r\n      GROUP BY lp.lieferschein, lp.artikel) AS \`lb\` ON r.parameter = lb.lieferschein AND r.artikel = :artikel AND r.objekt = 'lieferschein'\",\r\n      \$paramsRL\r\n    );\r\n  }";
rep($content, $old, $new, 'ReserviertLieferschein', $changes);

// Verify syntax
if ($content !== $original) {
    file_put_contents($file, $content);
    echo "File written\n";
}

// Print remaining DB-> count in range
$lines = explode("\n", $content);
$count = 0;
$first30 = [];
foreach ($lines as $idx => $line) {
    $lineNum = $idx + 1;
    if ($lineNum >= 13000 && $lineNum <= 26000 && strpos($line, '$this->app->DB->') !== false) {
        $count++;
        if (count($first30) < 30) {
            $first30[] = "$lineNum: " . trim($line);
        }
    }
}

echo "Remaining patterns: $count\n";
foreach ($first30 as $r) {
    echo $r . "\n";
}

foreach ($changes as $c) {
    echo $c . "\n";
}
