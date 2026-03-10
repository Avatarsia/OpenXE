<?php
/**
 * SQL injection migration script for class.erpapi.php lines 1-8000
 * Run once, then delete this file.
 */

$file = __DIR__ . '/www/lib/class.erpapi.php';
$content = file_get_contents($file);
$original = $content;
$changes = [];

// -----------------------------------------------------------------------
// Fix 1: TrackingCSV - $versand and $uebertragung interpolated in SQL
// -----------------------------------------------------------------------
$old = '    $arr = $this->app->DB->SelectRow("SELECT " . ($uebertragung ? " am.id_ext as auftragextid, " : "") . " v.tracking, l.belegnr, if(ifnull(l.sprache,\'\') = \'\',ifnull(a.sprache,\'\'),l.sprache) as tracking_sprache, l.name, v.versandart FROM versand v INNER JOIN lieferschein l ON v.lieferschein = l.id AND v.id = \'$versand\'
    LEFT JOIN auftrag a ON l.auftragid = a.id
    " . ($uebertragung ? "
    LEFT JOIN api_mapping am ON am.tabelle = \'auftrag\' AND am.uebertragung_account = \'$uebertragung\' AND id_int = a.id
    " : "") . "
    LIMIT 1");';
$new = '    if ($uebertragung) {
      $arr = $this->app->DatabaseService->selectRow(
        "SELECT am.id_ext as auftragextid, v.tracking, l.belegnr, if(ifnull(l.sprache,\'\') = \'\',ifnull(a.sprache,\'\'),l.sprache) as tracking_sprache, l.name, v.versandart FROM versand v INNER JOIN lieferschein l ON v.lieferschein = l.id AND v.id = :versand
        LEFT JOIN auftrag a ON l.auftragid = a.id
        LEFT JOIN api_mapping am ON am.tabelle = \'auftrag\' AND am.uebertragung_account = :uebertragung AND id_int = a.id
        LIMIT 1",
        [\'versand\' => (int) $versand, \'uebertragung\' => (int) $uebertragung]
      );
    } else {
      $arr = $this->app->DatabaseService->selectRow(
        "SELECT v.tracking, l.belegnr, if(ifnull(l.sprache,\'\') = \'\',ifnull(a.sprache,\'\'),l.sprache) as tracking_sprache, l.name, v.versandart FROM versand v INNER JOIN lieferschein l ON v.lieferschein = l.id AND v.id = :versand
        LEFT JOIN auftrag a ON l.auftragid = a.id
        LIMIT 1",
        [\'versand\' => (int) $versand]
      );
    }';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 1: TrackingCSV versand/uebertragung';
} else {
    $changes[] = 'Fix 1: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 2: LagerzahlenCSV - $lager_platz, $lager, $artikel interpolated
// -----------------------------------------------------------------------
$old = '      $artikelarr = $this->app->DB->SelectRow("SELECT art.nummer, art.herstellernummer, art.ean, ifnull(lag.menge,0) as lager_menge_total,  lag.lager_platz
      FROM artikel art
      LEFT JOIN (
        SELECT lpi.artikel,lp.kurzbezeichnung as lager_platz, trim(ifnull(sum(lpi.menge),0))+0 as menge FROM lager_platz_inhalt lpi
        INNER JOIN lager_platz lp ON lpi.lager_platz = lp.id AND lp.geloescht = 0 " . ($lager_platz ? " AND lp.id = \'$lager_platz\' " : "") . "
        " . ($lager ? " AND lp.lager = \'$lager\' " : "") . "
        WHERE lpi.artikel = \'$artikel\' GROUP BY lp.id
      ) lag ON art.id = lag.artikel  WHERE art.id = \'$artikel\' LIMIT 1");';
$new = '      $lagerParams = [\'artikel\' => (int) $artikel];
      $lagerSql = "SELECT art.nummer, art.herstellernummer, art.ean, ifnull(lag.menge,0) as lager_menge_total,  lag.lager_platz
      FROM artikel art
      LEFT JOIN (
        SELECT lpi.artikel,lp.kurzbezeichnung as lager_platz, trim(ifnull(sum(lpi.menge),0))+0 as menge FROM lager_platz_inhalt lpi
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
      ) lag ON art.id = lag.artikel WHERE art.id = :artikel LIMIT 1";
      $artikelarr = $this->app->DatabaseService->selectRow($lagerSql, $lagerParams);';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 2: LagerzahlenCSV lager/lager_platz/artikel';
} else {
    $changes[] = 'Fix 2: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 3: LagerzahlenCSV second query - $artikel
// -----------------------------------------------------------------------
$old = '      $artikelarr = $this->app->DB->SelectRow("SELECT art.nummer, art.herstellernummer, art.ean FROM artikel art
      WHERE art.id = \'$artikel\' LIMIT 1");';
$new = '      $artikelarr = $this->app->DatabaseService->selectRow(
        "SELECT art.nummer, art.herstellernummer, art.ean FROM artikel art WHERE art.id = :artikel LIMIT 1",
        [\'artikel\' => (int) $artikel]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 3: LagerzahlenCSV artikel select';
} else {
    $changes[] = 'Fix 3: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 4: AddShopArtikelIfEmpty - $shop and $artikel interpolated
// -----------------------------------------------------------------------
$old = '    $old = $this->app->DB->SelectArr("SELECT min(id) as id, name,wert  FROM shopexport_artikel
      WHERE shopid = \'$shop\' AND artikel = \'$artikel\' GROUP BY name,wert");';
$new = '    $old = $this->app->DatabaseService->select(
      "SELECT min(id) as id, name,wert FROM shopexport_artikel WHERE shopid = :shop AND artikel = :artikel GROUP BY name,wert",
      [\'shop\' => (int) $shop, \'artikel\' => (int) $artikel]
    );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 4: AddShopArtikelIfEmpty SELECT shopexport_artikel';
} else {
    $changes[] = 'Fix 4: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 5: AddShopArtikelIfEmpty - INSERT with $shop, $artikel
// -----------------------------------------------------------------------
$old = '          $this->app->DB->Insert("INSERT INTO shopexport_artikel (shopid, artikel, name) VALUES (\'$shop\',\'$artikel\',\'" . $this->app->DB->real_escape_string($k) . "\')");';
$new = '          $this->app->DatabaseService->execute(
            "INSERT INTO shopexport_artikel (shopid, artikel, name) VALUES (:shop, :artikel, :name)",
            [\'shop\' => (int) $shop, \'artikel\' => (int) $artikel, \'name\' => (string) $k]
          );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 5: AddShopArtikelIfEmpty INSERT shopexport_artikel';
} else {
    $changes[] = 'Fix 5: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 6: AddShopArtikelIfEmpty - UPDATE with $bearbeiter, escaped $v, $check
// -----------------------------------------------------------------------
$old = '            $this->app->DB->Update("UPDATE shopexport_artikel SET bearbeiter = \'$bearbeiter\', wert = \'" . $this->app->DB->real_escape_string($v) . "\' WHERE id = \'$check\' LIMIT 1");
          } else {
            $this->app->DB->Update("UPDATE shopexport_artikel SET bearbeiter = \'$bearbeiter\', wert = \'$v\' WHERE id = \'$check\' LIMIT 1");';
$new = '            $this->app->DatabaseService->execute(
              "UPDATE shopexport_artikel SET bearbeiter = :bearbeiter, wert = :wert WHERE id = :id LIMIT 1",
              [\'bearbeiter\' => (string) $bearbeiter, \'wert\' => (string) $v, \'id\' => (int) $check]
            );
          } else {
            $this->app->DatabaseService->execute(
              "UPDATE shopexport_artikel SET bearbeiter = :bearbeiter, wert = :wert WHERE id = :id LIMIT 1",
              [\'bearbeiter\' => (string) $bearbeiter, \'wert\' => (string) $v, \'id\' => (int) $check]
            );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 6: AddShopArtikelIfEmpty UPDATE shopexport_artikel';
} else {
    $changes[] = 'Fix 6: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 7: PhoneLink - $telefon and $adresse interpolated in SQL (line ~1457)
// -----------------------------------------------------------------------
$old = '      $typ = $this->app->DB->Select("SELECT if(telefon=\'$telefon\' AND telefon!=\'\',\'1\',if(mobil=\'$telefon\' AND mobil!=\'\',\'2\',\'\')) FROM adresse WHERE id=\'$adresse\' LIMIT 1");';
$new = '      $typ = $this->app->DatabaseService->selectValue(
        "SELECT if(telefon = :telefon AND telefon != \'\', \'1\', if(mobil = :telefon2 AND mobil != \'\', \'2\', \'\')) FROM adresse WHERE id = :adresse LIMIT 1",
        [\'telefon\' => (string) $telefon, \'telefon2\' => (string) $telefon, \'adresse\' => (int) $adresse]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 7: PhoneLink adresse query';
} else {
    $changes[] = 'Fix 7: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 8: PhoneLink - $telefon and $ansprechpartner (line ~1462)
// -----------------------------------------------------------------------
$old = '      $typ = $this->app->DB->Select("SELECT if(telefon=\'$telefon\' AND telefon!=\'\',\'3\',if(mobil=\'$telefon\' AND mobil!=\'\',\'4\',\'\')) FROM ansprechpartner WHERE id=\'$ansprechpartner\' LIMIT 1");';
$new = '      $typ = $this->app->DatabaseService->selectValue(
        "SELECT if(telefon = :telefon AND telefon != \'\', \'3\', if(mobil = :telefon2 AND mobil != \'\', \'4\', \'\')) FROM ansprechpartner WHERE id = :ansprechpartner LIMIT 1",
        [\'telefon\' => (string) $telefon, \'telefon2\' => (string) $telefon, \'ansprechpartner\' => (int) $ansprechpartner]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 8: PhoneLink ansprechpartner query';
} else {
    $changes[] = 'Fix 8: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 9: AbgleichBenutzerVorlagen - $vorlage id SELECT
// -----------------------------------------------------------------------
$old = '      $bezeichnung = $this->app->DB->Select("SELECT bezeichnung FROM uservorlage WHERE id = \'$vorlage\' LIMIT 1");';
$new = '      $bezeichnung = $this->app->DatabaseService->selectValue(
        "SELECT bezeichnung FROM uservorlage WHERE id = :id LIMIT 1",
        [\'id\' => (int) $vorlage]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 9: AbgleichBenutzerVorlagen vorlage bezeichnung SELECT';
} else {
    $changes[] = 'Fix 9: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 10: AbgleichBenutzerVorlagen - uservorlagerights permission SELECT
// -----------------------------------------------------------------------
$old = '      $permission = $this->app->DB->Select("SELECT permission FROM uservorlagerights WHERE vorlage = \'" . $id_vorlage . "\'  AND module = \'$module\'  AND action = \'$action\' LIMIT 1");';
$new = '      $permission = $this->app->DatabaseService->selectValue(
        "SELECT permission FROM uservorlagerights WHERE vorlage = :vorlage AND module = :module AND action = :action LIMIT 1",
        [\'vorlage\' => (int) $id_vorlage, \'module\' => (string) $module, \'action\' => (string) $action]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 10: AbgleichBenutzerVorlagen uservorlagerights permission SELECT';
} else {
    $changes[] = 'Fix 10: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 11: AbgleichBenutzerVorlagen - user SELECT with $bezeichnung (line ~1531)
// -----------------------------------------------------------------------
$old = '      $user = $this->app->DB->SelectArr("SELECT * FROM user" . ($bezeichnung != \'\' ? " WHERE vorlage like \'$bezeichnung\' " : \'\'));
    } else {
      $user = $this->app->DB->SelectArr("SELECT * FROM user WHERE id=\'$userid\'");';
$new = '      if ($bezeichnung !== \'\') {
        $user = $this->app->DatabaseService->select(
          "SELECT * FROM user WHERE vorlage LIKE :bezeichnung",
          [\'bezeichnung\' => (string) $bezeichnung]
        );
      } else {
        $user = $this->app->DatabaseService->select("SELECT * FROM user");
      }
    } else {
      $user = $this->app->DatabaseService->select(
        "SELECT * FROM user WHERE id = :id",
        [\'id\' => (int) $userid]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 11: AbgleichBenutzerVorlagen user SELECT';
} else {
    $changes[] = 'Fix 11: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 12: AbgleichBenutzerVorlagen - uservorlage SELECT by bezeichnung (line ~1547)
// -----------------------------------------------------------------------
$old = '        $id_vorlage = $this->app->DB->Select("SELECT id FROM uservorlage WHERE bezeichnung LIKE \'" . $user[$i][\'vorlage\'] . "\' LIMIT 1");';
$new = '        $id_vorlage = $this->app->DatabaseService->selectValue(
          "SELECT id FROM uservorlage WHERE bezeichnung LIKE :bezeichnung LIMIT 1",
          [\'bezeichnung\' => (string) $user[$i][\'vorlage\']]
        );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 12: AbgleichBenutzerVorlagen uservorlage SELECT by bezeichnung';
} else {
    $changes[] = 'Fix 12: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 13: AbgleichBenutzerVorlagen - DELETE userrights with $module/$action (line ~1555)
// -----------------------------------------------------------------------
$old = '          $this->app->DB->Delete("DELETE FROM userrights WHERE user = \'" . $user[$i][\'id\'] . "\' AND module=\'$module\' AND action = \'$action\'");';
$new = '          $this->app->DatabaseService->execute(
            "DELETE FROM userrights WHERE user = :user AND module = :module AND action = :action",
            [\'user\' => (int) $user[$i][\'id\'], \'module\' => (string) $module, \'action\' => (string) $action]
          );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 13: AbgleichBenutzerVorlagen DELETE userrights';
} else {
    $changes[] = 'Fix 13: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 14: AbgleichBenutzerVorlagen - INSERT userrights with $module/$action/$permission
// -----------------------------------------------------------------------
$old = '          $this->app->DB->Insert("INSERT INTO userrights (user, module, action, permission) VALUES (\'" . $user[$i][\'id\'] . "\',\'$module\',\'$action\',\'$permission\')")';
$new = '          $this->app->DatabaseService->execute(
            "INSERT INTO userrights (user, module, action, permission) VALUES (:user, :module, :action, :permission)",
            [\'user\' => (int) $user[$i][\'id\'], \'module\' => (string) $module, \'action\' => (string) $action, \'permission\' => (string) $permission]
          )';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 14: AbgleichBenutzerVorlagen INSERT userrights';
} else {
    $changes[] = 'Fix 14: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 15: AbgleichBenutzerVorlagen - SELECT uservorlagerights with $id_vorlage/$module/$action
// -----------------------------------------------------------------------
$old = '          $permissions = $this->app->DB->SelectArr("SELECT module, action,permission
            FROM uservorlagerights WHERE vorlage = \'" . $id_vorlage . "\' " . ($module != \'\' ? " AND module = \'$module\' " : "") . ($action != \'\' ? " AND action = \'$action\'" : \'\'));';
$new = '          $permSql = "SELECT module, action, permission FROM uservorlagerights WHERE vorlage = :vorlage";
          $permParams = [\'vorlage\' => (int) $id_vorlage];
          if ($module !== \'\') { $permSql .= " AND module = :module"; $permParams[\'module\'] = (string) $module; }
          if ($action !== \'\') { $permSql .= " AND action = :action"; $permParams[\'action\'] = (string) $action; }
          $permissions = $this->app->DatabaseService->select($permSql, $permParams);';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 15: AbgleichBenutzerVorlagen SELECT uservorlagerights';
} else {
    $changes[] = 'Fix 15: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 16: UserEventNachrichten - $user interpolated (line ~1697)
// -----------------------------------------------------------------------
$old = '    $adresse = (int) $this->app->DB->Select("SELECT adresse FROM user WHERE id = \'" . $user . "\' LIMIT 1");';
$new = '    $adresse = (int) $this->app->DatabaseService->selectValue(
      "SELECT adresse FROM user WHERE id = :id LIMIT 1",
      [\'id\' => (int) $user]
    );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 16: UserEventNachrichten adresse SELECT';
} else {
    $changes[] = 'Fix 16: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 17: UserEventNachrichten - $adresse in adresse_rolle SELECT (line ~1699)
// -----------------------------------------------------------------------
$old = '      $_gruppen = $this->app->DB->SelectArr("SELECT distinct parameter as gruppe FROM adresse_rolle WHERE adresse = \'$adresse\' AND subjekt = \'Mitglied\' AND (bis = \'0000-00-00\' OR bis >= date(now())) AND parameter > 0");';
$new = '      $_gruppen = $this->app->DatabaseService->select(
        "SELECT DISTINCT parameter as gruppe FROM adresse_rolle WHERE adresse = :adresse AND subjekt = \'Mitglied\' AND (bis = \'0000-00-00\' OR bis >= date(now())) AND parameter > 0",
        [\'adresse\' => (int) $adresse]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 17: UserEventNachrichten adresse_rolle SELECT';
} else {
    $changes[] = 'Fix 17: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 18: UserEventNachrichten - boxnachrichten with $user (line ~1708)
// -----------------------------------------------------------------------
$old = '      $nachrichten = $this->app->DB->SelectArr("SELECT b.* FROM boxnachrichten b WHERE (b.user = \'$user\' OR ($subwhere)) AND (ablaufzeit = 0 OR
      TIME_TO_SEC(
       TIMEDIFF(
        now(),
        zeitstempel
       ) <= ablaufzeit
      )
      ) ORDER BY ablaufzeit > 0 DESC, prio DESC, zeitstempel");';
$new = '      $nachrichten = $this->app->DatabaseService->select(
        "SELECT b.* FROM boxnachrichten b WHERE (b.user = :user OR ($subwhere)) AND (ablaufzeit = 0 OR TIME_TO_SEC(TIMEDIFF(now(), zeitstempel)) <= ablaufzeit) ORDER BY ablaufzeit > 0 DESC, prio DESC, zeitstempel",
        [\'user\' => (int) $user]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 18: UserEventNachrichten boxnachrichten with groups SELECT';
} else {
    $changes[] = 'Fix 18: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 19: UserEventNachrichten - boxnachrichten without groups (line ~1718)
// -----------------------------------------------------------------------
$old = '      $nachrichten = $this->app->DB->SelectArr("SELECT b.* FROM boxnachrichten b WHERE user = \'$user\' AND (ablaufzeit = 0 OR
      TIME_TO_SEC(
       TIMEDIFF(
        now(),
        zeitstempel
       ) <= ablaufzeit
      )
      ) ORDER BY ablaufzeit > 0 DESC, prio DESC, zeitstempel");';
$new = '      $nachrichten = $this->app->DatabaseService->select(
        "SELECT b.* FROM boxnachrichten b WHERE user = :user AND (ablaufzeit = 0 OR TIME_TO_SEC(TIMEDIFF(now(), zeitstempel)) <= ablaufzeit) ORDER BY ablaufzeit > 0 DESC, prio DESC, zeitstempel",
        [\'user\' => (int) $user]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 19: UserEventNachrichten boxnachrichten no-groups SELECT';
} else {
    $changes[] = 'Fix 19: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 20: UserEventNachrichten - DELETE boxnachrichten (line ~1729)
// -----------------------------------------------------------------------
$old = '        $this->app->DB->Delete("DELETE from boxnachrichten WHERE id = \'" . $nachrichten[0][\'id\'] . "\' LIMIT 1");';
$new = '        $this->app->DatabaseService->execute(
          "DELETE FROM boxnachrichten WHERE id = :id LIMIT 1",
          [\'id\' => (int) $nachrichten[0][\'id\']]
        );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 20: UserEventNachrichten DELETE boxnachrichten';
} else {
    $changes[] = 'Fix 20: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 21: AddUserGruppenNachricht - DELETE boxnachrichten by objekt/parameter
// -----------------------------------------------------------------------
$old = '        $this->app->DB->Delete("DELETE FROM boxnachrichten WHERE objekt like \'" . $objekt . "\' AND parameter = \'$parameter\' ");';
$new = '        $this->app->DatabaseService->execute(
          "DELETE FROM boxnachrichten WHERE objekt LIKE :objekt AND parameter = :parameter",
          [\'objekt\' => (string) $objekt, \'parameter\' => (int) $parameter]
        );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 21: AddUserGruppenNachricht DELETE by objekt/parameter';
} else {
    $changes[] = 'Fix 21: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 22: AddUserGruppenNachricht - DELETE by id
// -----------------------------------------------------------------------
$old = '      $this->app->DB->Delete("DELETE FROM boxnachrichten WHERE id = \'$delete\' LIMIT 1");';
$new = '      $this->app->DatabaseService->execute(
        "DELETE FROM boxnachrichten WHERE id = :id LIMIT 1",
        [\'id\' => (int) $delete]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 22: AddUserGruppenNachricht DELETE by id';
} else {
    $changes[] = 'Fix 22: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 23: GetTplMessage - $id interpolated (user is already cast to int) (line ~3397)
// -----------------------------------------------------------------------
$old = '    return $this->app->DB->Select("SELECT `message` FROM `templatemessage` WHERE `user` = \'$user\' AND id = \'$id\' LIMIT 1");';
$new = '    return $this->app->DatabaseService->selectValue(
      "SELECT `message` FROM `templatemessage` WHERE `user` = :user AND id = :id LIMIT 1",
      [\'user\' => (int) $user, \'id\' => (int) $id]
    );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 23: GetTplMessage SELECT';
} else {
    $changes[] = 'Fix 23: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 24: UserEvent chat queries - $userId and $registrierDatum (line ~1655)
// -----------------------------------------------------------------------
$old = '      $registrierDatum = $this->app->DB->Select("SELECT u.logdatei FROM `user` AS u WHERE u.id=\'" . $userId . "\'");';
$new = '      $registrierDatum = $this->app->DatabaseService->selectValue(
        "SELECT u.logdatei FROM `user` AS u WHERE u.id = :id",
        [\'id\' => (int) $userId]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 24: UserEvent logdatei SELECT';
} else {
    $changes[] = 'Fix 24: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 25: UserEvent chat unread queries - $userId (line ~1657-1671)
// -----------------------------------------------------------------------
$old = '      $ungelesenOeffentlich = (int) $this->app->DB->Select(
        "SELECT COUNT(c.id)
          FROM chat AS c
          LEFT JOIN chat_gelesen AS g ON c.id = g.message AND (g.user = \'" . $userId . "\' OR g.user = 0)
          WHERE c.user_to=\'0\' AND c.zeitstempel > \'" . $registrierDatum . "\'
          AND g.id IS NULL"
      );
      $ungelesenPrivat = (int) $this->app->DB->Select(
        "SELECT COUNT(c.id)
          FROM chat AS c
          INNER JOIN `user` AS u ON c.user_from = u.id
          LEFT JOIN chat_gelesen AS g ON c.id = g.message
          WHERE u.activ = 1 AND c.user_to=\'" . $userId . "\'
          AND g.id IS NULL"
      );';
$new = '      $ungelesenOeffentlich = (int) $this->app->DatabaseService->selectValue(
        "SELECT COUNT(c.id) FROM chat AS c LEFT JOIN chat_gelesen AS g ON c.id = g.message AND (g.user = :user OR g.user = 0) WHERE c.user_to = \'0\' AND c.zeitstempel > :datum AND g.id IS NULL",
        [\'user\' => (int) $userId, \'datum\' => (string) $registrierDatum]
      );
      $ungelesenPrivat = (int) $this->app->DatabaseService->selectValue(
        "SELECT COUNT(c.id) FROM chat AS c INNER JOIN `user` AS u ON c.user_from = u.id LEFT JOIN chat_gelesen AS g ON c.id = g.message WHERE u.activ = 1 AND c.user_to = :user AND g.id IS NULL",
        [\'user\' => (int) $userId]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 25: UserEvent chat unread queries';
} else {
    $changes[] = 'Fix 25: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 26: BelegVersand - $typ and $id in multiple SELECTs (line ~1877-1895)
// -----------------------------------------------------------------------
$old = '    $projekt = $this->app->DB->Select("SELECT projekt FROM $typ WHERE id=\'$id\' LIMIT 1");
    $name = $this->app->DB->Select("SELECT name FROM $typ WHERE id=\'$id\' LIMIT 1");
    $email = $this->app->DB->Select("SELECT email FROM $typ WHERE id=\'$id\' LIMIT 1");
    $adresse = $this->app->DB->Select("SELECT adresse FROM $typ WHERE id=\'$id\' LIMIT 1");

    // pruefe ob immer per per Papier oder immer per mail
    $rechnung_papier = $this->app->DB->Select("SELECT rechnung_papier FROM adresse WHERE id=\'$adresse\' LIMIT 1");
    $rechnung_permail = $this->app->DB->Select("SELECT rechnung_permail FROM adresse WHERE id=\'$adresse\' LIMIT 1");
    $rechnung_anzahlpapier = $this->app->DB->Select("SELECT rechnung_anzahlpapier FROM adresse WHERE id=\'$adresse\' LIMIT 1");';
$new = '    $belegRow = $this->app->DatabaseService->selectRow(
      sprintf("SELECT projekt, name, email, adresse, sprache FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($typ)),
      [\'id\' => (int) $id]
    );
    $projekt = $belegRow[\'projekt\'] ?? \'\';
    $name = $belegRow[\'name\'] ?? \'\';
    $email = $belegRow[\'email\'] ?? \'\';
    $adresse = $belegRow[\'adresse\'] ?? 0;

    // pruefe ob immer per per Papier oder immer per mail
    $adresseRow = $this->app->DatabaseService->selectRow(
      "SELECT rechnung_papier, rechnung_permail, rechnung_anzahlpapier FROM adresse WHERE id = :id LIMIT 1",
      [\'id\' => (int) $adresse]
    );
    $rechnung_papier = $adresseRow[\'rechnung_papier\'] ?? \'\';
    $rechnung_permail = $adresseRow[\'rechnung_permail\'] ?? \'\';
    $rechnung_anzahlpapier = $adresseRow[\'rechnung_anzahlpapier\'] ?? \'\';';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 26: BelegVersand projekt/name/email/adresse/papier SELECTs';
} else {
    $changes[] = 'Fix 26: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 27: BelegVersand - sprache SELECT (line ~1893-1895) - only if not already handled by fix 26
// -----------------------------------------------------------------------
$old = '    $sprache = $this->app->DB->Select("SELECT sprache FROM $typ WHERE id=\'$id\' LIMIT 1");
    if ($sprache == "")
      $sprache = $this->app->DB->Select("SELECT sprache FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");';
$new = '    $sprache = $belegRow[\'sprache\'] ?? \'\';
    if ($sprache == "")
      $sprache = $this->app->DatabaseService->selectValue(
        "SELECT sprache FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1",
        [\'id\' => (int) $adresse]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 27: BelegVersand sprache SELECT';
} else {
    $changes[] = 'Fix 27: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 28: GetSpracheBeleg - $id in SELECT fallbacks (line ~1837-1840)
// -----------------------------------------------------------------------
$old = '    $sprache = !empty($docArr) ? $docArr[\'sprache\'] : $this->app->DB->Select("SELECT sprache FROM $type WHERE id=\'$id\' LIMIT 1");
    if ($sprache == \'\') {
      $adresse = !empty($docArr) ? $docArr[\'adresse\'] : $this->app->DB->Select("SELECT adresse FROM $type WHERE id=\'$id\' LIMIT 1");
      $sprache = $this->app->DB->Select("SELECT sprache FROM adresse WHERE id=\'$adresse\' AND geloescht=0 LIMIT 1");';
$new = '    $sprache = !empty($docArr) ? $docArr[\'sprache\'] : $this->app->DatabaseService->selectValue(
      sprintf("SELECT sprache FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($type)),
      [\'id\' => (int) $id]
    );
    if ($sprache == \'\') {
      $adresse = !empty($docArr) ? $docArr[\'adresse\'] : $this->app->DatabaseService->selectValue(
        sprintf("SELECT adresse FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($type)),
        [\'id\' => (int) $id]
      );
      $sprache = $this->app->DatabaseService->selectValue(
        "SELECT sprache FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1",
        [\'id\' => (int) $adresse]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 28: GetSpracheBeleg fallback SELECTs';
} else {
    $changes[] = 'Fix 28: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 29: GetSpracheBelegISO - $language in SELECT (line ~1861)
// -----------------------------------------------------------------------
$old = '      $language = $this->app->DB->Select("SELECT iso FROM sprachen WHERE alias=\'$language\' ORDER by aktiv=1 LIMIT 1");';
$new = '      $language = $this->app->DatabaseService->selectValue(
        "SELECT iso FROM sprachen WHERE alias = :alias ORDER BY aktiv = 1 LIMIT 1",
        [\'alias\' => (string) $language]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 29: GetSpracheBelegISO sprachen SELECT';
} else {
    $changes[] = 'Fix 29: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 30: GetZahlungsweiseText - $zahlungsweise and $projekt in SELECT (line ~3697)
// -----------------------------------------------------------------------
$old = '    $zahlungsweiseid = $this->app->DB->SelectRow("SELECT id, modul,freitext FROM zahlungsweisen WHERE type = \'$zahlungsweise\' AND aktiv = 1 AND geloescht = 0 AND (projekt = 0 OR projekt = \'$projekt\') ORDER BY projekt = \'$projekt\' DESC LIMIT 1");';
$new = '    $zahlungsweiseid = $this->app->DatabaseService->selectRow(
      "SELECT id, modul, freitext FROM zahlungsweisen WHERE type = :type AND aktiv = 1 AND geloescht = 0 AND (projekt = 0 OR projekt = :projekt) ORDER BY projekt = :projekt2 DESC LIMIT 1",
      [\'type\' => (string) $zahlungsweise, \'projekt\' => (int) $projekt, \'projekt2\' => (int) $projekt]
    );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 30: Zahlungsweise zahlungsweisen SELECT';
} else {
    $changes[] = 'Fix 30: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 31: GetZahlungsweiseText - $adresse sprache SELECT (line ~3746)
// -----------------------------------------------------------------------
$old = '    $sprache = !empty($doctypeRow[\'sprache\']) ? $doctypeRow[\'sprache\'] : $this->app->DB->Select("SELECT sprache FROM adresse WHERE id=\'$adresse\' LIMIT 1");';
$new = '    $sprache = !empty($doctypeRow[\'sprache\']) ? $doctypeRow[\'sprache\'] : $this->app->DatabaseService->selectValue(
      "SELECT sprache FROM adresse WHERE id = :id LIMIT 1",
      [\'id\' => (int) $adresse]
    );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 31: GetZahlungsweiseText adresse sprache SELECT';
} else {
    $changes[] = 'Fix 31: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 32: ParseUserVars - $type and $id in SELECT (line ~3946)
// -----------------------------------------------------------------------
$old = '    $result = $this->app->DB->SelectArr("SELECT * FROM $type WHERE id=\'$id\' LIMIT 1");';
$new = '    $result = $this->app->DatabaseService->select(
      sprintf("SELECT * FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($type)),
      [\'id\' => (int) $id]
    );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 32: ParseUserVars type/id SELECT';
} else {
    $changes[] = 'Fix 32: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 33: ParseUserVars - projekt SELECT (line ~3955)
// -----------------------------------------------------------------------
$old = '      $projektArr = $this->app->DB->SelectRow("SELECT abkuerzung, name FROM projekt WHERE id=\'" . $result[0][\'projekt\'] . "\' LIMIT 1");';
$new = '      $projektArr = $this->app->DatabaseService->selectRow(
        "SELECT abkuerzung, name FROM projekt WHERE id = :id LIMIT 1",
        [\'id\' => (int) $result[0][\'projekt\']]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 33: ParseUserVars projekt SELECT';
} else {
    $changes[] = 'Fix 33: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 34: ParseUserVars - versandarten SELECTs (line ~3977-3979)
// -----------------------------------------------------------------------
$old = '      $versandartbezeichnung = $this->Beschriftung("versandart_bezeichnung_" . $this->app->DB->Select("SELECT id FROM versandarten WHERE type=\'" . $result[0][\'versandart\'] . "\' LIMIT 1"));
      if ($versandartbezeichnung == "") {
        $versandartbezeichnung = $this->app->DB->Select("SELECT bezeichnung FROM versandarten WHERE type=\'" . $result[0][\'versandart\'] . "\' LIMIT 1");';
$new = '      $versandartRow = $this->app->DatabaseService->selectRow(
        "SELECT id, bezeichnung FROM versandarten WHERE type = :type LIMIT 1",
        [\'type\' => (string) $result[0][\'versandart\']]
      );
      $versandartbezeichnung = !empty($versandartRow) ? $this->Beschriftung("versandart_bezeichnung_" . $versandartRow[\'id\']) : \'\';
      if ($versandartbezeichnung == "") {
        $versandartbezeichnung = !empty($versandartRow) ? $versandartRow[\'bezeichnung\'] : \'\';';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 34: ParseUserVars versandarten SELECT';
} else {
    $changes[] = 'Fix 34: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 35: ParseUserVars - produktion kundenname (line ~3985)
// -----------------------------------------------------------------------
$old = '      $result[0][\'kundenname\'] = $this->app->DB->Select("SELECT a.name as kundenname FROM produktion p LEFT JOIN adresse a ON a.id=p.adresse WHERE p.id=\'$id\' LIMIT 1");';
$new = '      $result[0][\'kundenname\'] = $this->app->DatabaseService->selectValue(
        "SELECT a.name as kundenname FROM produktion p LEFT JOIN adresse a ON a.id = p.adresse WHERE p.id = :id LIMIT 1",
        [\'id\' => (int) $id]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 35: ParseUserVars produktion kundenname SELECT';
} else {
    $changes[] = 'Fix 35: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 36: ParseUserVars - gutschrift rechnungid SELECTs (line ~3991-3992)
// -----------------------------------------------------------------------
$old = '      $result[0][\'auftragid\'] = $this->app->DB->Select("SELECT auftragid FROM rechnung WHERE id=\'" . $rechnungid . "\' LIMIT 1");
      $result[0][\'rechnung\'] = $this->app->DB->Select("SELECT belegnr FROM rechnung WHERE id=\'" . $rechnungid . "\' LIMIT 1");';
$new = '      $rechnungRow = $this->app->DatabaseService->selectRow(
        "SELECT auftragid, belegnr FROM rechnung WHERE id = :id LIMIT 1",
        [\'id\' => (int) $rechnungid]
      );
      $result[0][\'auftragid\'] = $rechnungRow[\'auftragid\'] ?? \'\';
      $result[0][\'rechnung\'] = $rechnungRow[\'belegnr\'] ?? \'\';';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 36: ParseUserVars gutschrift rechnung SELECT';
} else {
    $changes[] = 'Fix 36: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 37: ParseUserVars - auftragArr SELECT (line ~3996)
// -----------------------------------------------------------------------
$old = '      $auftragArr = $this->app->DB->SelectRow("SELECT a.*, DATE_FORMAT(datum,\'%d.%m.%Y\') as datum_de FROM auftrag AS a WHERE id=\'" . $result[0][\'auftragid\'] . "\' LIMIT 1");';
$new = '      $auftragArr = $this->app->DatabaseService->selectRow(
        "SELECT a.*, DATE_FORMAT(datum, \'%d.%m.%Y\') as datum_de FROM auftrag AS a WHERE id = :id LIMIT 1",
        [\'id\' => (int) $result[0][\'auftragid\']]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 37: ParseUserVars auftragArr SELECT';
} else {
    $changes[] = 'Fix 37: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 38: ParseUserVars - skonto SELECT (line ~4019)
// -----------------------------------------------------------------------
$old = '      $skonto = $this->app->DB->Select("SELECT (gesamtsumme/100)*zahlungszielskonto FROM $type WHERE id=\'" . $id . "\' LIMIT 1");';
$new = '      $skonto = $this->app->DatabaseService->selectValue(
        sprintf("SELECT (gesamtsumme/100)*zahlungszielskonto FROM `%s` WHERE id = :id LIMIT 1", $this->app->DatabaseService->validateIdentifier($type)),
        [\'id\' => (int) $id]
      );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 38: ParseUserVars skonto SELECT';
} else {
    $changes[] = 'Fix 38: NOT FOUND';
}

// -----------------------------------------------------------------------
// Fix 39: ParseUserVars - lieferadressen hinweis SELECT (line ~4030)
// -----------------------------------------------------------------------
$old = '        $lieferhinweis = $this->app->DB->Select("SELECT hinweis FROM lieferadressen WHERE id=\'" . $result[0][\'lieferid\'] . "\' LIMIT 1");';
$new = '        $lieferhinweis = $this->app->DatabaseService->selectValue(
          "SELECT hinweis FROM lieferadressen WHERE id = :id LIMIT 1",
          [\'id\' => (int) $result[0][\'lieferid\']]
        );';
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    $changes[] = 'Fix 39: ParseUserVars lieferadressen hinweis SELECT';
} else {
    $changes[] = 'Fix 39: NOT FOUND';
}

// -----------------------------------------------------------------------
// Write the result
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
