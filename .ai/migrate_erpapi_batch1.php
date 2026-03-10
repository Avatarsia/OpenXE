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

// ---- ReplaceLibeferantennummer (first function ~13337) - projekt from $rmodule ----
rep($content,
    '$projekt = $this->app->DB->Select("SELECT projekt FROM $rmodule WHERE id = \'$rid\' LIMIT 1");',
    '$projekt = $this->app->DatabaseService->selectValue("SELECT projekt FROM `$rmodule` WHERE id = :id LIMIT 1", [\'id\' => $rid]);',
    'SELECT projekt FROM $rmodule (3 occurrences expected)', $changes
);

rep($content,
    '$eigenernummernkreis = $this->app->DB->Select("SELECT eigenernummernkreis FROM projekt WHERE id = \'$projekt\' LIMIT 1");',
    '$eigenernummernkreis = $this->app->DatabaseService->selectValue("SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1", [\'id\' => $projekt]);',
    'SELECT eigenernummernkreis FROM projekt (3 occurrences)', $changes
);

// ---- ReplaceLibeferantennummer line 13348 - lieferantennummer ORDER BY first occurrence ----
$old = '      $id = $this->app->DB->Select("SELECT id FROM adresse WHERE lieferantennummer=\'$rest\' AND lieferantennummer!=\'\' AND geloescht=0 ORDER BY " . ($filter_projekt ? " projekt = \'$filter_projekt\' DESC, " : "") . " projekt LIMIT 1");
      if ($id <= 0)
        $id = 0;
    }

    // wenn ziel datenbank
    if ($db) {
      return $id;
    }
    // wenn ziel formular
    else {
      return $abkuerzung;
    }
  }

  // @refactor Formater Komponente';
$new = '      $orderByLief1 = $filter_projekt ? "projekt = :filter_projekt DESC, projekt" : "projekt";
      $id = $this->app->DatabaseService->selectValue(
        "SELECT id FROM adresse WHERE lieferantennummer = :rest AND lieferantennummer != \'\' AND geloescht = 0 ORDER BY $orderByLief1 LIMIT 1",
        $filter_projekt ? [\'rest\' => $rest, \'filter_projekt\' => $filter_projekt] : [\'rest\' => $rest]
      );
      if ($id <= 0)
        $id = 0;
    }

    // wenn ziel datenbank
    if ($db) {
      return $id;
    }
    // wenn ziel formular
    else {
      return $abkuerzung;
    }
  }

  // @refactor Formater Komponente';
rep($content, $old, $new, 'ReplaceLieferantennummer lieferantennummer ORDER BY', $changes);

// ---- ReplaceKundennummer line ~13381 ----
rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT kundennummer as name FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT kundennummer as name FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1", [\'id\' => (int) $id]);',
    'SELECT kundennummer as name FROM adresse', $changes
);

// ---- ReplaceKundennummer line ~13414 kundennummer ORDER BY ----
$old = '      $id = $this->app->DB->Select("SELECT id FROM adresse WHERE kundennummer=\'$rest\' AND kundennummer!=\'\' AND geloescht=0 ORDER BY  " . ($filter_projekt ? " projekt = \'$filter_projekt\' DESC, " : "") . " projekt LIMIT 1");
      if ($id <= 0)
        $id = 0;
    }

    // wenn ziel datenbank
    if ($db) {
      return $id;
    }
    // wenn ziel formular
    else {
      return $abkuerzung;
    }
  }

  // @refactor FormHelper Komponente
  function ReplaceKunde($db, $value, $fromform)';
$new = '      $orderByKdn1 = $filter_projekt ? "projekt = :filter_projekt DESC, projekt" : "projekt";
      $id = $this->app->DatabaseService->selectValue(
        "SELECT id FROM adresse WHERE kundennummer = :rest AND kundennummer != \'\' AND geloescht = 0 ORDER BY $orderByKdn1 LIMIT 1",
        $filter_projekt ? [\'rest\' => $rest, \'filter_projekt\' => $filter_projekt] : [\'rest\' => $rest]
      );
      if ($id <= 0)
        $id = 0;
    }

    // wenn ziel datenbank
    if ($db) {
      return $id;
    }
    // wenn ziel formular
    else {
      return $abkuerzung;
    }
  }

  // @refactor FormHelper Komponente
  function ReplaceKunde($db, $value, $fromform)';
