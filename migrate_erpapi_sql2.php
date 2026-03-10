<?php
/**
 * SQL injection migration script 2 for class.erpapi.php - fixes not found in pass 1
 */

$file = __DIR__ . '/www/lib/class.erpapi.php';
$content = file_get_contents($file);
$original = $content;
$changes = [];

// -----------------------------------------------------------------------
// Fix 2b: LagerzahlenCSV - use regex to handle whitespace variation
// -----------------------------------------------------------------------
$pattern = '/\$artikelarr = \$this->app->DB->SelectRow\("SELECT art\.nummer, art\.herstellernummer, art\.ean, ifnull\(lag\.menge,0\) as lager_menge_total,  lag\.lager_platz\s+FROM artikel art\s+LEFT JOIN \(\s+SELECT lpi\.artikel,lp\.kurzbezeichnung as lager_platz, trim\(ifnull\(sum\(lpi\.menge\),0\)\)\+0 as menge FROM lager_platz_inhalt lpi\s+INNER JOIN lager_platz lp ON lpi\.lager_platz = lp\.id AND lp\.geloescht = 0 " \. \(\$lager_platz \? " AND lp\.id = \'\$lager_platz\' " : ""\) \. "\s+" \. \(\$lager \? " AND lp\.lager = \'\$lager\' " : ""\) \. "\s+WHERE lpi\.artikel = \'\$artikel\' GROUP BY lp\.id\s+\) lag ON art\.id = lag\.artikel  WHERE art\.id = \'\$artikel\' LIMIT 1"\);/s';

