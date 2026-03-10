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

// ---- AngebotNettoGewicht ----
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge)\r\n        FROM angebot_position ap \r\n        INNER JOIN artikel a ON ap.artikel=a.id \r\n        WHERE ap.angebot='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge) FROM angebot_position ap INNER JOIN artikel a ON ap.artikel = a.id WHERE ap.angebot = :id\",\r\n        ['id' => \$id]\r\n      );",
    'AngebotNettoGewicht branch 1', $changes
);
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge)\r\n        FROM angebot_position ap \r\n        INNER JOIN artikel a ON ap.artikel=a.id \r\n        LEFT JOIN angebot_position ap2 ON ap2.id=ap.explodiert_parent \r\n        LEFT JOIN artikel a2 ON a2.id=ap2.artikel \r\n        WHERE ap.angebot='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge) FROM angebot_position ap INNER JOIN artikel a ON ap.artikel = a.id LEFT JOIN angebot_position ap2 ON ap2.id = ap.explodiert_parent LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.angebot = :id\",\r\n        ['id' => \$id]\r\n      );",
    'AngebotNettoGewicht branch 2', $changes
);

// ---- ProformarechnungNettoGewicht ----
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*prp.menge)\r\n        FROM proformarechnung_position prp \r\n        INNER JOIN artikel a ON prp.artikel=a.id \r\n        WHERE prp.proformarechnung='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*prp.menge) FROM proformarechnung_position prp INNER JOIN artikel a ON prp.artikel = a.id WHERE prp.proformarechnung = :id\",\r\n        ['id' => \$id]\r\n      );",
    'ProformarechnungNettoGewicht branch 1', $changes
);
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge)\r\n        FROM proformarechnung_position ap \r\n        INNER JOIN artikel a ON ap.artikel=a.id \r\n        LEFT JOIN proformarechnung_position ap2 ON ap2.id=ap.explodiert_parent_artikel \r\n        LEFT JOIN artikel a2 ON a2.id=ap2.artikel \r\n        WHERE ap.proformarechnung='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge) FROM proformarechnung_position ap INNER JOIN artikel a ON ap.artikel = a.id LEFT JOIN proformarechnung_position ap2 ON ap2.id = ap.explodiert_parent_artikel LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.proformarechnung = :id\",\r\n        ['id' => \$id]\r\n      );",
    'ProformarechnungNettoGewicht branch 2', $changes
);

// ---- Now read the geschaeftsbrief sections ----
// Line ~14898: SelectArr with subquery (not SQL injection risk - $artikelid not from user directly, but let's check)
// Line ~14960: abweichendebezeichnung FROM $dokument (dynamic table name - keep as is, just int-cast $dokumentid)
// Line ~14970: datei_stichwoerter WHERE objekt='geschaeftsbrief_vorlagen' AND parameter='$idvorlage'

// Fix DeleteNotification lines 14764/14770
// Read what's there
// Lines 14764: $this->app->DB->Delete(
// Lines 14770: $this->app->DB->Insert(

// Read 14760-14860
$lines = explode("\n", $content);

// Line 14898 - SelectArr with subquery: minor risk, leave for now
// Line 14960: dynamic table name $dokument - use int cast for $dokumentid
// Line 14970: geschaeftsbrief_vorlagen - parameter='$idvorlage'

// Fix geschaeftsbrief text queries (lines 15008-15082)
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' AND projekt='\$projekt' LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache AND projekt = :projekt LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache, 'projekt' => \$projekt]);",
    'geschaeftsbrief text SELECT (projekt)', $changes
);
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' AND (projekt='0' OR projekt='')  LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache AND (projekt = '0' OR projekt = '') LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache]);",
    'geschaeftsbrief text SELECT (no projekt)', $changes
);
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache]);",
    'geschaeftsbrief text SELECT (sprache only)', $changes
);
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT text FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt LIMIT 1\", ['subjekt' => \$subjekt]);",
    'geschaeftsbrief text SELECT (subjekt only)', $changes
);

// betreff queries
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' AND projekt='\$projekt' LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache AND projekt = :projekt LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache, 'projekt' => \$projekt]);",
    'geschaeftsbrief betreff SELECT (projekt)', $changes
);
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' AND (projekt='0' OR projekt='')  LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache AND (projekt = '0' OR projekt = '') LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache]);",
    'geschaeftsbrief betreff SELECT (no projekt)', $changes
);
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache]);",
    'geschaeftsbrief betreff SELECT (sprache only)', $changes
);
rep($content,
    "        \$text = \$this->app->DB->Select(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' LIMIT 1\");",
    "        \$text = \$this->app->DatabaseService->selectValue(\"SELECT betreff FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt LIMIT 1\", ['subjekt' => \$subjekt]);",
    'geschaeftsbrief betreff SELECT (subjekt only)', $changes
);