rep($content, $old, $new, 'ReplaceKundennummer kundennummer ORDER BY', $changes);

// ---- ReplaceKunde line ~13428 CONCAT kundennummer name ----
rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT CONCAT(kundennummer,\' \',name) as name FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT CONCAT(kundennummer,\' \',name) as name FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1", [\'id\' => (int) $id]);',
    'SELECT CONCAT(kundennummer name) adresse', $changes
);

// ---- ReplaceKunde line ~13451 kundennummer ORDER BY ----
$old = '      $id = $this->app->DB->Select("SELECT id FROM adresse WHERE kundennummer=\'$rest\' AND kundennummer!=\'\' AND geloescht=0 ORDER BY  " . ($filter_projekt ? " projekt = \'$filter_projekt\' DESC, " : "") . " projekt LIMIT 1");
      if ($id <= 0)
        $id = 0;
    }

    // wenn ziel datenbank
    if ($db) {
      return $id;
    }
    // wenn ziel formular
    else {
      return $abkuerzung;
    }
  }

  // @refactor FormHelper Komponente
  function ReplaceSteuersatz';
$new = '      $orderByKunde2 = $filter_projekt ? "projekt = :filter_projekt DESC, projekt" : "projekt";
      $id = $this->app->DatabaseService->selectValue(
        "SELECT id FROM adresse WHERE kundennummer = :rest AND kundennummer != \'\' AND geloescht = 0 ORDER BY $orderByKunde2 LIMIT 1",
        $filter_projekt ? [\'rest\' => $rest, \'filter_projekt\' => $filter_projekt] : [\'rest\' => $rest]
      );
      if ($id <= 0)
        $id = 0;
    }

    // wenn ziel datenbank
    if ($db) {
      return $id;
    }
    // wenn ziel formular
    else {
      return $abkuerzung;
    }
  }

  // @refactor FormHelper Komponente
  function ReplaceSteuersatz';
rep($content, $old, $new, 'ReplaceKunde kundennummer ORDER BY', $changes);

// ---- ReplaceSteuergruppe line 13496 ----
rep($content,
    '      $value = $this->app->DB->real_escape_string($value);
      // Removed, table does not exist      $id =  $this->app->DB->Select("SELECT id FROM steuerregelngruppe WHERE bezeichnung = \'$value\' ORDER BY aktiv = 1 DESC LIMIT 1");',
    '      // Removed, table does not exist (real_escape_string call also removed)',
    'ReplaceSteuergruppe real_escape_string', $changes
);

// ---- ReplaceKontorahmen line 13514 ----
$old = '    $value = $this->app->DB->real_escape_string($value);

    if ($db) {
      $sachkonto = explode(\' \', $value)[0];
      $kontoid = $this->app->DB->Select("SELECT id FROM kontorahmen WHERE sachkonto = \'$sachkonto\' LIMIT 1");
      return ($kontoid);
    } else {
      $sachkonto = $this->app->DB->Select("SELECT CONCAT(sachkonto,\' \',beschriftung) FROM kontorahmen WHERE id = \'$value\' LIMIT 1");
      return ($sachkonto);
    }
  }

  function ReplaceKonto';
