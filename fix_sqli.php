<?php
$filepath = 'C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php';
$content = file_get_contents($filepath);
$total = 0;

function rep(&$content, $old, $new, $label) {
    global $total;
    $result = str_replace($old, $new, $content, $count);
    if ($count > 0) {
        $content = $result;
        $total += $count;
        echo "OK [$count] $label\n";
    } else {
        echo "MISS $label\n";
    }
}

// ============================================================
// GenerateHook fixes (lines ~10395-10456 in Read tool numbering)
// ============================================================

rep($content,
    '$checkarr = $this->app->DB->SelectRow(
      "SELECT h.id, h.parametercount, h.aktiv FROM `hook` AS `h` WHERE h.name = \'$name\' LIMIT 1"
    );',
    '$checkarr = $this->app->DatabaseService->selectRow(
      "SELECT h.id, h.parametercount, h.aktiv FROM `hook` AS `h` WHERE h.name = :name LIMIT 1",
      [\'name\' => $name]
    );',
    'GenerateHook SelectRow'
);

rep($content,
    '        $this->app->DB->Update(
          "UPDATE `hook`
          SET `parametercount` = \'$parametercount\'
          WHERE `id` = \'" . $checkarr[\'id\'] . "\' LIMIT 1"
        );',
    '        $this->app->DatabaseService->execute(
          "UPDATE `hook` SET `parametercount` = :parametercount WHERE `id` = :id LIMIT 1",
          [\'parametercount\' => $parametercount, \'id\' => (int) $checkarr[\'id\']]
        );',
    'GenerateHook Update new-branch parametercount'
);

rep($content,
    '        $this->app->DB->Update(
          "UPDATE `hook` SET `aktiv` = \'$aktiv\' WHERE `id` = \'$check\'"
        );',
    '        $this->app->DatabaseService->execute(
          "UPDATE `hook` SET `aktiv` = :aktiv WHERE `id` = :id",
          [\'aktiv\' => $aktiv, \'id\' => $check]
        );',
    'GenerateHook Update aktiv'
);

rep($content,
    '        $this->app->DB->Update(
          "UPDATE `hook` SET `parametercount` = \'$parametercount\' WHERE `id` = \'$check\'"
        );',
    '        $this->app->DatabaseService->execute(
          "UPDATE `hook` SET `parametercount` = :parametercount WHERE `id` = :id",
          [\'parametercount\' => (int) $parametercount, \'id\' => $check]
        );',
    'GenerateHook Update parametercount checkarr-branch'
);

rep($content,
    '    $this->app->DB->Insert(
      "INSERT INTO `hook` (`name`, `aktiv`, `parametercount`) VALUES (\'$name\',\'$aktiv\',\'$parametercount\')"
    );',
    '    $this->app->DatabaseService->execute(
      "INSERT INTO `hook` (`name`, `aktiv`, `parametercount`) VALUES (:name, :aktiv, :parametercount)",
      [\'name\' => $name, \'aktiv\' => $aktiv, \'parametercount\' => (int) $parametercount]
    );',
    'GenerateHook Insert'
);

rep($content,
    '    $max = 1 + (int) $this->app->DB->Select("SELECT MAX(h.id) FROM `hook` AS `h`");',
    '    $max = 1 + (int) $this->app->DatabaseService->selectValue("SELECT MAX(h.id) FROM `hook` AS `h`");',
    'GenerateHook SELECT MAX'
);

rep($content,
    '    $this->app->DB->Update(
      "UPDATE `hook` SET `id` = \'$max\' WHERE `id` = 0 AND `name` = \'$name\' AND `aktiv` = \'$aktiv\' AND `parametercount` = \'$parametercount\' LIMIT 1"
    );',
    '    $this->app->DatabaseService->execute(
      "UPDATE `hook` SET `id` = :max WHERE `id` = 0 AND `name` = :name AND `aktiv` = :aktiv AND `parametercount` = :parametercount LIMIT 1",
      [\'max\' => $max, \'name\' => $name, \'aktiv\' => $aktiv, \'parametercount\' => (int) $parametercount]
    );',
    'GenerateHook Update id=max'
);

// ============================================================
// RegisterHook fixes (~10514-10571)
// ============================================================

rep($content,
    '      $hook = $this->app->DB->Select(
        sprintf("SELECT h.id FROM `hook` AS `h` WHERE h.name = \'%s\' LIMIT 1", $name)
      );',
    '      $hook = $this->app->DatabaseService->selectValue(
        "SELECT h.id FROM `hook` AS `h` WHERE h.name = :name LIMIT 1",
        [\'name\' => $name]
      );',
    'RegisterHook SELECT hook by name'
);

rep($content,
    '    $check = $this->app->DB->Select(
      sprintf(
        "SELECT hr.id FROM `hook_register` AS `hr` WHERE hr.hook = %d AND hr.module = \'%s\' AND hr.module_parameter = %d LIMIT 1",
        (int) $hook,
        $callmodule,
        (int) $moduleParameter
      )
    );',
    '    $check = $this->app->DatabaseService->selectValue(
      "SELECT hr.id FROM `hook_register` AS `hr` WHERE hr.hook = :hook AND hr.module = :module AND hr.module_parameter = :module_parameter LIMIT 1",
      [\'hook\' => (int) $hook, \'module\' => $callmodule, \'module_parameter\' => (int) $moduleParameter]
    );',
    'RegisterHook SELECT check hook_register'
);

rep($content,
    '      $this->app->DB->Update(
        sprintf(
          "UPDATE `hook_register` SET `aktiv` = %d, `function` = \'%s\' WHERE `id` = %d LIMIT 1",
          $aktiv,
          $funktion,
          $check
        )
      );',
    '      $this->app->DatabaseService->execute(
        "UPDATE `hook_register` SET `aktiv` = :aktiv, `function` = :function WHERE `id` = :id LIMIT 1",
        [\'aktiv\' => (int) $aktiv, \'function\' => $funktion, \'id\' => (int) $check]
      );',
    'RegisterHook Update hook_register aktiv+function'
);

