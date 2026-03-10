<?php
$filepath = 'C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php';
$content = file_get_contents($filepath);
$total = 0;

function rep(&$content, $old, $new, $label) {
    global $total;
    $result = str_replace($old, $new, $content, $count);
    if ($count > 0) {
        $content = $result;
        $total += $count;
        echo "OK [$count] $label\n";
    } else {
        echo "MISS $label\n";
    }
}

$n = "\r\n";

// ============================================================
// ReplaceLieferantennummer — $rmodule table injection (~13317)
// ============================================================
rep($content,
    "        \$projekt = \$this->app->DB->Select(\"SELECT projekt FROM \$rmodule WHERE id = '\$rid' LIMIT 1\");{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DB->Select(\"SELECT eigenernummernkreis FROM projekt WHERE id = '\$projekt' LIMIT 1\");{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DB->Select(\"SELECT id FROM adresse WHERE lieferantennummer='\$rest' AND lieferantennummer!='' AND geloescht=0 ORDER BY \" . (\$filter_projekt ? \" projekt = '\$filter_projekt' DESC, \" : \"\") . \" projekt LIMIT 1\");",
    "        \$safeRmodule = \$this->app->DatabaseService->validateIdentifier(\$rmodule);{$n}        \$projekt = \$this->app->DatabaseService->selectValue(\"SELECT projekt FROM `\$safeRmodule` WHERE id = :id LIMIT 1\", ['id' => \$rid]);{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DatabaseService->selectValue(\"SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM adresse WHERE lieferantennummer = :rest AND lieferantennummer != '' AND geloescht = 0\" . (\$filter_projekt ? \" ORDER BY projekt = :filter_projekt DESC, projekt\" : \" ORDER BY projekt\") . \" LIMIT 1\", \$filter_projekt ? ['rest' => \$rest, 'filter_projekt' => (int) \$filter_projekt] : ['rest' => \$rest]);",
    'ReplaceLieferantennummer projekt+eigenernummernkreis+id SELECT'
);

// ============================================================
// ReplaceKundennummer — $rmodule table injection (~13372)
// ============================================================
rep($content,
    "        \$projekt = \$this->app->DB->Select(\"SELECT projekt FROM \$rmodule WHERE id = '\$rid' LIMIT 1\");{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DB->Select(\"SELECT eigenernummernkreis FROM projekt WHERE id = '\$projekt' LIMIT 1\");{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      //\$rest = substr(\$tmp, 0, 5);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DB->Select(\"SELECT id FROM adresse WHERE kundennummer='\$rest' AND kundennummer!='' AND geloescht=0 ORDER BY  \" . (\$filter_projekt ? \" projekt = '\$filter_projekt' DESC, \" : \"\") . \" projekt LIMIT 1\");",
    "        \$safeRmodule = \$this->app->DatabaseService->validateIdentifier(\$rmodule);{$n}        \$projekt = \$this->app->DatabaseService->selectValue(\"SELECT projekt FROM `\$safeRmodule` WHERE id = :id LIMIT 1\", ['id' => \$rid]);{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DatabaseService->selectValue(\"SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      //\$rest = substr(\$tmp, 0, 5);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM adresse WHERE kundennummer = :rest AND kundennummer != '' AND geloescht = 0\" . (\$filter_projekt ? \" ORDER BY projekt = :filter_projekt DESC,  projekt\" : \" ORDER BY  projekt\") . \" LIMIT 1\", \$filter_projekt ? ['rest' => \$rest, 'filter_projekt' => (int) \$filter_projekt] : ['rest' => \$rest]);",
    'ReplaceKundennummer projekt+eigenernummernkreis+id SELECT'
);

// ============================================================
// ReplaceKundennummer SELECT kundennummer as name (~13361)
// ============================================================
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT kundennummer as name FROM adresse WHERE id='\$id' AND geloescht=0 LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT kundennummer as name FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceKundennummer SELECT kundennummer as name'
);

// ============================================================
// ReplaceKunde SELECT (~13408+)
// ============================================================
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(kundennummer,' ',name) as name FROM adresse WHERE id='\$id' AND geloescht=0 LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(kundennummer,' ',name) as name FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceKunde SELECT CONCAT kundennummer name'
);

