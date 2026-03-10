<?php
/**
 * SQL injection migration script 3 for class.erpapi.php - lines 4000-8000
 */

$file = __DIR__ . '/www/lib/class.erpapi.php';
$content = file_get_contents($file);
$original = $content;
$changes = [];

// -----------------------------------------------------------------------
// Fix A: ParseUserVars - auftrag DATUM SELECT (line ~4210)
// -----------------------------------------------------------------------
$old = '      $text = str_replace(\'{DATUM}\', $this->app->DB->Select("SELECT DATE_FORMAT(datum,\'%d.%m.%Y\') FROM auftrag WHERE id=\'$id\' LIMIT 1"), $text);';
$new = '      $text = str_replace(\'{DATUM}\', $this->app->DatabaseService->selectValue(
        "SELECT DATE_FORMAT(datum, \'%d.%m.%Y\') FROM auftrag WHERE id = :id LIMIT 1",
        [\'id\' => (int) $id]
      ), $text);';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix A: ParseUserVars auftrag DATUM SELECT';
} else {
    $changes[] = 'Fix A: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix B: ParseUserVars - zahlungsdatum and skontodatum with $type and $id (line ~4290-4291)
// -----------------------------------------------------------------------
$old = '      $zahlungdatum = $this->app->DB->Select("SELECT DATE_FORMAT(DATE_ADD(datum, INTERVAL $zahlungszieltage DAY),\'%d.%m.%Y\') FROM $type WHERE id=\'$id\' LIMIT 1");
      $zahlungszielskontodatum = $this->app->DB->Select("SELECT DATE_FORMAT(DATE_ADD(datum, INTERVAL $zahlungszieltageskonto DAY),\'%d.%m.%Y\') FROM $type WHERE id=\'$id\' LIMIT 1");';
$new = '      $zahlungdatum = $this->app->DatabaseService->selectValue(
        sprintf("SELECT DATE_FORMAT(DATE_ADD(datum, INTERVAL %d DAY), \'%%d.%%m.%%Y\') FROM `%s` WHERE id = :id LIMIT 1", (int) $zahlungszieltage, $this->app->DatabaseService->validateIdentifier($type)),
        [\'id\' => (int) $id]
      );
      $zahlungszielskontodatum = $this->app->DatabaseService->selectValue(
        sprintf("SELECT DATE_FORMAT(DATE_ADD(datum, INTERVAL %d DAY), \'%%d.%%m.%%Y\') FROM `%s` WHERE id = :id LIMIT 1", (int) $zahlungszieltageskonto, $this->app->DatabaseService->validateIdentifier($type)),
        [\'id\' => (int) $id]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix B: ParseUserVars zahlungsdatum/skontodatum SELECT';
} else {
    $changes[] = 'Fix B: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix C: ParseUserVars - lieferschein rechnungsid SELECT (line ~4305)
// -----------------------------------------------------------------------
$old = '      $rechnungsid = $this->app->DB->Select("SELECT id FROM rechnung WHERE lieferschein=\'$id\' LIMIT 1");
      if ($rechnungsid > 0) {
        $resultrechnung = $this->app->DB->SelectArr("SELECT * FROM rechnung WHERE id=\'$rechnungsid\' LIMIT 1");';
$new = '      $rechnungsid = $this->app->DatabaseService->selectValue(
        "SELECT id FROM rechnung WHERE lieferschein = :id LIMIT 1",
        [\'id\' => (int) $id]
      );
      if ($rechnungsid > 0) {
        $resultrechnung = $this->app->DatabaseService->select(
          "SELECT * FROM rechnung WHERE id = :id LIMIT 1",
          [\'id\' => (int) $rechnungsid]
        );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix C: ParseUserVars lieferschein rechnungsid/rechnung SELECTs';
} else {
    $changes[] = 'Fix C: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix D: GetZahlungsweiseText fallback zahlungsweisen SELECT (line ~4001)
// -----------------------------------------------------------------------
$old = '          $zahlungsweisetext = $this->app->DB->Select("SELECT freitext FROM zahlungsweisen WHERE type=\'" . $zahlungsweise . "\' AND aktiv=\'1\' AND type!=\'\' LIMIT 1");';
// Note: same pattern appears multiple times - use replace_all approach via count
$count = substr_count($content, $old);
if ($count > 0) {
    $new = '          $zahlungsweisetext = $this->app->DatabaseService->selectValue(
            "SELECT freitext FROM zahlungsweisen WHERE type = :type AND aktiv = \'1\' AND type != \'\' LIMIT 1",
            [\'type\' => (string) $zahlungsweise]
          );';
    $content = str_replace($old, $new, $content);
    $changes[] = "Fix D: GetZahlungsweiseText freitext SELECT ($count occurrences)";
} else {
    $changes[] = 'Fix D: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix E: CheckBearbeiter - bearbeiter UPDATE with $module and $id (line ~5181)
// -----------------------------------------------------------------------
$old = '      $this->app->DB->Update("UPDATE $module SET bearbeiter=\'\' WHERE id=\'$id\' AND bearbeiter REGEXP \'^[0-9]+$\' LIMIT 1");';
$new = '      $this->app->DatabaseService->execute(
        sprintf("UPDATE `%s` SET bearbeiter = \'\' WHERE id = :id AND bearbeiter REGEXP \'^[0-9]+$\' LIMIT 1", $this->app->DatabaseService->validateIdentifier($module)),
        [\'id\' => (int) $id]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix E: CheckBearbeiter UPDATE bearbeiter empty';
} else {
    $changes[] = 'Fix E: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix F: CheckBearbeiter - bearbeiter SELECT fallback (line ~5195)
// -----------------------------------------------------------------------
$old = '      $bearbeiter = $this->app->DB->Select("SELECT bearbeiter FROM $module WHERE id=\'$id\' LIMIT 1");';
$new = '      $bearbeiter = $this->app->DatabaseService->selectValue(
        sprintf("SELECT bearbeiter FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($module)),
        [\'id\' => (int) $id]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix F: CheckBearbeiter bearbeiter SELECT fallback';
} else {
    $changes[] = 'Fix F: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix G: CheckBearbeiter - adresse SELECT fallback (line ~5200)
// -----------------------------------------------------------------------
$old = '      $adresse = !empty($docArr) ? $docArr[\'adresse\'] : $this->app->DB->Select(
        "SELECT adresse FROM $module WHERE id=\'$id\' LIMIT 1"
      );';
$new = '      $adresse = !empty($docArr) ? $docArr[\'adresse\'] : $this->app->DatabaseService->selectValue(
        sprintf("SELECT adresse FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($module)),
        [\'id\' => (int) $id]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix G: CheckBearbeiter adresse SELECT fallback';
} else {
    $changes[] = 'Fix G: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix H: CheckBearbeiter - innendienst SELECT (line ~5202)
// -----------------------------------------------------------------------
$old = '      $innendienst = empty($adresse) ? null : $this->app->DB->Select("SELECT innendienst FROM adresse WHERE id=\'$adresse\' LIMIT 1");
      $innendienst_name = empty($innendienst) ? null : $this->app->DB->Select("SELECT name FROM adresse WHERE id=\'$innendienst\' LIMIT 1");';
$new = '      $innendienst = empty($adresse) ? null : $this->app->DatabaseService->selectValue(
        "SELECT innendienst FROM adresse WHERE id = :id LIMIT 1",
        [\'id\' => (int) $adresse]
      );
      $innendienst_name = empty($innendienst) ? null : $this->app->DatabaseService->selectValue(
        "SELECT name FROM adresse WHERE id = :id LIMIT 1",
        [\'id\' => (int) $innendienst]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix H: CheckBearbeiter innendienst/name SELECTs';
} else {
    $changes[] = 'Fix H: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix I: CheckBearbeiter - bearbeiter name SELECT (line ~5233)
// -----------------------------------------------------------------------
$old = '      $bearbeiter = $this->app->DB->Select("SELECT name FROM adresse WHERE id=\'" . $bearbeiter . "\' LIMIT 1");';
$new = '      $bearbeiter = $this->app->DatabaseService->selectValue(
        "SELECT name FROM adresse WHERE id = :id LIMIT 1",
        [\'id\' => (int) $bearbeiter]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix I: CheckBearbeiter bearbeiter name SELECT';
} else {
    $changes[] = 'Fix I: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix J: VertriebAendern - name SELECT and UPDATE with $sid/$table/$id (line ~5290-5291)
// -----------------------------------------------------------------------
$old = '        $name = $this->app->DB->Select("SELECT name FROM adresse WHERE id=\'$sid\' LIMIT 1");
        $this->app->DB->Update("UPDATE $table SET vertriebid = $sid, vertrieb=\'$name\' WHERE id=\'$id\' LIMIT 1");';
$new = '        $name = $this->app->DatabaseService->selectValue(
          "SELECT name FROM adresse WHERE id = :id LIMIT 1",
          [\'id\' => (int) $sid]
        );
        $this->app->DatabaseService->execute(
          sprintf("UPDATE `%s` SET vertriebid = :sid, vertrieb = :name WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($table)),
          [\'sid\' => (int) $sid, \'name\' => (string) $name, \'id\' => (int) $id]
        );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix J: VertriebAendern name SELECT + UPDATE';
} else {
    $changes[] = 'Fix J: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix K: VertriebAendern - schreibschutz SELECT (line ~5304)
// -----------------------------------------------------------------------
$old = '      $schreibschutz = $this->app->DB->Select("SELECT schreibschutz FROM $table WHERE id=\'$id\' LIMIT 1");
      if ($schreibschutz != "1") {
        $this->app->Tpl->Set(\'VERTRIEBBUTTON\'';
$new = '      $schreibschutz = $this->app->DatabaseService->selectValue(
        sprintf("SELECT schreibschutz FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($table)),
        [\'id\' => (int) $id]
      );
      if ($schreibschutz != "1") {
        $this->app->Tpl->Set(\'VERTRIEBBUTTON\'';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix K: VertriebAendern schreibschutz SELECT';
} else {
    $changes[] = 'Fix K: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix L: InnendienstAendern - name SELECT and UPDATE (line ~5318-5319)
// -----------------------------------------------------------------------
$old = '        $name = $this->app->DB->Select("SELECT name FROM adresse WHERE id=\'$sid\' LIMIT 1");
        $this->app->DB->Update("UPDATE $table SET bearbeiterid = $sid, bearbeiter=\'$name\' WHERE id=\'$id\' LIMIT 1");';
$new = '        $name = $this->app->DatabaseService->selectValue(
          "SELECT name FROM adresse WHERE id = :id LIMIT 1",
          [\'id\' => (int) $sid]
        );
        $this->app->DatabaseService->execute(
          sprintf("UPDATE `%s` SET bearbeiterid = :sid, bearbeiter = :name WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($table)),
          [\'sid\' => (int) $sid, \'name\' => (string) $name, \'id\' => (int) $id]
        );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix L: InnendienstAendern name SELECT + UPDATE';
} else {
    $changes[] = 'Fix L: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix M: InnendienstAendern - schreibschutz SELECT (line ~5332)
// -----------------------------------------------------------------------
$old = '      $schreibschutz = $this->app->DB->Select("SELECT schreibschutz FROM $table WHERE id=\'$id\' LIMIT 1");
      if ($schreibschutz != "1") {
        $this->app->Tpl->Set(\'INNENDIENSTBUTTON\'';
$new = '      $schreibschutz = $this->app->DatabaseService->selectValue(
        sprintf("SELECT schreibschutz FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($table)),
        [\'id\' => (int) $id]
      );
      if ($schreibschutz != "1") {
        $this->app->Tpl->Set(\'INNENDIENSTBUTTON\'';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix M: InnendienstAendern schreibschutz SELECT';
} else {
    $changes[] = 'Fix M: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix N: CheckVertrieb - vertrieb UPDATE empty (line ~5356)
// -----------------------------------------------------------------------
$old = '      $this->app->DB->Update("UPDATE $module SET vertrieb=\'\' WHERE id=\'$id\' AND vertrieb REGEXP \'^[0-9]+$\' LIMIT 1");';
$new = '      $this->app->DatabaseService->execute(
        sprintf("UPDATE `%s` SET vertrieb = \'\' WHERE id = :id AND vertrieb REGEXP \'^[0-9]+$\' LIMIT 1", $this->app->DatabaseService->validateIdentifier($module)),
        [\'id\' => (int) $id]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix N: CheckVertrieb UPDATE vertrieb empty';
} else {
    $changes[] = 'Fix N: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix O: CheckVertrieb - vertriebid SELECT fallback (line ~5375)
// -----------------------------------------------------------------------
$old = '    $vertrieb = !empty($docArr) ? $docArr[\'vertriebid\'] : $this->app->DB->Select("SELECT vertriebid FROM $module WHERE id=\'$id\' LIMIT 1");';
$new = '    $vertrieb = !empty($docArr) ? $docArr[\'vertriebid\'] : $this->app->DatabaseService->selectValue(
      sprintf("SELECT vertriebid FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($module)),
      [\'id\' => (int) $id]
    );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix O: CheckVertrieb vertriebid SELECT fallback';
} else {
    $changes[] = 'Fix O: NOT FOUND';
}

// -----------------------------------------------------------------------
// Write result
// -----------------------------------------------------------------------
if ($content === $original) {
    echo "ERROR: No changes were made!\n";
} else {
    file_put_contents($file, $content);
    echo "SUCCESS: " . count($changes) . " fixes applied\n";
}

foreach ($changes as $c) {
    echo "  - $c\n";
}
