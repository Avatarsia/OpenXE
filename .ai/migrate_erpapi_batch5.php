<?php
$file = 'C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php';
$content = file_get_contents($file);
$original = $content;
$changes = [];

// Use regex-based replacement for multi-line patterns where whitespace may vary
function repRegex(&$content, $pattern, $replacement, $label, &$changes) {
    $result = preg_replace($pattern, $replacement, $content, -1, $count);
    if ($count > 0) {
        $content = $result;
        $changes[] = "Fixed ($count): $label";
    } else {
        $changes[] = "NOT FOUND (regex): $label";
    }
}

function rep(&$content, $old, $new, $label, &$changes) {
    $count = substr_count($content, $old);
    if ($count > 0) {
        $content = str_replace($old, $new, $content);
        $changes[] = "Fixed ($count): $label";
    } else {
        $changes[] = "NOT FOUND: $label";
    }
}

// ---- ArtikelBestellung ----
repRegex($content,
    '/\$summe_in_bestellung\s*=\s*\$this->app->DB->Select\("SELECT "\s*\.\s*\(\$format\s*\?[^)]+\)\s*\.\s*"\s*\n\s*FROM bestellung_position bp\s*\n\s*LEFT JOIN bestellung b ON b\.id=bp\.bestellung\s*\n\s*WHERE bp\.artikel=\'\$artikel\' "\s*\.\s*\([^)]+\)\s*\.\s*" AND bp\.geliefert < bp\.menge AND[^"]+storniert\'"\);(\s*\n\s*if \(\$summe_in_bestellung <= 0\)\s*\n\s*return 0;\s*\n\s*\n\s*return \$summe_in_bestellung;\s*\n\s*\}\s*\n\s*\/\/ @refactor Bestellung Modul\s*\n\s*function ArtikelBestellungNichtVersendet)/',
    '$selectExprAB = $format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)";
    $ohnebestellauftragWhereAB = $ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "";
    $summe_in_bestellung = $this->app->DatabaseService->selectValue(
      "SELECT $selectExprAB FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung
      WHERE bp.artikel = :artikel $ohnebestellauftragWhereAB AND bp.geliefert < bp.menge
      AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1)
      AND b.status != \'abgeschlossen\' AND b.status != \'freigegeben\' AND b.status != \'angelegt\' AND b.status != \'storniert\'",
      [\'artikel\' => $artikel]
    );$1',
    'ArtikelBestellung (regex)',
    $changes
);

// ---- ArtikelBestellungNichtVersendet ----
repRegex($content,
    '/\$summe_in_bestellung\s*=\s*\$this->app->DB->Select\("SELECT "\s*\.\s*\(\$format\s*\?[^)]+\)\s*\.\s*"\s*\n\s*FROM bestellung_position bp\s*\n\s*LEFT JOIN bestellung b ON b\.id=bp\.bestellung\s*\n\s*WHERE bp\.artikel=\'\$artikel\' "\s*\.\s*\([^)]+\)\s*\.\s*" AND bp\.geliefert < bp\.menge[^"]+angelegt\'\)"\);\s*\n\s*\n\s*\n\s*if \(\$summe_in_bestellung/',
    '$selectExprABNV = $format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)";
    $ohnebestellauftragWhereABNV = $ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "";
    $summe_in_bestellung = $this->app->DatabaseService->selectValue(
      "SELECT $selectExprABNV FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung
      WHERE bp.artikel = :artikel $ohnebestellauftragWhereABNV AND bp.geliefert < bp.menge
      AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1)
      AND (b.status = \'freigegeben\' OR b.status = \'angelegt\')",
      [\'artikel\' => $artikel]
    );


    if ($summe_in_bestellung',
    'ArtikelBestellungNichtVersendet (regex)',
    $changes
);

// ---- LieferscheinNettoGewicht (uses dynamic $doctype) ----
$old = "    if (\$this->Firmendaten('stuecklistegewichtnurartikel') != '1') {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge)
        FROM \" . \$doctype . \"_position ap
        INNER JOIN artikel a ON ap.artikel=a.id WHERE ap.\" . \$doctype . \"='\$id'\"
      );
    } else {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge)
        FROM \" . \$doctype . \"_position ap
        INNER JOIN artikel a ON ap.artikel=a.id
        LEFT JOIN \" . \$doctype . \"_position ap2 ON ap2.id=ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id=ap2.artikel
        WHERE ap.\" . \$doctype . \"='\$id'\"
      );
    }";