rep($content,
    '        $this->app->DB->Update(
          sprintf(
            \'UPDATE `hook_register` SET `position` = %d WHERE `id` = %d LIMIT 1\',
            (int) $position,
            $check
          )
        );',
    '        $this->app->DatabaseService->execute(
          "UPDATE `hook_register` SET `position` = :position WHERE `id` = :id LIMIT 1",
          [\'position\' => (int) $position, \'id\' => (int) $check]
        );',
    'RegisterHook Update hook_register position'
);

rep($content,
    '      $position = 1 + (int) $this->app->DB->Select(
        sprintf(\'SELECT MAX(hr.position) FROM `hook_register` AS `hr` WHERE hr.hook = %d\', $hook)
      );',
    '      $position = 1 + (int) $this->app->DatabaseService->selectValue(
        "SELECT MAX(hr.position) FROM `hook_register` AS `hr` WHERE hr.hook = :hook",
        [\'hook\' => (int) $hook]
      );',
    'RegisterHook SELECT MAX position'
);

rep($content,
    '    $this->app->DB->Insert(
      sprintf("INSERT INTO `hook_register` (`hook`, `module`, `function`, `aktiv`, `position`, `module_parameter`)
        VALUES (%d,\'%s\',\'%s\',%d,%d,%d)",
        $hook,
        $callmodule,
        $funktion,
        (int) $aktiv,
        (int) $position,
        (int) $moduleParameter
      )
    );',
    '    $this->app->DatabaseService->execute(
      "INSERT INTO `hook_register` (`hook`, `module`, `function`, `aktiv`, `position`, `module_parameter`) VALUES (:hook, :module, :function, :aktiv, :position, :module_parameter)",
      [\'hook\' => (int) $hook, \'module\' => $callmodule, \'function\' => $funktion, \'aktiv\' => (int) $aktiv, \'position\' => (int) $position, \'module_parameter\' => (int) $moduleParameter]
    );',
    'RegisterHook Insert hook_register'
);

// ============================================================
// RemoveHook (~10697)
// ============================================================

rep($content,
    '    $this->app->DB->Delete(
      sprintf(
        "DELETE `h`, `hr`
        FROM `hook` AS `h`
        LEFT JOIN `hook_register` AS `hr` ON h.id = hr.hook
        WHERE h.name = \'%s\'",
        $this->app->DB->real_escape_string($name)
      )
    );',
    '    $this->app->DatabaseService->execute(
      "DELETE `h`, `hr` FROM `hook` AS `h` LEFT JOIN `hook_register` AS `hr` ON h.id = hr.hook WHERE h.name = :name",
      [\'name\' => $name]
    );',
    'RemoveHook Delete'
);

// ============================================================
// removeHookRegister (~10718)
// ============================================================

rep($content,
    '    $hook = $this->app->DB->Select(
      sprintf(
        "SELECT h.id FROM `hook` AS `h` WHERE h.name = \'%s\' OR h.alias = \'%s\' LIMIT 1",
        $this->app->DB->real_escape_string($hookName),
        $this->app->DB->real_escape_string($hookName)
      )
    );',
    '    $hook = $this->app->DatabaseService->selectValue(
      "SELECT h.id FROM `hook` AS `h` WHERE h.name = :name OR h.alias = :alias LIMIT 1",
      [\'name\' => $hookName, \'alias\' => $hookName]
    );',
    'removeHookRegister SELECT hook'
);

rep($content,
    '    $this->app->DB->Delete(
      sprintf(
        "DELETE FROM `hook_register` WHERE `hook` = %d AND `module` = \'%s\' AND `function` = \'%s\'",
        $hook,
        $this->app->DB->real_escape_string($module),
        $this->app->DB->real_escape_string($callback)
      )
    );',
    '    $this->app->DatabaseService->execute(
      "DELETE FROM `hook_register` WHERE `hook` = :hook AND `module` = :module AND `function` = :function",
      [\'hook\' => (int) $hook, \'module\' => $module, \'function\' => $callback]
    );',
    'removeHookRegister Delete'
);

// ============================================================
// getHookByAlias (~10748)
// ============================================================

rep($content,
    '    return $this->app->DB->SelectRow(
      sprintf(
        "SELECT h.* FROM `hook` AS `h` WHERE h.alias = \'%s\' AND h.name <> \'\'",
        $this->app->DB->real_escape_string($alias)
      )
    );',
    '    return $this->app->DatabaseService->selectRow(
      "SELECT h.* FROM `hook` AS `h` WHERE h.alias = :alias AND h.name <> \'\'",
      [\'alias\' => $alias]
    );',
    'getHookByAlias SelectRow'
);

// ============================================================
// RunHook - fallback SelectRow (~10877)
// ============================================================

rep($content,
    '      $check = $this->app->DB->SelectRow("SELECT id, aktiv, parametercount FROM hook WHERE name = \'$name\' LIMIT 1");',
    '      $check = $this->app->DatabaseService->selectRow("SELECT id, aktiv, parametercount FROM hook WHERE name = :name LIMIT 1", [\'name\' => $name]);',
    'RunHook fallback SelectRow'
);

// ============================================================
// RunMenuHook - fallback SelectRow (~11116) and SelectArr (~11122)
// ============================================================

rep($content,
    '      $check = $this->app->DB->SelectRow("SELECT hm.id, hm.aktiv FROM `hook_menu` AS `hm` WHERE hm.module = \'$module\' LIMIT 1");',
    '      $check = $this->app->DatabaseService->selectRow("SELECT hm.id, hm.aktiv FROM `hook_menu` AS `hm` WHERE hm.module = :module LIMIT 1", [\'module\' => $module]);',
    'RunMenuHook fallback SelectRow'
);