rep($content,
    "        \$projekt = \$this->app->DB->Select(\"SELECT projekt FROM \$rmodule WHERE id = '\$rid' LIMIT 1\");{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DB->Select(\"SELECT eigenernummernkreis FROM projekt WHERE id = '\$projekt' LIMIT 1\");{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DB->Select(\"SELECT id FROM adresse WHERE kundennummer='\$rest' AND kundennummer!='' AND geloescht=0 ORDER BY  \" . (\$filter_projekt ? \" projekt = '\$filter_projekt' DESC, \" : \"\") . \" projekt LIMIT 1\");",
    "        \$safeRmodule = \$this->app->DatabaseService->validateIdentifier(\$rmodule);{$n}        \$projekt = \$this->app->DatabaseService->selectValue(\"SELECT projekt FROM `\$safeRmodule` WHERE id = :id LIMIT 1\", ['id' => \$rid]);{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DatabaseService->selectValue(\"SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM adresse WHERE kundennummer = :rest AND kundennummer != '' AND geloescht = 0\" . (\$filter_projekt ? \" ORDER BY  projekt = :filter_projekt DESC,  projekt\" : \" ORDER BY  projekt\") . \" LIMIT 1\", \$filter_projekt ? ['rest' => \$rest, 'filter_projekt' => (int) \$filter_projekt] : ['rest' => \$rest]);",
    'ReplaceKunde projekt+eigenernummernkreis+id SELECT'
);

// ============================================================
// ReplaceLieferantLieferant second instance (~13543)
// ============================================================
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(lieferantennummer,' ',name) as name FROM adresse WHERE id='\$id' AND geloescht=0 LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(lieferantennummer,' ',name) as name FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceLieferant CONCAT lieferantennummer name'
);

rep($content,
    "        \$projekt = \$this->app->DB->Select(\"SELECT projekt FROM \$rmodule WHERE id = '\$rid' LIMIT 1\");{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DB->Select(\"SELECT eigenernummernkreis FROM projekt WHERE id = '\$projekt' LIMIT 1\");{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DB->Select(\"SELECT id FROM adresse WHERE lieferantennummer='\$rest' AND lieferantennummer!='' AND geloescht=0 ORDER BY \" . (\$filter_projekt ? \" projekt = '\$filter_projekt' DESC, \" : \"\") . \" projekt LIMIT 1\");",
    "        \$safeRmodule = \$this->app->DatabaseService->validateIdentifier(\$rmodule);{$n}        \$projekt = \$this->app->DatabaseService->selectValue(\"SELECT projekt FROM `\$safeRmodule` WHERE id = :id LIMIT 1\", ['id' => \$rid]);{$n}        if (\$projekt) {{$n}          \$eigenernummernkreis = \$this->app->DatabaseService->selectValue(\"SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);{$n}          //if(\$eigenernummernkreis){$n}          \$filter_projekt = \$projekt;{$n}        }{$n}      }{$n}      \$abkuerzung = \$value;{$n}      \$tmp = trim(\$value);{$n}      \$rest = explode(\" \", \$tmp);{$n}      \$rest = \$rest[0];{$n}      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM adresse WHERE lieferantennummer = :rest AND lieferantennummer != '' AND geloescht = 0\" . (\$filter_projekt ? \" ORDER BY projekt = :filter_projekt DESC, projekt\" : \" ORDER BY projekt\") . \" LIMIT 1\", \$filter_projekt ? ['rest' => \$rest, 'filter_projekt' => (int) \$filter_projekt] : ['rest' => \$rest]);",
    'ReplaceLieferant second instance projekt+eigenernummernkreis+id SELECT'
);

// ============================================================
// ReplaceKontorahmen (~13494)
// ============================================================
rep($content,
    "    \$value = \$this->app->DB->real_escape_string(\$value);{$n}    if (\$db) {{$n}      \$kontoid = \$this->app->DB->Select(\"SELECT id FROM kontorahmen WHERE sachkonto = '\$sachkonto' LIMIT 1\");",
    "    if (\$db) {{$n}      \$kontoid = \$this->app->DatabaseService->selectValue(\"SELECT id FROM kontorahmen WHERE sachkonto = :sachkonto LIMIT 1\", ['sachkonto' => \$sachkonto]);",
    'ReplaceKontorahmen SELECT id'
);

