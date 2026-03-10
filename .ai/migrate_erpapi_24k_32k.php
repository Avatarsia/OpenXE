<?php
/**
 * SQL Injection Migration Script — class.erpapi.php lines 24000–32000
 * Replaces raw string interpolation DB calls with DatabaseService named params.
 * Run: php .ai/migrate_erpapi_24k_32k.php
 */

$filepath = __DIR__ . '/../www/lib/class.erpapi.php';
$content = file_get_contents($filepath);
$original = $content;
$fixes = [];

// ─── Helper ──────────────────────────────────────────────────────────────────
function applyFix(string &$content, string $old, string $new, string $label, array &$fixes): void {
    if (strpos($content, $old) !== false) {
        $content = str_replace($old, $new, $content);
        $fixes[] = "APPLIED: $label";
    } else {
        $fixes[] = "NOT FOUND: $label";
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 1 — NewEvent INSERT (~line 24327)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
    $this->app->DB->Insert("INSERT INTO event (id,beschreibung,kategorie,zeit,objekt,parameter,bearbeiter)
        VALUES('','$beschreibung','$kategorie',NOW(),'$objekt','$parameter','$bearbeiter')");
OLD,
    <<<'NEW'
    $this->app->DatabaseService->execute(
      "INSERT INTO event (id,beschreibung,kategorie,zeit,objekt,parameter,bearbeiter)
        VALUES('', :beschreibung, :kategorie, NOW(), :objekt, :parameter, :bearbeiter)",
      [
        ':beschreibung' => $beschreibung,
        ':kategorie'    => $kategorie,
        ':objekt'       => $objekt,
        ':parameter'    => $parameter,
        ':bearbeiter'   => $bearbeiter,
      ]
    );
NEW,
    'NewEvent INSERT',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 2 — UpdateArtikelChecksum SELECT (~line 24345)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
    $tmp = $this->app->DB->SelectArr("SELECT typ,
        nummer, projekt, inaktiv, warengruppe, name_de, name_en, kurztext_de, ausverkauft,
        kurztext_en , beschreibung_de, beschreibung_en,standardbild, herstellerlink, hersteller, uebersicht_de,uebersicht_en,links_de,links_en, startseite_de, startseite_en,
        lieferzeit , lieferzeitmanuell, wichtig,  gewicht, sperrgrund,  gueltigbis,umsatzsteuer,  klasse,  adresse, shop, firma, neu,topseller,startseite,
        (SELECT MAX(preis) FROM verkaufspreise WHERE
         artikel='$artikel' AND (gueltig_bis>=NOW() OR gueltig_bis='0000-00-00') AND ab_menge = 1 AND (adresse='0' OR adresse='')) as preis
        FROM artikel WHERE id='$artikel' LIMIT 1");
OLD,
    <<<'NEW'
    $tmp = $this->app->DatabaseService->select(
      "SELECT typ,
        nummer, projekt, inaktiv, warengruppe, name_de, name_en, kurztext_de, ausverkauft,
        kurztext_en , beschreibung_de, beschreibung_en,standardbild, herstellerlink, hersteller, uebersicht_de,uebersicht_en,links_de,links_en, startseite_de, startseite_en,
        lieferzeit , lieferzeitmanuell, wichtig,  gewicht, sperrgrund,  gueltigbis,umsatzsteuer,  klasse,  adresse, shop, firma, neu,topseller,startseite,
        (SELECT MAX(preis) FROM verkaufspreise WHERE
         artikel = :artikel AND (gueltig_bis>=NOW() OR gueltig_bis='0000-00-00') AND ab_menge = 1 AND (adresse='0' OR adresse='')) as preis
        FROM artikel WHERE id = :artikel2 LIMIT 1",
      [':artikel' => $artikel, ':artikel2' => $artikel]
    );
NEW,
    'UpdateArtikelChecksum SELECT',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 3 — UpdateArtikelChecksum UPDATE (~line 24358)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    '$this->app->DB->Update("UPDATE artikel SET checksum=\'$checksum\' WHERE id=\'$artikel\' LIMIT 1");',
    <<<'NEW'
$this->app->DatabaseService->execute(
      "UPDATE artikel SET checksum = :checksum WHERE id = :artikel LIMIT 1",
      [':checksum' => $checksum, ':artikel' => $artikel]
    );
NEW,
    'UpdateArtikelChecksum UPDATE',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 4 — GetSteuersatzNormal provisionsgutschrift SELECT (~line 24445)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    '$steuersatz = $this->app->DB->Select("SELECT steuersatz FROM mlm_abrechnung_adresse WHERE id=\'$id\' LIMIT 1");',
    <<<'NEW'
$steuersatz = $this->app->DatabaseService->selectValue(
        "SELECT steuersatz FROM mlm_abrechnung_adresse WHERE id = :id LIMIT 1",
        [':id' => $id]
      );
NEW,
    'GetSteuersatzNormal provisionsgutschrift SELECT',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 5 — GetSteuersatzNormal dynamic table SELECT (~line 24447)
// The table $typ is passed by callers; we validate it via validateIdentifier.
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    '      $steuersatz = $this->app->DB->Select("SELECT steuersatz_normal FROM $typ WHERE id=\'$id\' LIMIT 1");',
    <<<'NEW'
      $safeTyp = $this->app->DatabaseService->validateIdentifier($typ);
      $steuersatz = $this->app->DatabaseService->selectValue(
        "SELECT steuersatz_normal FROM `{$safeTyp}` WHERE id = :id LIMIT 1",
        [':id' => $id]
      );
NEW,
    'GetSteuersatzNormal dynamic table SELECT',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 6 — GetSteuersatzErmaessigt dynamic table SELECT (~line 24461)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    '      $steuersatz = $this->app->DB->Select("SELECT steuersatz_ermaessigt FROM $typ WHERE id=\'$id\' LIMIT 1");',
    <<<'NEW'
      $safeTyp2 = $this->app->DatabaseService->validateIdentifier($typ);
      $steuersatz = $this->app->DatabaseService->selectValue(
        "SELECT steuersatz_ermaessigt FROM `{$safeTyp2}` WHERE id = :id LIMIT 1",
        [':id' => $id]
      );
NEW,
    'GetSteuersatzErmaessigt dynamic table SELECT',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 7 — MailSendFinal imap_sentfolder_aktiv SELECT (~line 24915)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    '$imap_aktiv = $this->app->DB->Select("SELECT imap_sentfolder_aktiv FROM emailbackup WHERE email=\'' . "'" . ' . $from . \'' . "'" . '\' AND imap_sentfolder!=\'\' AND geloescht!=1 LIMIT 1");',
    // The above won't match due to quoting complexity — handle via literal string below
    '', '', $fixes
);
// Redo fix 7 with exact literal
$old7 = 'SELECT imap_sentfolder_aktiv FROM emailbackup WHERE email=\'" . $from . "\' AND imap_sentfolder!=\'\' AND geloescht!=1 LIMIT 1"';
// Use a targeted approach
$marker7 = '$imap_aktiv = $this->app->DB->Select("SELECT imap_sentfolder_aktiv FROM emailbackup WHERE email=\'" . $from . "\' AND imap_sentfolder!=\'\'';
if (strpos($content, $marker7) !== false) {
    $content = preg_replace(
        '/\$imap_aktiv = \$this->app->DB->Select\("SELECT imap_sentfolder_aktiv FROM emailbackup WHERE email=\'" \. \$from \. "\' AND imap_sentfolder!=\'\' AND geloescht!=1 LIMIT 1"\);/',
        '$imap_aktiv = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT imap_sentfolder_aktiv FROM emailbackup WHERE email = :email AND imap_sentfolder != \'\' AND geloescht != 1 LIMIT 1",' . "\n" .
        '        [\':email\' => $from]' . "\n" .
        '      );',
        $content,
        1
    );
    $fixes[] = 'APPLIED: MailSendFinal imap_sentfolder_aktiv SELECT';
} else {
    $fixes[] = 'NOT FOUND: MailSendFinal imap_sentfolder_aktiv SELECT';
}

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 8 — MailSendFinal imap_data SelectRow (~line 24942)
// ═══════════════════════════════════════════════════════════════════════════════
$marker8 = '$imap_data = $this->app->DB->SelectRow("SELECT * FROM emailbackup WHERE email=\'" . $from . "\' AND geloescht!=1 LIMIT 1");';
if (strpos($content, $marker8) !== false) {
    $content = str_replace(
        $marker8,
        '$imap_data = $this->app->DatabaseService->selectRow(' . "\n" .
        '          "SELECT * FROM emailbackup WHERE email = :email AND geloescht != 1 LIMIT 1",' . "\n" .
        '          [\':email\' => $from]' . "\n" .
        '        );',
        $content
    );
    $fixes[] = 'APPLIED: MailSendFinal imap_data SelectRow';
} else {
    $fixes[] = 'NOT FOUND: MailSendFinal imap_data SelectRow';
}

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 9 — Signatur SELECT (~line 25492)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
    $signatur = $this->app->DB->Select("SELECT signatur FROM emailbackup WHERE email='$from' AND email!='' AND eigenesignatur=1 AND geloescht!=1 LIMIT 1");
OLD,
    <<<'NEW'
    $signatur = $this->app->DatabaseService->selectValue(
      "SELECT signatur FROM emailbackup WHERE email = :email AND email != '' AND eigenesignatur = 1 AND geloescht != 1 LIMIT 1",
      [':email' => $from]
    );
NEW,
    'Signatur SELECT emailbackup',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 10 — UpdateArbeitszeit SELECT adresse_abrechnung (~line 26921)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    "      \$kunde = \$this->app->DB->Select(\"SELECT adresse_abrechnung FROM zeiterfassung WHERE  id='\$id'\");",
    <<<'NEW'
      $kunde = $this->app->DatabaseService->selectValue(
        "SELECT adresse_abrechnung FROM zeiterfassung WHERE id = :id",
        [':id' => $id]
      );
NEW,
    'UpdateArbeitszeit SELECT adresse_abrechnung',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 11 — UpdateArbeitszeit main UPDATE (~line 26928)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
    $this->app->DB->Update("UPDATE zeiterfassung SET aufgabe='$aufgabe',adresse='$adr_id',arbeitspaket='$paketauswahl',ort='$ort',beschreibung='$beschreibung', projekt='$projekt',art='$art',
            von='$vonZeit',bis='$bisZeit',adresse_abrechnung='$kunde',abrechnen='$abrechnen',kostenstelle='$kostenstelle', verrechnungsart='$verrechnungsart', abgerechnet='$abgerechnet', ist_abgerechnet='$abgerechnet',gps='$gps',internerkommentar='$internerkommentar',auftrag='$auftrag',produktion='$produktion',auftragpositionid='$auftragpositionid' WHERE id='$id'");
OLD,
    <<<'NEW'
    $this->app->DatabaseService->execute(
      "UPDATE zeiterfassung SET
        aufgabe = :aufgabe,
        adresse = :adr_id,
        arbeitspaket = :paketauswahl,
        ort = :ort,
        beschreibung = :beschreibung,
        projekt = :projekt,
        art = :art,
        von = :vonZeit,
        bis = :bisZeit,
        adresse_abrechnung = :kunde,
        abrechnen = :abrechnen,
        kostenstelle = :kostenstelle,
        verrechnungsart = :verrechnungsart,
        abgerechnet = :abgerechnet,
        ist_abgerechnet = :abgerechnet2,
        gps = :gps,
        internerkommentar = :internerkommentar,
        auftrag = :auftrag,
        produktion = :produktion,
        auftragpositionid = :auftragpositionid
      WHERE id = :id",
      [
        ':aufgabe'          => $aufgabe,
        ':adr_id'           => $adr_id,
        ':paketauswahl'     => $paketauswahl,
        ':ort'              => $ort,
        ':beschreibung'     => $beschreibung,
        ':projekt'          => $projekt,
        ':art'              => $art,
        ':vonZeit'          => $vonZeit,
        ':bisZeit'          => $bisZeit,
        ':kunde'            => $kunde,
        ':abrechnen'        => $abrechnen,
        ':kostenstelle'     => $kostenstelle,
        ':verrechnungsart'  => $verrechnungsart,
        ':abgerechnet'      => $abgerechnet,
        ':abgerechnet2'     => $abgerechnet,
        ':gps'              => $gps,
        ':internerkommentar' => $internerkommentar,
        ':auftrag'          => $auftrag,
        ':produktion'       => $produktion,
        ':auftragpositionid' => $auftragpositionid,
        ':id'               => $id,
      ]
    );
NEW,
    'UpdateArbeitszeit main UPDATE',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 12 — UpdateArbeitszeit arbeitsnachweisposition UPDATE (~line 26937)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
      $this->app->DB->Update("UPDATE arbeitsnachweis_position SET bezeichnung='$aufgabe',beschreibung='$beschreibung',ort='$ort', von='$von',bis='$bis',
              adresse='$adr_id' WHERE id='$arbeitsnachweisposid' LIMIT 1");
OLD,
    <<<'NEW'
      $this->app->DatabaseService->execute(
        "UPDATE arbeitsnachweis_position SET
          bezeichnung = :aufgabe,
          beschreibung = :beschreibung,
          ort = :ort,
          von = :von,
          bis = :bis,
          adresse = :adr_id
        WHERE id = :arbeitsnachweisposid LIMIT 1",
        [
          ':aufgabe'           => $aufgabe,
          ':beschreibung'      => $beschreibung,
          ':ort'               => $ort,
          ':von'               => $von,
          ':bis'               => $bis,
          ':adr_id'            => $adr_id,
          ':arbeitsnachweisposid' => $arbeitsnachweisposid,
        ]
      );
NEW,
    'UpdateArbeitszeit arbeitsnachweis_position UPDATE',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 13 — UpdateArbeitszeit stundensatz SELECT (~line 26941)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    "    if (!\$preis)\n      \$preis = \$this->app->DB->Select(\"SELECT stundensatz FROM zeiterfassung WHERE id = '\$id' LIMIT 1\");",
    <<<'NEW'
    if (!$preis)
      $preis = $this->app->DatabaseService->selectValue(
        "SELECT stundensatz FROM zeiterfassung WHERE id = :id LIMIT 1",
        [':id' => $id]
      );
NEW,
    'UpdateArbeitszeit stundensatz SELECT',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 14 — UpdateArbeitszeit zeiterfassung_kosten stundensatz SELECT (~line 26943)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
      $stundensatz = (float) $this->app->DB->Select("SELECT stundensatz FROM `zeiterfassung_kosten` WHERE adresse = '$adr_id' AND (gueltig_ab >= date(von) OR gueltig_ab = '0000-00-00') ORDER by gueltig_ab = '0000-00-00', gueltig_ab LIMIT 1");
      if ($stundensatz)
        $this->app->DB->Update("UPDATE zeiterfassung SET stundensatz = '$stundensatz' WHERE id = '$id' LIMIT 1");
OLD,
    <<<'NEW'
      $stundensatz = (float) $this->app->DatabaseService->selectValue(
        "SELECT stundensatz FROM `zeiterfassung_kosten` WHERE adresse = :adr_id AND (gueltig_ab >= date(von) OR gueltig_ab = '0000-00-00') ORDER BY gueltig_ab = '0000-00-00', gueltig_ab LIMIT 1",
        [':adr_id' => $adr_id]
      );
      if ($stundensatz)
        $this->app->DatabaseService->execute(
          "UPDATE zeiterfassung SET stundensatz = :stundensatz WHERE id = :id LIMIT 1",
          [':stundensatz' => $stundensatz, ':id' => $id]
        );
NEW,
    'UpdateArbeitszeit zeiterfassung_kosten stundensatz',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 15 — AddArbeitszeit auftrag adresse SELECT (~line 26958)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    "        \$kunde = \$this->app->DB->Select(\"SELECT adresse FROM auftrag WHERE id='\$auftrag' LIMIT 1\");

      if (\$produktion > 0 && \$kunde <= 0)\n        \$kunde = \$this->app->DB->Select(\"SELECT adresse FROM produktion WHERE id='\$produktion' LIMIT 1\");",
    <<<'NEW'
        $kunde = $this->app->DatabaseService->selectValue(
          "SELECT adresse FROM auftrag WHERE id = :id LIMIT 1",
          [':id' => $auftrag]
        );

      if ($produktion > 0 && $kunde <= 0)
        $kunde = $this->app->DatabaseService->selectValue(
          "SELECT adresse FROM produktion WHERE id = :id LIMIT 1",
          [':id' => $produktion]
        );
NEW,
    'AddArbeitszeit auftrag/produktion adresse SELECT (branch 1)',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 16 — AddArbeitszeit INSERT branch 1 (paketauswahl == 0) (~line 26970)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
      $insert = 'INSERT INTO zeiterfassung (adresse, von, bis, aufgabe, beschreibung, projekt, buchungsart,art,adresse_abrechnung,abrechnen,gebucht_von_user,ort,kostenstelle,verrechnungsart,abgerechnet,ist_abgerechnet,gps,aufgabe_id,internerkommentar,auftrag,produktion,auftragpositionid,serviceauftrag)
            VALUES (' . $adr_id . ',"' . $vonZeit . '","' . $bisZeit . '","' . $aufgabe . '", "' . $beschreibung . '",' . $projekt . ', "manuell","' . $art . '","' . $kunde . '","' . $abrechnen . '","' . $this->app->User->GetID() . '","' . $ort . '","' . $kostenstelle . '","' . $verrechnungsart . '","' . $abgerechnet . '","' . $abgerechnet . '","' . $gps . '","' . $aufgabeid . '","' . $internerkommentar . '","' . $auftrag . '","' . $produktion . '","' . $auftragpositionid . '","' . $serviceauftrag . '")';

    } else {
OLD,
    <<<'NEW'
      $newId1 = $this->app->DatabaseService->insert(
        "INSERT INTO zeiterfassung
          (adresse, von, bis, aufgabe, beschreibung, projekt, buchungsart, art, adresse_abrechnung, abrechnen,
           gebucht_von_user, ort, kostenstelle, verrechnungsart, abgerechnet, ist_abgerechnet, gps, aufgabe_id,
           internerkommentar, auftrag, produktion, auftragpositionid, serviceauftrag)
         VALUES
          (:adr_id, :vonZeit, :bisZeit, :aufgabe, :beschreibung, :projekt, 'manuell', :art, :kunde, :abrechnen,
           :gebucht_von_user, :ort, :kostenstelle, :verrechnungsart, :abgerechnet, :abgerechnet2, :gps, :aufgabeid,
           :internerkommentar, :auftrag, :produktion, :auftragpositionid, :serviceauftrag)",
        [
          ':adr_id'           => $adr_id,
          ':vonZeit'          => $vonZeit,
          ':bisZeit'          => $bisZeit,
          ':aufgabe'          => $aufgabe,
          ':beschreibung'     => $beschreibung,
          ':projekt'          => $projekt,
          ':art'              => $art,
          ':kunde'            => $kunde,
          ':abrechnen'        => $abrechnen,
          ':gebucht_von_user' => $this->app->User->GetID(),
          ':ort'              => $ort,
          ':kostenstelle'     => $kostenstelle,
          ':verrechnungsart'  => $verrechnungsart,
          ':abgerechnet'      => $abgerechnet,
          ':abgerechnet2'     => $abgerechnet,
          ':gps'              => $gps,
          ':aufgabeid'        => $aufgabeid,
          ':internerkommentar' => $internerkommentar,
          ':auftrag'          => $auftrag,
          ':produktion'       => $produktion,
          ':auftragpositionid' => $auftragpositionid,
          ':serviceauftrag'   => $serviceauftrag,
        ]
      );
      $insert = null; // branch 1 executed directly above

    } else {
NEW,
    'AddArbeitszeit INSERT branch 1',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 17 — AddArbeitszeit INSERT branch 2 (paketauswahl != 0) (~line 26981)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
      $insert = 'INSERT INTO zeiterfassung (adresse, von, bis, arbeitspaket, aufgabe, beschreibung, projekt, buchungsart,art,gebucht_von_user,ort,adresse_abrechnung,abrechnen,abgerechnet,ist_abgerechnet,gps,aufgabe_id,internerkommentar,auftrag,produktion,auftragpositionid,serviceauftrag) VALUES
          (' . $adr_id . ',"' . $vonZeit . '","' . $bisZeit . '",' . $paketauswahl . ' , "' . $aufgabe . '", "' . $beschreibung . '",' . $myArr["projekt"] . ', "AP","' . $art . '","' . $this->app->User->GetID() . '","' . $ort . '","' . $kunde . '","' . $abrechnen . '","' . $abgerechnet . '","' . $abgerechnet . '","' . $gps . '","' . $aufgabeid . '","' . $internerkommentar . '","' . $auftrag . '","' . $produktion . '","' . $auftragpositionid . '","' . $serviceauftrag . '")';
    }
    $this->app->DB->Insert($insert);
    $ret = $this->app->DB->GetInsertID();
OLD,
    <<<'NEW'
      $newId2 = $this->app->DatabaseService->insert(
        "INSERT INTO zeiterfassung
          (adresse, von, bis, arbeitspaket, aufgabe, beschreibung, projekt, buchungsart, art, gebucht_von_user,
           ort, adresse_abrechnung, abrechnen, abgerechnet, ist_abgerechnet, gps, aufgabe_id, internerkommentar,
           auftrag, produktion, auftragpositionid, serviceauftrag)
         VALUES
          (:adr_id, :vonZeit, :bisZeit, :paketauswahl, :aufgabe, :beschreibung, :projekt, 'AP', :art, :gebucht_von_user,
           :ort, :kunde, :abrechnen, :abgerechnet, :abgerechnet2, :gps, :aufgabeid, :internerkommentar,
           :auftrag, :produktion, :auftragpositionid, :serviceauftrag)",
        [
          ':adr_id'           => $adr_id,
          ':vonZeit'          => $vonZeit,
          ':bisZeit'          => $bisZeit,
          ':paketauswahl'     => $paketauswahl,
          ':aufgabe'          => $aufgabe,
          ':beschreibung'     => $beschreibung,
          ':projekt'          => $myArr['projekt'],
          ':art'              => $art,
          ':gebucht_von_user' => $this->app->User->GetID(),
          ':ort'              => $ort,
          ':kunde'            => $kunde,
          ':abrechnen'        => $abrechnen,
          ':abgerechnet'      => $abgerechnet,
          ':abgerechnet2'     => $abgerechnet,
          ':gps'              => $gps,
          ':aufgabeid'        => $aufgabeid,
          ':internerkommentar' => $internerkommentar,
          ':auftrag'          => $auftrag,
          ':produktion'       => $produktion,
          ':auftragpositionid' => $auftragpositionid,
          ':serviceauftrag'   => $serviceauftrag,
        ]
      );
      $insert = null; // branch 2 executed directly above
    }
    $ret = ($insert === null) ? ($newId1 ?? $newId2 ?? $this->app->DB->GetInsertID()) : ($this->app->DB->Insert($insert) ? $this->app->DB->GetInsertID() : 0);
NEW,
    'AddArbeitszeit INSERT branch 2 + ret',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 18 — AddArbeitszeit stundensatz UPDATE in AddArbeitszeit (~line 26994)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    "      if (\$stundensatz)\n        \$this->app->DB->Update(\"UPDATE zeiterfassung SET stundensatz = '\$stundensatz' WHERE id = '\$ret' LIMIT 1\");",
    <<<'NEW'
      if ($stundensatz)
        $this->app->DatabaseService->execute(
          "UPDATE zeiterfassung SET stundensatz = :stundensatz WHERE id = :id LIMIT 1",
          [':stundensatz' => $stundensatz, ':id' => $ret]
        );
NEW,
    'AddArbeitszeit stundensatz UPDATE',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 19 — ZeitUrlaubOffen stundenprowoche SELECT (double-quote injection, ~line 29730)
// ═══════════════════════════════════════════════════════════════════════════════
// These all use "adresse = \"$adresse\"" pattern — use preg_replace
$patterns_zeitUrlaub = [
    [
        '/\$stundenprowoche = \$this->app->DB->Select\("SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \\\\"\\$adresse\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$stundenprowoche = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':jahr\' => $jahr]' . "\n" .
        '      );',
        'ZeitUrlaubOffen stundenprowoche'
    ],
    [
        '/\$ueberstundentoleranz = \$this->app->DB->Select\("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre wHERE adresse = \\\\"\\$adresse\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$ueberstundentoleranz = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':jahr\' => $jahr]' . "\n" .
        '      );',
        'ZeitUrlaubOffen ueberstundentoleranz'
    ],
    [
        '/\$urlaubimjahr = \$this->app->DB->Select\("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \\\\"\\$adresse\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$urlaubimjahr = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':jahr\' => $jahr]' . "\n" .
        '      );',
        'ZeitUrlaubOffen urlaubimjahr'
    ],
    [
        '/\$restueberstunden = \$this->app->DB->Select\("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \\\\"\\$adresse\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$restueberstunden = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':jahr\' => $jahr]' . "\n" .
        '      );',
        'ZeitUrlaubOffen restueberstunden'
    ],
    [
        '/\$resturlaub = \$this->app->DB->Select\("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \\\\"\\$adresse\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$resturlaub = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':jahr\' => $jahr]' . "\n" .
        '      );',
        'ZeitUrlaubOffen resturlaub'
    ],
    [
        '/\$restnotiz = \$this->app->DB->Select\("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \\\\"\\$adresse\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$restnotiz = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':jahr\' => $jahr]' . "\n" .
        '      );',
        'ZeitUrlaubOffen restnotiz'
    ],
    [
        '/\$gesamtsummesoll = \$this->app->DB->Select\("SELECT SUM\(soll\) FROM zeiterfassung_stundenuebersicht WHERE adresse = \\\\"\\$adresse\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$gesamtsummesoll = $this->app->DatabaseService->selectValue(' . "\n" .
        '      "SELECT SUM(soll) FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND jahr = :jahr",' . "\n" .
        '      [\':adresse\' => $adresse, \':jahr\' => $jahr]' . "\n" .
        '      );',
        'ZeitUrlaubOffen gesamtsummesoll'
    ],
    [
        '/\$asoll2\[\$i\] = \$this->app->DB->Select\("SELECT soll FROM zeiterfassung_stundenuebersicht WHERE adresse = \\\\"\\$adresse\\\\" AND monat = \\\\"\\$i\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$asoll2[$i] = $this->app->DatabaseService->selectValue(' . "\n" .
        '            "SELECT soll FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr",' . "\n" .
        '            [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]' . "\n" .
        '          );',
        'ZeitUrlaubOffen asoll2'
    ],
    [
        '/\$aueberstunden2\[\$i\] = \$this->app->DB->Select\("SELECT ueberstunden_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = \\\\"\\$adresse\\\\" AND monat = \\\\"\\$i\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$aueberstunden2[$i] = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT ueberstunden_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]' . "\n" .
        '        );',
        'ZeitUrlaubOffen aueberstunden2'
    ],
    [
        '/\$aurlaub2\[\$i\] = \$this->app->DB->Select\("SELECT urlaub_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = \\\\"\\$adresse\\\\" AND monat = \\\\"\\$i\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$aurlaub2[$i] = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT urlaub_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]' . "\n" .
        '        );',
        'ZeitUrlaubOffen aurlaub2'
    ],
    [
        '/\$anotizen2\[\$i\] = \$this->app->DB->Select\("SELECT notizen FROM zeiterfassung_stundenuebersicht WHERE adresse = \\\\"\\$adresse\\\\" AND monat = \\\\"\\$i\\\\" AND jahr = \\\\"\\$jahr\\\\""\);/',
        '$anotizen2[$i] = $this->app->DatabaseService->selectValue(' . "\n" .
        '        "SELECT notizen FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr",' . "\n" .
        '        [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]' . "\n" .
        '        );',
        'ZeitUrlaubOffen anotizen2'
    ],
];

foreach ($patterns_zeitUrlaub as [$pattern, $replacement, $label]) {
    $new_content = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($new_content !== null && $count > 0) {
        $content = $new_content;
        $fixes[] = "APPLIED: $label";
    } else {
        $fixes[] = "NOT FOUND (regex): $label";
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 20 — ZeitUrlaubOffen stundenausadresse SELECT (~line 29757)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    "\$stundenausadresse = \$this->app->DB->Select(\"SELECT arbeitszeitprowoche FROM adresse WHERE id='\$adresse' AND id>0\");",
    <<<'NEW'
$stundenausadresse = $this->app->DatabaseService->selectValue(
        "SELECT arbeitszeitprowoche FROM adresse WHERE id = :adresse AND id > 0",
        [':adresse' => $adresse]
      );
NEW,
    'ZeitUrlaubOffen stundenausadresse SELECT',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 21 — ZeitUrlaubOffen loop IST query (~line 29776)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
        $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE 'Pause'
              AND DATE_FORMAT(z.von,'%Y-%m')='" . $jahr . "-" . ($i < 10 ? '0' : '') . $i . "' AND z.adresse='$adresse'";

        $aist2[$i] = $this->app->DB->Select($sql);
OLD,
    <<<'NEW'
        $monatStr = $jahr . '-' . ($i < 10 ? '0' : '') . $i;
        $aist2[$i] = $this->app->DatabaseService->selectValue(
          "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z
           WHERE z.art NOT LIKE 'Pause' AND DATE_FORMAT(z.von,'%Y-%m') = :monat AND z.adresse = :adresse",
          [':monat' => $monatStr, ':adresse' => $adresse]
        );
NEW,
    'ZeitUrlaubOffen loop IST query',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 22 — AddArtikelEigenschaft artikel existence check (~line 30679)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    "    if (!\$this->app->DB->Select(\"SELECT id FROM artikel WHERE id = '\$artikel' AND nummer <> 'DEL' AND ifnull(geloescht,0) = 0\")) {",
    <<<'NEW'
    if (!$this->app->DatabaseService->selectValue(
      "SELECT id FROM artikel WHERE id = :id AND nummer <> 'DEL' AND ifnull(geloescht,0) = 0",
      [':id' => $artikel]
    )) {
NEW,
    'AddArtikelEigenschaft artikel check',
    $fixes
);

// ═══════════════════════════════════════════════════════════════════════════════
// FIX 23 — AddArtikelEigenschaft checkkategorie SELECT + INSERT (~line 30682)
// ═══════════════════════════════════════════════════════════════════════════════
applyFix($content,
    <<<'OLD'
    $checkkategorie = $this->app->DB->Select("SELECT id FROM artikeleigenschaften WHERE name = '$name' AND geloescht <> 1 LIMIT 1");
    if (!$checkkategorie) {
      $this->app->DB->Insert("INSERT INTO artikeleigenschaften (name) values ('$name')");
      $checkkategorie = $this->app->DB->GetInsertID();
    }
    $checkwert = $this->app->DB->Select("SELECT id FROM artikeleigenschaftenwerte WHERE artikeleigenschaften = '$checkkategorie' AND artikel = '$artikel' AND wert = '$wert' LIMIT 1");
    if (!$checkwert) {
      $this->app->DB->Insert("INSERT INTO artikeleigenschaftenwerte (wert, artikeleigenschaften, artikel, einheit) values ('$wert','$checkkategorie','$artikel','$einheit')");
    }
OLD,
    <<<'NEW'
    $checkkategorie = $this->app->DatabaseService->selectValue(
      "SELECT id FROM artikeleigenschaften WHERE name = :name AND geloescht <> 1 LIMIT 1",
      [':name' => $name]
    );
    if (!$checkkategorie) {
      $checkkategorie = $this->app->DatabaseService->insert(
        "INSERT INTO artikeleigenschaften (name) VALUES (:name)",
        [':name' => $name]
      );
    }
    $checkwert = $this->app->DatabaseService->selectValue(
      "SELECT id FROM artikeleigenschaftenwerte WHERE artikeleigenschaften = :kategorie AND artikel = :artikel AND wert = :wert LIMIT 1",
      [':kategorie' => $checkkategorie, ':artikel' => $artikel, ':wert' => $wert]
    );
    if (!$checkwert) {
      $this->app->DatabaseService->insert(
        "INSERT INTO artikeleigenschaftenwerte (wert, artikeleigenschaften, artikel, einheit) VALUES (:wert, :kategorie, :artikel, :einheit)",
        [':wert' => $wert, ':kategorie' => $checkkategorie, ':artikel' => $artikel, ':einheit' => $einheit]
      );
    }
NEW,
    'AddArtikelEigenschaft checkkategorie + checkwert SELECT/INSERT',
    $fixes
);

// ─── Write file and report ────────────────────────────────────────────────────
if ($content !== $original) {
    file_put_contents($filepath, $content);
    echo "File written.\n\n";
} else {
    echo "No changes made to file.\n\n";
}

foreach ($fixes as $fix) {
    echo $fix . "\n";
}
