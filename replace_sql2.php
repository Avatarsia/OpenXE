<?php
$file = 'www/lib/class.erpapi.php';
$content = file_get_contents($file);
$count = 0;

function r($content, $old, $new, $label) {
    global $count;
    if (strpos($content, $old) !== false) {
        $content = str_replace($old, $new, $content);
        echo "$label: replaced\n";
        $count++;
    } else {
        echo "$label: NOT FOUND\n";
        // Show context
        $words = explode(' ', trim($old));
        $search = implode(' ', array_slice($words, 0, 5));
        $pos = strpos($content, $search);
        if ($pos !== false) {
            echo "  Context found at pos $pos:\n";
            echo "  " . bin2hex(substr($content, $pos, 200)) . "\n";
        } else {
            echo "  No context found for: $search\n";
        }
    }
    return $content;
}

// Read actual bytes from file for the AddChargeLagerOhneBewegung block
// The INSERT has a trailing space before CRLF
$old = "\$this->app->DB->Insert(\"INSERT INTO lager_charge (artikel,menge,lager_platz,datum,internebemerkung,charge,zwischenlagerid) \r\n      VALUES ('{$artikel}','{$menge}','{$lagerplatz}','{$datum}','{$internebemerkung}','{$charge}','{$zid}')\");";

// Let me just do targeted pattern-based replacements using regex
// ============================================================
// AddChargeLagerOhneBewegung
// ============================================================
$pattern = '/\$this->app->DB->Insert\("INSERT INTO lager_charge \(artikel,menge,lager_platz,datum,internebemerkung,charge,zwischenlagerid\) \r?\n\s+VALUES \(\'\$artikel\',\'\$menge\',\'\$lagerplatz\',\'\$datum\',\'\$internebemerkung\',\'\$charge\',\'\$zid\'\)"\);\r?\n\s+\$this->app->DB->Insert\("INSERT INTO chargen_log \(artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid, is_interim\) \r?\n\s+VALUES \(\'\$artikel\',\'\$lagerplatz\',\'1\',\'\$charge\',NOW\(\)," \. \$this->app->User->GetAdresse\(\) \. ",\'\$menge\',\'\$internebemerkung\',\'\$doctype\',\'\$doctypeid\'," \. \(int\) \$isInterim \. "\)"\);\r?\n\s+\$id = \$this->app->DB->GetInsertID\(\);\r?\n\s+\$bestand = \$this->app->DB->Select\("SELECT ifnull\(sum\(menge\),0\) FROM lager_charge WHERE artikel = \'\$artikel\' AND lager_platz = \'\$lagerplatz\' AND charge = \'\$charge\'"\);\r?\n\s+\$this->app->DB->Update\("UPDATE chargen_log SET bestand = \'\$bestand\' WHERE id = \'\$id\' LIMIT 1"\);/';

$replacement = '    $this->app->DatabaseService->execute(
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

$result = preg_replace($pattern, $replacement, $content, 1, $replaced);
if ($replaced > 0) {
    $content = $result;
    echo "AddChargeLagerOhneBewegung: replaced\n";
    $count++;
} else {
    echo "AddChargeLagerOhneBewegung: NOT FOUND (regex)\n";
}

// ============================================================
// AddMindesthaltbarkeitsdatumLagerOhneBewegung
// ============================================================
$pattern = '/\$this->app->DB->Insert\("INSERT INTO lager_mindesthaltbarkeitsdatum \(artikel,menge,lager_platz,datum,internebemerkung,charge,zwischenlagerid,mhddatum\) VALUES \(\'\$artikel\',\'\$menge\',\'\$lagerplatz\',NOW\(\),\'\$internebemerkung\',\'\$charge\',\'\$zid\',\'\$mhd\'\)"\);\r?\n\s+\$bestand = \(float\) \$this->app->DB->Select\("SELECT ifnull\(sum\(menge\),0\) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = \'\$artikel\' AND lager_platz = \'\$lagerplatz\' AND mhddatum = \'\$mhd\' AND ifnull\(charge,\'\'\) = \'\$charge\' "\);\r?\n\s+\$this->app->DB->Insert\("INSERT INTO mhd_log \(artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim\) \r?\n\s+VALUES \(\'\$artikel\',\'\$lagerplatz\',\'1\',\'\$mhd\',NOW\(\)," \. \$this->app->User->GetAdresse\(\) \. ",\'\$menge\',\'\$internebemerkung\',\'\$doctype\',\'\$doctypeid\',\'\$bestand\',\'\$adresse\'," \. \(int\) \$isInterim \. "\)"\);\r?\n\s+\$insid = \$this->app->DB->GetInsertID\(\);\r?\n\s+if \(\$charge != \'\'\) \{\r?\n\s+\$this->app->DB->Update\("UPDATE mhd_log SET charge = \'\$charge\' WHERE id = \'\$insid\' LIMIT 1"\);\r?\n\s+\}/';

$replacement = '    $this->app->DatabaseService->execute(
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

$result = preg_replace($pattern, $replacement, $content, 1, $replaced);
if ($replaced > 0) {
    $content = $result;
    echo "AddMindesthaltbarkeitsdatumLagerOhneBewegung: replaced\n";
    $count++;
} else {
    echo "AddMindesthaltbarkeitsdatumLagerOhneBewegung: NOT FOUND (regex)\n";
}

