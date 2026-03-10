<?php
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
        // debug: show nearby context
        $search = preg_quote(substr(trim($old), 0, 50), '/');
        if (preg_match('/' . $search . '/s', $content, $m, PREG_OFFSET_CAPTURE)) {
            echo "  => partial match found at offset " . $m[0][1] . "\n";
        }
    }
}

// 1. ZeitGesamtDatumArbeitAbrechnen
replace_once($content,
    '  function ZeitGesamtDatumArbeitAbrechnen($adresse, $datum)
  {
    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Arbeit\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\')=\'$datum\' AND z.adresse=\'$adresse\' AND z.abrechnen=1";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtDatumArbeitAbrechnen($adresse, $datum)
  {
    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Arbeit\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\') = :datum AND z.adresse = :adresse AND z.abrechnen = 1",
      [\':datum\' => $datum, \':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtDatumArbeitAbrechnen'
);

// 2. ZeitGesamtAufgabe
replace_once($content,
    '  function ZeitGesamtAufgabe($id, $adresse = 0)
  {

    if ($adresse <= 0) {
      $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Arbeit\' AND z.aufgabe_id=\'$id\'";
    } else {
      $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Arbeit\' AND z.aufgabe_id=\'$id\' AND z.adresse=\'$adresse\'";
    }

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtAufgabe($id, $adresse = 0)
  {
    if ($adresse <= 0) {
      $erg = $this->app->DatabaseService->selectValue(
        "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Arbeit\' AND z.aufgabe_id = :id",
        [\':id\' => $id]
      );
    } else {
      $erg = $this->app->DatabaseService->selectValue(
        "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Arbeit\' AND z.aufgabe_id = :id AND z.adresse = :adresse",
        [\':id\' => $id, \':adresse\' => $adresse]
      );
    }
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtAufgabe'
);

// 3. ZeitGesamtDatumArbeit
replace_once($content,
    '  function ZeitGesamtDatumArbeit($adresse, $datum)
  {
    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\')=\'$datum\' AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtDatumArbeit($adresse, $datum)
  {
    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\') = :datum AND z.adresse = :adresse",
      [\':datum\' => $datum, \':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtDatumArbeit'
);

// 4. ZeitGesamtHeuteArbeit
replace_once($content,
    '  function ZeitGesamtHeuteArbeit($adresse)
  {
    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\')=DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtHeuteArbeit($adresse)
  {
    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\') = DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND z.adresse = :adresse",
      [\':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtHeuteArbeit'
);

// 5. ZeitGesamtArbeit
replace_once($content,
    '  function ZeitGesamtArbeit($adresse, $datum)
  {
    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\')=DATE_FORMAT(\'$datum\',\'%Y-%m-%d\') AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtArbeit($adresse, $datum)
  {
    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art NOT LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\') = DATE_FORMAT(:datum,\'%Y-%m-%d\') AND z.adresse = :adresse",
      [\':datum\' => $datum, \':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtArbeit'
);

// 6. ZeitGesamtDatumPause
replace_once($content,
    '  function ZeitGesamtDatumPause($adresse, $datum)
  {
    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\')=\'$datum\' AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtDatumPause($adresse, $datum)
  {
    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\') = :datum AND z.adresse = :adresse",
      [\':datum\' => $datum, \':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtDatumPause'
);

// 7. ZeitGesamtHeutePause
replace_once($content,
    '  function ZeitGesamtHeutePause($adresse)
  {
    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\')=DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtHeutePause($adresse)
  {
    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\') = DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND z.adresse = :adresse",
      [\':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtHeutePause'
);

// 8. ZeitGesamtPause
replace_once($content,
    '  function ZeitGesamtPause($adresse, $datum)
  {
    $sql = "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\')=DATE_FORMAT(\'$datum\',\'%Y-%m-%d\') AND z.adresse=\'$adresse\'";

    $erg = $this->app->DB->Select($sql);
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    '  function ZeitGesamtPause($adresse, $datum)
  {
    $erg = $this->app->DatabaseService->selectValue(
      "SELECT SUM((TIMESTAMPDIFF(SECOND,z.von, z.bis))/3600) FROM `zeiterfassung` z WHERE z.art LIKE \'Pause\'
          AND DATE_FORMAT(z.von,\'%Y-%m-%d\') = DATE_FORMAT(:datum,\'%Y-%m-%d\') AND z.adresse = :adresse",
      [\':datum\' => $datum, \':adresse\' => $adresse]
    );
    if ($erg <= 0)
      $erg = 0;
    return $erg;
  }',
    'ZeitGesamtPause'
);

file_put_contents($filepath, $content);
echo "Total changes: $changes\n";