// id queries for geschaeftsbrief
rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' AND projekt='\$projekt' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache AND projekt = :projekt LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache, 'projekt' => \$projekt]);",
    'geschaeftsbrief id SELECT (projekt)', $changes
);
rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' AND (projekt='0' OR projekt='')  LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache AND (projekt = '0' OR projekt = '') LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache]);",
    'geschaeftsbrief id SELECT (no projekt)', $changes
);
rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' AND sprache='\$sprache' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt AND sprache = :sprache LIMIT 1\", ['subjekt' => \$subjekt, 'sprache' => \$sprache]);",
    'geschaeftsbrief id SELECT (sprache only)', $changes
);
rep($content,
    "      \$text = \$this->app->DB->Select(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt='\$subjekt' LIMIT 1\");",
    "      \$text = \$this->app->DatabaseService->selectValue(\"SELECT id FROM geschaeftsbrief_vorlagen WHERE subjekt = :subjekt LIMIT 1\", ['subjekt' => \$subjekt]);",
    'geschaeftsbrief id SELECT (subjekt only)', $changes
);

// ---- AuftragStornieren auftragarr line 15092 ----
rep($content,
    "    \$auftragarr = \$this->app->DB->SelectRow(\"SELECT adresse,projekt,email,name,belegnr,keinestornomail,sprache  FROM auftrag WHERE id='\$auftrag' LIMIT 1\");",
    "    \$auftragarr = \$this->app->DatabaseService->selectRow(\"SELECT adresse, projekt, email, name, belegnr, keinestornomail, sprache FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftrag]);",
    'AuftragStornieren SelectRow auftragarr', $changes
);

