<?php
/**
 * Migration batch 3: ZeitGesamtMonatSoll, GetArbeitszeitTag, GetArbeitszeitWoche,
 * AddArbeitsnachweisPositionZeiterfassung, CreateAufgabe, AbschlussAufgabe,
 * CreateRetoure, various Delete functions, CreateAuftrag, ArtikelIDProjekt
 */
$filepath = __DIR__ . '/www/lib/class.erpapi.php';
$content = file_get_contents($filepath);
$changes = 0;

function replace_once(&$content, $old, $new, $label) {
    global $changes;
    $pos = strpos($content, $old);
    if ($pos !== false) {
        $content = substr_replace($content, $new, $pos, strlen($old));
        echo "REPLACED: $label\n";
        $changes++;
    } else {
        echo "NOT FOUND: $label\n";
    }
}

// ZeitGesamtMonatSoll - datum format selects
replace_once($content,
    '  function ZeitGesamtMonatSoll($adresse, $jahr = "", $monat = "", $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%Y\')");
      $monat = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%m\')");
    }',
    '  function ZeitGesamtMonatSoll($adresse, $jahr = "", $monat = "", $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%Y\')", [\':datum\' => $datum]);
      $monat = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%m\')", [\':datum\' => $datum]);
    }',
    'ZeitGesamtMonatSoll - datum format'
);

