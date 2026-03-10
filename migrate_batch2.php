<?php
/**
 * Migration batch 2: ZeitGesamtWocheIst, ZeitGesamtMonatIst, ZeitUrlaubGenommen,
 * ZeitGesamtMonatSoll, GetArbeitszeitTag, GetArbeitszeitWoche, and more functions.
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

// -----------------------------------------------------------------------
// ZeitGesamtWocheIst
// -----------------------------------------------------------------------
replace_once($content,
    '  function ZeitGesamtWocheIst($adresse, $jahr = "", $kw = "", $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%Y\')");
      $kw = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%u\')");
    }

    if ($jahr == "")
      $jahr = date(\'Y\');
    if ($kw == "")
      $kw = date(\'W\');

    $kw = str_pad($kw, 2, "0", STR_PAD_LEFT);

    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%v\')=\'" . $jahr . "-" . $kw . "\' AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtWocheIst($adresse, $jahr = "", $kw = "", $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%Y\')", [\':datum\' => $datum]);
      $kw = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%u\')", [\':datum\' => $datum]);
    }

    if ($jahr == "")
      $jahr = date(\'Y\');
    if ($kw == "")
      $kw = date(\'W\');

    $kw = str_pad($kw, 2, "0", STR_PAD_LEFT);
    $jahrKw = $jahr . "-" . $kw;

    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%v\') = :jahrKw AND z.adresse = :adresse",
      [\':jahrKw\' => $jahrKw, \':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtWocheIst'
);

// -----------------------------------------------------------------------
// ZeitGesamtMonatIst
// -----------------------------------------------------------------------
replace_once($content,
    '  function ZeitGesamtMonatIst($adresse, $jahr = "", $monat = "", $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%Y\')");
      $monat = $this->app->DB->Select("SELECT DATE_FORMAT(\'$datum\',\'%m\')");
    }

    if ($jahr == "")
      $jahr = date(\'Y\');
    if ($monat == "")
      $monat = date(\'m\');

    $monat = str_pad($monat, 2, "0", STR_PAD_LEFT);

    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m\')=\'" . $jahr . "-" . $monat . "\' AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtMonatIst($adresse, $jahr = "", $monat = "", $datum = "")
  {
    if ($datum != "") {
      $jahr = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%Y\')", [\':datum\' => $datum]);
      $monat = $this->app->DatabaseService->selectValue("SELECT DATE_FORMAT(:datum,\'%m\')", [\':datum\' => $datum]);
    }

    if ($jahr == "")
      $jahr = date(\'Y\');
    if ($monat == "")
      $monat = date(\'m\');

    $monat = str_pad($monat, 2, "0", STR_PAD_LEFT);
    $jahrMonat = $jahr . "-" . $monat;

    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m\') = :jahrMonat AND z.adresse = :adresse",
      [\':jahrMonat\' => $jahrMonat, \':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtMonatIst'
);

// -----------------------------------------------------------------------
// ZeitUrlaubGenommen - 6 DB->Select calls with $adresse/$jahr interpolation
// -----------------------------------------------------------------------
replace_once($content,
    '      $stundenprowoche = $this->app->DB->Select("SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $ueberstundentoleranz = $this->app->DB->Select("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre wHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $urlaubimjahr = $this->app->DB->Select("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $restueberstunden = $this->app->DB->Select("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $resturlaub = $this->app->DB->Select("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");
      $restnotiz = $this->app->DB->Select("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");',
    '      $stundenprowoche = $this->app->DatabaseService->selectValue("SELECT stundenprowoche FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $ueberstundentoleranz = $this->app->DatabaseService->selectValue("SELECT ueberstundentoleranz FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $urlaubimjahr = $this->app->DatabaseService->selectValue("SELECT urlaubimjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $restueberstunden = $this->app->DatabaseService->selectValue("SELECT ueberstundenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $resturlaub = $this->app->DatabaseService->selectValue("SELECT urlaubvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);
      $restnotiz = $this->app->DatabaseService->selectValue("SELECT notizenvorjahr FROM zeiterfassung_stundenuebersicht_jahre WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);',
    'ZeitUrlaubGenommen - 6 stunden/urlaub selects'
);

// gesamtsummesoll in ZeitUrlaubGenommen
replace_once($content,
    '      $gesamtsummesoll = $this->app->DB->Select("SELECT SUM(soll) FROM zeiterfassung_stundenuebersicht WHERE adresse = \"$adresse\" AND jahr = \"$jahr\"");

      $eingeloest = 0;',
    '      $gesamtsummesoll = $this->app->DatabaseService->selectValue("SELECT SUM(soll) FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND jahr = :jahr", [\':adresse\' => $adresse, \':jahr\' => $jahr]);

      $eingeloest = 0;',
    'ZeitUrlaubGenommen gesamtsummesoll'
);

// asoll2 soll in ZeitUrlaubGenommen
replace_once($content,
    '          $asoll2[$i] = $this->app->DB->Select("SELECT soll FROM zeiterfassung_stundenuebersicht WHERE adresse = \"$adresse\" AND monat = \"$i\" AND jahr = \"$jahr\"");
          $asoll2[$i] = number_format($asoll2[$i], 2, \'.\', \'\');',
    '          $asoll2[$i] = $this->app->DatabaseService->selectValue("SELECT soll FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr", [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]);
          $asoll2[$i] = number_format($asoll2[$i], 2, \'.\', \'\');',
    'ZeitUrlaubGenommen asoll2'
);

// aueberstunden2 in ZeitUrlaubGenommen
replace_once($content,
    '        $aueberstunden2[$i] = $this->app->DB->Select("SELECT ueberstunden_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = \"$adresse\" AND monat = \"$i\" AND jahr = \"$jahr\"");
        $aueberstunden2[$i] = number_format($aueberstunden2[$i], 2, \'.\', \'\');

        //EINGELÖSTER URLAUB
        $aurlaub2[$i] = $this->app->DB->Select("SELECT urlaub_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = \"$adresse\" AND monat = \"$i\" AND jahr = \"$jahr\"");
        $aurlaub2[$i] = number_format($aurlaub2[$i], 2, \'.\', \'\');
        $eingeloest += $aurlaub2[$i];
        //NOTIZEN
        $anotizen2[$i] = $this->app->DB->Select("SELECT notizen FROM zeiterfassung_stundenuebersicht WHERE adresse = \"$adresse\" AND monat = \"$i\" AND jahr = \"$jahr\"");',
    '        $aueberstunden2[$i] = $this->app->DatabaseService->selectValue("SELECT ueberstunden_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr", [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]);
        $aueberstunden2[$i] = number_format($aueberstunden2[$i], 2, \'.\', \'\');

        //EINGELÖSTER URLAUB
        $aurlaub2[$i] = $this->app->DatabaseService->selectValue("SELECT urlaub_eingeloest FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr", [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]);
        $aurlaub2[$i] = number_format($aurlaub2[$i], 2, \'.\', \'\');
        $eingeloest += $aurlaub2[$i];
        //NOTIZEN
        $anotizen2[$i] = $this->app->DatabaseService->selectValue("SELECT notizen FROM zeiterfassung_stundenuebersicht WHERE adresse = :adresse AND monat = :monat AND jahr = :jahr", [\':adresse\' => $adresse, \':monat\' => $i, \':jahr\' => $jahr]);',
    'ZeitUrlaubGenommen aueberstunden2/aurlaub2/anotizen2'
);

file_put_contents($filepath, $content);
echo "Total changes: $changes\n";