$new2 = '      $lagerParams = [\'artikel\' => (int) $artikel];
      $lagerSql = "SELECT art.nummer, art.herstellernummer, art.ean, ifnull(lag.menge,0) as lager_menge_total, lag.lager_platz
      FROM artikel art
      LEFT JOIN (
        SELECT lpi.artikel, lp.kurzbezeichnung as lager_platz, trim(ifnull(sum(lpi.menge),0))+0 as menge FROM lager_platz_inhalt lpi
        INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND lp.geloescht = 0";
      if ($lager_platz) {
        $lagerSql .= " AND lp.id = :lager_platz";
        $lagerParams[\'lager_platz\'] = (int) $lager_platz;
      }
      if ($lager) {
        $lagerSql .= " AND lp.lager = :lager";
        $lagerParams[\'lager\'] = (int) $lager;
      }
      $lagerSql .= " WHERE lpi.artikel = :artikel GROUP BY lp.id
      ) lag ON art.id = lag.artikel WHERE art.id = :artikel2 LIMIT 1";
      $lagerParams[\'artikel2\'] = (int) $artikel;
      $artikelarr = $this->app->DatabaseService->selectRow($lagerSql, $lagerParams);';

$result = preg_replace($pattern, $new2, $content);
if ($result !== null && $result !== $content) {
    $content = $result;
    $changes[] = 'Fix 2b: LagerzahlenCSV lager/lager_platz/artikel (regex)';
} else {
    $changes[] = 'Fix 2b: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 4b: AddShopArtikelIfEmpty - SELECT with line break
// -----------------------------------------------------------------------
$pattern4 = '/\$old = \$this->app->DB->SelectArr\("SELECT min\(id\) as id, name,wert  FROM shopexport_artikel\s+WHERE shopid = \'\$shop\' AND artikel = \'\$artikel\' GROUP BY name,wert"\);/s';
$new4 = '    $old = $this->app->DatabaseService->select(
      "SELECT min(id) as id, name, wert FROM shopexport_artikel WHERE shopid = :shop AND artikel = :artikel GROUP BY name, wert",
      [\'shop\' => (int) $shop, \'artikel\' => (int) $artikel]
    );';
$result4 = preg_replace($pattern4, $new4, $content);
if ($result4 !== null && $result4 !== $content) {
    $content = $result4;
    $changes[] = 'Fix 4b: AddShopArtikelIfEmpty SELECT shopexport_artikel (regex)';
} else {
    $changes[] = 'Fix 4b: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 25b: UserEvent chat - $userId with concatenation style (line ~1657-1671)
// -----------------------------------------------------------------------
$pattern25 = '/\$ungelesenOeffentlich = \(int\) \$this->app->DB->Select\(\s+"SELECT COUNT\(c\.id\)\s+FROM chat AS c\s+LEFT JOIN chat_gelesen AS g ON c\.id = g\.message AND \(g\.user = \'" \. \$userId \. "\' OR g\.user = 0\)\s+WHERE c\.user_to=\'0\' AND c\.zeitstempel > \'" \. \$registrierDatum \. "\'\s+AND g\.id IS NULL"\s+\);\s+\$ungelesenPrivat = \(int\) \$this->app->DB->Select\(\s+"SELECT COUNT\(c\.id\)\s+FROM chat AS c\s+INNER JOIN `user` AS u ON c\.user_from = u\.id\s+LEFT JOIN chat_gelesen AS g ON c\.id = g\.message\s+WHERE u\.activ = 1 AND c\.user_to=\'" \. \$userId \. "\'\s+AND g\.id IS NULL"\s+\);/s';

$new25 = '      $ungelesenOeffentlich = (int) $this->app->DatabaseService->selectValue(
        "SELECT COUNT(c.id) FROM chat AS c LEFT JOIN chat_gelesen AS g ON c.id = g.message AND (g.user = :user OR g.user = 0) WHERE c.user_to = \'0\' AND c.zeitstempel > :datum AND g.id IS NULL",
        [\'user\' => (int) $userId, \'datum\' => (string) $registrierDatum]
      );
      $ungelesenPrivat = (int) $this->app->DatabaseService->selectValue(
        "SELECT COUNT(c.id) FROM chat AS c INNER JOIN `user` AS u ON c.user_from = u.id LEFT JOIN chat_gelesen AS g ON c.id = g.message WHERE u.activ = 1 AND c.user_to = :user AND g.id IS NULL",
        [\'user\' => (int) $userId]
      );';
$result25 = preg_replace($pattern25, $new25, $content);
if ($result25 !== null && $result25 !== $content) {
    $content = $result25;
    $changes[] = 'Fix 25b: UserEvent chat unread queries (regex)';
} else {
    $changes[] = 'Fix 25b: NOT FOUND';
}

// -----------------------------------------------------------------------
// Additional fix: AbgleichBenutzerVorlagen - REPLACE INTO userrights with $id_vorlage
// -----------------------------------------------------------------------
$pattern_replace = '/\$this->app->DB->Update\("REPLACE INTO userrights \(user, module,action,permission\) \(SELECT \'" \. \$user\[\$i\]\[\'id\'\] \. "\',module, action,permission\s+FROM uservorlagerights WHERE vorlage = \'" \. \$id_vorlage \. "\' " \. \(\$module != \'\' \? " AND module = \'\$module\' " : ""\) \. \(\$action != \'\' \? " AND action = \'\$action\'" : \'\'\) \. "\)   "\);/s';
$new_replace = '          $replaceSql = "REPLACE INTO userrights (user, module, action, permission) (SELECT :userid, module, action, permission FROM uservorlagerights WHERE vorlage = :vorlage";
          $replaceParams = [\'userid\' => (int) $user[$i][\'id\'], \'vorlage\' => (int) $id_vorlage];
          if ($module !== \'\') { $replaceSql .= " AND module = :module"; $replaceParams[\'module\'] = (string) $module; }
          if ($action !== \'\') { $replaceSql .= " AND action = :action"; $replaceParams[\'action\'] = (string) $action; }
          $replaceSql .= ")";
          $this->app->DatabaseService->execute($replaceSql, $replaceParams);';
$result_replace = preg_replace($pattern_replace, $new_replace, $content);
if ($result_replace !== null && $result_replace !== $content) {
    $content = $result_replace;
    $changes[] = 'Fix extra: AbgleichBenutzerVorlagen REPLACE INTO userrights';
} else {
    $changes[] = 'Fix extra: NOT FOUND';
}

// -----------------------------------------------------------------------
// Additional fix: AddUserGruppenNachricht INSERT boxnachrichten
// The values are already escaped via real_escape_string, migrate to params
// -----------------------------------------------------------------------
$old_insert = "    \$this->app->DB->Insert(\"INSERT INTO boxnachrichten (user, gruppe, bezeichnung, nachricht, prio, ablaufzeit, objekt, parameter, beep) VALUES (
    '\$user','\$gruppe','\$bezeichnung','\$nachricht','\$prio','\$ablaufzeit','\$objekt','\$parameter','\$beep'
    )\");";
$new_insert = '    $this->app->DatabaseService->execute(
      "INSERT INTO boxnachrichten (user, gruppe, bezeichnung, nachricht, prio, ablaufzeit, objekt, parameter, beep) VALUES (:user, :gruppe, :bezeichnung, :nachricht, :prio, :ablaufzeit, :objekt, :parameter, :beep)",
      [\'user\' => (int) $user, \'gruppe\' => (int) $gruppe, \'bezeichnung\' => (string) $bezeichnung, \'nachricht\' => (string) $nachricht, \'prio\' => (int) $prio, \'ablaufzeit\' => (int) $ablaufzeit, \'objekt\' => (string) $objekt, \'parameter\' => (int) $parameter, \'beep\' => (int) $beep]
    );';
if (strpos($content, $old_insert) !== false) {
    $content = str_replace($old_insert, $new_insert, $content);
    $changes[] = 'Fix extra2: AddUserGruppenNachricht INSERT boxnachrichten';
} else {
    // Try with \r\n
    $old_insert2 = "    \$this->app->DB->Insert(\"INSERT INTO boxnachrichten (user, gruppe, bezeichnung, nachricht, prio, ablaufzeit, objekt, parameter, beep) VALUES (\r\n    '\$user','\$gruppe','\$bezeichnung','\$nachricht','\$prio','\$ablaufzeit','\$objekt','\$parameter','\$beep'\r\n    )\");";
    if (strpos($content, $old_insert2) !== false) {
        $content = str_replace($old_insert2, $new_insert, $content);
        $changes[] = 'Fix extra2: AddUserGruppenNachricht INSERT boxnachrichten (CRLF)';
    } else {
        // Use regex
        $pattern_insert = '/\$this->app->DB->Insert\("INSERT INTO boxnachrichten \(user, gruppe, bezeichnung, nachricht, prio, ablaufzeit, objekt, parameter, beep\) VALUES \(\s*\'\$user\',\'\$gruppe\',\'\$bezeichnung\',\'\$nachricht\',\'\$prio\',\'\$ablaufzeit\',\'\$objekt\',\'\$parameter\',\'\$beep\'\s*\)"\);/s';
        $result_insert = preg_replace($pattern_insert, $new_insert, $content);
        if ($result_insert !== null && $result_insert !== $content) {
            $content = $result_insert;
            $changes[] = 'Fix extra2: AddUserGruppenNachricht INSERT boxnachrichten (regex)';
        } else {
            $changes[] = 'Fix extra2: NOT FOUND';
        }
    }
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