rep($content,
    "      \$sachkonto = \$this->app->DB->Select(\"SELECT CONCAT(sachkonto,' ',beschriftung) FROM kontorahmen WHERE id = '\$value' LIMIT 1\");",
    "      \$sachkonto = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(sachkonto,' ',beschriftung) FROM kontorahmen WHERE id = :id LIMIT 1\", ['id' => (int) \$value]);",
    'ReplaceKontorahmen SELECT CONCAT'
);

// ============================================================
// ReplaceKonto (~13508)
// ============================================================
rep($content,
    "    \$value = \$this->app->DB->real_escape_string(\$value);{$n}    if (\$db) {{$n}      \$kontoid = \$this->app->DB->Select(\"SELECT id FROM konten WHERE kurzbezeichnung = '\$konto' LIMIT 1\");",
    "    if (\$db) {{$n}      \$kontoid = \$this->app->DatabaseService->selectValue(\"SELECT id FROM konten WHERE kurzbezeichnung = :konto LIMIT 1\", ['konto' => \$konto]);",
    'ReplaceKonto SELECT id'
);

rep($content,
    "      \$konto = \$this->app->DB->Select(\"SELECT CONCAT(kurzbezeichnung,' ',bezeichnung) FROM konten WHERE id = '\$value' LIMIT 1\");",
    "      \$konto = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(kurzbezeichnung,' ',bezeichnung) FROM konten WHERE id = :id LIMIT 1\", ['id' => (int) \$value]);",
    'ReplaceKonto SELECT CONCAT'
);

// ============================================================
// ReplaceSmartyTemplate (~13522)
// ============================================================
rep($content,
    "    \$value = \$this->app->DB->real_escape_string(\$value);{$n}    if (\$db) {{$n}      \$smarty_template = \$this->app->DB->Select(\"SELECT CONCAT(id,' ',name) FROM smarty_templates WHERE id = '\$value' LIMIT 1\");",
    "    if (\$db) {{$n}      \$smarty_template = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(id,' ',name) FROM smarty_templates WHERE id = :id LIMIT 1\", ['id' => (int) \$value]);",
    'ReplaceSmartyTemplate SELECT'
);

// ============================================================
// GetEinkaufspreis (~14025)
// ============================================================
rep($content,
    "    \$ek = \$this->app->DB->Select(\"SELECT preis FROM einkaufspreise WHERE artikel='\$id' AND adresse='\$adresse' AND (gueltig_bis>=NOW() OR gueltig_bis='0000-00-00') AND ab_menge <= \$menge order by ab_menge desc LIMIT 1\");",
    "    \$ek = \$this->app->DatabaseService->selectValue(\"SELECT preis FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = '0000-00-00') AND ab_menge <= :menge ORDER BY ab_menge DESC LIMIT 1\", ['artikel' => (int) \$id, 'adresse' => (int) \$adresse, 'menge' => (float) \$menge]);",
    'GetEinkaufspreis SELECT preis menge'
);

rep($content,
    "    return \$this->app->DB->Select(\"SELECT preis FROM einkaufspreise WHERE artikel='\$id' AND adresse='\$adresse' AND (gueltig_bis>=NOW() OR gueltig_bis='0000-00-00') order by ab_menge ASC LIMIT 1\");",
    "    return \$this->app->DatabaseService->selectValue(\"SELECT preis FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = '0000-00-00') ORDER BY ab_menge ASC LIMIT 1\", ['artikel' => (int) \$id, 'adresse' => (int) \$adresse]);",
    'GetEinkaufspreis SELECT preis fallback'
);