$new = '    if ($db) {
      $sachkonto = explode(\' \', $value)[0];
      $kontoid = $this->app->DatabaseService->selectValue("SELECT id FROM kontorahmen WHERE sachkonto = :sachkonto LIMIT 1", [\'sachkonto\' => $sachkonto]);
      return ($kontoid);
    } else {
      $sachkonto = $this->app->DatabaseService->selectValue("SELECT CONCAT(sachkonto,\' \',beschriftung) FROM kontorahmen WHERE id = :id LIMIT 1", [\'id\' => $value]);
      return ($sachkonto);
    }
  }

  function ReplaceKonto';
rep($content, $old, $new, 'ReplaceKontorahmen', $changes);

// ---- ReplaceKonto line 13528 ----
$old = '    $value = $this->app->DB->real_escape_string($value);

    if ($db) {
      $konto = explode(\' \', $value)[0];
      $kontoid = $this->app->DB->Select("SELECT id FROM konten WHERE kurzbezeichnung = \'$konto\' LIMIT 1");
      return ($kontoid);
    } else {
      $konto = $this->app->DB->Select("SELECT CONCAT(kurzbezeichnung,\' \',bezeichnung) FROM konten WHERE id = \'$value\' LIMIT 1");
      return ($konto);
    }
  }

  function ReplaceSmartyTemplate';
$new = '    if ($db) {
      $konto = explode(\' \', $value)[0];
      $kontoid = $this->app->DatabaseService->selectValue("SELECT id FROM konten WHERE kurzbezeichnung = :konto LIMIT 1", [\'konto\' => $konto]);
      return ($kontoid);
    } else {
      $konto = $this->app->DatabaseService->selectValue("SELECT CONCAT(kurzbezeichnung,\' \',bezeichnung) FROM konten WHERE id = :id LIMIT 1", [\'id\' => $value]);
      return ($konto);
    }
  }

  function ReplaceSmartyTemplate';
rep($content, $old, $new, 'ReplaceKonto', $changes);

// ---- ReplaceSmartyTemplate line 13542 ----
$old = '    $value = $this->app->DB->real_escape_string($value);

    if ($db) {
      $smarty_template = explode(\' \', $value)[0];
      return ($smarty_template);
    } else {
      $smarty_template = $this->app->DB->Select("SELECT CONCAT(id,\' \',name) FROM smarty_templates WHERE id = \'$value\' LIMIT 1");
      return ($smarty_template);
    }
  }';
$new = '    if ($db) {
      $smarty_template = explode(\' \', $value)[0];
      return ($smarty_template);
    } else {
      $smarty_template = $this->app->DatabaseService->selectValue("SELECT CONCAT(id,\' \',name) FROM smarty_templates WHERE id = :id LIMIT 1", [\'id\' => $value]);
      return ($smarty_template);
    }
  }';
rep($content, $old, $new, 'ReplaceSmartyTemplate', $changes);

// ---- ReplaceLieferant line 13563 ----
rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT CONCAT(lieferantennummer,\' \',name) as name FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT CONCAT(lieferantennummer,\' \',name) as name FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1", [\'id\' => (int) $id]);',
    'ReplaceLieferant CONCAT lieferantennummer name', $changes
);

// ---- ReplaceLieferant line 13574/13586 ----
$old = '      if ($raction == \'edit\' && $rid && in_array($rmodule, $pruefemodule)) {
        $projekt = $this->app->DatabaseService->selectValue("SELECT projekt FROM `$rmodule` WHERE id = :id LIMIT 1", [\'id\' => $rid]);
        if ($projekt) {
          $eigenernummernkreis = $this->app->DatabaseService->selectValue("SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1", [\'id\' => $projekt]);
          //if($eigenernummernkreis)
          $filter_projekt = $projekt;
        }
      }
      $dbformat = 0;
      $abkuerzung = $value;
      $tmp = trim($value);
      $rest = explode(" ", $tmp);
      $rest = $rest[0];
      $id = $this->app->DB->Select("SELECT id FROM adresse WHERE lieferantennummer=\'$rest\' AND lieferantennummer!=\'\' AND geloescht=0 ORDER BY " . ($filter_projekt ? " projekt = \'$filter_projekt\' DESC, " : "") . " projekt LIMIT 1");';
$new = '      if ($raction == \'edit\' && $rid && in_array($rmodule, $pruefemodule)) {
        $projekt = $this->app->DatabaseService->selectValue("SELECT projekt FROM `$rmodule` WHERE id = :id LIMIT 1", [\'id\' => $rid]);
        if ($projekt) {
          $eigenernummernkreis = $this->app->DatabaseService->selectValue("SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1", [\'id\' => $projekt]);
          //if($eigenernummernkreis)
          $filter_projekt = $projekt;
        }
      }
      $dbformat = 0;
      $abkuerzung = $value;
      $tmp = trim($value);
      $rest = explode(" ", $tmp);
      $rest = $rest[0];
      $orderByLief2 = $filter_projekt ? "projekt = :filter_projekt DESC, projekt" : "projekt";
      $id = $this->app->DatabaseService->selectValue(
        "SELECT id FROM adresse WHERE lieferantennummer = :rest AND lieferantennummer != \'\' AND geloescht = 0 ORDER BY $orderByLief2 LIMIT 1",
        $filter_projekt ? [\'rest\' => $rest, \'filter_projekt\' => $filter_projekt] : [\'rest\' => $rest]
      );';
rep($content, $old, $new, 'ReplaceLieferant lieferantennummer ORDER BY', $changes);

// ---- AddArtikel line 13676 ----
rep($content,
    '    $this->app->DB->Insert("INSERT INTO artikel (id) VALUES (\'\')");
    $id = $this->app->DB->GetInsertID();
    if ($felder[\'firma\'] <= 0)
      $felder[\'firma\'] = $this->app->DB->Select("SELECT MAX(f.id) FROM firma f INNER JOIN firmendaten fd ON f.id = fd.firma LIMIT 1");
    if ($felder[\'firma\'] <= 0)
      $felder[\'firma\'] = $this->app->DB->Select("SELECT MAX(firma) FROM firmendaten LIMIT 1");

    if ($felder[\'projekt\'] <= 0)
      $felder[\'projekt\'] = $this->app->DB->Select("SELECT standardprojekt FROM firma WHERE id=\'" . $felder[\'firma\'] . "\' LIMIT 1");',
    '    $this->app->DatabaseService->execute("INSERT INTO artikel (id) VALUES (\'\')");
    $id = $this->app->DB->GetInsertID();
    if ($felder[\'firma\'] <= 0)
      $felder[\'firma\'] = $this->app->DatabaseService->selectValue("SELECT MAX(f.id) FROM firma f INNER JOIN firmendaten fd ON f.id = fd.firma LIMIT 1", []);
    if ($felder[\'firma\'] <= 0)
      $felder[\'firma\'] = $this->app->DatabaseService->selectValue("SELECT MAX(firma) FROM firmendaten LIMIT 1", []);

    if ($felder[\'projekt\'] <= 0)
      $felder[\'projekt\'] = $this->app->DatabaseService->selectValue("SELECT standardprojekt FROM firma WHERE id = :id LIMIT 1", [\'id\' => $felder[\'firma\']]);',
    'AddArtikel INSERT + firma/projekt SELECTs', $changes
);

// ---- AddArtikel dateiname line 13705 ----
rep($content,
    '          $dateiname = $this->app->DB->Select("SELECT nummer FROM artikel WHERE id =\'$id\'");',
    '          $dateiname = $this->app->DatabaseService->selectValue("SELECT nummer FROM artikel WHERE id = :id", [\'id\' => $id]);',
    'AddArtikel dateiname SELECT', $changes
);

// ---- SetzteSperreAPIArtikelPreise line 13775 ----
rep($content,
    '    $this->app->DB->Update("UPDATE verkaufspreise SET apichange=0 WHERE artikel=$artikel");',
    '    $this->app->DatabaseService->execute("UPDATE verkaufspreise SET apichange = 0 WHERE artikel = :artikel", [\'artikel\' => $artikel]);',
    'SetzteSperreAPIArtikelPreise', $changes
);

// ---- EntferneSperreAPIArtikelPreise line 13782 ----
rep($content,
    '    $this->app->DB->Update("UPDATE verkaufspreise SET gueltig_bis=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE apichange!=1 AND artikel=$artikel");',
    '    $this->app->DatabaseService->execute("UPDATE verkaufspreise SET gueltig_bis = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE apichange != 1 AND artikel = :artikel", [\'artikel\' => $artikel]);',
    'EntferneSperreAPIArtikelPreise', $changes
);

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