// ---- stornomail line 15099 ----
rep($content,
    "    \$stornomail = \$this->app->DB->Select(\"SELECT stornomail FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "    \$stornomail = \$this->app->DatabaseService->selectValue(\"SELECT stornomail FROM projekt WHERE id = :id LIMIT 1\", ['id' => \$projekt]);",
    'AuftragStornieren stornomail', $changes
);

// ---- sprache line 15119 ----
rep($content,
    "          \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM adresse WHERE id = '\$adresse' LIMIT 1\");",
    "          \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM adresse WHERE id = :id LIMIT 1\", ['id' => \$adresse]);",
    'AuftragStornieren sprache', $changes
);

// ---- ExportlinkSendAll lines 15145-15181 ----
rep($content,
    "    \$exports = \$this->app->DB->SelectArr(\"SELECT * FROM exportlink_sent WHERE mail='0'\");",
    "    \$exports = \$this->app->DatabaseService->select(\"SELECT * FROM exportlink_sent WHERE mail = 0\", []);",
    'ExportlinkSendAll SELECT', $changes
);
rep($content,
    "      \$to = \$this->app->DB->Select(\"SELECT email FROM adresse WHERE id='\$adresse' LIMIT 1\");",
    "      \$to = \$this->app->DatabaseService->selectValue(\"SELECT email FROM adresse WHERE id = :id LIMIT 1\", ['id' => \$adresse]);",
    'ExportlinkSendAll to email', $changes
);
rep($content,
    "      \$to_name = \$this->app->DB->Select(\"SELECT name FROM adresse WHERE id='\$adresse' LIMIT 1\");",
    "      \$to_name = \$this->app->DatabaseService->selectValue(\"SELECT name FROM adresse WHERE id = :id LIMIT 1\", ['id' => \$adresse]);",
    'ExportlinkSendAll to_name', $changes
);
rep($content,
    "      \$artikel = \$this->app->DB->Select(\"SELECT name_de FROM artikel WHERE id='\$artikelid' LIMIT 1\");",
    "      \$artikel = \$this->app->DatabaseService->selectValue(\"SELECT name_de FROM artikel WHERE id = :id LIMIT 1\", ['id' => \$artikelid]);",
    'ExportlinkSendAll artikel name_de', $changes
);
rep($content,
    "      \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM adresse WHERE id = '\$adresse' LIMIT 1\");",
    "      \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM adresse WHERE id = :id LIMIT 1\", ['id' => \$adresse]);",
    'ExportlinkSendAll sprache', $changes
);
rep($content,
    "      \$this->app->DB->Update(\"UPDATE exportlink_sent SET mail=1 WHERE reg='\$reg' LIMIT 1\");",
    "      \$this->app->DatabaseService->execute(\"UPDATE exportlink_sent SET mail = 1 WHERE reg = :reg LIMIT 1\", ['reg' => \$reg]);",
    'ExportlinkSendAll UPDATE mail', $changes
);

// ---- AuftragZahlungserinnerung lines 15195-15284 ----
rep($content,
    "      \$auftragarr = \$this->app->DB->SelectRow(\"SELECT belegnr,adresse,gesamtsumme,projekt FROM auftrag WHERE id='\$id' LIMIT 1\");",
    "      \$auftragarr = \$this->app->DatabaseService->selectRow(\"SELECT belegnr, adresse, gesamtsumme, projekt FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$id]);",
    'AuftragZahlungserinnerung auftragarr', $changes
);
rep($content,
    "        \$projektarr = \$this->app->DB->SelectRow(\"SELECT zahlungserinnerung,zahlungsmailbedinungen FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "        \$projektarr = \$this->app->DatabaseService->selectRow(\"SELECT zahlungserinnerung, zahlungsmailbedinungen FROM projekt WHERE id = :id LIMIT 1\", ['id' => \$projekt]);",
    'AuftragZahlungserinnerung projektarr', $changes
);
rep($content,
    "            \$lager_ok = \$this->app->DB->Select(\"SELECT lager_ok FROM auftrag WHERE id='\$id' LIMIT 1\");",
    "            \$lager_ok = \$this->app->DatabaseService->selectValue(\"SELECT lager_ok FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$id]);",
    'AuftragZahlungserinnerung lager_ok', $changes
);
rep($content,
    "            \$check_ok = \$this->app->DB->Select(\"SELECT check_ok FROM auftrag WHERE id='\$id' LIMIT 1\");",
    "            \$check_ok = \$this->app->DatabaseService->selectValue(\"SELECT check_ok FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$id]);",
    'AuftragZahlungserinnerung check_ok', $changes
);

// ---- AuftragZahlungserinnerungSenden lines 15271-15284 ----
rep($content,
    "    \$to = \$this->app->DB->Select(\"SELECT email FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$to = \$this->app->DatabaseService->selectValue(\"SELECT email FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden to', $changes
);
rep($content,
    "    \$to_name = \$this->app->DB->Select(\"SELECT name FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$to_name = \$this->app->DatabaseService->selectValue(\"SELECT name FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden to_name', $changes
);
rep($content,
    "    \$belegnr = \$this->app->DB->Select(\"SELECT belegnr FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$belegnr = \$this->app->DatabaseService->selectValue(\"SELECT belegnr FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden belegnr', $changes
);
rep($content,
    "    \$internetnummer = \$this->app->DB->Select(\"SELECT internet FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$internetnummer = \$this->app->DatabaseService->selectValue(\"SELECT internet FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden internet', $changes
);
rep($content,
    "    \$projekt = \$this->app->DB->Select(\"SELECT projekt FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$projekt = \$this->app->DatabaseService->selectValue(\"SELECT projekt FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden projekt', $changes
);
rep($content,
    "    \$zahlungsmail = \$this->app->DB->Select(\"SELECT zahlungserinnerung FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "    \$zahlungsmail = \$this->app->DatabaseService->selectValue(\"SELECT zahlungserinnerung FROM projekt WHERE id = :id LIMIT 1\", ['id' => \$projekt]);",
    'AuftragZahlungserinnerungSenden zahlungsmail', $changes
);
rep($content,
    "    \$zahlungsmailcounter = (int) \$this->app->DB->Select(\"SELECT zahlungsmailcounter FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$zahlungsmailcounter = (int) \$this->app->DatabaseService->selectValue(\"SELECT zahlungsmailcounter FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden zahlungsmailcounter', $changes
);
rep($content,
    "    \$check_adresse = \$this->app->DB->Select(\"SELECT adresse FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$check_adresse = \$this->app->DatabaseService->selectValue(\"SELECT adresse FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden check_adresse', $changes
);
rep($content,
    "    \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM auftrag WHERE id = '\$auftragid' LIMIT 1\");",
    "    \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden sprache', $changes
);
rep($content,
    "      \$adresse = \$this->app->DB->Select(\"SELECT adresse FROM auftrag WHERE id = '\$auftragid' LIMIT 1\");",
    "      \$adresse = \$this->app->DatabaseService->selectValue(\"SELECT adresse FROM auftrag WHERE id = :id LIMIT 1\", ['id' => \$auftragid]);",
    'AuftragZahlungserinnerungSenden adresse', $changes
);

// Write if changed
if ($content !== $original) {
    file_put_contents($file, $content);
    echo "File written successfully\n";
} else {
    echo "No changes made\n";
}

// Print remaining DB-> count in range
$lines = explode("\n", $content);
$count = 0;
foreach ($lines as $idx => $line) {
    $lineNum = $idx + 1;
    if ($lineNum >= 13000 && $lineNum <= 26000 && strpos($line, '$this->app->DB->') !== false) {
        $count++;
    }
}
echo "Remaining patterns in range: $count\n";

foreach ($changes as $c) {
    echo $c . "\n";
}