// ============================================================
// GetEinkaufspreisID (~14039)
// ============================================================
rep($content,
    "    \$ek = \$this->app->DB->Select(\"SELECT id FROM einkaufspreise WHERE artikel='\$id' AND adresse='\$adresse' AND (gueltig_bis>=NOW() OR gueltig_bis='0000-00-00') AND ab_menge <= \$menge order by ab_menge desc  LIMIT 1\");",
    "    \$ek = \$this->app->DatabaseService->selectValue(\"SELECT id FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = '0000-00-00') AND ab_menge <= :menge ORDER BY ab_menge DESC LIMIT 1\", ['artikel' => (int) \$id, 'adresse' => (int) \$adresse, 'menge' => (float) \$menge]);",
    'GetEinkaufspreisID SELECT id menge'
);

rep($content,
    "    return \$this->app->DB->Select(\"SELECT id FROM einkaufspreise WHERE artikel='\$id' AND adresse='\$adresse' AND (gueltig_bis>=NOW() OR gueltig_bis='0000-00-00') order by ab_menge LIMIT 1\");",
    "    return \$this->app->DatabaseService->selectValue(\"SELECT id FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = '0000-00-00') ORDER BY ab_menge LIMIT 1\", ['artikel' => (int) \$id, 'adresse' => (int) \$adresse]);",
    'GetEinkaufspreisID SELECT id fallback'
);

// ============================================================
// StornoMail / eMail related - auftrag email name (~14986)
// ============================================================
rep($content,
    "      \$auftragarr = \$this->app->DB->SelectRow(\"SELECT adresse,projekt,email,name,belegnr,keinestornomail,sprache  FROM auftrag WHERE id='\$auftrag' LIMIT 1\");",
    "      \$auftragarr = \$this->app->DatabaseService->selectRow(\"SELECT adresse,projekt,email,name,belegnr,keinestornomail,sprache FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftrag]);",
    'StornoMail SelectRow auftrag'
);