$new = "    // \$doctype is validated by callers (internal, not from user input)
    \$dtPos = \$doctype . '_position';
    \$dtCol = \$doctype;
    if (\$this->Firmendaten('stuecklistegewichtnurartikel') != '1') {
      \$nettogewicht = \$this->app->DatabaseService->selectValue(
        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge) FROM `{\$dtPos}` ap INNER JOIN artikel a ON ap.artikel = a.id WHERE ap.`{\$dtCol}` = :id\",
        ['id' => \$id]
      );
    } else {
      \$nettogewicht = \$this->app->DatabaseService->selectValue(
        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge) FROM `{\$dtPos}` ap
        INNER JOIN artikel a ON ap.artikel = a.id
        LEFT JOIN `{\$dtPos}` ap2 ON ap2.id = ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.`{\$dtCol}` = :id\",
        ['id' => \$id]
      );
    }";
rep($content, $old, $new, 'LieferscheinNettoGewicht', $changes);

// ---- AuftragNettoGewicht ----
$old = "    if (\$this->Firmendaten('stuecklistegewichtnurartikel') != '1') {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge)
        FROM auftrag_position ap
        INNER JOIN artikel a ON ap.artikel=a.id
        WHERE ap.auftrag='\$id'\"
      );
    } else {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge)
        FROM auftrag_position ap
        INNER JOIN artikel a ON ap.artikel=a.id
        LEFT JOIN auftrag_position ap2 ON ap2.id=ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id=ap2.artikel
        WHERE ap.auftrag='\$id'\"
      );
    }";
$new = "    if (\$this->Firmendaten('stuecklistegewichtnurartikel') != '1') {
      \$nettogewicht = \$this->app->DatabaseService->selectValue(
        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge) FROM auftrag_position ap INNER JOIN artikel a ON ap.artikel = a.id WHERE ap.auftrag = :id\",
        ['id' => \$id]
      );
    } else {
      \$nettogewicht = \$this->app->DatabaseService->selectValue(
        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge) FROM auftrag_position ap
        INNER JOIN artikel a ON ap.artikel = a.id
        LEFT JOIN auftrag_position ap2 ON ap2.id = ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.auftrag = :id\",
        ['id' => \$id]
      );
    }";
rep($content, $old, $new, 'AuftragNettoGewicht', $changes);

// ---- BestellungNettoGewicht ----
$old = "    if (\$this->Firmendaten('stuecklistegewichtnurartikel') != '1') {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(a.gewicht,',','.')*bp.menge)
        FROM bestellung_position bp
        INNER JOIN artikel a ON bp.artikel=a.id WHERE bp.bestellung='\$id'\"
      );
    } else {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*bp.menge)
        FROM bestellung_position bp
        INNER JOIN artikel a ON bp.artikel=a.id
        LEFT JOIN bestellung_position bp2 ON bp2.id=bp.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id=bp2.artikel
        WHERE bp.bestellung='\$id'\"
      );
    }";
$new = "    if (\$this->Firmendaten('stuecklistegewichtnurartikel') != '1') {
      \$nettogewicht = \$this->app->DatabaseService->selectValue(
        \"SELECT SUM(REPLACE(a.gewicht,',','.')*bp.menge) FROM bestellung_position bp INNER JOIN artikel a ON bp.artikel = a.id WHERE bp.bestellung = :id\",
        ['id' => \$id]
      );
    } else {
      \$nettogewicht = \$this->app->DatabaseService->selectValue(
        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*bp.menge) FROM bestellung_position bp
        INNER JOIN artikel a ON bp.artikel = a.id
        LEFT JOIN bestellung_position bp2 ON bp2.id = bp.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id = bp2.artikel WHERE bp.bestellung = :id\",
        ['id' => \$id]
      );
    }";
rep($content, $old, $new, 'BestellungNettoGewicht', $changes);

// Write if changed
if ($content !== $original) {
    file_put_contents($file, $content);
    echo "File written successfully\n";
} else {
    echo "No changes made\n";
}

foreach ($changes as $c) {
    echo $c . "\n";
}