// ============================================================
// Chargenlog — the function body (not function signature)
// ============================================================
$pattern = '/if \(!\$adresse && \$doctype != \'\' && \$doctypeid > 0\) \{\r?\n\s+\$adresse = \$this->app->DB->Select\("SELECT adresse FROM \$doctype WHERE id = \'\$doctypeid\' LIMIT 1"\);\r?\n\s+\}\r?\n\s+\$internebemerkung = \$this->app->DB->real_escape_string\(\$internebemerkung\);\r?\n\s+\$bestand = \$this->app->DB->Select\("SELECT ifnull\(sum\(menge\),0\) FROM lager_charge WHERE artikel = \'\$artikel\' AND lager_platz = \'\$lager_platz\' AND charge = \'\$charge\'"\);\r?\n\s+\$this->RunHook\(\'chargenlog_bestand\', 4, \$artikel, \$lager_platz, \$charge, \$bestand\);\r?\n\s+if \(\$chargen_log_id\) \{\r?\n\s+\$chargen_log_id = \$this->app->DB->Select\("SELECT id FROM chargen_log WHERE id=\'\$chargen_log_id\' AND eingang = \'\$eingang\' AND artikel = \'\$artikel\' AND charge = \'\$charge\' AND doctype = \'\$doctype\' AND doctypeid = \'\$doctypeid\' AND adresse = \'\$adresse\' LIMIT 1"\);\r?\n\s+\}\r?\n\s+if \(\$chargen_log_id\) \{\r?\n\s+\$this->app->DB->Update\("UPDATE chargen_log SET menge = menge \+ \$menge, bestand = \'\$bestand\' WHERE id = \'\$chargen_log_id\' LIMIT 1"\);\r?\n\s+return \$chargen_log_id;\r?\n\s+\}\r?\n\s+\$this->app->DB->Insert\("INSERT INTO chargen_log \(artikel,lager_platz,eingang,bezeichnung,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim\) \r?\n\s+VALUES \(\'\$artikel\',\'\$lager_platz\',\'\$eingang\',\'" \. \$charge \. "\',NOW\(\)," \. \(int\) \$this->app->User->GetAdresse\(\) \. ",\'" \. \$menge \. "\',\'\$internebemerkung\',\'\$doctype\',\'\$doctypeid\',\'\$bestand\',\'\$adresse\'," \. \(int\) \$isInterim \. "\)"\);\r?\n\s+return \$this->app->DB->GetInsertID\(\);/';

$replacement = 'if (!$adresse && $doctype != \'\' && $doctypeid > 0) {
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

$result = preg_replace($pattern, $replacement, $content, 1, $replaced);
if ($replaced > 0) {
    $content = $result;
    echo "Chargenlog: replaced\n";
    $count++;
} else {
    echo "Chargenlog: NOT FOUND (regex)\n";
    // Debug: find the function
    $pos = strpos($content, 'function Chargenlog(');
    if ($pos !== false) {
        $chunk = substr($content, $pos, 1200);
        echo bin2hex($chunk) . "\n";
    }
}

// ============================================================
// MHDLog
// ============================================================
$pattern = '/function MHDLog\(\$artikel, \$lager_platz, \$eingang, \$mhd, \$menge, \$internebemerkung = \'\', \$doctype = \'\', \$doctypeid = 0, \$charge = \'\', \$adresse = 0, \$isInterim = 0\)\r?\n\s+\{\r?\n\s+if \(\$artikel <= 0\) \{\r?\n\s+return;\r?\n\s+\}\r?\n\s+if \(!\$adresse && \$doctype != \'\' && \$doctypeid > 0\) \{\r?\n\s+\$adresse = \$this->app->DB->Select\("SELECT adresse FROM \$doctype WHERE id = \'\$doctypeid\' LIMIT 1"\);\r?\n\s+\}\r?\n\s+\$internebemerkung = \$this->app->DB->real_escape_string\(\$internebemerkung\);\r?\n\s+\$bestand = \$this->app->DB->Select\("SELECT ifnull\(sum\(menge\),0\) FROM lager_mindesthaltbarkeitsdatum WHERE artikel = \'\$artikel\' AND lager_platz = \'\$lager_platz\' AND mhddatum = \'\$mhd\' AND ifnull\(charge,\'\'\) = \'\$charge\'"\);\r?\n\s+\$this->RunHook\(\'mhdlog_bestand\', 4, \$artikel, \$lager_platz, \$mhd, \$bestand\);\r?\n\s+\$this->app->DB->Insert\("INSERT INTO mhd_log \(artikel,lager_platz,eingang,mhddatum,zeit,adresse_mitarbeiter,menge,internebemerkung,doctype,doctypeid,bestand,adresse,is_interim\) \r?\n\s+VALUES \(\'\$artikel\',\'\$lager_platz\',\'\$eingang\',\'" \. \$mhd \. "\',NOW\(\)," \. \(int\) \$this->app->User->GetAdresse\(\) \. ",\'" \. \$menge \. "\',\'\$internebemerkung\',\'\$doctype\',\'\$doctypeid\',\'\$bestand\',\'\$adresse\'," \. \(int\) \$isInterim \. "\)"\);\r?\n\s+\$insid = \$this->app->DB->GetInsertID\(\);\r?\n\s+if \(\$charge != \'\'\) \{\r?\n\s+\$this->app->DB->Update\("UPDATE mhd_log SET charge = \'\$charge\' WHERE id = \'\$insid\' LIMIT 1"\);\r?\n\s+\}\r?\n\s+\}/';

$replacement = 'function MHDLog($artikel, $lager_platz, $eingang, $mhd, $menge, $internebemerkung = \'\', $doctype = \'\', $doctypeid = 0, $charge = \'\', $adresse = 0, $isInterim = 0)
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

$result = preg_replace($pattern, $replacement, $content, 1, $replaced);
if ($replaced > 0) {
    $content = $result;
    echo "MHDLog: replaced\n";
    $count++;
} else {
    echo "MHDLog: NOT FOUND (regex)\n";
}

file_put_contents($file, $content);
echo "\nTotal replacements: $count\n";
