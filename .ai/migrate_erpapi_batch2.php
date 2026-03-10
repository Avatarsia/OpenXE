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

// ---- AddVerkaufspreisGruppe: SELECT check (gruppe > 0 branch) ----
$old = '    if ($gruppe > 0)
      $check = $this->app->DB->Select("SELECT id FROM verkaufspreise WHERE ab_menge=\'" . (int)$abmenge . "\' AND gruppe=\'" . (int)$gruppe . "\' AND artikel=\'" . (int)$artikel . "\'  AND art=\'Gruppe\'
        AND (gueltig_bis=\'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND geloescht!=\'1\' AND adresse <= 0 LIMIT 1");
    else
      $check = $this->app->DB->Select("SELECT id FROM verkaufspreise WHERE ab_menge=\'" . (int)$abmenge . "\' AND (gruppe=\'\' OR gruppe=\'0\') AND artikel=\'" . (int)$artikel . "\'  AND art=\'Gruppe\'
        AND (gueltig_bis=\'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND geloescht!=\'1\' AND adresse <= 0 LIMIT 1");';
$new = '    if ($gruppe > 0)
      $check = $this->app->DatabaseService->selectValue(
        "SELECT id FROM verkaufspreise WHERE ab_menge = :abmenge AND gruppe = :gruppe AND artikel = :artikel AND art = \'Gruppe\'
        AND (gueltig_bis = \'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND geloescht != \'1\' AND adresse <= 0 LIMIT 1",
        [\'abmenge\' => (int)$abmenge, \'gruppe\' => (int)$gruppe, \'artikel\' => (int)$artikel]
      );
    else
      $check = $this->app->DatabaseService->selectValue(
        "SELECT id FROM verkaufspreise WHERE ab_menge = :abmenge AND (gruppe = \'\' OR gruppe = \'0\') AND artikel = :artikel AND art = \'Gruppe\'
        AND (gueltig_bis = \'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND geloescht != \'1\' AND adresse <= 0 LIMIT 1",
        [\'abmenge\' => (int)$abmenge, \'artikel\' => (int)$artikel]
      );';
rep($content, $old, $new, 'AddVerkaufspreisGruppe SELECT check', $changes);

// ---- AddVerkaufspreisGruppe: UPDATE gueltig_bis (first check>0 branch) ----
rep($content,
    '        $this->app->DB->Update("UPDATE verkaufspreise SET gueltig_bis=DATE_SUB($gueltigabwhere,INTERVAL 1 DAY),apichange=1 WHERE id=\'" . (int)$check . "\' LIMIT 1");',
    '        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_bis = DATE_SUB($gueltigabwhere, INTERVAL 1 DAY), apichange = 1 WHERE id = :id LIMIT 1", [\'id\' => (int)$check]);',
    'AddVerkaufspreisGruppe UPDATE gueltig_bis check', $changes
);

// ---- AddVerkaufspreisGruppe: GetInsertID after DatabaseService insert (line 13824) ----
// Note: $insid = $this->app->DB->GetInsertID() — these are used after DatabaseService->execute INSERT
// We can't easily change GetInsertID unless we refactor the insert. Keep as-is for now (not injection risk).

// ---- AddVerkaufspreisGruppe UPDATE kurs (first branch, line 13835) ----
$old = '          $this->app->DB->Update("UPDATE verkaufspreise SET kurs = $kurs, kursdatum = $kursdatum WHERE id = " . (int)$insid . " LIMIT 1");
        }
        if ($gueltig_ab)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_ab = :gueltig_ab WHERE id = :id LIMIT 1", [\'gueltig_ab\' => $gueltig_ab, \'id\' => $insid]);
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $insid]);
      } else {
        // nur attribute update
        if ($kundenartikelnummer != "") {
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET kundenartikelnummer = :knr, apichange = 1 WHERE id = :id LIMIT 1", [\'knr\' => $kundenartikelnummer, \'id\' => $check]);
        } else {
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET apichange = 1 WHERE id = :id LIMIT 1", [\'id\' => $check]);
        }
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $check]);
      }
    } else {
      $this->app->DatabaseService->execute(
        "INSERT INTO verkaufspreise (id,gruppe,artikel,angelegt_am,ab_menge,waehrung,preis,firma,kundenartikelnummer,adresse,art,apichange) VALUES (\'\', :gruppe, :artikel, NOW(), :abmenge, :waehrung, :preis, :firma, :kundenartikelnummer, 0, \'Gruppe\', 1)",
        [\'gruppe\' => $gruppe, \'artikel\' => $artikel, \'abmenge\' => $abmenge, \'waehrung\' => $waehrung, \'preis\' => $preis, \'firma\' => $this->app->User->GetFirma(), \'kundenartikelnummer\' => $kundenartikelnummer]
      );
      $insid = $this->app->DB->GetInsertID();
      if ($waehrung !== \'EUR\' && $waehrung !== \'\') {
        $kurs = $this->app->erp->GetWaehrungUmrechnungskurs(\'EUR\', $waehrung, true);
      } else {
        $kurs = false;
      }
      if ($kurs !== false) {
        if ($kurs !== -1) {
          $kursdatum = "\'" . date(\'Y-m-d\') . "\'";
        }
        $this->app->DB->Update("UPDATE verkaufspreise SET kurs = $kurs, kursdatum = $kursdatum WHERE id = " . (int)$insid . " LIMIT 1");
      }';
$new = '          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET kurs = :kurs, kursdatum = $kursdatum WHERE id = :id LIMIT 1", [\'kurs\' => $kurs, \'id\' => (int)$insid]);
        }
        if ($gueltig_ab)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_ab = :gueltig_ab WHERE id = :id LIMIT 1", [\'gueltig_ab\' => $gueltig_ab, \'id\' => $insid]);
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $insid]);
      } else {
        // nur attribute update
        if ($kundenartikelnummer != "") {
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET kundenartikelnummer = :knr, apichange = 1 WHERE id = :id LIMIT 1", [\'knr\' => $kundenartikelnummer, \'id\' => $check]);
        } else {
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET apichange = 1 WHERE id = :id LIMIT 1", [\'id\' => $check]);
        }
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $check]);
      }
    } else {
      $this->app->DatabaseService->execute(
        "INSERT INTO verkaufspreise (id,gruppe,artikel,angelegt_am,ab_menge,waehrung,preis,firma,kundenartikelnummer,adresse,art,apichange) VALUES (\'\', :gruppe, :artikel, NOW(), :abmenge, :waehrung, :preis, :firma, :kundenartikelnummer, 0, \'Gruppe\', 1)",
        [\'gruppe\' => $gruppe, \'artikel\' => $artikel, \'abmenge\' => $abmenge, \'waehrung\' => $waehrung, \'preis\' => $preis, \'firma\' => $this->app->User->GetFirma(), \'kundenartikelnummer\' => $kundenartikelnummer]
      );
      $insid = $this->app->DB->GetInsertID();
      if ($waehrung !== \'EUR\' && $waehrung !== \'\') {
        $kurs = $this->app->erp->GetWaehrungUmrechnungskurs(\'EUR\', $waehrung, true);
      } else {
        $kurs = false;
      }
      if ($kurs !== false) {
        if ($kurs !== -1) {
          $kursdatum = "\'" . date(\'Y-m-d\') . "\'";
        }
        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET kurs = :kurs, kursdatum = $kursdatum WHERE id = :id LIMIT 1", [\'kurs\' => $kurs, \'id\' => (int)$insid]);
      }';
rep($content, $old, $new, 'AddVerkaufspreisGruppe UPDATE kurs (both branches)', $changes);

// ---- AddVerkaufspreis: SELECT check adresse>0 and adresse=0 ----
$old = '    if ($adresse > 0)
      $check = $this->app->DB->Select("SELECT id FROM verkaufspreise WHERE ab_menge=" . $abmenge . " " . $where . " AND adresse=" . $adresse . " AND artikel=$artikel AND art=\'Kunde\'
        AND (gueltig_bis=\'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND (gueltig_ab=\'0000-00-00\' OR gueltig_ab <= $gueltigabwhere) AND geloescht!=\'1\' " . ($gruppe ? " AND gruppe = \'$gruppe\' " : \'\') . " LIMIT 1");
    else
      $check = $this->app->DB->Select("SELECT id FROM verkaufspreise WHERE ab_menge=" . $abmenge . " " . $where . " AND (adresse=\'\' OR adresse=0) AND artikel=$artikel AND art=\'Kunde\'
        AND (gueltig_bis=\'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND (gueltig_ab=\'0000-00-00\' OR gueltig_ab <= $gueltigabwhere) AND geloescht!=1 " . ($gruppe ? " AND gruppe = \'$gruppe\' " : \'\') . " LIMIT 1");';
$new = '    $gruppeWhere = $gruppe ? " AND gruppe = :gruppe " : "";
    $gruppeParams = $gruppe ? [\'gruppe\' => $gruppe] : [];
    if ($adresse > 0)
      $check = $this->app->DatabaseService->selectValue(
        "SELECT id FROM verkaufspreise WHERE ab_menge = :abmenge $where AND adresse = :adresse AND artikel = :artikel AND art = \'Kunde\'
        AND (gueltig_bis = \'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND (gueltig_ab = \'0000-00-00\' OR gueltig_ab <= $gueltigabwhere) AND geloescht != \'1\' $gruppeWhere LIMIT 1",
        array_merge([\'abmenge\' => $abmenge, \'adresse\' => $adresse, \'artikel\' => $artikel], $gruppeParams)
      );
    else
      $check = $this->app->DatabaseService->selectValue(
        "SELECT id FROM verkaufspreise WHERE ab_menge = :abmenge $where AND (adresse = \'\' OR adresse = 0) AND artikel = :artikel AND art = \'Kunde\'
        AND (gueltig_bis = \'0000-00-00\' OR gueltig_bis >= $gueltigabwhere) AND (gueltig_ab = \'0000-00-00\' OR gueltig_ab <= $gueltigabwhere) AND geloescht != 1 $gruppeWhere LIMIT 1",
        array_merge([\'abmenge\' => $abmenge, \'artikel\' => $artikel], $gruppeParams)
      );';
rep($content, $old, $new, 'AddVerkaufspreis SELECT check', $changes);

// ---- AddVerkaufspreis: SELECT preis_alt ----
rep($content,
    '      $preis_alt = $this->app->DB->Select("SELECT preis FROM verkaufspreise WHERE id=\'$check\' LIMIT 1");',
    '      $preis_alt = $this->app->DatabaseService->selectValue("SELECT preis FROM verkaufspreise WHERE id = :id LIMIT 1", [\'id\' => $check]);',
    'AddVerkaufspreis SELECT preis_alt', $changes
);

// ---- AddVerkaufspreis: UPDATE gueltig_bis (check) and INSERT + GetInsertID + UPDATE kurs + gueltig_ab + gruppe + gueltig_bis + bemerkung (block) ----
$old = '        $this->app->DB->Update("UPDATE verkaufspreise SET gueltig_bis=DATE_SUB($gueltigabwhere,INTERVAL 1 DAY),apichange=1 WHERE id=\'$check\' LIMIT 1");
        $this->app->DB->Insert("INSERT INTO verkaufspreise (id,adresse,artikel,angelegt_am,
      ab_menge,waehrung,preis,firma,kundenartikelnummer,art,apichange,logdatei)
        VALUES (\'\',\'$adresse\',\'$artikel\',NOW(),\'$abmenge\',\'$waehrung\',\'$preis\',\'1\',\'" . $kundenartikelnummer . "\',\'Kunde\',1,now())");

        $insid = $this->app->DB->GetInsertID();
        if ($waehrung !== \'EUR\' && $waehrung !== \'\') {
          $kurs = $this->app->erp->GetWaehrungUmrechnungskurs(\'EUR\', $waehrung, true);
        } else {
          $kurs = -1;
          $kursdatum = \'NULL\';
        }
        if ($kurs !== false) {
          if ($kurs !== -1) {
            $kursdatum = "\'" . date(\'Y-m-d\') . "\'";
          }
          $this->app->DB->Update("UPDATE verkaufspreise SET kurs = $kurs, kursdatum = $kursdatum WHERE id = $insid LIMIT 1");
        }

        if ($gueltig_ab)
          $this->app->DB->Update("UPDATE verkaufspreise SET gueltig_ab = \'$gueltig_ab\' WHERE id = \'$insid\' LIMIT 1");
        $this->ObjektProtokoll(\'verkaufspreise\', $insid, \'AddVerkaufspreis\', "Verkaufspreis von $preis_alt nach $preis ge&auuml;ndert");
        if ($gruppe) {
          $this->app->DB->Update("UPDATE verkaufspreise set gruppe = \'" . $gruppe . "\' where id = \'" . $insid . "\'");
        }
        if ($gueltig_bis)
          $this->app->DB->Update("UPDATE verkaufspreise SET gueltig_bis = \'" . $this->app->DB->real_escape_string($gueltig_bis) . "\' WHERE id = \'$insid\' LIMIT 1");
        if ($interner_kommentar)
          $this->app->DB->Update("UPDATE verkaufspreise SET bemerkung = \'" . $this->app->DB->real_escape_string($interner_kommentar) . "\' WHERE id = \'$insid\' LIMIT 1");
        return $insid;
      } else {
        // nur attribute update
        if ($kundenartikelnummer != "") {
          $this->app->DB->Update("UPDATE verkaufspreise SET kundenartikelnummer=\'$kundenartikelnummer\',apichange=1 WHERE id=\'$check\' LIMIT 1");
          $this->ObjektProtokoll(\'verkaufspreise\', $check, \'AddVerkaufspreis\', "Kundenartikelnummer ge&auuml;ndert");
        } else {
          $this->app->DB->Update("UPDATE verkaufspreise SET apichange=1 WHERE id=\'$check\' LIMIT 1");
        }
        if ($interner_kommentar)
          $this->app->DB->Update("UPDATE verkaufspreise SET bemerkung = \'" . $this->app->DB->real_escape_string($interner_kommentar) . "\' WHERE id = \'$check\' LIMIT 1");
      }
      return $check;
    } else {
      $this->app->DB->Insert("INSERT INTO verkaufspreise (id,adresse,artikel,angelegt_am,
    ab_menge,waehrung,preis,firma,kundenartikelnummer,art,apichange,logdatei)
      VALUES (\'\',\'$adresse\',\'$artikel\',NOW(),\'$abmenge\',\'$waehrung\',\'$preis\',\'1\',\'" . $kundenartikelnummer . "\',\'Kunde\',1,now())");
      $insid = $this->app->DB->GetInsertID();
      if ($waehrung !== \'EUR\' && $waehrung !== \'\') {
        $kurs = $this->app->erp->GetWaehrungUmrechnungskurs(\'EUR\', $waehrung, true);
      } else {
        $kurs = false;
      }
      if ($kurs !== false) {
        $kursdatum = "\'" . date(\'Y-m-d\') . "\'";
        $this->app->DB->Update("UPDATE verkaufspreise SET kurs = $kurs, kursdatum = $kursdatum WHERE id = $insid LIMIT 1");
      }
      if ($gueltig_ab)
        $this->app->DB->Update("UPDATE verkaufspreise SET gueltig_ab = \'" . $this->app->DB->real_escape_string($gueltig_ab) . "\' WHERE id = \'$insid\' LIMIT 1");
      if ($gueltig_bis)
        $this->app->DB->Update("UPDATE verkaufspreise SET gueltig_bis = \'" . $this->app->DB->real_escape_string($gueltig_bis) . "\' WHERE id = \'$insid\' LIMIT 1");
      if ($gruppe) {
        $this->app->DB->Update("UPDATE verkaufspreise set gruppe = \'" . $gruppe . "\' where id = \'" . $insid . "\'");
      }
      if ($interner_kommentar)
        $this->app->DB->Update("UPDATE verkaufspreise SET bemerkung = \'" . $this->app->DB->real_escape_string($interner_kommentar) . "\' WHERE id = \'$insid\' LIMIT 1");
      $this->ObjektProtokoll(\'verkaufspreise\', $insid, \'AddVerkaufspreis\', "Verkaufspreis angelegt");
      return $insid;
    }
  }';
$new = '        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_bis = DATE_SUB($gueltigabwhere, INTERVAL 1 DAY), apichange = 1 WHERE id = :id LIMIT 1", [\'id\' => $check]);
        $this->app->DatabaseService->execute(
          "INSERT INTO verkaufspreise (id,adresse,artikel,angelegt_am,ab_menge,waehrung,preis,firma,kundenartikelnummer,art,apichange,logdatei) VALUES (\'\', :adresse, :artikel, NOW(), :abmenge, :waehrung, :preis, 1, :knr, \'Kunde\', 1, now())",
          [\'adresse\' => $adresse, \'artikel\' => $artikel, \'abmenge\' => $abmenge, \'waehrung\' => $waehrung, \'preis\' => $preis, \'knr\' => $kundenartikelnummer]
        );
        $insid = $this->app->DB->GetInsertID();
        if ($waehrung !== \'EUR\' && $waehrung !== \'\') {
          $kurs = $this->app->erp->GetWaehrungUmrechnungskurs(\'EUR\', $waehrung, true);
        } else {
          $kurs = -1;
          $kursdatum = \'NULL\';
        }
        if ($kurs !== false) {
          if ($kurs !== -1) {
            $kursdatum = "\'" . date(\'Y-m-d\') . "\'";
          }
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET kurs = :kurs, kursdatum = $kursdatum WHERE id = :id LIMIT 1", [\'kurs\' => $kurs, \'id\' => $insid]);
        }

        if ($gueltig_ab)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_ab = :gueltig_ab WHERE id = :id LIMIT 1", [\'gueltig_ab\' => $gueltig_ab, \'id\' => $insid]);
        $this->ObjektProtokoll(\'verkaufspreise\', $insid, \'AddVerkaufspreis\', "Verkaufspreis von $preis_alt nach $preis ge&auuml;ndert");
        if ($gruppe) {
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gruppe = :gruppe WHERE id = :id", [\'gruppe\' => $gruppe, \'id\' => $insid]);
        }
        if ($gueltig_bis)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_bis = :gueltig_bis WHERE id = :id LIMIT 1", [\'gueltig_bis\' => $gueltig_bis, \'id\' => $insid]);
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $insid]);
        return $insid;
      } else {
        // nur attribute update
        if ($kundenartikelnummer != "") {
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET kundenartikelnummer = :knr, apichange = 1 WHERE id = :id LIMIT 1", [\'knr\' => $kundenartikelnummer, \'id\' => $check]);
          $this->ObjektProtokoll(\'verkaufspreise\', $check, \'AddVerkaufspreis\', "Kundenartikelnummer ge&auuml;ndert");
        } else {
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET apichange = 1 WHERE id = :id LIMIT 1", [\'id\' => $check]);
        }
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE verkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $check]);
      }
      return $check;
    } else {
      $this->app->DatabaseService->execute(
        "INSERT INTO verkaufspreise (id,adresse,artikel,angelegt_am,ab_menge,waehrung,preis,firma,kundenartikelnummer,art,apichange,logdatei) VALUES (\'\', :adresse, :artikel, NOW(), :abmenge, :waehrung, :preis, 1, :knr, \'Kunde\', 1, now())",
        [\'adresse\' => $adresse, \'artikel\' => $artikel, \'abmenge\' => $abmenge, \'waehrung\' => $waehrung, \'preis\' => $preis, \'knr\' => $kundenartikelnummer]
      );
      $insid = $this->app->DB->GetInsertID();
      if ($waehrung !== \'EUR\' && $waehrung !== \'\') {
        $kurs = $this->app->erp->GetWaehrungUmrechnungskurs(\'EUR\', $waehrung, true);
      } else {
        $kurs = false;
      }
      if ($kurs !== false) {
        $kursdatum = "\'" . date(\'Y-m-d\') . "\'";
        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET kurs = :kurs, kursdatum = $kursdatum WHERE id = :id LIMIT 1", [\'kurs\' => $kurs, \'id\' => $insid]);
      }
      if ($gueltig_ab)
        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_ab = :gueltig_ab WHERE id = :id LIMIT 1", [\'gueltig_ab\' => $gueltig_ab, \'id\' => $insid]);
      if ($gueltig_bis)
        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_bis = :gueltig_bis WHERE id = :id LIMIT 1", [\'gueltig_bis\' => $gueltig_bis, \'id\' => $insid]);
      if ($gruppe) {
        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gruppe = :gruppe WHERE id = :id", [\'gruppe\' => $gruppe, \'id\' => $insid]);
      }
      if ($interner_kommentar)
        $this->app->DatabaseService->execute("UPDATE verkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $insid]);
      $this->ObjektProtokoll(\'verkaufspreise\', $insid, \'AddVerkaufspreis\', "Verkaufspreis angelegt");
      return $insid;
    }
  }';
rep($content, $old, $new, 'AddVerkaufspreis INSERT+UPDATE block (both branches)', $changes);

// ---- AddEinkaufspreis: SELECT check ----
$old = '    if ($testebestellnummer && $bestellnummer)
      $where = " AND bestellnummer = \'" . addslashes($bestellnummer) . "\' ";
    $check = $this->app->DB->Select("SELECT id FROM einkaufspreise WHERE ab_menge=\'" . $abmenge . "\' AND adresse=\'" . $adresse . "\' AND artikel=\'$artikel\'
      AND (gueltig_bis=\'0000-00-00\' OR gueltig_bis >= NOW()) AND geloescht!=\'1\' " . $where . " LIMIT 1");';
$new = '    if ($testebestellnummer && $bestellnummer)
      $where = " AND bestellnummer = \'" . addslashes($bestellnummer) . "\' ";
    $check = $this->app->DatabaseService->selectValue(
      "SELECT id FROM einkaufspreise WHERE ab_menge = :abmenge AND adresse = :adresse AND artikel = :artikel
      AND (gueltig_bis = \'0000-00-00\' OR gueltig_bis >= NOW()) AND geloescht != \'1\' $where LIMIT 1",
      [\'abmenge\' => $abmenge, \'adresse\' => $adresse, \'artikel\' => $artikel]
    );';
rep($content, $old, $new, 'AddEinkaufspreis SELECT check', $changes);

// ---- AddEinkaufspreis: SELECT preis_alt ----
rep($content,
    '      $preis_alt = $this->app->DB->Select("SELECT preis FROM einkaufspreise WHERE id=\'$check\' LIMIT 1");',
    '      $preis_alt = $this->app->DatabaseService->selectValue("SELECT preis FROM einkaufspreise WHERE id = :id LIMIT 1", [\'id\' => $check]);',
    'AddEinkaufspreis SELECT preis_alt', $changes
);

// ---- AddEinkaufspreis: UPDATE+INSERT+UPDATE (changed price branch) ----
$old = '        $this->app->DB->Update("UPDATE einkaufspreise SET gueltig_bis=DATE_SUB(NOW(),INTERVAL 1 DAY),apichange=1 WHERE id=\'$check\' LIMIT 1");
        //$this->AddEinkaufspreis($artikel,$abmenge,$adresse,$bestellnummer,$bezeichnunglieferant,$preis,$waehrung);
        $this->app->DB->Insert("INSERT INTO einkaufspreise (id,adresse,artikel,bestellnummer,bezeichnunglieferant, preis_anfrage_vom,
        ab_menge,waehrung,preis,firma,vpe,apichange,logdatei) VALUES
          (\'\',\'$adresse\',\'$artikel\',\'$bestellnummer\',\'$bezeichnunglieferant\',NOW(),\'$abmenge\',\'$waehrung\',\'$preis\',\'" . $this->app->User->GetFirma() . "\',\'$vpe\',1, now())");
        if ($interner_kommentar)
          $this->app->DB->Update("UPDATE einkaufspreise SET bemerkung = \'" . $this->app->DB->real_escape_string($interner_kommentar) . "\' WHERE id = \'$insid\' LIMIT 1");
        $insid = $this->app->DB->GetInsertID();';
$new = '        $this->app->DatabaseService->execute("UPDATE einkaufspreise SET gueltig_bis = DATE_SUB(NOW(), INTERVAL 1 DAY), apichange = 1 WHERE id = :id LIMIT 1", [\'id\' => $check]);
        //$this->AddEinkaufspreis($artikel,$abmenge,$adresse,$bestellnummer,$bezeichnunglieferant,$preis,$waehrung);
        $this->app->DatabaseService->execute(
          "INSERT INTO einkaufspreise (id,adresse,artikel,bestellnummer,bezeichnunglieferant,preis_anfrage_vom,ab_menge,waehrung,preis,firma,vpe,apichange,logdatei) VALUES (\'\', :adresse, :artikel, :bestellnummer, :bezeichnunglieferant, NOW(), :abmenge, :waehrung, :preis, :firma, :vpe, 1, now())",
          [\'adresse\' => $adresse, \'artikel\' => $artikel, \'bestellnummer\' => $bestellnummer, \'bezeichnunglieferant\' => $bezeichnunglieferant, \'abmenge\' => $abmenge, \'waehrung\' => $waehrung, \'preis\' => $preis, \'firma\' => $this->app->User->GetFirma(), \'vpe\' => $vpe]
        );
        $insid = $this->app->DB->GetInsertID();
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE einkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $insid]);';
rep($content, $old, $new, 'AddEinkaufspreis UPDATE+INSERT changed-price branch', $changes);

// ---- AddEinkaufspreis: UPDATE bestellnummer (same price branch) ----
$old = '        $this->app->DB->Update("UPDATE einkaufspreise SET bestellnummer=\'$bestellnummer\', bezeichnunglieferant=\'$bezeichnunglieferant\',apichange=1, logdatei = now()
          WHERE id=\'$check\' LIMIT 1");
        $this->ObjektProtokoll(\'einkaufspreise\', $check, \'AddEinkaufspreis\', "Einkaufspreis ge&auuml;ndert");
        if ($interner_kommentar)
          $this->app->DB->Update("UPDATE einkaufspreise SET bemerkung = \'" . $this->app->DB->real_escape_string($interner_kommentar) . "\' WHERE id = \'$check\' LIMIT 1");';
$new = '        $this->app->DatabaseService->execute(
          "UPDATE einkaufspreise SET bestellnummer = :bestellnummer, bezeichnunglieferant = :bezeichnunglieferant, apichange = 1, logdatei = now() WHERE id = :id LIMIT 1",
          [\'bestellnummer\' => $bestellnummer, \'bezeichnunglieferant\' => $bezeichnunglieferant, \'id\' => $check]
        );
        $this->ObjektProtokoll(\'einkaufspreise\', $check, \'AddEinkaufspreis\', "Einkaufspreis ge&auuml;ndert");
        if ($interner_kommentar)
          $this->app->DatabaseService->execute("UPDATE einkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $check]);';
rep($content, $old, $new, 'AddEinkaufspreis UPDATE bestellnummer same-price branch', $changes);

// ---- AddEinkaufspreis: INSERT (new record branch) ----
$old = '      //$this->AddEinkaufspreis($artikel,$abmenge,$adresse,$bestellnummer,$bezeichnunglieferant,$preis,$waehrung);
      $this->app->DB->Insert("INSERT INTO einkaufspreise (id,adresse,artikel,bestellnummer,bezeichnunglieferant,      preis_anfrage_vom,
      ab_menge,waehrung,preis,firma,vpe,apichange, logdatei) VALUES
        (\'\',\'$adresse\',\'$artikel\',\'$bestellnummer\',\'$bezeichnunglieferant\',NOW(),\'$abmenge\',\'$waehrung\',\'$preis\',\'" . $this->app->User->GetFirma() . "\',\'$vpe\',1,now())");
      $insid = $this->app->DB->GetInsertID();
      if ($interner_kommentar)
        $this->app->DB->Update("UPDATE einkaufspreise SET bemerkung = \'" . $this->app->DB->real_escape_string($interner_kommentar) . "\' WHERE id = \'$insid\' LIMIT 1");';
$new = '      //$this->AddEinkaufspreis($artikel,$abmenge,$adresse,$bestellnummer,$bezeichnunglieferant,$preis,$waehrung);
      $this->app->DatabaseService->execute(
        "INSERT INTO einkaufspreise (id,adresse,artikel,bestellnummer,bezeichnunglieferant,preis_anfrage_vom,ab_menge,waehrung,preis,firma,vpe,apichange,logdatei) VALUES (\'\', :adresse, :artikel, :bestellnummer, :bezeichnunglieferant, NOW(), :abmenge, :waehrung, :preis, :firma, :vpe, 1, now())",
        [\'adresse\' => $adresse, \'artikel\' => $artikel, \'bestellnummer\' => $bestellnummer, \'bezeichnunglieferant\' => $bezeichnunglieferant, \'abmenge\' => $abmenge, \'waehrung\' => $waehrung, \'preis\' => $preis, \'firma\' => $this->app->User->GetFirma(), \'vpe\' => $vpe]
      );
      $insid = $this->app->DB->GetInsertID();
      if ($interner_kommentar)
        $this->app->DatabaseService->execute("UPDATE einkaufspreise SET bemerkung = :bemerkung WHERE id = :id LIMIT 1", [\'bemerkung\' => $interner_kommentar, \'id\' => $insid]);';
rep($content, $old, $new, 'AddEinkaufspreis INSERT new-record branch', $changes);

// ---- EinkaufspreisBetrag ----
$old = '    $ek = $this->app->DB->Select("SELECT preis FROM einkaufspreise WHERE artikel=\'$id\' AND adresse=\'$adresse\' AND (gueltig_bis>=NOW() OR gueltig_bis=\'0000-00-00\') AND ab_menge <= $menge order by ab_menge desc LIMIT 1");
    if ($ek) {
      return $ek;
    }

    return $this->app->DB->Select("SELECT preis FROM einkaufspreise WHERE artikel=\'$id\' AND adresse=\'$adresse\' AND (gueltig_bis>=NOW() OR gueltig_bis=\'0000-00-00\') order by ab_menge ASC LIMIT 1");
  }';
$new = '    $ek = $this->app->DatabaseService->selectValue(
      "SELECT preis FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = \'0000-00-00\') AND ab_menge <= :menge ORDER BY ab_menge DESC LIMIT 1",
      [\'artikel\' => $id, \'adresse\' => $adresse, \'menge\' => $menge]
    );
    if ($ek) {
      return $ek;
    }

    return $this->app->DatabaseService->selectValue(
      "SELECT preis FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = \'0000-00-00\') ORDER BY ab_menge ASC LIMIT 1",
      [\'artikel\' => $id, \'adresse\' => $adresse]
    );
  }';
rep($content, $old, $new, 'EinkaufspreisBetrag', $changes);

// ---- Einkaufspreis ----
$old = '    $ek = $this->app->DB->Select("SELECT id FROM einkaufspreise WHERE artikel=\'$id\' AND adresse=\'$adresse\' AND (gueltig_bis>=NOW() OR gueltig_bis=\'0000-00-00\') AND ab_menge <= $menge order by ab_menge desc  LIMIT 1");
    if ($ek) {
      return $ek;
    }
    return $this->app->DB->Select("SELECT id FROM einkaufspreise WHERE artikel=\'$id\' AND adresse=\'$adresse\' AND (gueltig_bis>=NOW() OR gueltig_bis=\'0000-00-00\') order by ab_menge LIMIT 1");
  }';
$new = '    $ek = $this->app->DatabaseService->selectValue(
      "SELECT id FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = \'0000-00-00\') AND ab_menge <= :menge ORDER BY ab_menge DESC LIMIT 1",
      [\'artikel\' => $id, \'adresse\' => $adresse, \'menge\' => $menge]
    );
    if ($ek) {
      return $ek;
    }
    return $this->app->DatabaseService->selectValue(
      "SELECT id FROM einkaufspreise WHERE artikel = :artikel AND adresse = :adresse AND (gueltig_bis >= NOW() OR gueltig_bis = \'0000-00-00\') ORDER BY ab_menge LIMIT 1",
      [\'artikel\' => $id, \'adresse\' => $adresse]
    );
  }';
rep($content, $old, $new, 'Einkaufspreis', $changes);

// ---- ArtikelBestellung ----
$old = '    $summe_in_bestellung = $this->app->DB->Select("SELECT " . ($format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)") . "
  FROM bestellung_position bp
  LEFT JOIN bestellung b ON b.id=bp.bestellung
  WHERE bp.artikel=\'$artikel\' " . ($ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "") . " AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen!=1) AND b.status!=\'abgeschlossen\' AND b.status!=\'freigegeben\' AND b.status!=\'angelegt\' AND b.status!=\'storniert\'");

    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }

  // @refactor Bestellung Modul
  function ArtikelBestellungNichtVersendet';
$new = '    $ohnebestellauftragWhere = $ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "";
    $selectExpr = $format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)";
    $summe_in_bestellung = $this->app->DatabaseService->selectValue(
      "SELECT $selectExpr FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung
      WHERE bp.artikel = :artikel $ohnebestellauftragWhere AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1)
      AND b.status != \'abgeschlossen\' AND b.status != \'freigegeben\' AND b.status != \'angelegt\' AND b.status != \'storniert\'",
      [\'artikel\' => $artikel]
    );

    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }

  // @refactor Bestellung Modul
  function ArtikelBestellungNichtVersendet';
rep($content, $old, $new, 'ArtikelBestellung', $changes);

// ---- ArtikelBestellungNichtVersendet ----
$old = '    $summe_in_bestellung = $this->app->DB->Select("SELECT " . ($format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)") . "
  FROM bestellung_position bp
  LEFT JOIN bestellung b ON b.id=bp.bestellung
  WHERE bp.artikel=\'$artikel\' " . ($ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "") . " AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen!=1) AND (b.status=\'freigegeben\' OR b.status=\'angelegt\')");


    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }';
$new = '    $ohnebestellauftragWhere2 = $ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "";
    $selectExpr2 = $format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)";
    $summe_in_bestellung = $this->app->DatabaseService->selectValue(
      "SELECT $selectExpr2 FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung
      WHERE bp.artikel = :artikel $ohnebestellauftragWhere2 AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1)
      AND (b.status = \'freigegeben\' OR b.status = \'angelegt\')",
      [\'artikel\' => $artikel]
    );


    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }';
rep($content, $old, $new, 'ArtikelBestellungNichtVersendet', $changes);

// ---- ArtikelVerkaufGesamt ----
$old = '    if ($format) {
      $summe_im_auftrag = $this->app->DB->Select("SELECT trim(SUM(menge))+0 FROM auftrag_position ap LEFT JOIN auftrag a ON a.id=ap.auftrag WHERE ap.artikel=\'$artikel\' AND a.status=\'abgeschlossen\'");
      if ($summe_im_auftrag <= 0)
        $summe_im_auftrag = 0;
      return $summe_im_auftrag;
    }
    $summe_im_auftrag = $this->app->DB->Select("SELECT SUM(menge) FROM auftrag_position ap LEFT JOIN auftrag a ON a.id=ap.auftrag WHERE ap.artikel=\'$artikel\' AND a.status=\'abgeschlossen\'");';
$new = '    if ($format) {
      $summe_im_auftrag = $this->app->DatabaseService->selectValue(
        "SELECT trim(SUM(menge))+0 FROM auftrag_position ap LEFT JOIN auftrag a ON a.id = ap.auftrag WHERE ap.artikel = :artikel AND a.status = \'abgeschlossen\'",
        [\'artikel\' => $artikel]
      );
      if ($summe_im_auftrag <= 0)
        $summe_im_auftrag = 0;
      return $summe_im_auftrag;
    }
    $summe_im_auftrag = $this->app->DatabaseService->selectValue(
      "SELECT SUM(menge) FROM auftrag_position ap LEFT JOIN auftrag a ON a.id = ap.auftrag WHERE ap.artikel = :artikel AND a.status = \'abgeschlossen\'",
      [\'artikel\' => $artikel]
    );';
rep($content, $old, $new, 'ArtikelVerkaufGesamt', $changes);

// ---- ArtikelImLagerPlatz ----
$old = '    if ($format)
      return $this->app->DB->Select("SELECT trim(SUM(menge))+44 FROM lager_platz_inhalt WHERE artikel=\'$artikel\' AND lager_platz=\'$lager_platz\'");
    $summe_im_lager = $this->app->DB->Select("SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel=\'$artikel\' AND lager_platz=\'$lager_platz\'");
    return $summe_im_lager;';
$new = '    if ($format)
      return $this->app->DatabaseService->selectValue(
        "SELECT trim(SUM(menge))+44 FROM lager_platz_inhalt WHERE artikel = :artikel AND lager_platz = :lager_platz",
        [\'artikel\' => $artikel, \'lager_platz\' => $lager_platz]
      );
    $summe_im_lager = $this->app->DatabaseService->selectValue(
      "SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel = :artikel AND lager_platz = :lager_platz",
      [\'artikel\' => $artikel, \'lager_platz\' => $lager_platz]
    );
    return $summe_im_lager;';
rep($content, $old, $new, 'ArtikelImLagerPlatz', $changes);

// ---- ArtikelImLager ----
$old = '    if ($format) {
      return $this->app->DB->Select("SELECT trim(SUM(menge))+0 FROM lager_platz_inhalt WHERE artikel=\'$artikel\'");
    }
    $summe_im_lager = $this->app->DB->Select("SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel=\'$artikel\'");
    return $summe_im_lager;
  }

  // @refactor Lager Modul
  public function ArtikelImLagerOhneSperrlager';
$new = '    if ($format) {
      return $this->app->DatabaseService->selectValue(
        "SELECT trim(SUM(menge))+0 FROM lager_platz_inhalt WHERE artikel = :artikel",
        [\'artikel\' => $artikel]
      );
    }
    $summe_im_lager = $this->app->DatabaseService->selectValue(
      "SELECT SUM(menge) FROM lager_platz_inhalt WHERE artikel = :artikel",
      [\'artikel\' => $artikel]
    );
    return $summe_im_lager;
  }

  // @refactor Lager Modul
  public function ArtikelImLagerOhneSperrlager';
rep($content, $old, $new, 'ArtikelImLager', $changes);

// ---- ArtikelImLagerOhneSperrlager ----
$old = '    if ($format) {
      return $this->app->DB->Select("SELECT trim(ifnull(SUM(lpi.menge),0))+0 FROM lager_platz_inhalt lpi
      INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND (ifnull(lp.sperrlager,0) = 0 OR lp.allowproduction)
      WHERE lpi.artikel=\'$artikel\'");
    }
    $summe_im_lager = $this->app->DB->Select("SELECT ifnull(SUM(lpi.menge),0) FROM lager_platz_inhalt lpi
    INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND ifnull(lp.sperrlager,0) = 0
    WHERE lpi.artikel=\'$artikel\'");
    return $summe_im_lager;';
$new = '    if ($format) {
      return $this->app->DatabaseService->selectValue(
        "SELECT trim(ifnull(SUM(lpi.menge),0))+0 FROM lager_platz_inhalt lpi
        INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND (ifnull(lp.sperrlager,0) = 0 OR lp.allowproduction)
        WHERE lpi.artikel = :artikel",
        [\'artikel\' => $artikel]
      );
    }
    $summe_im_lager = $this->app->DatabaseService->selectValue(
      "SELECT ifnull(SUM(lpi.menge),0) FROM lager_platz_inhalt lpi
      INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND ifnull(lp.sperrlager,0) = 0
      WHERE lpi.artikel = :artikel",
      [\'artikel\' => $artikel]
    );
    return $summe_im_lager;';
rep($content, $old, $new, 'ArtikelImLagerOhneSperrlager', $changes);

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
