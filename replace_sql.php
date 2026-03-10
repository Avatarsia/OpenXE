<?php
$file = 'www/lib/class.erpapi.php';
$content = file_get_contents($file);
$original_len = strlen($content);
$count = 0;

function replace_once($content, $old, $new, $label) {
    global $count;
    // Try CRLF first then LF
    $old_crlf = str_replace("\n", "\r\n", $old);
    $new_crlf = str_replace("\n", "\r\n", $new);
    if (strpos($content, $old_crlf) !== false) {
        $content = str_replace($old_crlf, $new_crlf, $content);
        echo "$label: replaced (CRLF)\n";
        $count++;
    } elseif (strpos($content, $old) !== false) {
        $content = str_replace($old, $new, $content);
        echo "$label: replaced (LF)\n";
        $count++;
    } else {
        echo "$label: NOT FOUND\n";
    }
    return $content;
}

// ============================================================
// 1. KundeUpdate — foreach loop body
// ============================================================
$old = '    foreach ($fields as $key) {
      $check = $this->app->DB->Select("SELECT $key FROM adresse WHERE id=\'$adresse\' LIMIT 1");
      if ($check != ${$key}) {
        $val = $this->app->DB->real_escape_string(${$key});
        $this->app->DB->Update("UPDATE adresse SET $key=\'$val\' WHERE id=\'$adresse\' LIMIT 1");
        $logfile = $this->app->DB->Select("SELECT `logfile` FROM adresse WHERE id=\'$adresse\' LIMIT 1");
        $this->app->DB->Update("UPDATE adresse SET `logfile`=\'" . $logfile . " Update Feld $key alt:$check neu:" . $val . ";\' WHERE id=\'$adresse\' LIMIT 1");
      }

    }';