rep($content,
    "    \$stornomail = \$this->app->DB->Select(\"SELECT stornomail FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "    \$stornomail = \$this->app->DatabaseService->selectValue(\"SELECT stornomail FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);",
    'StornoMail SELECT stornomail'
);

rep($content,
    "          \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM adresse WHERE id = '\$adresse' LIMIT 1\");",
    "          \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);",
    'StornoMail SELECT sprache adresse'
);

// ============================================================
// RechnungMail related (~15354-15500)
// ============================================================
rep($content,
    "      \$to = \$this->app->DB->Select(\"SELECT email FROM adresse WHERE id='\$adresse' LIMIT 1\");{$n}      \$to_name = \$this->app->DB->Select(\"SELECT name FROM adresse WHERE id='\$adresse' LIMIT 1\");{$n}      \$artikel = \$this->app->DB->Select(\"SELECT name_de FROM artikel WHERE id='\$artikelid' LIMIT 1\");{$n}      \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM adresse WHERE id = '\$adresse' LIMIT 1\");",
    "      \$to = \$this->app->DatabaseService->selectValue(\"SELECT email FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);{$n}      \$to_name = \$this->app->DatabaseService->selectValue(\"SELECT name FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);{$n}      \$artikel = \$this->app->DatabaseService->selectValue(\"SELECT name_de FROM artikel WHERE id = :id LIMIT 1\", ['id' => (int) \$artikelid]);{$n}      \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);",
    'ExportLink email/name/artikel/sprache SELECT'
);

rep($content,
    "      \$this->app->DB->Update(\"UPDATE exportlink_sent SET mail=1 WHERE reg='\$reg' LIMIT 1\");",
    "      \$this->app->DatabaseService->execute(\"UPDATE exportlink_sent SET mail = 1 WHERE reg = :reg LIMIT 1\", ['reg' => \$reg]);",
    'ExportLink UPDATE mail=1'
);

// ZahlungserinnerungMail (~15089)
rep($content,
    "      \$auftragarr = \$this->app->DB->SelectRow(\"SELECT belegnr,adresse,gesamtsumme,projekt FROM auftrag WHERE id='\$id' LIMIT 1\");",
    "      \$auftragarr = \$this->app->DatabaseService->selectRow(\"SELECT belegnr,adresse,gesamtsumme,projekt FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ZahlungserinnerungMail SelectRow auftrag'
);

rep($content,
    "        \$projektarr = \$this->app->DB->SelectRow(\"SELECT zahlungserinnerung,zahlungsmailbedinungen FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "        \$projektarr = \$this->app->DatabaseService->selectRow(\"SELECT zahlungserinnerung,zahlungsmailbedinungen FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);",
    'ZahlungserinnerungMail SelectRow projekt'
);

rep($content,
    "            \$lager_ok = \$this->app->DB->Select(\"SELECT lager_ok FROM auftrag WHERE id='\$id' LIMIT 1\");",
    "            \$lager_ok = \$this->app->DatabaseService->selectValue(\"SELECT lager_ok FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ZahlungserinnerungMail SELECT lager_ok'
);

rep($content,
    "            \$check_ok = \$this->app->DB->Select(\"SELECT check_ok FROM auftrag WHERE id='\$id' LIMIT 1\");",
    "            \$check_ok = \$this->app->DatabaseService->selectValue(\"SELECT check_ok FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ZahlungserinnerungMail SELECT check_ok'
);

// ============================================================
// ZahlungsMail (~15165)
// ============================================================
rep($content,
    "    \$to = \$this->app->DB->Select(\"SELECT email FROM auftrag WHERE id='\$auftragid' LIMIT 1\");{$n}    \$to_name = \$this->app->DB->Select(\"SELECT name FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$to = \$this->app->DatabaseService->selectValue(\"SELECT email FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}    \$to_name = \$this->app->DatabaseService->selectValue(\"SELECT name FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);",
    'ZahlungsMail SELECT to + to_name'
);

rep($content,
    "    \$belegnr = \$this->app->DB->Select(\"SELECT belegnr FROM auftrag WHERE id='\$auftragid' LIMIT 1\");{$n}    \$internetnummer = \$this->app->DB->Select(\"SELECT internet FROM auftrag WHERE id='\$auftragid' LIMIT 1\");{$n}    \$projekt = \$this->app->DB->Select(\"SELECT projekt FROM auftrag WHERE id='\$auftragid' LIMIT 1\");{$n}    \$zahlungsmail = \$this->app->DB->Select(\"SELECT zahlungserinnerung FROM projekt WHERE id='\$projekt' LIMIT 1\");{$n}    \$zahlungsmailcounter = (int) \$this->app->DB->Select(\"SELECT zahlungsmailcounter FROM auftrag WHERE id='\$auftragid' LIMIT 1\");{$n}    \$check_adresse = \$this->app->DB->Select(\"SELECT adresse FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$belegnr = \$this->app->DatabaseService->selectValue(\"SELECT belegnr FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}    \$internetnummer = \$this->app->DatabaseService->selectValue(\"SELECT internet FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}    \$projekt = \$this->app->DatabaseService->selectValue(\"SELECT projekt FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}    \$zahlungsmail = \$this->app->DatabaseService->selectValue(\"SELECT zahlungserinnerung FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);{$n}    \$zahlungsmailcounter = (int) \$this->app->DatabaseService->selectValue(\"SELECT zahlungsmailcounter FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}    \$check_adresse = \$this->app->DatabaseService->selectValue(\"SELECT adresse FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);",
    'ZahlungsMail SELECT belegnr+internet+projekt+zahlungserinnerung+counter+adresse'
);

rep($content,
    "    \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM auftrag WHERE id = '\$auftragid' LIMIT 1\");{$n}    if (!\$sprache) {{$n}      \$adresse = \$this->app->DB->Select(\"SELECT adresse FROM auftrag WHERE id = '\$auftragid' LIMIT 1\");{$n}      \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM adresse WHERE id = '\$adresse' LIMIT 1\");",
    "    \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}    if (!\$sprache) {{$n}      \$adresse = \$this->app->DatabaseService->selectValue(\"SELECT adresse FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}      \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);",
    'ZahlungsMail SELECT sprache + adresse + sprache fallback'
);

rep($content,
    "    \$gesamt = \$this->app->DB->Select(\"SELECT gesamtsumme FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$gesamt = \$this->app->DatabaseService->selectValue(\"SELECT gesamtsumme FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);",
    'ZahlungsMail SELECT gesamt'
);

rep($content,
    "      \$gesamtsumme = \$this->app->DB->Select(\"SELECT gesamtsumme FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "      \$gesamtsumme = \$this->app->DatabaseService->selectValue(\"SELECT gesamtsumme FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);",
    'ZahlungsMail SELECT gesamtsumme'
);

rep($content,
    "      \$text = str_replace('{GESAMT}', \$this->app->DB->Select(\"SELECT gesamtsumme FROM auftrag WHERE id='\$auftragid' LIMIT 1\"), \$text);",
    "      \$text = str_replace('{GESAMT}', \$this->app->DatabaseService->selectValue(\"SELECT gesamtsumme FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]), \$text);",
    'ZahlungsMail str_replace GESAMT'
);

rep($content,
    "      \$text = str_replace('{DATUM}', \$this->app->DB->Select(\"SELECT DATE_FORMAT(datum,'%d.%m.%Y') FROM auftrag WHERE id='\$auftragid' LIMIT 1\"), \$text);{$n}      \$text = str_replace('{DATUM}', \$this->app->DB->Select(\"SELECT DATE_FORMAT(datum,'%d.%m.%Y') FROM kontoauszuege_zahlungseingang WHERE objekt='auftrag' AND parameter='\$auftragid' LIMIT 1\"), \$text);{$n}      \$betreff = str_replace('{DATUM}', \$this->app->DB->Select(\"SELECT DATE_FORMAT(datum,'%d.%m.%Y') FROM kontoauszuege_zahlungseingang WHERE objekt='auftrag' AND parameter='\$auftragid' LIMIT 1\"), \$betreff);",
    "      \$text = str_replace('{DATUM}', \$this->app->DatabaseService->selectValue(\"SELECT DATE_FORMAT(datum,'%d.%m.%Y') FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]), \$text);{$n}      \$text = str_replace('{DATUM}', \$this->app->DatabaseService->selectValue(\"SELECT DATE_FORMAT(datum,'%d.%m.%Y') FROM kontoauszuege_zahlungseingang WHERE objekt = 'auftrag' AND parameter = :auftragid LIMIT 1\", ['auftragid' => (int) \$auftragid]), \$text);{$n}      \$betreff = str_replace('{DATUM}', \$this->app->DatabaseService->selectValue(\"SELECT DATE_FORMAT(datum,'%d.%m.%Y') FROM kontoauszuege_zahlungseingang WHERE objekt = 'auftrag' AND parameter = :auftragid LIMIT 1\", ['auftragid' => (int) \$auftragid]), \$betreff);",
    'ZahlungsMail str_replace DATUM block'
);

rep($content,
    "    \$zahlungsmailauftrag = \$this->app->DB->Select(\"SELECT zahlungsmail FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "    \$zahlungsmailauftrag = \$this->app->DatabaseService->selectValue(\"SELECT zahlungsmail FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);",
    'ZahlungsMail SELECT zahlungsmail'
);

rep($content,
    "      \$tmpzahlungsmailauftrag = \$this->app->DB->Select(\"SELECT datum FROM auftrag WHERE id='\$auftragid' LIMIT 1\");",
    "      \$tmpzahlungsmailauftrag = \$this->app->DatabaseService->selectValue(\"SELECT datum FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);",
    'ZahlungsMail SELECT datum'
);

rep($content,
    "    \$tage = \$this->app->DB->Select(\"SELECT DATEDIFF(NOW(),'\$tmpzahlungsmailauftrag')\");",
    "    \$tage = \$this->app->DatabaseService->selectValue(\"SELECT DATEDIFF(NOW(), :datum)\", ['datum' => \$tmpzahlungsmailauftrag]);",
    'ZahlungsMail SELECT DATEDIFF'
);

rep($content,
    "      \$this->app->DB->Update(\"UPDATE auftrag SET zahlungsmail=NOW(),zahlungsmailcounter='\$zahlungsmailcounter' WHERE id='\$auftragid' LIMIT 1\");",
    "      \$this->app->DatabaseService->execute(\"UPDATE auftrag SET zahlungsmail = NOW(), zahlungsmailcounter = :counter WHERE id = :id LIMIT 1\", ['counter' => (int) \$zahlungsmailcounter, 'id' => (int) \$auftragid]);",
    'ZahlungsMail UPDATE zahlungsmail (first)'
);

rep($content,
    "            \$this->app->DB->Update(\"UPDATE auftrag SET zahlungsmail=NOW(),zahlungsmailcounter='3' WHERE id='\$auftragid' LIMIT 1\");{$n}            \$this->app->DB->Update(\"UPDATE auftrag SET zahlungsmail=NOW(),zahlungsmailcounter='1' WHERE id='\$auftragid' LIMIT 1\");",
    "            \$this->app->DatabaseService->execute(\"UPDATE auftrag SET zahlungsmail = NOW(), zahlungsmailcounter = 3 WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);{$n}            \$this->app->DatabaseService->execute(\"UPDATE auftrag SET zahlungsmail = NOW(), zahlungsmailcounter = 1 WHERE id = :id LIMIT 1\", ['id' => (int) \$auftragid]);",
    'ZahlungsMail UPDATE zahlungsmail counter 3/1 block'
);

// ============================================================
// RechnungMail (~15354)
// ============================================================
rep($content,
    "      \$sprache = \$this->app->DB->Select(\"SELECT sprache FROM adresse WHERE id='\$adresse' LIMIT 1\");",
    "      \$sprache = \$this->app->DatabaseService->selectValue(\"SELECT sprache FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);",
    'RechnungMail SELECT sprache adresse'
);

rep($content,
    "      \$rechnung_cc = \$this->app->DB->Select(\"SELECT rechnung_cc FROM adresse WHERE id = '\$adresse' LIMIT 1\");",
    "      \$rechnung_cc = \$this->app->DatabaseService->selectValue(\"SELECT rechnung_cc FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);",
    'RechnungMail SELECT rechnung_cc'
);

rep($content,
    "        \$this->app->DB->Update(\"UPDATE rechnung SET versendet='1',schreibschutz='1' WHERE id='\$id' LIMIT 1\");{$n}        \$this->app->DB->Update(\"UPDATE rechnung SET status='versendet' WHERE status!='storniert' AND id='\$id' LIMIT 1\");",
    "        \$this->app->DatabaseService->execute(\"UPDATE rechnung SET versendet = 1, schreibschutz = 1 WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);{$n}        \$this->app->DatabaseService->execute(\"UPDATE rechnung SET status = 'versendet' WHERE status != 'storniert' AND id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'RechnungMail UPDATE versendet+status'
);

// ============================================================
// VersandMail (~15596)
// ============================================================
rep($content,
    "      \$versandarr = \$this->app->DB->SelectRow(\"SELECT * FROM versand WHERE id='\$id' LIMIT 1\");",
    "      \$versandarr = \$this->app->DatabaseService->selectRow(\"SELECT * FROM versand WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'VersandMail SelectRow versand (first)'
);

rep($content,
    "      \$lieferscheinarr = \$this->app->DB->SelectRow(\"SELECT * FROM lieferschein WHERE id='\$lieferscheinid' LIMIT 1\");",
    "      \$lieferscheinarr = \$this->app->DatabaseService->selectRow(\"SELECT * FROM lieferschein WHERE id = :id LIMIT 1\", ['id' => (int) \$lieferscheinid]);",
    'VersandMail SelectRow lieferschein (first)'
);

rep($content,
    "      \$auftragarr = \$this->app->DB->SelectRow(\"SELECT email,name,belegnr,internet,ihrebestellnummer,sprache,DATE_FORMAT(datum,'%d.%m.%Y') as datum_de FROM auftrag WHERE id='\$auftrag' LIMIT 1\");",
    "      \$auftragarr = \$this->app->DatabaseService->selectRow(\"SELECT email,name,belegnr,internet,ihrebestellnummer,sprache,DATE_FORMAT(datum,'%d.%m.%Y') as datum_de FROM auftrag WHERE id = :id LIMIT 1\", ['id' => (int) \$auftrag]);",
    'VersandMail SelectRow auftragarr'
);

rep($content,
    "      \$selbstabholermail = \$this->app->DB->Select(\"SELECT selbstabholermail FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "      \$selbstabholermail = \$this->app->DatabaseService->selectValue(\"SELECT selbstabholermail FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);",
    'VersandMail SELECT selbstabholermail'
);

// ============================================================
// Lieferschein getDPDKundennr (~14334)
// ============================================================
rep($content,
    "    \$kundennummerdpd = \$this->app->DB->Select(\"SELECT dpdkundennr FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "    \$kundennummerdpd = \$this->app->DatabaseService->selectValue(\"SELECT dpdkundennr FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);",
    'getDPDKundennr SELECT dpdkundennr'
);

// ============================================================
// Gewicht/Versand related (~14386)
// ============================================================
rep($content,
    "      \$versandart = \$this->app->DB->Select(\"SELECT versandart FROM lieferschein WHERE id='\$id' LIMIT 1\");{$n}      \$projekt = \$this->app->DB->Select(\"SELECT projekt FROM lieferschein WHERE id='\$id' LIMIT 1\");{$n}      \$intraship_weightinkg = \$this->app->DB->Select(\"SELECT intraship_weightinkg FROM projekt WHERE id='\$projekt' LIMIT 1\");",
    "      \$versandart = \$this->app->DatabaseService->selectValue(\"SELECT versandart FROM lieferschein WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);{$n}      \$projekt = \$this->app->DatabaseService->selectValue(\"SELECT projekt FROM lieferschein WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);{$n}      \$intraship_weightinkg = \$this->app->DatabaseService->selectValue(\"SELECT intraship_weightinkg FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$projekt]);",
    'Gewicht SELECT versandart+projekt+intraship_weightinkg'
);

// ============================================================
// ArtikelCreateVariante related (~16650)
// ============================================================
rep($content,
    "    \$artikelarr = \$this->app->DB->SelectRow(\"SELECT * FROM artikel WHERE id = '\$artikel' LIMIT 1\");",
    "    \$artikelarr = \$this->app->DatabaseService->selectRow(\"SELECT * FROM artikel WHERE id = :id LIMIT 1\", ['id' => (int) \$artikel]);",
    'ArtikelCreateVariante SelectRow artikel'
);

rep($content,
    "      if (!\$this->app->DB->Select(\"SELECT id FROM artikel_onlineshops WHERE artikel = '\$artikel' AND shop = '\$shop' LIMIT 1\")) {",
    "      if (!\$this->app->DatabaseService->selectValue(\"SELECT id FROM artikel_onlineshops WHERE artikel = :artikel AND shop = :shop LIMIT 1\", ['artikel' => (int) \$artikel, 'shop' => (int) \$shop])) {",
    'ArtikelCreateVariante SELECT artikel_onlineshops check'
);

rep($content,
    "      \$eks = \$this->app->DB->SelectArr(\"SELECT * FROM einkaufspreise WHERE artikel = '\$artikel'\");",
    "      \$eks = \$this->app->DatabaseService->select(\"SELECT * FROM einkaufspreise WHERE artikel = :artikel\", ['artikel' => (int) \$artikel]);",
    'ArtikelCreateVariante SelectArr einkaufspreise'
);

rep($content,
    "      \$stueckliste = \$this->app->DB->SelectArr(\"SELECT s.*, a.variante_kopie FROM stueckliste s INNER JOIN artikel a ON a.id = s.artikel WHERE stuecklistevonartikel = '\$artikelvon'\");",
    "      \$stueckliste = \$this->app->DatabaseService->select(\"SELECT s.*, a.variante_kopie FROM stueckliste s INNER JOIN artikel a ON a.id = s.artikel WHERE stuecklistevonartikel = :artikelvon\", ['artikelvon' => (int) \$artikelvon]);",
    'ArtikelCreateVariante SelectArr stueckliste'
);

// ============================================================
// Save file
// ============================================================
file_put_contents($filepath, $content);
echo "\nTotal: $total\n";
echo "Done.\n";