rep($content,
    '    $hooks = $this->app->DB->SelectArr(
      "SELECT hmr.module, hmr.funktion
      FROM `hook_menu_register` AS `hmr`
      WHERE hmr.hook_menu = \'$hook_menu\' AND hmr.aktiv = 1 AND hmr.module <> \'\' AND hmr.funktion <> \'\'
      GROUP BY hmr.module, hmr.funktion
      ORDER BY hmr.position"
    );',
    '    $hooks = $this->app->DatabaseService->select(
      "SELECT hmr.module, hmr.funktion FROM `hook_menu_register` AS `hmr` WHERE hmr.hook_menu = :hook_menu AND hmr.aktiv = 1 AND hmr.module <> \'\' AND hmr.funktion <> \'\' GROUP BY hmr.module, hmr.funktion ORDER BY hmr.position",
      [\'hook_menu\' => (int) $hook_menu]
    );',
    'RunMenuHook SelectArr hook_menu_register'
);

// ============================================================
// SaldoAdresseAuftrag (~11846)
// ============================================================

rep($content,
    '    return $this->app->DB->Select("SELECT SUM(gesamtsumme) FROM auftrag WHERE adresse=\'$adresse\' AND status=\'freigegeben\' LIMIT 1");',
    '    return $this->app->DatabaseService->selectValue("SELECT SUM(gesamtsumme) FROM auftrag WHERE adresse = :adresse AND status = \'freigegeben\' LIMIT 1", [\'adresse\' => (int) $adresse]);',
    'SaldoAdresseAuftrag'
);

// ============================================================
// UmsatzAdresseAuftragJahr (~11852)
// ============================================================