$new = '    foreach ($fields as $key) {
      $check = $this->app->DatabaseService->selectValue(
        "SELECT `$key` FROM adresse WHERE id = :id LIMIT 1",
        [\'id\' => $adresse]
      );
      if ($check != ${$key}) {
        $this->app->DatabaseService->execute(
          "UPDATE adresse SET `$key` = :val WHERE id = :id LIMIT 1",
          [\'val\' => ${$key}, \'id\' => $adresse]
        );
        $logfile = $this->app->DatabaseService->selectValue(
          \'SELECT `logfile` FROM adresse WHERE id = :id LIMIT 1\',
          [\'id\' => $adresse]
        );
        $this->app->DatabaseService->execute(
          \'UPDATE adresse SET `logfile` = :logfile WHERE id = :id LIMIT 1\',
          [\'logfile\' => $logfile . " Update Feld $key alt:$check neu:" . ${$key} . ";", \'id\' => $adresse]
        );
      }

    }';

$content = replace_once($content, $old, $new, 'KundeUpdate foreach');

// ============================================================
// 2. KundeAnlegen — INSERT with real_escape_string + UPDATE with raw $adresse
// ============================================================
$old = '    $name = $this->app->DB->real_escape_string($name);
    $abteilung = $this->app->DB->real_escape_string($abteilung);
    $unterabteilung = $this->app->DB->real_escape_string($unterabteilung);
    $ansprechpartner = $this->app->DB->real_escape_string($ansprechpartner);
    $adresszusatz = $this->app->DB->real_escape_string($adresszusatz);
    $strasse = $this->app->DB->real_escape_string($strasse);
    $land = $this->app->DB->real_escape_string($land);
    $plz = $this->app->DB->real_escape_string($plz);
    $ort = $this->app->DB->real_escape_string($ort);
    $email = $this->app->DB->real_escape_string($email);
    $telefon = $this->app->DB->real_escape_string($telefon);
    $telefax = $this->app->DB->real_escape_string($telefax);
    $ustid = $this->app->DB->real_escape_string($ustid);
    $partner = $this->app->DB->real_escape_string($partner);
    $bundesstaat = $this->app->DB->real_escape_string($bundesstaat);
    $rechnung_bundesstaat = $this->app->DB->real_escape_string($rechnung_bundesstaat);

    $this->app->DB->Insert("INSERT INTO adresse (id,typ,name,abteilung,unterabteilung,ansprechpartner,adresszusatz,strasse,land,plz,ort,email,telefon,telefax,ustid,partner,projekt,firma,bundesstaat,rechnung_bundesstaat)
      VALUES(\'\',\'$typ\',\'$name\',\'$abteilung\',\'$unterabteilung\',\'$ansprechpartner\',\'$adresszusatz\',\'$strasse\',\'$land\',\'$plz\',\'$ort\',\'$email\',\'$telefon\',\'$telefax\',\'$ustid\',\'$partner\',\'$projekt\',\'" . $this->app->User->GetFirma() . "\',\'$bundesstaat\',\'$rechnung_bundesstaat\')");
    $adresse = $this->app->DB->GetInsertID();
    $this->ObjektProtokoll("adresse", $adresse, "adresse_create", "Adresse angelegt");
    $zahlungsweise = $this->Firmendaten(\'zahlungsweise\');
    if ($zahlungsweise)
      $this->app->DB->Update("UPDATE adresse SET zahlungsweise = \'" . $this->app->DB->real_escape_string($zahlungsweise) . "\' WHERE id = \'$adresse\' LIMIT 1");
    if ($zahlungsweise == \'rechnung\') {
      $zahlungszieltage = $this->Firmendaten(\'zahlungszieltage\');
      $zahlungszieltageskonto = $this->Firmendaten(\'zahlungszieltageskonto\');
      $zahlungszielskonto = $this->Firmendaten(\'zahlungszielskonto\');
      if ($zahlungsweise)
        $this->app->DB->Update("UPDATE adresse SET
    zahlungszieltage = \'" . $this->app->DB->real_escape_string($zahlungszieltage) . "\',
    zahlungszielskonto = \'" . $this->app->DB->real_escape_string($zahlungszielskonto) . "\',
    zahlungszieltageskonto = \'" . $this->app->DB->real_escape_string($zahlungszieltageskonto) . "\'
    WHERE id = \'$adresse\' LIMIT 1");
    }

    $ust_befreit = $this->AdresseUSTCheck($adresse);
    $this->app->DB->Update("UPDATE adresse SET ust_befreit=\'$ust_befreit\' WHERE id=$adresse");';

$new = '    $this->app->DatabaseService->execute(
      "INSERT INTO adresse (id,typ,name,abteilung,unterabteilung,ansprechpartner,adresszusatz,strasse,land,plz,ort,email,telefon,telefax,ustid,partner,projekt,firma,bundesstaat,rechnung_bundesstaat)
      VALUES(\'\', :typ, :name, :abteilung, :unterabteilung, :ansprechpartner, :adresszusatz, :strasse, :land, :plz, :ort, :email, :telefon, :telefax, :ustid, :partner, :projekt, :firma, :bundesstaat, :rechnung_bundesstaat)",
      [
        \'typ\' => $typ, \'name\' => $name, \'abteilung\' => $abteilung,
        \'unterabteilung\' => $unterabteilung, \'ansprechpartner\' => $ansprechpartner,
        \'adresszusatz\' => $adresszusatz, \'strasse\' => $strasse, \'land\' => $land,
        \'plz\' => $plz, \'ort\' => $ort, \'email\' => $email, \'telefon\' => $telefon,
        \'telefax\' => $telefax, \'ustid\' => $ustid, \'partner\' => $partner,
        \'projekt\' => $projekt, \'firma\' => $this->app->User->GetFirma(),
        \'bundesstaat\' => $bundesstaat, \'rechnung_bundesstaat\' => $rechnung_bundesstaat,
      ]
    );
    $adresse = $this->app->DB->GetInsertID();
    $this->ObjektProtokoll("adresse", $adresse, "adresse_create", "Adresse angelegt");
    $zahlungsweise = $this->Firmendaten(\'zahlungsweise\');
    if ($zahlungsweise)
      $this->app->DatabaseService->execute(
        \'UPDATE adresse SET zahlungsweise = :zahlungsweise WHERE id = :id LIMIT 1\',
        [\'zahlungsweise\' => $zahlungsweise, \'id\' => $adresse]
      );
    if ($zahlungsweise == \'rechnung\') {
      $zahlungszieltage = $this->Firmendaten(\'zahlungszieltage\');
      $zahlungszieltageskonto = $this->Firmendaten(\'zahlungszieltageskonto\');
      $zahlungszielskonto = $this->Firmendaten(\'zahlungszielskonto\');
      if ($zahlungsweise)
        $this->app->DatabaseService->execute(
          \'UPDATE adresse SET zahlungszieltage = :tage, zahlungszielskonto = :skonto, zahlungszieltageskonto = :tageskonto WHERE id = :id LIMIT 1\',
          [\'tage\' => $zahlungszieltage, \'skonto\' => $zahlungszielskonto, \'tageskonto\' => $zahlungszieltageskonto, \'id\' => $adresse]
        );
    }

    $ust_befreit = $this->AdresseUSTCheck($adresse);
    $this->app->DatabaseService->execute(
      \'UPDATE adresse SET ust_befreit = :ust WHERE id = :id\',
      [\'ust\' => $ust_befreit, \'id\' => $adresse]
    );';

$content = replace_once($content, $old, $new, 'KundeAnlegen INSERT+UPDATEs');

// ============================================================
// 3. GetArticleIDFromShopnumber
// ============================================================
$old = '    $extart = $this->app->DB->Select("SELECT artikelnummernummerkreis FROM shopexport WHERE id = \'$shop\'");
    $projekt = $this->app->DB->Select("SELECT projekt FROM shopexport WHERE id = \'$shop\'");
    $eigenernummernkreis = $this->app->DB->Select("SELECT eigenernummernkreis FROM projekt WHERE id=\'$projekt\' LIMIT 1");
    if ($multiprojekt == \'1\' || !$eigenernummernkreis) {
      $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer=\'$nummer\' AND IFNULL(geloescht,0) = 0 AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1");
    } else {
      $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer=\'$nummer\' AND projekt=\'$projekt\' AND IFNULL(geloescht,0) = 0 AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1");
    }
    if ($j_id) {
      return $j_id;
    }

    $multiprojekt = $this->app->DB->Select("SELECT multiprojekt FROM shopexport WHERE id=\'$shop\' LIMIT 1");


    if (!$j_id) {
      if ($multiprojekt == \'1\' || !$eigenernummernkreis)
        $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE ean=\'$nummer\' AND nummer <> \'DEL\' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");  //TODO BENE
      else
        $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE ean=\'$nummer\' AND nummer <> \'DEL\' AND projekt=\'$projekt\' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");  //TODO BENE

      if ($j_id) {
        return $j_id;
      }
    }

    if ($herstellernummerUeberspringen) {
      return false;
    }
    if (!$j_id) {
      if ($multiprojekt == \'1\' || !$eigenernummernkreis)
        $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE herstellernummer=\'$nummer\' AND nummer <> \'DEL\' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");  //TODO BENE
      else
        $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE herstellernummer=\'$nummer\' AND nummer <> \'DEL\' AND projekt=\'$projekt\' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");  //TODO BENE
      if ($j_id) {
        return $j_id;
      }
    }';

$new = '    $extart = $this->app->DatabaseService->selectValue(
      \'SELECT artikelnummernummerkreis FROM shopexport WHERE id = :id\',
      [\'id\' => $shop]
    );
    $projekt = $this->app->DatabaseService->selectValue(
      \'SELECT projekt FROM shopexport WHERE id = :id\',
      [\'id\' => $shop]
    );
    $eigenernummernkreis = $this->app->DatabaseService->selectValue(
      \'SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1\',
      [\'id\' => $projekt]
    );
    if ($multiprojekt == \'1\' || !$eigenernummernkreis) {
      $j_id = $this->app->DatabaseService->selectValue(
        \'SELECT id FROM artikel WHERE nummer = :nummer AND IFNULL(geloescht,0) = 0 AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1\',
        [\'nummer\' => $nummer]
      );
    } else {
      $j_id = $this->app->DatabaseService->selectValue(
        \'SELECT id FROM artikel WHERE nummer = :nummer AND projekt = :projekt AND IFNULL(geloescht,0) = 0 AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1\',
        [\'nummer\' => $nummer, \'projekt\' => $projekt]
      );
    }
    if ($j_id) {
      return $j_id;
    }

    $multiprojekt = $this->app->DatabaseService->selectValue(
      \'SELECT multiprojekt FROM shopexport WHERE id = :id LIMIT 1\',
      [\'id\' => $shop]
    );


    if (!$j_id) {
      if ($multiprojekt == \'1\' || !$eigenernummernkreis)
        $j_id = $this->app->DatabaseService->selectValue(
          "SELECT id FROM artikel WHERE ean = :nummer AND nummer <> \'DEL\' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1",
          [\'nummer\' => $nummer]
        );  //TODO BENE
      else
        $j_id = $this->app->DatabaseService->selectValue(
          "SELECT id FROM artikel WHERE ean = :nummer AND nummer <> \'DEL\' AND projekt = :projekt AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1",
          [\'nummer\' => $nummer, \'projekt\' => $projekt]
        );  //TODO BENE

      if ($j_id) {
        return $j_id;
      }
    }

    if ($herstellernummerUeberspringen) {
      return false;
    }
    if (!$j_id) {
      if ($multiprojekt == \'1\' || !$eigenernummernkreis)
        $j_id = $this->app->DatabaseService->selectValue(
          "SELECT id FROM artikel WHERE herstellernummer = :nummer AND nummer <> \'DEL\' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1",
          [\'nummer\' => $nummer]
        );  //TODO BENE
      else
        $j_id = $this->app->DatabaseService->selectValue(
          "SELECT id FROM artikel WHERE herstellernummer = :nummer AND nummer <> \'DEL\' AND projekt = :projekt AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1",
          [\'nummer\' => $nummer, \'projekt\' => $projekt]
        );  //TODO BENE
      if ($j_id) {
        return $j_id;
      }
    }';

$content = replace_once($content, $old, $new, 'GetArticleIDFromShopnumber');

// ============================================================
// 4. KundennummerVergeben
// ============================================================
$old = '    $kundennummer = $this->app->DB->Select("SELECT kundennummer FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");
    $tmp_data_adresse = $this->app->DB->SelectRow("SELECT * FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");

    if ($projekt == "")
      $projekt = $this->app->DB->Select("SELECT projekt FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");

    if ($kundennummer == 0 || $kundennummer == "") {
      // pruefe ob rolle kunden vorhanden
      $check = $this->app->DB->Select("SELECT adresse FROM adresse_rolle WHERE adresse=\'$id\' AND subjekt=\'Kunde\' LIMIT 1");
      if ($check != "") {
        $kundennummer = $this->GetNextKundennummer($projekt, $tmp_data_adresse);
        $this->ObjektProtokoll("adresse", $id, "adresse_next_kundennummer", "Kundennummer erhalten: $kundennummer");
        $this->app->DB->Update("UPDATE adresse SET kundennummer=\'$kundennummer\' WHERE id=\'$id\' AND (kundennummer=\'0\' OR kundennummer=\'\') LIMIT 1");
        return $kundennummer;
      }
    }';

$new = '    $kundennummer = $this->app->DatabaseService->selectValue(
      \'SELECT kundennummer FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\',
      [\'id\' => $id]
    );
    $tmp_data_adresse = $this->app->DatabaseService->selectRow(
      \'SELECT * FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\',
      [\'id\' => $id]
    );

    if ($projekt == "")
      $projekt = $this->app->DatabaseService->selectValue(
        \'SELECT projekt FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\',
        [\'id\' => $id]
      );

    if ($kundennummer == 0 || $kundennummer == "") {
      // pruefe ob rolle kunden vorhanden
      $check = $this->app->DatabaseService->selectValue(
        "SELECT adresse FROM adresse_rolle WHERE adresse = :id AND subjekt = \'Kunde\' LIMIT 1",
        [\'id\' => $id]
      );
      if ($check != "") {
        $kundennummer = $this->GetNextKundennummer($projekt, $tmp_data_adresse);
        $this->ObjektProtokoll("adresse", $id, "adresse_next_kundennummer", "Kundennummer erhalten: $kundennummer");
        $this->app->DatabaseService->execute(
          "UPDATE adresse SET kundennummer = :nr WHERE id = :id AND (kundennummer = \'0\' OR kundennummer = \'\') LIMIT 1",
          [\'nr\' => $kundennummer, \'id\' => $id]
        );
        return $kundennummer;
      }
    }';

$content = replace_once($content, $old, $new, 'KundennummerVergeben');

// ============================================================
// 5. AdresseUSTCheck
// ============================================================
$old = '    $land = $this->app->DB->Select("SELECT land FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");';
// This pattern appears multiple times; we need to distinguish the one inside AdresseUSTCheck
// It occurs right after: function AdresseUSTCheck($adresse)
$old = '  function AdresseUSTCheck($adresse)
  {
    //wenn land DE

    $land = $this->app->DB->Select("SELECT land FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");';

$new = '  function AdresseUSTCheck($adresse)
  {
    //wenn land DE

    $land = $this->app->DatabaseService->selectValue(
      \'SELECT land FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\',
      [\'id\' => $adresse]
    );';

$content = replace_once($content, $old, $new, 'AdresseUSTCheck land SELECT');

// ============================================================
// 6. AutoUSTPruefung — multiple SELECTs and INSERT
// ============================================================
$old = '    // schaue obs heute bereits eine pruefung gegeben hat die erfolgreich war
    $ustcheck = $this->app->DB->Select("SELECT id FROM ustprf WHERE DATE_FORMAT(datum_online,\'%Y-%m-%d\')=DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND status=\'erfolgreich\' AND adresse=\'$adresse\' LIMIT 1");
    if ($ustcheck > 0 && is_numeric($ustcheck))
      return 1;


    $name = $this->app->DB->Select("SELECT name FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");
    $ustid = $this->app->DB->Select("SELECT ustid FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");
    $land = $this->app->DB->Select("SELECT land FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");
    $ort = $this->app->DB->Select("SELECT ort FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");
    $plz = $this->app->DB->Select("SELECT plz FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");
    $strasse = $this->app->DB->Select("SELECT strasse FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");

    if ($land == $this->Firmendaten("land") || $ustid == "")
      return 0;


    $ustcheck = $this->app->DB->Select("SELECT id FROM ustprf WHERE status=\'\' AND adresse=\'$adresse\' LIMIT 1");
    if (!($ustcheck > 0 && is_numeric($ustcheck))) {
      $this->app->DB->Insert("INSERT INTO ustprf (id,adresse,name,ustid,land,ort,plz,rechtsform,strasse,datum_online,bearbeiter)
          VALUES(\'\',\'$adresse\',\'$name\',\'$ustid\',\'$land\',\'$ort\',\'$plz\',\'$rechtsform\',\'$strasse\',NOW(),\'" . $this->app->User->GetName() . "\')");
      $ustprf_id = $this->app->DB->GetInsertID();
    } else
      $ustprf_id = $ustcheck;';

$new = '    // schaue obs heute bereits eine pruefung gegeben hat die erfolgreich war
    $ustcheck = $this->app->DatabaseService->selectValue(
      "SELECT id FROM ustprf WHERE DATE_FORMAT(datum_online,\'%Y-%m-%d\') = DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND status = \'erfolgreich\' AND adresse = :adresse LIMIT 1",
      [\'adresse\' => $adresse]
    );
    if ($ustcheck > 0 && is_numeric($ustcheck))
      return 1;


    $row = $this->app->DatabaseService->selectRow(
      \'SELECT name, ustid, land, ort, plz, strasse FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\',
      [\'id\' => $adresse]
    );
    $name = $row[\'name\'] ?? \'\';
    $ustid = $row[\'ustid\'] ?? \'\';
    $land = $row[\'land\'] ?? \'\';
    $ort = $row[\'ort\'] ?? \'\';
    $plz = $row[\'plz\'] ?? \'\';
    $strasse = $row[\'strasse\'] ?? \'\';

    if ($land == $this->Firmendaten("land") || $ustid == "")
      return 0;


    $ustcheck = $this->app->DatabaseService->selectValue(
      "SELECT id FROM ustprf WHERE status = \'\' AND adresse = :adresse LIMIT 1",
      [\'adresse\' => $adresse]
    );
    if (!($ustcheck > 0 && is_numeric($ustcheck))) {
      $this->app->DatabaseService->execute(
        "INSERT INTO ustprf (id,adresse,name,ustid,land,ort,plz,rechtsform,strasse,datum_online,bearbeiter) VALUES(\'\', :adresse, :name, :ustid, :land, :ort, :plz, :rechtsform, :strasse, NOW(), :bearbeiter)",
        [\'adresse\' => $adresse, \'name\' => $name, \'ustid\' => $ustid, \'land\' => $land, \'ort\' => $ort, \'plz\' => $plz, \'rechtsform\' => $rechtsform ?? \'\', \'strasse\' => $strasse, \'bearbeiter\' => $this->app->User->GetName()]
      );
      $ustprf_id = $this->app->DB->GetInsertID();
    } else
      $ustprf_id = $ustcheck;';

$content = replace_once($content, $old, $new, 'AutoUSTPruefung SELECTs+INSERT');

// AutoUSTPruefung: DELETE ustprf_protokoll
$old = '      $this->app->DB->Delete("DELETE FROM ustprf_protokoll WHERE ustprf_id=\'$ustprf_id\' AND bemerkung=\'UST g&uuml;ltig aber Name, Ort oder PLZ wird anders geschrieben\'");';
$new = '      $this->app->DatabaseService->execute(
        "DELETE FROM ustprf_protokoll WHERE ustprf_id = :id AND bemerkung = \'UST g&uuml;ltig aber Name, Ort oder PLZ wird anders geschrieben\'",
        [\'id\' => $ustprf_id]
      );';
$content = replace_once($content, $old, $new, 'AutoUSTPruefung DELETE ustprf_protokoll');

// ============================================================
// 7. AddChargeLagerOhneBewegung
// ============================================================
$old = '    $this->app->DB->Insert("INSERT INTO lager_charge (artikel,menge,lager_platz,datum,internebemerkung,charge,zwischenlagerid)
      VALUES (\'$artikel\',\'$menge\',\'$lagerplatz\',\'$datum\',\'$internebemerkung\',\'$charge\',\'$zid\')");
    $this->app->DB->Insert("INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid, is_interim)
      VALUES (\'$artikel\',\'$lagerplatz\',\'1\',\'$charge\',NOW()," . $this->app->User->GetAdresse() . ",\'$menge\',\'$internebemerkung\',\'$doctype\',\'$doctypeid\'," . (int) $isInterim . ")");
    $id = $this->app->DB->GetInsertID();
    $bestand = $this->app->DB->Select("SELECT ifnull(sum(menge),0) FROM lager_charge WHERE artikel = \'$artikel\' AND lager_platz = \'$lagerplatz\' AND charge = \'$charge\'");
    $this->app->DB->Update("UPDATE chargen_log SET bestand = \'$bestand\' WHERE id = \'$id\' LIMIT 1");';

$new = '    $this->app->DatabaseService->execute(
      "INSERT INTO lager_charge (artikel,menge,lager_platz,datum,internebemerkung,charge,zwischenlagerid) VALUES (:artikel, :menge, :lagerplatz, :datum, :internebemerkung, :charge, :zid)",
      [\'artikel\' => $artikel, \'menge\' => $menge, \'lagerplatz\' => $lagerplatz, \'datum\' => $datum, \'internebemerkung\' => $internebemerkung, \'charge\' => $charge, \'zid\' => $zid]
    );
    $this->app->DatabaseService->execute(
      "INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,is_interim) VALUES (:artikel, :lagerplatz, \'1\', :charge, NOW(), :mitarbeiter, :menge, :internebemerkung, :doctype, :doctypeid, :isInterim)",
      [\'artikel\' => $artikel, \'lagerplatz\' => $lagerplatz, \'charge\' => $charge, \'mitarbeiter\' => $this->app->User->GetAdresse(), \'menge\' => $menge, \'internebemerkung\' => $internebemerkung, \'doctype\' => $doctype, \'doctypeid\' => $doctypeid, \'isInterim\' => (int) $isInterim]
    );
    $id = $this->app->DB->GetInsertID();
    $bestand = $this->app->DatabaseService->selectValue(
      \'SELECT ifnull(sum(menge),0) FROM lager_charge WHERE artikel = :artikel AND lager_platz = :lagerplatz AND charge = :charge\',
      [\'artikel\' => $artikel, \'lagerplatz\' => $lagerplatz, \'charge\' => $charge]
    );
    $this->app->DatabaseService->execute(
      \'UPDATE chargen_log SET bestand = :bestand WHERE id = :id LIMIT 1\',
      [\'bestand\' => $bestand, \'id\' => $id]
    );';

$content = replace_once($content, $old, $new, 'AddChargeLagerOhneBewegung INSERTs+SELECT+UPDATE');

// ============================================================
// 8. AddMindesthaltbarkeitsdatumLagerOhneBewegung
// ============================================================
$old = '    $this->app->DB->Insert("INSERT INTO lager_mindesthaltbarkeitsdatum (artikel,menge,lager_platz,datum,internebemerkung,charge,zwischenlagerid,mhddatum) VALUES (\'$artikel\',\'$menge\',\'$lagerplatz\',NOW(),\'$internebemerkung\',\'$charge\',\'$zid\',\'$mhd\')");
    $bestand = (float) $this->app->DB->Select("SELECT ifnull(sum(menge),0) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = \'$artikel\' AND lager_platz = \'$lagerplatz\' AND mhddatum = \'$mhd\' AND ifnull(charge,\'\') = \'$charge\' ");
    $this->app->DB->Insert("INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim)
      VALUES (\'$artikel\',\'$lagerplatz\',\'1\',\'$mhd\',NOW()," . $this->app->User->GetAdresse() . ",\'$menge\',\'$internebemerkung\',\'$doctype\',\'$doctypeid\',\'$bestand\',\'$adresse\'," . (int) $isInterim . ")");
    $insid = $this->app->DB->GetInsertID();
    if ($charge != \'\') {
      $this->app->DB->Update("UPDATE mhd_log SET charge = \'$charge\' WHERE id = \'$insid\' LIMIT 1");
    }';

$new = '    $this->app->DatabaseService->execute(
      "INSERT INTO lager_mindesthaltbarkeitsdatum (artikel,menge,lager_platz,datum,internebemerkung,charge,zwischenlagerid,mhddatum) VALUES (:artikel, :menge, :lagerplatz, NOW(), :internebemerkung, :charge, :zid, :mhd)",
      [\'artikel\' => $artikel, \'menge\' => $menge, \'lagerplatz\' => $lagerplatz, \'internebemerkung\' => $internebemerkung, \'charge\' => $charge, \'zid\' => $zid, \'mhd\' => $mhd]
    );
    $bestand = (float) $this->app->DatabaseService->selectValue(
      "SELECT ifnull(sum(menge),0) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = :artikel AND lager_platz = :lagerplatz AND mhddatum = :mhd AND ifnull(charge,\'\') = :charge",
      [\'artikel\' => $artikel, \'lagerplatz\' => $lagerplatz, \'mhd\' => $mhd, \'charge\' => $charge]
    );
    $this->app->DatabaseService->execute(
      "INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim) VALUES (:artikel, :lagerplatz, \'1\', :mhd, NOW(), :mitarbeiter, :menge, :internebemerkung, :doctype, :doctypeid, :bestand, :adresse, :isInterim)",
      [\'artikel\' => $artikel, \'lagerplatz\' => $lagerplatz, \'mhd\' => $mhd, \'mitarbeiter\' => $this->app->User->GetAdresse(), \'menge\' => $menge, \'internebemerkung\' => $internebemerkung, \'doctype\' => $doctype, \'doctypeid\' => $doctypeid, \'bestand\' => $bestand, \'adresse\' => $adresse, \'isInterim\' => (int) $isInterim]
    );
    $insid = $this->app->DB->GetInsertID();
    if ($charge != \'\') {
      $this->app->DatabaseService->execute(
        \'UPDATE mhd_log SET charge = :charge WHERE id = :id LIMIT 1\',
        [\'charge\' => $charge, \'id\' => $insid]
      );
    }';

$content = replace_once($content, $old, $new, 'AddMindesthaltbarkeitsdatumLagerOhneBewegung');

// ============================================================
// 9. Chargenlog — doctype SELECT + main SELECT + UPDATE + INSERT
// ============================================================
$old = '    if (!$adresse && $doctype != \'\' && $doctypeid > 0) {
      $adresse = $this->app->DB->Select("SELECT adresse FROM $doctype WHERE id = \'$doctypeid\' LIMIT 1");
    }
    $internebemerkung = $this->app->DB->real_escape_string($internebemerkung);
    $bestand = $this->app->DB->Select("SELECT ifnull(sum(menge),0) FROM lager_charge WHERE artikel = \'$artikel\' AND lager_platz = \'$lager_platz\' AND charge = \'$charge\'");
    $this->RunHook(\'chargenlog_bestand\', 4, $artikel, $lager_platz, $charge, $bestand);
    if ($chargen_log_id) {
      $chargen_log_id = $this->app->DB->Select("SELECT id FROM chargen_log WHERE id=\'$chargen_log_id\' AND eingang = \'$eingang\' AND artikel = \'$artikel\' AND charge = \'$charge\' AND doctype = \'$doctype\' AND doctypeid = \'$doctypeid\' AND adresse = \'$adresse\' LIMIT 1");
    }
    if ($chargen_log_id) {
      $this->app->DB->Update("UPDATE chargen_log SET menge = menge + $menge, bestand = \'$bestand\' WHERE id = \'$chargen_log_id\' LIMIT 1");
      return $chargen_log_id;
    }
    $this->app->DB->Insert("INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim)
            VALUES (\'$artikel\',\'$lager_platz\',\'$eingang\',\'" . $charge . "\',NOW()," . (int) $this->app->User->GetAdresse() . ",\'" . $menge . "\',\'$internebemerkung\',\'$doctype\',\'$doctypeid\',\'$bestand\',\'$adresse\'," . (int) $isInterim . ")");
    return $this->app->DB->GetInsertID();';

$new = '    if (!$adresse && $doctype != \'\' && $doctypeid > 0) {
      // Note: $doctype is an internal table name set by ERP logic, not user input
      $adresse = $this->app->DatabaseService->selectValue(
        "SELECT adresse FROM `$doctype` WHERE id = :id LIMIT 1",
        [\'id\' => $doctypeid]
      );
    }
    $bestand = $this->app->DatabaseService->selectValue(
      \'SELECT ifnull(sum(menge),0) FROM lager_charge WHERE artikel = :artikel AND lager_platz = :lager_platz AND charge = :charge\',
      [\'artikel\' => $artikel, \'lager_platz\' => $lager_platz, \'charge\' => $charge]
    );
    $this->RunHook(\'chargenlog_bestand\', 4, $artikel, $lager_platz, $charge, $bestand);
    if ($chargen_log_id) {
      $chargen_log_id = $this->app->DatabaseService->selectValue(
        \'SELECT id FROM chargen_log WHERE id = :cid AND eingang = :eingang AND artikel = :artikel AND charge = :charge AND doctype = :doctype AND doctypeid = :doctypeid AND adresse = :adresse LIMIT 1\',
        [\'cid\' => $chargen_log_id, \'eingang\' => $eingang, \'artikel\' => $artikel, \'charge\' => $charge, \'doctype\' => $doctype, \'doctypeid\' => $doctypeid, \'adresse\' => $adresse]
      );
    }
    if ($chargen_log_id) {
      $this->app->DatabaseService->execute(
        \'UPDATE chargen_log SET menge = menge + :menge, bestand = :bestand WHERE id = :id LIMIT 1\',
        [\'menge\' => $menge, \'bestand\' => $bestand, \'id\' => $chargen_log_id]
      );
      return $chargen_log_id;
    }
    $this->app->DatabaseService->execute(
      "INSERT INTO chargen_log (artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim) VALUES (:artikel, :lager_platz, :eingang, :charge, NOW(), :mitarbeiter, :menge, :internebemerkung, :doctype, :doctypeid, :bestand, :adresse, :isInterim)",
      [\'artikel\' => $artikel, \'lager_platz\' => $lager_platz, \'eingang\' => $eingang, \'charge\' => $charge, \'mitarbeiter\' => (int) $this->app->User->GetAdresse(), \'menge\' => $menge, \'internebemerkung\' => $internebemerkung, \'doctype\' => $doctype, \'doctypeid\' => $doctypeid, \'bestand\' => $bestand, \'adresse\' => $adresse, \'isInterim\' => (int) $isInterim]
    );
    return $this->app->DB->GetInsertID();';

$content = replace_once($content, $old, $new, 'Chargenlog');

// ============================================================
// 10. MHDLog — doctype SELECT + SELECT + INSERT + UPDATE
// ============================================================
$old = '  function MHDLog($artikel, $lager_platz, $eingang, $mhd, $menge, $internebemerkung = \'\', $doctype = \'\', $doctypeid = 0, $charge = \'\', $adresse = 0, $isInterim = 0)
  {
    if ($artikel <= 0) {
      return;
    }
    if (!$adresse && $doctype != \'\' && $doctypeid > 0) {
      $adresse = $this->app->DB->Select("SELECT adresse FROM $doctype WHERE id = \'$doctypeid\' LIMIT 1");
    }
    $internebemerkung = $this->app->DB->real_escape_string($internebemerkung);
    $bestand = $this->app->DB->Select("SELECT ifnull(sum(menge),0) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = \'$artikel\' AND lager_platz = \'$lager_platz\' AND mhddatum = \'$mhd\' AND ifnull(charge,\'\') = \'$charge\'");
    $this->RunHook(\'mhdlog_bestand\', 4, $artikel, $lager_platz, $mhd, $bestand);
    $this->app->DB->Insert("INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim)
            VALUES (\'$artikel\',\'$lager_platz\',\'$eingang\',\'" . $mhd . "\',NOW()," . (int) $this->app->User->GetAdresse() . ",\'" . $menge . "\',\'$internebemerkung\',\'$doctype\',\'$doctypeid\',\'$bestand\',\'$adresse\'," . (int) $isInterim . ")");
    $insid = $this->app->DB->GetInsertID();
    if ($charge != \'\') {
      $this->app->DB->Update("UPDATE mhd_log SET charge = \'$charge\' WHERE id = \'$insid\' LIMIT 1");
    }
  }';

$new = '  function MHDLog($artikel, $lager_platz, $eingang, $mhd, $menge, $internebemerkung = \'\', $doctype = \'\', $doctypeid = 0, $charge = \'\', $adresse = 0, $isInterim = 0)
  {
    if ($artikel <= 0) {
      return;
    }
    if (!$adresse && $doctype != \'\' && $doctypeid > 0) {
      // Note: $doctype is an internal table name set by ERP logic, not user input
      $adresse = $this->app->DatabaseService->selectValue(
        "SELECT adresse FROM `$doctype` WHERE id = :id LIMIT 1",
        [\'id\' => $doctypeid]
      );
    }
    $bestand = $this->app->DatabaseService->selectValue(
      "SELECT ifnull(sum(menge),0) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = :artikel AND lager_platz = :lager_platz AND mhddatum = :mhd AND ifnull(charge,\'\') = :charge",
      [\'artikel\' => $artikel, \'lager_platz\' => $lager_platz, \'mhd\' => $mhd, \'charge\' => $charge]
    );
    $this->RunHook(\'mhdlog_bestand\', 4, $artikel, $lager_platz, $mhd, $bestand);
    $this->app->DatabaseService->execute(
      "INSERT INTO mhd_log (artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim) VALUES (:artikel, :lager_platz, :eingang, :mhd, NOW(), :mitarbeiter, :menge, :internebemerkung, :doctype, :doctypeid, :bestand, :adresse, :isInterim)",
      [\'artikel\' => $artikel, \'lager_platz\' => $lager_platz, \'eingang\' => $eingang, \'mhd\' => $mhd, \'mitarbeiter\' => (int) $this->app->User->GetAdresse(), \'menge\' => $menge, \'internebemerkung\' => $internebemerkung, \'doctype\' => $doctype, \'doctypeid\' => $doctypeid, \'bestand\' => $bestand, \'adresse\' => $adresse, \'isInterim\' => (int) $isInterim]
    );
    $insid = $this->app->DB->GetInsertID();
    if ($charge != \'\') {
      $this->app->DatabaseService->execute(
        \'UPDATE mhd_log SET charge = :charge WHERE id = :id LIMIT 1\',
        [\'charge\' => $charge, \'id\' => $insid]
      );
    }
  }';

$content = replace_once($content, $old, $new, 'MHDLog');

// ============================================================
// 11. AngebotSuche — POST data into returned SQL strings
// The search functions return SQL strings consumed by a table component.
// The safest fix is to use LIKE with properly escaped percent-wrapped values
// via DatabaseService in the calling context — but since these *return* SQL
// strings to a legacy YUI table component we cannot change that interface.
// We use addslashes / intval to prevent injection in the returned SQL string,
// since the legacy component does not support PDO parameterization in returned SQL.
// Note: This is a partial mitigation; full fix requires refactoring the table component.
// ============================================================

// AngebotSuche: inject via $suchwort and session vars
$old = '      if ($suchwort != "") {
        return "SELECT DATE_FORMAT(a.datum,\'%d.%m.%y\') as vom, if(a.belegnr,a.belegnr,\'ohne Nummer\') as Angebot, ad.kundennummer as kunde, a.name, p.abkuerzung as projekt, a.status, a.id
          FROM angebot a, projekt p, adresse ad WHERE
          (a.plz LIKE \'%$suchwort%\' OR a.name LIKE \'%$suchwort%\' OR a.belegnr LIKE \'%$suchwort%\')
          AND p.id=a.projekt AND a.adresse=ad.id
          order by a.datum DESC, a.id DESC";
      } else {
        return "SELECT DATE_FORMAT(a.datum,\'%d.%m.%y\') as vom, if(a.belegnr,a.belegnr,\'ohne Nummer\') as Angebot, ad.kundennummer as kunde, a.name, p.abkuerzung as projekt, a.status, a.id
          FROM angebot a, projekt p, adresse ad WHERE
          (ad.kundennummer LIKE \'%{$_SESSION[\'angebotkundennummer\']}%\' AND a.plz LIKE \'%{$_SESSION[\'angebotplz\']}%\'
           AND a.name LIKE \'%{$_SESSION[\'angebotname\']}%\' AND a.belegnr LIKE \'%{$_SESSION[\'angebotangebot\']}%\' )
          AND p.id=a.projekt AND a.adresse=ad.id
          order by a.datum DESC, a.id DESC";

      }';

$new = '      if ($suchwort != "") {
        $sw = addslashes($suchwort);
        return "SELECT DATE_FORMAT(a.datum,\'%d.%m.%y\') as vom, if(a.belegnr,a.belegnr,\'ohne Nummer\') as Angebot, ad.kundennummer as kunde, a.name, p.abkuerzung as projekt, a.status, a.id
          FROM angebot a, projekt p, adresse ad WHERE
          (a.plz LIKE \'%$sw%\' OR a.name LIKE \'%$sw%\' OR a.belegnr LIKE \'%$sw%\')
          AND p.id=a.projekt AND a.adresse=ad.id
          order by a.datum DESC, a.id DESC";
      } else {
        $s_kd = addslashes($_SESSION[\'angebotkundennummer\']);
        $s_plz = addslashes($_SESSION[\'angebotplz\']);
        $s_name = addslashes($_SESSION[\'angebotname\']);
        $s_angebot = addslashes($_SESSION[\'angebotangebot\']);
        return "SELECT DATE_FORMAT(a.datum,\'%d.%m.%y\') as vom, if(a.belegnr,a.belegnr,\'ohne Nummer\') as Angebot, ad.kundennummer as kunde, a.name, p.abkuerzung as projekt, a.status, a.id
          FROM angebot a, projekt p, adresse ad WHERE
          (ad.kundennummer LIKE \'%$s_kd%\' AND a.plz LIKE \'%$s_plz%\'
           AND a.name LIKE \'%$s_name%\' AND a.belegnr LIKE \'%$s_angebot%\' )
          AND p.id=a.projekt AND a.adresse=ad.id
          order by a.datum DESC, a.id DESC";

      }';

$content = replace_once($content, $old, $new, 'AngebotSuche returned SQL strings');

// ============================================================
// 12. ArtikelSuche — $suchwort, $name, $nummer, $projekt
// ============================================================
$old = '    if (($name != "" || $nummer != "" || $projekt != "" || $suchwort != "") && $suche != "") {
      if ($suchwort != "") {

        return ("SELECT DISTINCT a.nummer, a.name_de as Artikel, p.abkuerzung, a.id FROM artikel a LEFT JOIN projekt p ON p.id=a.projekt WHERE
            (a.name_de LIKE \'%$suchwort%\' OR
             a.nummer LIKE \'%$suchwort%\')
            AND geloescht=\'0\'
            ORDER by a.id DESC");

      } else {
        return ("SELECT DISTINCT a.nummer, a.name_de as Artikel, p.abkuerzung, a.id FROM artikel a LEFT JOIN projekt p ON p.id=a.projekt WHERE
            a.name_de LIKE \'%$name%\' AND
            a.nummer LIKE \'%$nummer%\' AND
            p.abkuerzung LIKE \'%$projekt%\'
            AND a.geloescht=\'0\'
            ORDER by a.id DESC");
      }';

$new = '    if (($name != "" || $nummer != "" || $projekt != "" || $suchwort != "") && $suche != "") {
      if ($suchwort != "") {
        $sw = addslashes($suchwort);
        return ("SELECT DISTINCT a.nummer, a.name_de as Artikel, p.abkuerzung, a.id FROM artikel a LEFT JOIN projekt p ON p.id=a.projekt WHERE
            (a.name_de LIKE \'%$sw%\' OR
             a.nummer LIKE \'%$sw%\')
            AND geloescht=\'0\'
            ORDER by a.id DESC");

      } else {
        $s_name = addslashes($name);
        $s_nummer = addslashes($nummer);
        $s_projekt = addslashes($projekt);
        return ("SELECT DISTINCT a.nummer, a.name_de as Artikel, p.abkuerzung, a.id FROM artikel a LEFT JOIN projekt p ON p.id=a.projekt WHERE
            a.name_de LIKE \'%$s_name%\' AND
            a.nummer LIKE \'%$s_nummer%\' AND
            p.abkuerzung LIKE \'%$s_projekt%\'
            AND a.geloescht=\'0\'
            ORDER by a.id DESC");
      }';

$content = replace_once($content, $old, $new, 'ArtikelSuche returned SQL strings');

// ============================================================
// 13. AdressSuche — $name, $ansprechpartner, $ort, $strasse, $kundennummer, $plz
// ============================================================
$old = '      return ("SELECT DISTINCT a.kundennummer, a.name, a.ort, a.telefon, a.email, a.id FROM adresse a LEFT JOIN adresse_rolle r ON a.id=r.adresse WHERE
          a.name LIKE \'%$name%\' AND
          a.ansprechpartner LIKE \'%$ansprechpartner%\' AND
          a.ort LIKE \'%$ort%\' AND
          a.strasse LIKE \'%$strasse%\' AND
          a.kundennummer LIKE \'%$kundennummer%\' AND
          a.plz LIKE \'%$plz%\' AND a.geloescht=0 ORDER by a.id DESC");';

$new = '      $s_name = addslashes($name);
      $s_ansprechpartner = addslashes($ansprechpartner);
      $s_ort = addslashes($ort);
      $s_strasse = addslashes($strasse);
      $s_kundennummer = addslashes($kundennummer);
      $s_plz = addslashes($plz);
      return ("SELECT DISTINCT a.kundennummer, a.name, a.ort, a.telefon, a.email, a.id FROM adresse a LEFT JOIN adresse_rolle r ON a.id=r.adresse WHERE
          a.name LIKE \'%$s_name%\' AND
          a.ansprechpartner LIKE \'%$s_ansprechpartner%\' AND
          a.ort LIKE \'%$s_ort%\' AND
          a.strasse LIKE \'%$s_strasse%\' AND
          a.kundennummer LIKE \'%$s_kundennummer%\' AND
          a.plz LIKE \'%$s_plz%\' AND a.geloescht=0 ORDER by a.id DESC");';

$content = replace_once($content, $old, $new, 'AdressSuche returned SQL strings');

// ============================================================
// 14. ImportAuftrag: adresse_rolle SELECT + INSERT with $adresse, $gruppenid
// ============================================================
$old = '      if (!$this->app->DB->Select("SELECT id FROM adresse_rolle WHERE adresse=\'$adresse\' AND projekt=\'$projekt\' AND subjekt=\'Mitglied\' AND praedikat=\'von\' AND objekt=\'Gruppe\' AND parameter=\'$gruppenid\'  LIMIT 1")) {
          $this->app->DB->Insert("INSERT INTO adresse_rolle (adresse, projekt, subjekt, praedikat, objekt, parameter, von, bis) VALUES (\'$adresse\', \'$projekt\', \'Mitglied\', \'von\', \'Gruppe\', \'$gruppenid\', \'CURRENT_DATE\', \'0000-00-00\')");
        }';

$new = '      if (!$this->app->DatabaseService->selectValue(
          "SELECT id FROM adresse_rolle WHERE adresse = :adresse AND projekt = :projekt AND subjekt = \'Mitglied\' AND praedikat = \'von\' AND objekt = \'Gruppe\' AND parameter = :gruppenid LIMIT 1",
          [\'adresse\' => $adresse, \'projekt\' => $projekt, \'gruppenid\' => $gruppenid]
        )) {
          $this->app->DatabaseService->execute(
            "INSERT INTO adresse_rolle (adresse, projekt, subjekt, praedikat, objekt, parameter, von, bis) VALUES (:adresse, :projekt, \'Mitglied\', \'von\', \'Gruppe\', :gruppenid, CURRENT_DATE, \'0000-00-00\')",
            [\'adresse\' => $adresse, \'projekt\' => $projekt, \'gruppenid\' => $gruppenid]
          );
        }';

$content = replace_once($content, $old, $new, 'ImportAuftrag adresse_rolle SELECT+INSERT');

// ============================================================
// 15. ImportAuftrag: bankverbindung UPDATE with real_escape_string pattern
// ============================================================
$old = '      if ((!empty($anweisung) ? count($anweisung) : 0) > 0) {
        $this->app->DB->Update(\'UPDATE adresse SET \' . implode(\', \', $anweisung) . " WHERE id=\'$adresse\'");
      }';

$new = '      if ((!empty($anweisung) ? count($anweisung) : 0) > 0) {
        // $anweisung entries use real_escape_string above; this dynamic SET is safe
        $this->app->DB->Update(\'UPDATE adresse SET \' . implode(\', \', $anweisung) . \' WHERE id = \' . (int) $adresse);
      }';

$content = replace_once($content, $old, $new, 'ImportAuftrag bankverbindung UPDATE');

// ============================================================
// 16. ArtikelMindestlager — $artikel in SELECT
// ============================================================
$old = '    $mindestlager = $this->app->DB->Select("SELECT mindestlager FROM artikel WHERE id=\'$artikel\' LIMIT 1");';
$new = '    $mindestlager = $this->app->DatabaseService->selectValue(
      \'SELECT mindestlager FROM artikel WHERE id = :id LIMIT 1\',
      [\'id\' => $artikel]
    );';
$content = replace_once($content, $old, $new, 'ArtikelMindestlager');

// ============================================================
// Write result
// ============================================================
file_put_contents($file, $content);
echo "\nTotal replacements: $count\n";
echo "File size: " . strlen($content) . " bytes (was $original_len)\n";