// ZeitGesamtMonatSoll - mitarbeiterzeiterfassung check
replace_once($content,
    '    if ($this->ModulVorhanden(\'mitarbeiterzeiterfassung\') && $this->app->DB->Select("SELECT id FROM mitarbeiterzeiterfassung_einstellungen WHERE adresse = \'$adresse\' LIMIT 1")) {
      if (!$jahr || !$monat) {
        $urlaubkrankheitsstunden = (float) $this->app->DB->Select("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE  adresse = \'$adresse\' AND month(datum) = month(now()) and year(datum) = year(now()) AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))");

        $urlaubkrankheitsstunden += (float) $this->app->DB->Select("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE  ms.adresse = \'$adresse\' AND month(ms.datum) = month(now()) and year(ms.datum) = year(now()) AND NOT (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))");
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DB->Select("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND month(datum) = month(now()) and year(datum) = year(now())");
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      } else {
        $urlaubkrankheitsstunden = (float) $this->app->DB->Select("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND month(datum) = $monat and year(datum) = $jahr AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))");
        $urlaubkrankheitsstunden += (float) $this->app->DB->Select("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE  ms.adresse = \'$adresse\' AND month(ms.datum) = $monat and year(ms.datum) = $jahr AND NOT (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))");
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DB->Select("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND month(datum) = $monat and year(datum) = $jahr");
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      }
    } elseif ($this->ModulVorhanden(\'zeiterfassung_stundenuebersicht\')) {
      if (!$jahr || !$monat) {
        $monat = date(\'m\', strtotime($datum));
        $jahr = date(\'Y\', strtotime($datum));
      }
      $stundenprowoche = $this->app->DB->Select("SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $ueberstundentoleranz = $this->app->DB->Select("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre wHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $urlaubimjahr = $this->app->DB->Select("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $restueberstunden = $this->app->DB->Select("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $resturlaub = $this->app->DB->Select("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $restnotiz = $this->app->DB->Select("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");',
    '    if ($this->ModulVorhanden(\'mitarbeiterzeiterfassung\') && $this->app->DatabaseService->selectValue("SELECT id FROM mitarbeiterzeiterfassung_einstellungen WHERE adresse = :adresse LIMIT 1", [\':adresse\' => $adresse])) {
      if (!$jahr || !$monat) {
        $urlaubkrankheitsstunden = (float) $this->app->DatabaseService->selectValue("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND month(datum) = month(now()) and year(datum) = year(now()) AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))", [\':adresse\' => $adresse]);

        $urlaubkrankheitsstunden += (float) $this->app->DatabaseService->selectValue("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = :adresse AND month(ms.datum) = month(now()) and year(ms.datum) = year(now()) AND NOT (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))", [\':adresse\' => $adresse]);
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DatabaseService->selectValue("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND month(datum) = month(now()) and year(datum) = year(now())", [\':adresse\' => $adresse]);
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      } else {
        $urlaubkrankheitsstunden = (float) $this->app->DatabaseService->selectValue("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND month(datum) = :monat and year(datum) = :jahr AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))", [\':adresse\' => $adresse, \':monat\' => $monat, \':jahr\' => $jahr]);
        $urlaubkrankheitsstunden += (float) $this->app->DatabaseService->selectValue("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = :adresse AND month(ms.datum) = :monat and year(ms.datum) = :jahr AND NOT (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))", [\':adresse\' => $adresse, \':monat\' => $monat, \':jahr\' => $jahr]);
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DatabaseService->selectValue("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND month(datum) = :monat and year(datum) = :jahr", [\':adresse\' => $adresse, \':monat\' => $monat, \':jahr\' => $jahr]);
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      }
    } elseif ($this->ModulVorhanden(\'zeiterfassung_stundenuebersicht\')) {
      if (!$jahr || !$monat) {
        $monat = date(\'m\', strtotime($datum));
        $jahr = date(\'Y\', strtotime($datum));
      }
      $stundenprowoche = $this->app->DatabaseService->selectValue("SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $ueberstundentoleranz = $this->app->DatabaseService->selectValue("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $urlaubimjahr = $this->app->DatabaseService->selectValue("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $restueberstunden = $this->app->DatabaseService->selectValue("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $resturlaub = $this->app->DatabaseService->selectValue("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $restnotiz = $this->app->DatabaseService->selectValue("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);',
    'ZeitGesamtMonatSoll - main block'
);

// ZeitGesamtMonatSoll gesamtsummesoll and asoll2
replace_once($content,
    '      $gesamtsummesoll = $this->app->DB->Select("SELECT SUM(soll) FROM zeiterfassung_stundenuebersicht WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      if ($gesamtsummesoll != 0) {
        $asoll2 = $this->app->DB->Select("SELECT soll FROM zeiterfassung_stundenuebersicht WHERE adresse = \"$adresse\" AND monat = \"$monat\" AND jahr = \"$jahr\"");
        return number_format($asoll2, 2, \'.\', \'\');
      }',
    '      $gesamtsummesoll = $this->app->DatabaseService->selectValue("SELECT SUM(soll) FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      if ($gesamtsummesoll != 0) {
        $asoll2 = $this->app->DatabaseService->selectValue("SELECT soll FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr", [\':adresse\' => $adresse, \':monat\' => $monat, \':jahr\' => $jahr]);
        return number_format($asoll2, 2, \'.\', \'\');
      }',
    'ZeitGesamtMonatSoll - gesamtsummesoll/asoll2'
);

// GetArbeitszeitTag - datum format selects
replace_once($content,
    '  function GetArbeitszeitTag($adresse, $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%Y\')");
      $kw = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%u\')");
    }',
    '  function GetArbeitszeitTag($adresse, $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%Y\')", [\':datum\' => $datum]);
      $kw = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%u\')", [\':datum\' => $datum]);
    }',
    'GetArbeitszeitTag - datum format'
);

// GetArbeitszeitTag - mitarbeiterzeiterfassung block ($datum != "")
replace_once($content,
    '    if ($this->ModulVorhanden(\'mitarbeiterzeiterfassung\')) {
      if ($datum != "") {
        $urlaubkrankheitsstunden = (float) $this->app->DB->Select("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND datum = \'$datum\' AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))");
        $urlaubkrankheitsstunden += (float) $this->app->DB->Select("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = \'$adresse\' AND datum=\'$datum\' AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))");
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DB->Select("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND datum=\'$datum\'");
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      } else {
        $urlaubkrankheitsstunden = (float) $this->app->DB->Select("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND datum=\'$datum\' AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))");
        $urlaubkrankheitsstunden += (float) $this->app->DB->Select("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = \'$adresse\' AND datum=\'$datum\' AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))");
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DB->Select("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND datum=\'$datum\'");
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      }
    } elseif ($this->ModulVorhanden(\'zeiterfassung_stundenuebersicht\')) {',
    '    if ($this->ModulVorhanden(\'mitarbeiterzeiterfassung\')) {
      if ($datum != "") {
        $urlaubkrankheitsstunden = (float) $this->app->DatabaseService->selectValue("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND datum = :datum AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))", [\':adresse\' => $adresse, \':datum\' => $datum]);
        $urlaubkrankheitsstunden += (float) $this->app->DatabaseService->selectValue("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = :adresse AND ms.datum = :datum AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))", [\':adresse\' => $adresse, \':datum\' => $datum]);
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DatabaseService->selectValue("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND datum = :datum", [\':adresse\' => $adresse, \':datum\' => $datum]);
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      } else {
        $urlaubkrankheitsstunden = (float) $this->app->DatabaseService->selectValue("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND datum = :datum AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))", [\':adresse\' => $adresse, \':datum\' => $datum]);
        $urlaubkrankheitsstunden += (float) $this->app->DatabaseService->selectValue("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = :adresse AND ms.datum = :datum AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))", [\':adresse\' => $adresse, \':datum\' => $datum]);
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DatabaseService->selectValue("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND datum = :datum", [\':adresse\' => $adresse, \':datum\' => $datum]);
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      }
    } elseif ($this->ModulVorhanden(\'zeiterfassung_stundenuebersicht\')) {',
    'GetArbeitszeitTag - mitarbeiterzeiterfassung block'
);

// GetArbeitszeitTag - zeiterfassung_stundenuebersicht block
replace_once($content,
    '      $stundenprowoche = $this->app->DB->Select("SELECT stundenprowoche/5 FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $ueberstundentoleranz = $this->app->DB->Select("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre wHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $urlaubimjahr = $this->app->DB->Select("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $restueberstunden = $this->app->DB->Select("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $resturlaub = $this->app->DB->Select("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $restnotiz = $this->app->DB->Select("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      if ($stundenprowoche == "") {
        $stundenprowoche = 0;
      }
      if ($ueberstundentoleranz == "") {
        $ueberstundentoleranz = 0;
      }
      if ($urlaubimjahr == "") {
        $urlaubimjahr = 0;
      }

      if ($restueberstunden == "") {
        $restueberstunden = 0;
      }
      if ($resturlaub == "") {
        $resturlaub = 0;
      }
      $stundenausadresse = $this->app->DB->Select("SELECT arbeitszeitprowoche/5 FROM adresse WHERE id=\'$adresse\' AND id>0");
      if ($stundenprowoche <= 0 && $stundenausadresse > 0)
        $stundenprowoche = $stundenausadresse;
      return $stundenprowoche;
    }

    $arbeitszeitprowoche = $this->app->DB->Select("SELECT arbeitszeitprowoche/5 FROM adresse WHERE id=\'$adresse\' LIMIT 1");',
    '      $stundenprowoche = $this->app->DatabaseService->selectValue("SELECT stundenprowoche/5 FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $ueberstundentoleranz = $this->app->DatabaseService->selectValue("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $urlaubimjahr = $this->app->DatabaseService->selectValue("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $restueberstunden = $this->app->DatabaseService->selectValue("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $resturlaub = $this->app->DatabaseService->selectValue("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $restnotiz = $this->app->DatabaseService->selectValue("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      if ($stundenprowoche == "") {
        $stundenprowoche = 0;
      }
      if ($ueberstundentoleranz == "") {
        $ueberstundentoleranz = 0;
      }
      if ($urlaubimjahr == "") {
        $urlaubimjahr = 0;
      }

      if ($restueberstunden == "") {
        $restueberstunden = 0;
      }
      if ($resturlaub == "") {
        $resturlaub = 0;
      }
      $stundenausadresse = $this->app->DatabaseService->selectValue("SELECT arbeitszeitprowoche/5 FROM adresse WHERE id = :adresse AND id > 0", [\':adresse\' => $adresse]);
      if ($stundenprowoche <= 0 && $stundenausadresse > 0)
        $stundenprowoche = $stundenausadresse;
      return $stundenprowoche;
    }

    $arbeitszeitprowoche = $this->app->DatabaseService->selectValue("SELECT arbeitszeitprowoche/5 FROM adresse WHERE id = :adresse LIMIT 1", [\':adresse\' => $adresse]);',
    'GetArbeitszeitTag - zeiterfassung_stundenuebersicht block + final'
);

// GetArbeitszeitWoche - datum format selects
replace_once($content,
    '  function GetArbeitszeitWoche($adresse, $jahr, $kw, $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%Y\')");
      $kw = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%u\')");
    }',
    '  function GetArbeitszeitWoche($adresse, $jahr, $kw, $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%Y\')", [\':datum\' => $datum]);
      $kw = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%u\')", [\':datum\' => $datum]);
    }',
    'GetArbeitszeitWoche - datum format'
);

// GetArbeitszeitWoche - mitarbeiterzeiterfassung block
replace_once($content,
    '    if ($this->ModulVorhanden(\'mitarbeiterzeiterfassung\')) {
      if (!$jahr || !$kw) {
        $urlaubkrankheitsstunden = (float) $this->app->DB->Select("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND week(datum,3) = week(now(),3) and year(datum) = year(now()) AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))");
        $urlaubkrankheitsstunden += (float) $this->app->DB->Select("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = \'$adresse\' AND week(ms.datum,3) = week(now(),3) and year(ms.datum) = year(now()) AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))");
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DB->Select("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND week(datum,3) = week(now(),3) and year(datum) = year(now())");
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      } else {
        $urlaubkrankheitsstunden = (float) $this->app->DB->Select("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND week(datum,3) = $kw and year(datum) = $jahr AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))");
        $urlaubkrankheitsstunden += (float) $this->app->DB->Select("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = \'$adresse\' AND week(ms.datum,3) = $kw and year(ms.datum) = $jahr AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))");
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DB->Select("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = \'$adresse\' AND week(datum,3) = $kw and year(datum) = $jahr");
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      }
    } elseif ($this->ModulVorhanden(\'zeiterfassung_stundenuebersicht\')) {',
    '    if ($this->ModulVorhanden(\'mitarbeiterzeiterfassung\')) {
      if (!$jahr || !$kw) {
        $urlaubkrankheitsstunden = (float) $this->app->DatabaseService->selectValue("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND week(datum,3) = week(now(),3) and year(datum) = year(now()) AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))", [\':adresse\' => $adresse]);
        $urlaubkrankheitsstunden += (float) $this->app->DatabaseService->selectValue("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = :adresse AND week(ms.datum,3) = week(now(),3) and year(ms.datum) = year(now()) AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))", [\':adresse\' => $adresse]);
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DatabaseService->selectValue("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND week(datum,3) = week(now(),3) and year(datum) = year(now())", [\':adresse\' => $adresse]);
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      } else {
        $urlaubkrankheitsstunden = (float) $this->app->DatabaseService->selectValue("SELECT sum(minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND week(datum,3) = :kw and year(datum) = :jahr AND (instr(kuerzel,\'U\') OR instr(kuerzel,\'K\'))", [\':adresse\' => $adresse, \':kw\' => $kw, \':jahr\' => $jahr]);
        $urlaubkrankheitsstunden += (float) $this->app->DatabaseService->selectValue("SELECT sum(ms.minuten)/60 FROM mitarbeiterzeiterfassung_sollstunden ms
          INNER JOIN (SELECT distinct datum FROM arbeitsfreietage) a ON a.datum = ms.datum
          WHERE ms.adresse = :adresse AND week(ms.datum,3) = :kw and year(ms.datum) = :jahr AND not (instr(ms.kuerzel,\'U\') OR instr(ms.kuerzel,\'K\'))", [\':adresse\' => $adresse, \':kw\' => $kw, \':jahr\' => $jahr]);
        $mitarbeiterzeiterfassung_sollstunden = $this->app->DatabaseService->selectValue("SELECT sum(minuten) / 60 as wochenstunden FROM mitarbeiterzeiterfassung_sollstunden WHERE adresse = :adresse AND week(datum,3) = :kw and year(datum) = :jahr", [\':adresse\' => $adresse, \':kw\' => $kw, \':jahr\' => $jahr]);
        if ($mitarbeiterzeiterfassung_sollstunden)
          return $mitarbeiterzeiterfassung_sollstunden - $urlaubkrankheitsstunden;
      }
    } elseif ($this->ModulVorhanden(\'zeiterfassung_stundenuebersicht\')) {',
    'GetArbeitszeitWoche - mitarbeiterzeiterfassung block'
);

// GetArbeitszeitWoche - stundenuebersicht block
replace_once($content,
    '      $stundenprowoche = $this->app->DB->Select("SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $ueberstundentoleranz = $this->app->DB->Select("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre wHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $urlaubimjahr = $this->app->DB->Select("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $restueberstunden = $this->app->DB->Select("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $resturlaub = $this->app->DB->Select("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $restnotiz = $this->app->DB->Select("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      if ($stundenprowoche == "") {
        $stundenprowoche = 0;
      }
      if ($ueberstundentoleranz == "") {
        $ueberstundentoleranz = 0;
      }
      if ($urlaubimjahr == "") {
        $urlaubimjahr = 0;
      }

      if ($restueberstunden == "") {
        $restueberstunden = 0;
      }
      if ($resturlaub == "") {
        $resturlaub = 0;
      }
      $stundenausadresse = $this->app->DatabaseService->selectValue(
        "SELECT arbeitszeitprowoche FROM adresse WHERE id = :adresse AND id > 0",
        [\':adresse\' => $adresse]
      );
      if ($stundenprowoche <= 0 && $stundenausadresse > 0)
        $stundenprowoche = $stundenausadresse;
      return $stundenprowoche;
    }

    $arbeitszeitprowoche = $this->app->DB->Select("SELECT arbeitszeitprowoche FROM adresse WHERE id=\'$adresse\' LIMIT 1");',
    '      $stundenprowoche = $this->app->DatabaseService->selectValue("SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $ueberstundentoleranz = $this->app->DatabaseService->selectValue("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $urlaubimjahr = $this->app->DatabaseService->selectValue("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $restueberstunden = $this->app->DatabaseService->selectValue("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $resturlaub = $this->app->DatabaseService->selectValue("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $restnotiz = $this->app->DatabaseService->selectValue("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      if ($stundenprowoche == "") {
        $stundenprowoche = 0;
      }
      if ($ueberstundentoleranz == "") {
        $ueberstundentoleranz = 0;
      }
      if ($urlaubimjahr == "") {
        $urlaubimjahr = 0;
      }

      if ($restueberstunden == "") {
        $restueberstunden = 0;
      }
      if ($resturlaub == "") {
        $resturlaub = 0;
      }
      $stundenausadresse = $this->app->DatabaseService->selectValue(
        "SELECT arbeitszeitprowoche FROM adresse WHERE id = :adresse AND id > 0",
        [\':adresse\' => $adresse]
      );
      if ($stundenprowoche <= 0 && $stundenausadresse > 0)
        $stundenprowoche = $stundenausadresse;
      return $stundenprowoche;
    }

    $arbeitszeitprowoche = $this->app->DatabaseService->selectValue("SELECT arbeitszeitprowoche FROM adresse WHERE id = :adresse LIMIT 1", [\':adresse\' => $adresse]);',
    'GetArbeitszeitWoche - stundenuebersicht block + final'
);

// AddArbeitsnachweisPositionZeiterfassung
replace_once($content,
    '    $tmp = $this->app->DB->SelectArr("SELECT *,DATE_FORMAT(von,\'%Y-%m-%d\') as datum,DATE_FORMAT(von,\'%H:%i\') as von,DATE_FORMAT(bis,\'%H:%i\') as bis FROM zeiterfassung WHERE id=\'$zid\'");
    $sort = $this->app->DB->Select("SELECT MAX(sort) FROM arbeitsnachweis_position WHERE arbeitsnachweis=\'$arbeitsnachweis\' LIMIT 1");',
    '    $tmp = $this->app->DatabaseService->selectRow("SELECT *,DATE_FORMAT(von,\'%Y-%m-%d\') as datum,DATE_FORMAT(von,\'%H:%i\') as von,DATE_FORMAT(bis,\'%H:%i\') as bis FROM zeiterfassung WHERE id = :id", [\':id\' => $zid]);
    $tmp = $tmp ? [$tmp] : [];
    $sort = $this->app->DatabaseService->selectValue("SELECT MAX(sort) FROM arbeitsnachweis_position WHERE arbeitsnachweis = :an LIMIT 1", [\':an\' => $arbeitsnachweis]);',
    'AddArbeitsnachweisPositionZeiterfassung - selectArr+sort'
);

// AddArbeitsnachweisPositionZeiterfassung - INSERT and UPDATE
replace_once($content,
    '    $this->app->DB->Insert("INSERT INTO arbeitsnachweis_position (id,arbeitsnachweis,artikel,bezeichnung,beschreibung,ort,arbeitspaket,datum,von,bis,sort,status,projekt,adresse)
            VALUES (\'\',\'$arbeitsnachweis\',\'$artikel\',\'$bezeichnung\',\'$beschreibung\',\'$ort\',\'$arbeitspaket\',\'$datum\',\'$von\',\'$bis\',\'$sort\',\'angelegt\',\'$projekt\',\'$adresse\')");
    $tmpid = $this->app->DB->GetInsertID();
    //markieren als erledigt
    $this->app->DB->Update("UPDATE zeiterfassung SET arbeitsnachweis=\'$arbeitsnachweis\',arbeitsnachweispositionid=\'$tmpid\' WHERE id=\'$zid\'");',
    '    $this->app->DatabaseService->insert(
      "INSERT INTO arbeitsnachweis_position (arbeitsnachweis,artikel,bezeichnung,beschreibung,ort,arbeitspaket,datum,von,bis,sort,status,projekt,adresse) VALUES (:arbeitsnachweis,:artikel,:bezeichnung,:beschreibung,:ort,:arbeitspaket,:datum,:von,:bis,:sort,\'angelegt\',:projekt,:adresse)",
      [\':arbeitsnachweis\' => $arbeitsnachweis, \':artikel\' => $artikel, \':bezeichnung\' => $bezeichnung, \':beschreibung\' => $beschreibung, \':ort\' => $ort, \':arbeitspaket\' => $arbeitspaket, \':datum\' => $datum, \':von\' => $von, \':bis\' => $bis, \':sort\' => $sort, \':projekt\' => $projekt, \':adresse\' => $adresse]
    );
    $tmpid = $this->app->DB->GetInsertID();
    //markieren als erledigt
    $this->app->DatabaseService->execute(
      "UPDATE zeiterfassung SET arbeitsnachweis = :arbeitsnachweis, arbeitsnachweispositionid = :tmpid WHERE id = :zid",
      [\':arbeitsnachweis\' => $arbeitsnachweis, \':tmpid\' => $tmpid, \':zid\' => $zid]
    );',
    'AddArbeitsnachweisPositionZeiterfassung - INSERT+UPDATE'
);

// CreateAufgabe
replace_once($content,
    '  function CreateAufgabe($adresse, $aufgabe, $kunde = 0)
  {
    $this->app->DB->Insert("INSERT INTO aufgabe (id,adresse,initiator,aufgabe,status,kunde)
          VALUES (\'\',\'$adresse\',\'" . $this->app->User->GetAdresse() . "\',\'$aufgabe\',\'offen\',\'$kunde\')");
    return $this->app->DB->GetInsertID();
  }',
    '  function CreateAufgabe($adresse, $aufgabe, $kunde = 0)
  {
    $this->app->DatabaseService->insert(
      "INSERT INTO aufgabe (adresse,initiator,aufgabe,status,kunde) VALUES (:adresse,:initiator,:aufgabe,\'offen\',:kunde)",
      [\':adresse\' => $adresse, \':initiator\' => $this->app->User->GetAdresse(), \':aufgabe\' => $aufgabe, \':kunde\' => $kunde]
    );
    return $this->app->DB->GetInsertID();
  }',
    'CreateAufgabe'
);

// AbschlussAufgabe
replace_once($content,
    '    $intervall_tage = $this->app->DB->Select("SELECT intervall_tage FROM aufgabe WHERE id=\'$id\'");
    $startdatum = $this->app->DB->Select("SELECT abgabe_bis FROM aufgabe WHERE id=\'$id\'");
    $check = $this->app->DB->Select("SELECT id FROM aufgabe WHERE id=\'$id\' AND ((abgabe_bis <= NOW() AND intervall_tage > 0) OR intervall_tage=0)");',
    '    $intervall_tage = $this->app->DatabaseService->selectValue("SELECT intervall_tage FROM aufgabe WHERE id = :id", [\':id\' => $id]);
    $startdatum = $this->app->DatabaseService->selectValue("SELECT abgabe_bis FROM aufgabe WHERE id = :id", [\':id\' => $id]);
    $check = $this->app->DatabaseService->selectValue("SELECT id FROM aufgabe WHERE id = :id AND ((abgabe_bis <= NOW() AND intervall_tage > 0) OR intervall_tage = 0)", [\':id\' => $id]);',
    'AbschlussAufgabe - selects'
);

// AbschlussAufgabe - UPDATE aufgabe abgeschlossen
replace_once($content,
    '    $this->app->DB->Update("UPDATE aufgabe SET status=\'abgeschlossen\',abgeschlossen_am=NOW() WHERE id=\'$id\' LIMIT 1");',
    '    $this->app->DatabaseService->execute("UPDATE aufgabe SET status=\'abgeschlossen\', abgeschlossen_am=NOW() WHERE id = :id LIMIT 1", [\':id\' => $id]);',
    'AbschlussAufgabe - UPDATE'
);

// AbschlussAufgabe - newstartdatum updates (case 1..4)
replace_once($content,
    '      $this->app->DB->Update("UPDATE aufgabe SET abgabe_bis=\'$newstartdatum\' WHERE id=\'$newaufgabe\'");
        break;

      case 2: //wochen
        $newaufgabe = $this->CopyAufgabe($id);
        $newstartdatum = date(\'Y-m-d H:i:s\', strtotime("$startdatum +7 days"));
        $this->app->DB->Update("UPDATE aufgabe SET abgabe_bis=\'$newstartdatum\' WHERE id=\'$newaufgabe\'");
        break;
      case 3: //monatlich
        $newaufgabe = $this->CopyAufgabe($id);
        $newstartdatum = date(\'Y-m-d H:i:s\', strtotime("$startdatum +1 month"));
        $this->app->DB->Update("UPDATE aufgabe SET abgabe_bis=\'$newstartdatum\' WHERE id=\'$newaufgabe\'");
        break;
      case 4: // jaehrlich
        $newaufgabe = $this->CopyAufgabe($id);
        $newstartdatum = date(\'Y-m-d H:i:s\', strtotime("$startdatum +1 year"));
        $this->app->DB->Update("UPDATE aufgabe SET abgabe_bis=\'$newstartdatum\' WHERE id=\'$newaufgabe\'");',
    '      $this->app->DatabaseService->execute("UPDATE aufgabe SET abgabe_bis = :datum WHERE id = :id", [\':datum\' => $newstartdatum, \':id\' => $newaufgabe]);
        break;

      case 2: //wochen
        $newaufgabe = $this->CopyAufgabe($id);
        $newstartdatum = date(\'Y-m-d H:i:s\', strtotime("$startdatum +7 days"));
        $this->app->DatabaseService->execute("UPDATE aufgabe SET abgabe_bis = :datum WHERE id = :id", [\':datum\' => $newstartdatum, \':id\' => $newaufgabe]);
        break;
      case 3: //monatlich
        $newaufgabe = $this->CopyAufgabe($id);
        $newstartdatum = date(\'Y-m-d H:i:s\', strtotime("$startdatum +1 month"));
        $this->app->DatabaseService->execute("UPDATE aufgabe SET abgabe_bis = :datum WHERE id = :id", [\':datum\' => $newstartdatum, \':id\' => $newaufgabe]);
        break;
      case 4: // jaehrlich
        $newaufgabe = $this->CopyAufgabe($id);
        $newstartdatum = date(\'Y-m-d H:i:s\', strtotime("$startdatum +1 year"));
        $this->app->DatabaseService->execute("UPDATE aufgabe SET abgabe_bis = :datum WHERE id = :id", [\':datum\' => $newstartdatum, \':id\' => $newaufgabe]);',
    'AbschlussAufgabe - 4 interval updates'
);

// CreateRetoure - SELECT standardlager
replace_once($content,
    '    $standardlager = $this->app->DB->Select("SELECT l.id FROM projekt p INNER JOIN lager l ON p.standardlager = l.id WHERE p.id = \'$projekt\' LIMIT 1");',
    '    $standardlager = $this->app->DatabaseService->selectValue("SELECT l.id FROM projekt p INNER JOIN lager l ON p.standardlager = l.id WHERE p.id = :id LIMIT 1", [\':id\' => $projekt]);',
    'CreateRetoure - standardlager'
);

// CreateRetoure - UPDATE standardlager
replace_once($content,
    '    if ($standardlager)
      $this->app->DB->Update("UPDATE retoure SET standardlager = \'$standardlager\' WHERE id = \'$id\' LIMIT 1");
    $type = "retoure";',
    '    if ($standardlager)
      $this->app->DatabaseService->execute("UPDATE retoure SET standardlager = :lager WHERE id = :id LIMIT 1", [\':lager\' => $standardlager, \':id\' => $id]);
    $type = "retoure";',
    'CreateRetoure - UPDATE standardlager'
);

// DeleteAnfrage
replace_once($content,
    '  function DeleteAnfrage($id)
  {
    $this->app->DB->Delete("DELETE FROM anfrage_position WHERE anfrage=\'$id\'");
    $this->app->DB->Delete("DELETE FROM anfrage_protokoll WHERE anfrage=\'$id\'");
    $this->app->DB->Delete("DELETE FROM anfrage WHERE id=\'$id\' LIMIT 1");
  }',
    '  function DeleteAnfrage($id)
  {
    $this->app->DatabaseService->execute("DELETE FROM anfrage_position WHERE anfrage = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM anfrage_protokoll WHERE anfrage = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM anfrage WHERE id = :id LIMIT 1", [\':id\' => $id]);
  }',
    'DeleteAnfrage'
);

// DeleteProformarechnung
replace_once($content,
    '  function DeleteProformarechnung($id)
  {
    $this->app->DB->Delete("DELETE FROM proformarechnung_lieferschein WHERE proformarechnung=\'$id\'");
    $this->app->DB->Delete("DELETE FROM proformarechnung_position WHERE proformarechnung=\'$id\'");
    $this->app->DB->Delete("DELETE FROM proformarechnung_protokoll WHERE proformarechnung=\'$id\'");
    $this->app->DB->Delete("DELETE FROM proformarechnung WHERE id=\'$id\' LIMIT 1");
  }',
    '  function DeleteProformarechnung($id)
  {
    $this->app->DatabaseService->execute("DELETE FROM proformarechnung_lieferschein WHERE proformarechnung = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM proformarechnung_position WHERE proformarechnung = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM proformarechnung_protokoll WHERE proformarechnung = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM proformarechnung WHERE id = :id LIMIT 1", [\':id\' => $id]);
  }',
    'DeleteProformarechnung'
);

// DeleteKalkulation
replace_once($content,
    '  function DeleteKalkulation($id)
  {
    $this->app->DB->Delete("DELETE FROM kalkulation_position WHERE kalkulation=\'$id\'");
    $this->app->DB->Delete("DELETE FROM kalkulation_protokoll WHERE kalkulation=\'$id\'");
    $this->app->DB->Delete("DELETE FROM kalkulation WHERE id=\'$id\' LIMIT 1");
  }',
    '  function DeleteKalkulation($id)
  {
    $this->app->DatabaseService->execute("DELETE FROM kalkulation_position WHERE kalkulation = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM kalkulation_protokoll WHERE kalkulation = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM kalkulation WHERE id = :id LIMIT 1", [\':id\' => $id]);
  }',
    'DeleteKalkulation'
);

// DeleteRetoure
replace_once($content,
    '  function DeleteRetoure($id)
  {
    $this->app->DB->Delete("DELETE FROM retoure_position WHERE retoure=\'$id\'");
    $this->app->DB->Delete("DELETE FROM retoure_protokoll WHERE retoure=\'$id\'");
    $this->app->DB->Delete("DELETE FROM retoure WHERE id=\'$id\' LIMIT 1");
  }',
    '  function DeleteRetoure($id)
  {
    $this->app->DatabaseService->execute("DELETE FROM retoure_position WHERE retoure = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM retoure_protokoll WHERE retoure = :id", [\':id\' => $id]);
    $this->app->DatabaseService->execute("DELETE FROM retoure WHERE id = :id LIMIT 1", [\':id\' => $id]);
  }',
    'DeleteRetoure'
);

// CreateAuftrag - standardlager
replace_once($content,
    '    $standardlager = $this->app->DB->Select("SELECT standardlager FROM projekt WHERE id = \'$projekt\' LIMIT 1");',
    '    $standardlager = $this->app->DatabaseService->selectValue("SELECT standardlager FROM projekt WHERE id = :id LIMIT 1", [\':id\' => $projekt]);',
    'CreateAuftrag - standardlager'
);

// CreateAuftrag - standardlager UPDATE
replace_once($content,
    '    if ($standardlager)
      $this->app->DB->Update("UPDATE auftrag SET standardlager = \'$standardlager\' WHERE id = \'$id\' LIMIT 1");
    $this->CheckVertrieb($id, "auftrag");',
    '    if ($standardlager)
      $this->app->DatabaseService->execute("UPDATE auftrag SET standardlager = :lager WHERE id = :id LIMIT 1", [\':lager\' => $standardlager, \':id\' => $id]);
    $this->CheckVertrieb($id, "auftrag");',
    'CreateAuftrag - UPDATE standardlager'
);

// ArtikelIDProjekt
replace_once($content,
    '    $eigenernummernkreis = $this->app->DB->Select("SELECT eigenernummernkreis FROM projekt WHERE id=\'$projekt\' LIMIT 1");
    if ($eigenernummernkreis == "1") {
      $artikel = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer=\'$artikelnummer\' AND projekt=\'$projekt\' AND nummer!=\'\' LIMIT 1");
      if ($artikel) {
        return $artikel;
      }
      return $this->app->DB->Select("SELECT id FROM artikel WHERE nummer=\'$artikelnummer\' AND projekt=\'0\' AND nummer!=\'\' LIMIT 1");
    }

    return $this->app->DB->Select("SELECT id FROM artikel WHERE nummer=\'$artikelnummer\' AND nummer!=\'\' LIMIT 1");
  }',
    '    $eigenernummernkreis = $this->app->DatabaseService->selectValue("SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1", [\':id\' => $projekt]);
    if ($eigenernummernkreis == "1") {
      $artikel = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nr AND projekt = :projekt AND nummer != \'\' LIMIT 1", [\':nr\' => $artikelnummer, \':projekt\' => $projekt]);
      if ($artikel) {
        return $artikel;
      }
      return $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nr AND projekt = \'0\' AND nummer != \'\' LIMIT 1", [\':nr\' => $artikelnummer]);
    }

    return $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nr AND nummer != \'\' LIMIT 1", [\':nr\' => $artikelnummer]);
  }',
    'ArtikelIDProjekt'
);

// AddAuftragPositionNummer - sprache select
replace_once($content,
    '    $sprache = $this->app->DB->Select("SELECT sprache FROM $doctype WHERE id=\'$auftrag\' LIMIT 1");',
    '    $sprache = $this->app->DatabaseService->selectValue("SELECT sprache FROM `$doctype` WHERE id = :id LIMIT 1", [\':id\' => $auftrag]);',
    'AddAuftragPositionNummer - sprache'
);

file_put_contents($filepath, $content);
echo "Total changes: $changes\n";