rep($content,
    '    return $this->app->DB->Select("SELECT
        SUM(ap.preis*ap.menge*(IF(ap.rabatt > 0, (100-ap.rabatt)/100, 1)))
        FROM auftrag_position ap LEFT JOIN auftrag a ON ap.auftrag=a.id WHERE (a.status=\'freigegeben\' OR a.status=\'abgeschlossen\')
        AND DATE_FORMAT(a.datum,\'%Y\')=DATE_FORMAT(NOW(),\'%Y\') AND a.adresse=\'$adresse\'");',
    '    return $this->app->DatabaseService->selectValue(
      "SELECT SUM(ap.preis*ap.menge*(IF(ap.rabatt > 0, (100-ap.rabatt)/100, 1))) FROM auftrag_position ap LEFT JOIN auftrag a ON ap.auftrag=a.id WHERE (a.status=\'freigegeben\' OR a.status=\'abgeschlossen\') AND DATE_FORMAT(a.datum,\'%Y\')=DATE_FORMAT(NOW(),\'%Y\') AND a.adresse = :adresse",
      [\'adresse\' => (int) $adresse]
    );',
    'UmsatzAdresseAuftragJahr'
);

// ============================================================
// UmsatzAdresseRechnungJahr (~11863)
// ============================================================

rep($content,
    '    return $this->app->DB->Select("SELECT
        SUM(ap.preis*ap.menge*(IF(ap.rabatt > 0, (100-ap.rabatt)/100, 1)))
        FROM rechnung_position ap LEFT JOIN rechnung a ON ap.rechnung=a.id WHERE (a.status=\'freigegeben\' OR a.status=\'abgeschlossen\' OR a.status=\'versendet\')
        AND DATE_FORMAT(a.datum,\'%Y\')=DATE_FORMAT(NOW(),\'%Y\') AND a.adresse=\'$adresse\'");',
    '    return $this->app->DatabaseService->selectValue(
      "SELECT SUM(ap.preis*ap.menge*(IF(ap.rabatt > 0, (100-ap.rabatt)/100, 1))) FROM rechnung_position ap LEFT JOIN rechnung a ON ap.rechnung=a.id WHERE (a.status=\'freigegeben\' OR a.status=\'abgeschlossen\' OR a.status=\'versendet\') AND DATE_FORMAT(a.datum,\'%Y\')=DATE_FORMAT(NOW(),\'%Y\') AND a.adresse = :adresse",
      [\'adresse\' => (int) $adresse]
    );',
    'UmsatzAdresseRechnungJahr'
);

// ============================================================
// KundenSaldo (~11883)
// ============================================================

rep($content,
    '    $rechnungs = $this->app->DB->Select("SELECT IFNULL(SUM(soll-ist),0) FROM rechnung WHERE status != \'angelegt\' AND zahlungsstatus != \'bezahlt\' AND adresse = \'$adresse\'");
    $gutschrifts = $this->app->DB->Select("SELECT IFNULL(SUM(soll-ist),0) FROM gutschrift WHERE status != \'angelegt\' AND (manuell_vorabbezahlt = \'0000-00-00\' OR manuell_vorabbezahlt IS NULL) AND zahlungsstatus != \'bezahlt\' AND adresse = \'$adresse\'");',
    '    $rechnungs = $this->app->DatabaseService->selectValue("SELECT IFNULL(SUM(soll-ist),0) FROM rechnung WHERE status != \'angelegt\' AND zahlungsstatus != \'bezahlt\' AND adresse = :adresse", [\'adresse\' => (int) $adresse]);
    $gutschrifts = $this->app->DatabaseService->selectValue("SELECT IFNULL(SUM(soll-ist),0) FROM gutschrift WHERE status != \'angelegt\' AND (manuell_vorabbezahlt = \'0000-00-00\' OR manuell_vorabbezahlt IS NULL) AND zahlungsstatus != \'bezahlt\' AND adresse = :adresse", [\'adresse\' => (int) $adresse]);',
    'KundenSaldo SELECT rechnung+gutschrift'
);

// ============================================================
// KreditlimitCheck (~12461)
// ============================================================

rep($content,
    '    $kreditlimit = $this->app->DB->Select("SELECT kreditlimit FROM adresse WHERE id=\'$adresse\' LIMIT 1");',
    '    $kreditlimit = $this->app->DatabaseService->selectValue("SELECT kreditlimit FROM adresse WHERE id = :id LIMIT 1", [\'id\' => (int) $adresse]);',
    'KreditlimitCheck SELECT kreditlimit'
);

// ============================================================
// AuftragExplodieren (~11907) - id is numeric auftrag
// ============================================================

rep($content,
    '      $auftraege = $this->app->DB->SelectRow("SELECT * FROM auftrag WHERE (status=\'freigegeben\' OR status=\'angelegt\') AND id=\'$auftrag\'");',
    '      $auftraege = $this->app->DatabaseService->selectRow("SELECT * FROM auftrag WHERE (status=\'freigegeben\' OR status=\'angelegt\') AND id = :id", [\'id\' => (int) $auftrag]);',
    'AuftragExplodieren SelectRow auftrag'
);

rep($content,
    '        && ($this->app->DB->Select("SELECT autostuecklistenanpassung FROM projekt WHERE id=\'$projekt\' LIMIT 1")) == 0',
    '        && ($this->app->DatabaseService->selectValue("SELECT autostuecklistenanpassung FROM projekt WHERE id = :id LIMIT 1", [\'id\' => (int) $projekt])) == 0',
    'AuftragExplodieren SELECT autostuecklistenanpassung'
);

rep($content,
    '        $projektlager = $this->app->DB->Select("SELECT id FROM projekt WHERE id = $projekt AND projektlager = 1 LIMIT 1");',
    '        $projektlager = $this->app->DatabaseService->selectValue("SELECT id FROM projekt WHERE id = :id AND projektlager = 1 LIMIT 1", [\'id\' => (int) $projekt]);',
    'AuftragExplodieren SELECT projektlager'
);

rep($content,
    '            $artikel_von_stueckliste = $this->app->DB->SelectArr(
              "SELECT ap.* ,art.lagerartikel, art.keineeinzelartikelanzeigen,art.juststueckliste,art.stueckliste
          FROM auftrag_position AS ap
          LEFT JOIN artikel AS art ON ap.artikel = art.id
          WHERE ap.auftrag=\'$auftrag\' AND (ap.geliefert_menge < ap.menge AND ap.geliefert=0) AND $swhere"
            );',
    '            $artikelarr = $this->app->DatabaseService->select(
              "SELECT ap.* ,art.lagerartikel, art.keineeinzelartikelanzeigen,art.juststueckliste,art.stueckliste FROM auftrag_position AS ap LEFT JOIN artikel AS art ON ap.artikel = art.id WHERE ap.auftrag = :auftrag AND (ap.geliefert_menge < ap.menge AND ap.geliefert=0) AND $swhere",
              [\'auftrag\' => (int) $auftrag]
            );',
    'AuftragExplodieren SELECT artikelarr with swhere'
);

rep($content,
    '        $artikelarr = $this->app->DB->SelectArr(
          "SELECT ap.* ,art.lagerartikel, art.keineeinzelartikelanzeigen,art.juststueckliste,art.stueckliste
          FROM auftrag_position AS ap
          LEFT JOIN artikel AS art ON ap.artikel = art.id
          WHERE ap.auftrag=\'$auftrag\' AND (ap.geliefert_menge < ap.menge AND ap.geliefert=0)"
        );',
    '        $artikelarr = $this->app->DatabaseService->select(
          "SELECT ap.* ,art.lagerartikel, art.keineeinzelartikelanzeigen,art.juststueckliste,art.stueckliste FROM auftrag_position AS ap LEFT JOIN artikel AS art ON ap.artikel = art.id WHERE ap.auftrag = :auftrag AND (ap.geliefert_menge < ap.menge AND ap.geliefert=0)",
          [\'auftrag\' => (int) $auftrag]
        );',
    'AuftragExplodieren SELECT artikelarr without swhere'
);

rep($content,
    '            $artikel_von_stueckliste = $this->app->DB->SelectArr(
              "SELECT s.*, art.nummer AS artnummer,art.projekt AS artprojekt
              FROM stueckliste AS s
              INNER JOIN artikel AS art ON s.artikel = art.id
              WHERE s.stuecklistevonartikel=\'$artikel\'"
            );',
    '            $artikel_von_stueckliste = $this->app->DatabaseService->select(
              "SELECT s.*, art.nummer AS artnummer,art.projekt AS artprojekt FROM stueckliste AS s INNER JOIN artikel AS art ON s.artikel = art.id WHERE s.stuecklistevonartikel = :artikel",
              [\'artikel\' => (int) $artikel]
            );',
    'AuftragExplodieren SELECT stueckliste'
);

rep($content,
    '              $this->app->DB->Update("UPDATE auftrag_position SET sort=sort+$erhoehe_sort WHERE auftrag=\'$auftrag\' AND sort > $sort");',
    '              $this->app->DatabaseService->execute("UPDATE auftrag_position SET sort = sort + :erhoehe_sort WHERE auftrag = :auftrag AND sort > :sort", [\'erhoehe_sort\' => (int) $erhoehe_sort, \'auftrag\' => (int) $auftrag, \'sort\' => (int) $sort]);',
    'AuftragExplodieren Update sort'
);

rep($content,
    '                  if ($this->app->DB->Select("SELECT id FROM artikel WHERE id = \'" . $value[\'artikel\'] . "\' AND (juststueckliste = 1 " . ($listeexplodieren ? " OR stueckliste = 1 " : \'\') . ") LIMIT 1"))',
    '                  if ($this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE id = :id AND (juststueckliste = 1" . ($listeexplodieren ? " OR stueckliste = 1" : \'\') . ") LIMIT 1", [\'id\' => (int) $value[\'artikel\']]))',
    'AuftragExplodieren SELECT artikel juststueckliste check'
);

rep($content,
    '                  $this->app->DB->Update("UPDATE auftrag_position SET explodiert = 1 WHERE id=\'$explodiert_id\' LIMIT 1");',
    '                  $this->app->DatabaseService->execute("UPDATE auftrag_position SET explodiert = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $explodiert_id]);',
    'AuftragExplodieren Update explodiert=1'
);

rep($content,
    '                  if ($this->Firmendaten(\'auftragexplodieren_unterstuecklisten\'))
                    $this->app->DB->Update("UPDATE auftrag_position SET explodiert = 0 WHERE id = \'$explodiert_id\' LIMIT 1");',
    '                  if ($this->Firmendaten(\'auftragexplodieren_unterstuecklisten\'))
                    $this->app->DatabaseService->execute("UPDATE auftrag_position SET explodiert = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $explodiert_id]);',
    'AuftragExplodieren Update explodiert=0 unterstueckliste'
);

rep($content,
    '                  $this->app->DB->Update("UPDATE `beleg_zwischenpositionen` SET pos = pos + 1 WHERE doctype = \'auftrag\' AND doctypeid = \'$auftrag\' AND pos >= \'" . ($sort - 1) . "\'  ");',
    '                  $this->app->DatabaseService->execute("UPDATE `beleg_zwischenpositionen` SET pos = pos + 1 WHERE doctype = \'auftrag\' AND doctypeid = :auftrag AND pos >= :pos", [\'auftrag\' => (int) $auftrag, \'pos\' => (int) ($sort - 1)]);',
    'AuftragExplodieren Update beleg_zwischenpositionen'
);

rep($content,
    '              $this->app->DB->Update("UPDATE auftrag_position SET mlmdirektpraemie=0, bonuspunkte=0,punkte=0 WHERE explodiert_parent=\'$artikel_position_id\'");',
    '              $this->app->DatabaseService->execute("UPDATE auftrag_position SET mlmdirektpraemie = 0, bonuspunkte = 0, punkte = 0 WHERE explodiert_parent = :parent", [\'parent\' => (int) $artikel_position_id]);',
    'AuftragExplodieren Update mlm mlmdirektpraemie'
);

rep($content,
    '              $this->app->DB->Update("UPDATE auftrag_position SET explodiert=\'1\' WHERE id=\'$artikel_position_id\' LIMIT 1");',
    '              $this->app->DatabaseService->execute("UPDATE auftrag_position SET explodiert = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $artikel_position_id]);',
    'AuftragExplodieren Update explodiert=1 outer'
);

// ============================================================
// AuftragEinzelnBerechnen (~12161)
// ============================================================

rep($content,
    '    $auftragarr = $this->app->DB->SelectRow("SELECT projekt,internet FROM auftrag WHERE id = \'$auftrag\' LIMIT 1");',
    '    $auftragarr = $this->app->DatabaseService->selectRow("SELECT projekt,internet FROM auftrag WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragEinzelnBerechnen SelectRow'
);

// ============================================================
// AuftragAutoversandBerechnen (~12187)
// ============================================================

rep($content,
    '    $auftraege = $this->app->DB->SelectArr("SELECT * FROM auftrag WHERE id=\'$auftrag\'");',
    '    $auftraege = $this->app->DatabaseService->select("SELECT * FROM auftrag WHERE id = :id", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SelectArr auftrag'
);

rep($content,
    '    $artikelarr = $this->app->DB->SelectArr("SELECT ap.id, ap.artikel, ap.menge, ap.geliefert_menge, art.lagerartikel as artlagerartikel FROM auftrag_position ap LEFT JOIN artikel art ON ap.artikel = art.id WHERE ap.auftrag=\'$auftrag\' AND ap.geliefert_menge < ap.menge AND ap.geliefert=0");',
    '    $artikelarr = $this->app->DatabaseService->select("SELECT ap.id, ap.artikel, ap.menge, ap.geliefert_menge, art.lagerartikel as artlagerartikel FROM auftrag_position ap LEFT JOIN artikel art ON ap.artikel = art.id WHERE ap.auftrag = :auftrag AND ap.geliefert_menge < ap.menge AND ap.geliefert = 0", [\'auftrag\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SelectArr auftrag_position'
);

rep($content,
    '    $reservierte = $this->app->DB->Select("SELECT COUNT(id) FROM lager_reserviert WHERE adresse=\'$adresse\' AND datum>=NOW() AND objekt!=\'lieferschein\'");',
    '    $reservierte = $this->app->DatabaseService->selectValue("SELECT COUNT(id) FROM lager_reserviert WHERE adresse = :adresse AND datum >= NOW() AND objekt != \'lieferschein\'", [\'adresse\' => (int) $adresse]);',
    'AuftragAutoversandBerechnen SELECT lager_reserviert'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET reserviert_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
    } elseif ($reservierte <= 0 && $auftraege[0][\'reserviert_ok\'] != 0) {
      $this->app->DB->Update("UPDATE auftrag SET reserviert_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET reserviert_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    } elseif ($reservierte <= 0 && $auftraege[0][\'reserviert_ok\'] != 0) {
      $this->app->DatabaseService->execute("UPDATE auftrag SET reserviert_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update reserviert_ok'
);

rep($content,
    '    $liefertermincheck = $this->app->DB->Select("SELECT id FROM auftrag WHERE (tatsaechlicheslieferdatum<=NOW() OR tatsaechlicheslieferdatum IS NULL OR tatsaechlicheslieferdatum=\'0000-00-00\') AND id=\'$auftrag\'");',
    '    $liefertermincheck = $this->app->DatabaseService->selectValue("SELECT id FROM auftrag WHERE (tatsaechlicheslieferdatum <= NOW() OR tatsaechlicheslieferdatum IS NULL OR tatsaechlicheslieferdatum = \'0000-00-00\') AND id = :id", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SELECT liefertermincheck'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET liefertermin_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
    } elseif ($liefertermincheck <= 0 && $auftraege[0][\'liefertermin_ok\'] != 0) {
      $this->app->DB->Update("UPDATE auftrag SET liefertermin_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET liefertermin_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    } elseif ($liefertermincheck <= 0 && $auftraege[0][\'liefertermin_ok\'] != 0) {
      $this->app->DatabaseService->execute("UPDATE auftrag SET liefertermin_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update liefertermin_ok'
);

rep($content,
    '    $liefersperre = $this->app->DB->Select("SELECT liefersperre FROM adresse WHERE id=\'$adresse\'");',
    '    $liefersperre = $this->app->DatabaseService->selectValue("SELECT liefersperre FROM adresse WHERE id = :id", [\'id\' => (int) $adresse]);',
    'AuftragAutoversandBerechnen SELECT liefersperre'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET liefersperre_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");
    } elseif ($liefersperre <= 0 && $auftraege[0][\'liefersperre_ok\'] != 1) {
      $this->app->DB->Update("UPDATE auftrag SET liefersperre_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET liefersperre_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    } elseif ($liefersperre <= 0 && $auftraege[0][\'liefersperre_ok\'] != 1) {
      $this->app->DatabaseService->execute("UPDATE auftrag SET liefersperre_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update liefersperre_ok'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET kreditlimit_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
    } elseif (!$setKreditLimitOk && $auftraege[0][\'kreditlimit_ok\'] != 0) {
      $this->app->DB->Update("UPDATE auftrag SET kreditlimit_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET kreditlimit_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    } elseif (!$setKreditLimitOk && $auftraege[0][\'kreditlimit_ok\'] != 0) {
      $this->app->DatabaseService->execute("UPDATE auftrag SET kreditlimit_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update kreditlimit_ok'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET ust_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
    }

    // Lager Check',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET ust_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    }

    // Lager Check',
    'AuftragAutoversandBerechnen Update ust_ok (first)'
);

rep($content,
    '        $gesamte_menge_im_auftrag = $this->app->DB->Select("SELECT SUM(menge-geliefert_menge) FROM auftrag_position WHERE auftrag=\'$auftrag\' AND artikel=\'$artikel\'");',
    '        $gesamte_menge_im_auftrag = $this->app->DatabaseService->selectValue("SELECT SUM(menge-geliefert_menge) FROM auftrag_position WHERE auftrag = :auftrag AND artikel = :artikel", [\'auftrag\' => (int) $auftrag, \'artikel\' => (int) $artikel]);',
    'AuftragAutoversandBerechnen SELECT SUM menge'
);

rep($content,
    '    $projekt = $this->app->DB->Select("SELECT projekt FROM auftrag WHERE id=\'$auftrag\' LIMIT 1");

    $this->app->DB->Update("UPDATE auftrag SET teillieferung_moeglich=\'0\' WHERE id=\'$auftrag\' LIMIT 1");',
    '    $projekt = $this->app->DatabaseService->selectValue("SELECT projekt FROM auftrag WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);

    $this->app->DatabaseService->execute("UPDATE auftrag SET teillieferung_moeglich = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SELECT projekt + Update teillieferung'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET lager_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
    } else {
      $kommissionierverfahren = $this->app->DB->Select("SELECT kommissionierverfahren FROM projekt WHERE id = \'$projekt\' LIMIT 1");
      if ($kommissionierverfahren == \'rechnungsmail\') {
        $this->app->DB->Update("UPDATE auftrag SET lager_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
      } else {
        $this->app->DB->Update("UPDATE auftrag SET lager_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");
      }
      if ($positionen_vorhanden > 0 && $artikelzaehlen > 0) {
        $this->app->DB->Update("UPDATE auftrag SET teillieferung_moeglich=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
      }',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET lager_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    } else {
      $kommissionierverfahren = $this->app->DatabaseService->selectValue("SELECT kommissionierverfahren FROM projekt WHERE id = :id LIMIT 1", [\'id\' => (int) $projekt]);
      if ($kommissionierverfahren == \'rechnungsmail\') {
        $this->app->DatabaseService->execute("UPDATE auftrag SET lager_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
      } else {
        $this->app->DatabaseService->execute("UPDATE auftrag SET lager_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
      }
      if ($positionen_vorhanden > 0 && $artikelzaehlen > 0) {
        $this->app->DatabaseService->execute("UPDATE auftrag SET teillieferung_moeglich = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
      }',
    'AuftragAutoversandBerechnen Update lager_ok + kommissionierverfahren block'
);

rep($content,
    '    $projektarr = empty($projekt) ? null : $this->app->DB->SelectRow("SELECT * FROM projekt WHERE id=\'$projekt\' LIMIT 1");',
    '    $projektarr = empty($projekt) ? null : $this->app->DatabaseService->selectRow("SELECT * FROM projekt WHERE id = :id LIMIT 1", [\'id\' => (int) $projekt]);',
    'AuftragAutoversandBerechnen SELECT * projekt'
);

rep($content,
    '    $altercheck = $this->app->DB->Select("SELECT check_ok FROM auftrag WHERE id=\'$auftrag\' LIMIT 1");',
    '    $altercheck = $this->app->DatabaseService->selectValue("SELECT check_ok FROM auftrag WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SELECT altercheck'
);

rep($content,
    '          $this->app->DB->Update("UPDATE auftrag SET check_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
        } else {
          $this->app->DB->Update("UPDATE auftrag SET check_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");
        }
      } else {
        $this->app->DB->Update("UPDATE auftrag SET check_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
      }
    } else {
      $this->app->DB->Update("UPDATE auftrag SET check_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");',
    '          $this->app->DatabaseService->execute("UPDATE auftrag SET check_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
        } else {
          $this->app->DatabaseService->execute("UPDATE auftrag SET check_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
        }
      } else {
        $this->app->DatabaseService->execute("UPDATE auftrag SET check_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
      }
    } else {
      $this->app->DatabaseService->execute("UPDATE auftrag SET check_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update check_ok block'
);

rep($content,
    '    $ustprf = $this->app->DB->Select("SELECT id FROM ustprf WHERE DATE_FORMAT(datum_online,\'%Y-%m-%d\')=DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND adresse=\'$adresse\' AND status=\'erfolgreich\' LIMIT 1");',
    '    $ustprf = $this->app->DatabaseService->selectValue("SELECT id FROM ustprf WHERE DATE_FORMAT(datum_online,\'%Y-%m-%d\') = DATE_FORMAT(NOW(),\'%Y-%m-%d\') AND adresse = :adresse AND status = \'erfolgreich\' LIMIT 1", [\'adresse\' => (int) $adresse]);',
    'AuftragAutoversandBerechnen SELECT ustprf'
);

rep($content,
    '    $auftragarr = $this->app->DB->SelectRow("SELECT * FROM auftrag WHERE id=\'$auftrag\' LIMIT 1");
    if (!empty($auftragarr)) {
      $ustid = $auftragarr[\'ustid\'];',
    '    $auftragarr = $this->app->DatabaseService->selectRow("SELECT * FROM auftrag WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    if (!empty($auftragarr)) {
      $ustid = $auftragarr[\'ustid\'];',
    'AuftragAutoversandBerechnen SelectRow auftrag ustid'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET ust_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
    }

    // Porto Check',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET ust_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    }

    // Porto Check',
    'AuftragAutoversandBerechnen Update ust_ok (second)'
);

rep($content,
    '    $porto = $this->app->DB->Select("SELECT ap.id FROM auftrag_position ap, artikel a WHERE ap.auftrag=\'$auftrag\' AND ap.artikel=a.id AND a.porto=1 AND ap.preis >= 0
        AND a.id=ap.artikel LIMIT 1");',
    '    $porto = $this->app->DatabaseService->selectValue("SELECT ap.id FROM auftrag_position ap, artikel a WHERE ap.auftrag = :auftrag AND ap.artikel = a.id AND a.porto = 1 AND ap.preis >= 0 AND a.id = ap.artikel LIMIT 1", [\'auftrag\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SELECT porto'
);

rep($content,
    '        $this->app->DB->Update("UPDATE auftrag SET porto_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
        $portoFreeLimit = (double) $this->app->DB->Select("SELECT portofreiab FROM adresse WHERE id={$adresse} LIMIT 1");',
    '        $this->app->DatabaseService->execute("UPDATE auftrag SET porto_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
        $portoFreeLimit = (double) $this->app->DatabaseService->selectValue("SELECT portofreiab FROM adresse WHERE id = :id LIMIT 1", [\'id\' => (int) $adresse]);',
    'AuftragAutoversandBerechnen UPDATE porto_ok=1 + SELECT portofreiab'
);

rep($content,
    '          $this->app->DB->Update("UPDATE auftrag SET porto_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
        } else {
          $this->app->DB->Update("UPDATE auftrag SET porto_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");
        }
      }
    } else {
      //projekt hat kein portocheck porto ist immer ok
      $this->app->DB->Update("UPDATE auftrag SET porto_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");',
    '          $this->app->DatabaseService->execute("UPDATE auftrag SET porto_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
        } else {
          $this->app->DatabaseService->execute("UPDATE auftrag SET porto_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
        }
      }
    } else {
      //projekt hat kein portocheck porto ist immer ok
      $this->app->DatabaseService->execute("UPDATE auftrag SET porto_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update porto_ok block'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET porto_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");
      //$this->app->DB->Update("UPDATE auftrag_position ap, artikel a SET ap.preis=\'0\' WHERE ap.auftrag=\'$auftrag\' AND a.id=ap.artikel AND a.porto=\'1\'");',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET porto_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
      //$this->app->DB->Update("UPDATE auftrag_position ap, artikel a SET ap.preis=\'0\' WHERE ap.auftrag=\'$auftrag\' AND a.id=ap.artikel AND a.porto=\'1\'");',
    'AuftragAutoversandBerechnen Update porto_ok keinporto'
);

rep($content,
    '    $this->app->DB->Update("UPDATE auftrag SET vorkasse_ok=\'$vorkasse_ok\' WHERE id=\'$auftrag\'");',
    '    $this->app->DatabaseService->execute("UPDATE auftrag SET vorkasse_ok = :vorkasse_ok WHERE id = :id", [\'vorkasse_ok\' => $vorkasse_ok, \'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update vorkasse_ok'
);

rep($content,
    '    $nachnahme = $this->app->DB->Select("SELECT COUNT(ap.id) FROM auftrag_position ap, artikel a WHERE ap.auftrag=\'$auftrag\' AND ap.artikel=a.id AND a.porto=1 AND ap.preis >= 0
      AND a.id=ap.artikel");',
    '    $nachnahme = $this->app->DatabaseService->selectValue("SELECT COUNT(ap.id) FROM auftrag_position ap, artikel a WHERE ap.auftrag = :auftrag AND ap.artikel = a.id AND a.porto = 1 AND ap.preis >= 0 AND a.id = ap.artikel", [\'auftrag\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SELECT COUNT nachnahme'
);

rep($content,
    '      $this->app->DB->Update("UPDATE auftrag SET nachnahme_ok=\'0\' WHERE id=\'$auftrag\' LIMIT 1");
    } else {
      $this->app->DB->Update("UPDATE auftrag SET nachnahme_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");',
    '      $this->app->DatabaseService->execute("UPDATE auftrag SET nachnahme_ok = 0 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    } else {
      $this->app->DatabaseService->execute("UPDATE auftrag SET nachnahme_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen Update nachnahme_ok'
);

rep($content,
    '    $lieferungtrotzsperre = $this->app->DB->Select("SELECT lieferungtrotzsperre FROM auftrag WHERE id=\'$auftrag\' LIMIT 1");
    if ($lieferungtrotzsperre == 1) {
      $this->app->DB->Update("UPDATE auftrag SET liefersperre_ok=\'1\' WHERE id=\'$auftrag\' LIMIT 1");',
    '    $lieferungtrotzsperre = $this->app->DatabaseService->selectValue("SELECT lieferungtrotzsperre FROM auftrag WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);
    if ($lieferungtrotzsperre == 1) {
      $this->app->DatabaseService->execute("UPDATE auftrag SET liefersperre_ok = 1 WHERE id = :id LIMIT 1", [\'id\' => (int) $auftrag]);',
    'AuftragAutoversandBerechnen SELECT lieferungtrotzsperre + Update liefersperre_ok'
);

// ============================================================
// ReplaceAdresse (~12512)
// ============================================================

rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT CONCAT(id,\' \',name) FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT CONCAT(id,\' \',name) FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1", [\'id\' => (int) $id]);',
    'ReplaceAdresse SELECT CONCAT id name'
);

rep($content,
    '      $id = $this->app->DB->Select("SELECT id FROM adresse WHERE id=\'$rest\' AND geloescht=0 LIMIT 1");',
    '      $id = $this->app->DatabaseService->selectValue("SELECT id FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1", [\'id\' => (int) $rest]);',
    'ReplaceAdresse SELECT id from rest'
);

// ============================================================
// ReplaceMitarbeiter (~12546)
// ============================================================

rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT CONCAT(mitarbeiternummer,\' \',name) FROM adresse WHERE id=\'$id\' AND geloescht=0 LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT CONCAT(mitarbeiternummer,\' \',name) FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1", [\'id\' => (int) $id]);',
    'ReplaceMitarbeiter SELECT CONCAT'
);

rep($content,
    '      $id = $this->app->DB->Select("SELECT id FROM adresse WHERE mitarbeiternummer=\'$rest\' AND mitarbeiternummer!=\'\' AND geloescht=0 LIMIT 1");',
    '      $id = $this->app->DatabaseService->selectValue("SELECT id FROM adresse WHERE mitarbeiternummer = :nr AND mitarbeiternummer != \'\' AND geloescht = 0 LIMIT 1", [\'nr\' => $rest]);',
    'ReplaceMitarbeiter SELECT id'
);

// ============================================================
// ReplaceGruppenKategorien (~12580)
// ============================================================

rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT CONCAT(id,\' \',bezeichnung) as name FROM gruppen_kategorien WHERE id=\'$id\' LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT CONCAT(id,\' \',bezeichnung) as name FROM gruppen_kategorien WHERE id = :id LIMIT 1", [\'id\' => (int) $id]);',
    'ReplaceGruppenKategorien SELECT CONCAT'
);

rep($content,
    '      $id = $this->app->DB->Select("SELECT id FROM gruppen_kategorien WHERE id=\'$rest\' LIMIT 1");',
    '      $id = $this->app->DatabaseService->selectValue("SELECT id FROM gruppen_kategorien WHERE id = :id LIMIT 1", [\'id\' => (int) $rest]);',
    'ReplaceGruppenKategorien SELECT id'
);

// ============================================================
// ReplacePreisgruppe (~12611)
// ============================================================

rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT CONCAT(g.kennziffer,\' \',g.name) as name FROM gruppen g WHERE id=\'$id\' LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT CONCAT(g.kennziffer,\' \',g.name) as name FROM gruppen g WHERE id = :id LIMIT 1", [\'id\' => (int) $id]);',
    'ReplacePreisgruppe SELECT CONCAT'
);

rep($content,
    '      $id = $this->app->DB->Select("SELECT id
        FROM gruppen AS g
        WHERE (CONCAT(g.kennziffer,\' \',g.name)=\'$value\' OR g.kennziffer = \'$rest\') AND
              (g.name!=\'\' OR g.kennziffer != \'\') AND g.art = \'preisgruppe\' AND g.aktiv=1
        LIMIT 1");',
    '      $id = $this->app->DatabaseService->selectValue(
        "SELECT id FROM gruppen AS g WHERE (CONCAT(g.kennziffer,\' \',g.name) = :value OR g.kennziffer = :rest) AND (g.name != \'\' OR g.kennziffer != \'\') AND g.art = \'preisgruppe\' AND g.aktiv = 1 LIMIT 1",
        [\'value\' => $value, \'rest\' => $rest]
      );',
    'ReplacePreisgruppe SELECT id'
);

// ============================================================
// ReplaceArtikel (~12651)
// ============================================================

rep($content,
    '        $abkuerzung = $this->app->DB->Select("SELECT CONCAT(nummer,\' \',name_de) as name FROM artikel WHERE id=\'$id\' AND geloescht=0 LIMIT 1");',
    '        $abkuerzung = $this->app->DatabaseService->selectValue("SELECT CONCAT(nummer,\' \',name_de) as name FROM artikel WHERE id = :id AND geloescht = 0 LIMIT 1", [\'id\' => (int) $id]);',
    'ReplaceArtikel SELECT CONCAT'
);

rep($content,
    '      $id = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer=\'$rest\' AND nummer!=\'\' AND geloescht=0 LIMIT 1");',
    '      $id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nummer AND nummer != \'\' AND geloescht = 0 LIMIT 1", [\'nummer\' => $rest]);',
    'ReplaceArtikel SELECT id'
);

// ============================================================
// Save file
// ============================================================

file_put_contents($filepath, $content);
echo "\nTotal replacements: $total\n";
echo "Saved.\n";
