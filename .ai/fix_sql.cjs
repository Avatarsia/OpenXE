const fs = require('fs');
const path = 'C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php';
let content = fs.readFileSync(path, 'utf8');

let fixCount = 0;

function fix(label, oldStr, newStr) {
  const oldCRLF = oldStr.replace(/\n/g, '\r\n');
  const newCRLF = newStr.replace(/\n/g, '\r\n');
  if (content.includes(oldCRLF)) {
    content = content.replace(oldCRLF, newCRLF);
    console.log(`${label}: OK`);
    fixCount++;
  } else if (content.includes(oldStr)) {
    content = content.replace(oldStr, newStr);
    console.log(`${label}: OK (LF)`);
    fixCount++;
  } else {
    console.log(`${label}: NOT FOUND`);
  }
}

// Fix 1: bankverbindung block
fix('bankverbindung',
`    if ($shopexportArr['lastschriftdatenueberschreiben'] && isset($warenkorb['bankverbindung']) && is_array($warenkorb['bankverbindung'])) {
      $anweisung = array();
      if (isset($warenkorb['bankverbindung']['inhaber'])) {
        $anweisung[] = "inhaber='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['inhaber']) . "'";
      }
      if (isset($warenkorb['bankverbindung']['bank'])) {
        $anweisung[] = "bank='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['bank']) . "'";
      }
      if (isset($warenkorb['bankverbindung']['iban'])) {
        $anweisung[] = "iban='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['iban']) . "'";
      }
      if (isset($warenkorb['bankverbindung']['bic'])) {
        $anweisung[] = "swift='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['bic']) . "'";
      }

      if (isset($warenkorb['bankverbindung']['firmensepa'])) {
        $anweisung[] = "firmensepa='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['firmensepa']) . "'";
      }
      if (isset($warenkorb['bankverbindung']['mandatsreferenzhinweis'])) {
        $anweisung[] = "mandatsreferenzhinweis='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['mandatsreferenzhinweis']) . "'";
      }
      if (isset($warenkorb['bankverbindung']['mandatsreferenzdatum'])) {
        $anweisung[] = "mandatsreferenzdatum='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['mandatsreferenzdatum']) . "'";
      }
      if (isset($warenkorb['bankverbindung']['mandatsreferenz'])) {
        $anweisung[] = "mandatsreferenz='" . $this->app->DB->real_escape_string($warenkorb['bankverbindung']['mandatsreferenz']) . "'";
      }

      if ((!empty($anweisung) ? count($anweisung) : 0) > 0) {
        // $anweisung entries use real_escape_string above; this dynamic SET is safe
        $this->app->DB->Update('UPDATE adresse SET ' . implode(', ', $anweisung) . ' WHERE id = ' . (int) $adresse);
      }
    }`,
`    if ($shopexportArr['lastschriftdatenueberschreiben'] && isset($warenkorb['bankverbindung']) && is_array($warenkorb['bankverbindung'])) {
      $bvSetParts = [];
      $bvParams   = ['adresse' => (int) $adresse];
      $bvMap = [
        'inhaber'                => 'inhaber',
        'bank'                   => 'bank',
        'iban'                   => 'iban',
        'bic'                    => 'swift',
        'firmensepa'             => 'firmensepa',
        'mandatsreferenzhinweis' => 'mandatsreferenzhinweis',
        'mandatsreferenzdatum'   => 'mandatsreferenzdatum',
        'mandatsreferenz'        => 'mandatsreferenz',
      ];
      foreach ($bvMap as $wkKey => $dbCol) {
        if (isset($warenkorb['bankverbindung'][$wkKey])) {
          $bvSetParts[] = "$dbCol = :bv_{$dbCol}";
          $bvParams["bv_{$dbCol}"] = $warenkorb['bankverbindung'][$wkKey];
        }
      }
      if (!empty($bvSetParts)) {
        $this->app->DatabaseService->execute(
          'UPDATE adresse SET ' . implode(', ', $bvSetParts) . ' WHERE id = :adresse',
          $bvParams
        );
      }
    }`
);

// Fix 2: versandartenmapping lieferung vars + SelectArr
fix('versandarten_lieferung_vars',
`      $lieferung1 = $this->app->DB->real_escape_string($warenkorb['lieferung']);
      $lieferung2 = $this->app->DB->real_escape_string($this->app->erp->ConvertForDBUTF8($warenkorb['lieferung']));
      if ($lieferung2 !== '' && $lieferung2 != $lieferung1) {
        $extrasel .= " OR versandart_shop = '$lieferung2' ";
      }
      $lieferung3 = $this->app->DB->real_escape_string($this->app->erp->ConvertForDB($warenkorb['lieferung']));
      if ($lieferung3 !== '' && $lieferung3 != $lieferung1) {
        $extrasel .= " OR versandart_shop = '$lieferung3' ";
      }
      $versandarten = $this->app->DB->SelectArr("SELECT * FROM shopexport_versandarten WHERE aktiv = 1 AND shop = '$shop' AND versandart_wawision <> '' AND (versandart_shop = '" . $this->app->DB->real_escape_string($warenkorb['lieferung']) . "' $extrasel)");`,
`      $lieferung1 = $warenkorb['lieferung'];
      $lieferung2 = $this->app->erp->ConvertForDBUTF8($warenkorb['lieferung']);
      $lieferung3 = $this->app->erp->ConvertForDB($warenkorb['lieferung']);
      $_vsParams = ['shop' => (int) $shop, 'lief1' => $lieferung1];
      $_vsWhere = "versandart_shop = :lief1";
      if ($lieferung2 !== '' && $lieferung2 !== $lieferung1) { $_vsWhere .= " OR versandart_shop = :lief2"; $_vsParams['lief2'] = $lieferung2; }
      if ($lieferung3 !== '' && $lieferung3 !== $lieferung1) { $_vsWhere .= " OR versandart_shop = :lief3"; $_vsParams['lief3'] = $lieferung3; }
      $versandarten = $this->app->DatabaseService->select("SELECT * FROM shopexport_versandarten WHERE aktiv = 1 AND shop = :shop AND versandart_wawision <> '' AND ($_vsWhere)", $_vsParams);`
);

// Fix 3: checkversand block
fix('checkversand',
`        $checkversand = $this->app->DB->Select("SELECT id FROM shopexport_versandarten WHERE shop = '$shop' AND versandart_shop = '" . $this->app->DB->real_escape_string($warenkorb['lieferung']) . "' LIMIT 1");
        if (!$checkversand) {
          $this->app->DB->Insert("INSERT INTO shopexport_versandarten (shop, versandart_shop, aktiv) values ('$shop','" . $this->app->DB->real_escape_string($warenkorb['lieferung']) . "',0)");
        }`,
`        $checkversand = $this->app->DatabaseService->selectValue("SELECT id FROM shopexport_versandarten WHERE shop = :shop AND versandart_shop = :lieferung LIMIT 1", ['shop' => (int) $shop, 'lieferung' => $warenkorb['lieferung']]);
        if (!$checkversand) {
          $this->app->DatabaseService->execute("INSERT INTO shopexport_versandarten (shop, versandart_shop, aktiv) VALUES (:shop, :lieferung, 0)", ['shop' => (int) $shop, 'lieferung' => $warenkorb['lieferung']]);
        }`
);

// Fix 4: zahlungsweise vars + SelectArr
fix('zahlungsweise_vars',
`      $zahlungsweise1 = $this->app->DB->real_escape_string($warenkorb['zahlungsweise']);
      $zahlungsweise2 = $this->app->DB->real_escape_string($this->app->erp->ConvertForDBUTF8($warenkorb['zahlungsweise']));
      if ($zahlungsweise2 !== '' && $zahlungsweise2 != $zahlungsweise1)
        $extrasel .= " OR zahlweise_shop = '$zahlungsweise2' ";
      $zahlungsweise3 = $this->app->DB->real_escape_string($this->app->erp->ConvertForDB($warenkorb['zahlungsweise']));
      if ($zahlungsweise3 !== '' && $zahlungsweise3 != $zahlungsweise1)
        $extrasel .= " OR zahlweise_shop = '$zahlungsweise3' ";
      $shopexport_zahlweisen = $this->app->DB->SelectArr("SELECT * FROM shopexport_zahlweisen WHERE shop='$shop' AND aktiv = 1 AND zahlweise_wawision <> '' AND (zahlweise_shop = '" . $this->app->DB->real_escape_string($warenkorb['zahlungsweise']) . "' $extrasel )");`,
`      $zahlungsweise1 = $warenkorb['zahlungsweise'];
      $zahlungsweise2 = $this->app->erp->ConvertForDBUTF8($warenkorb['zahlungsweise']);
      $zahlungsweise3 = $this->app->erp->ConvertForDB($warenkorb['zahlungsweise']);
      $_zwParams = ['shop' => (int) $shop, 'zw1' => $zahlungsweise1];
      $_zwWhere = "zahlweise_shop = :zw1";
      if ($zahlungsweise2 !== '' && $zahlungsweise2 !== $zahlungsweise1) { $_zwWhere .= " OR zahlweise_shop = :zw2"; $_zwParams['zw2'] = $zahlungsweise2; }
      if ($zahlungsweise3 !== '' && $zahlungsweise3 !== $zahlungsweise1) { $_zwWhere .= " OR zahlweise_shop = :zw3"; $_zwParams['zw3'] = $zahlungsweise3; }
      $shopexport_zahlweisen = $this->app->DatabaseService->select("SELECT * FROM shopexport_zahlweisen WHERE shop = :shop AND aktiv = 1 AND zahlweise_wawision <> '' AND ($_zwWhere)", $_zwParams);`
);

// Fix 5: 2nd zahlweisen SelectArr + checkzahl + insert
fix('shopexport_zahlweisen2',
`        $shopexport_zahlweisen = $this->app->DB->SelectArr("SELECT * FROM shopexport_zahlweisen WHERE aktiv = 1 AND shop='$shop' AND zahlweise_shop = '" . $this->app->DB->real_escape_string($warenkorb['zahlungsweise']) . "'");
        if (!$shopexport_zahlweisen) {
          $checkzahl = $this->app->DB->Select("SELECT id FROM shopexport_zahlweisen WHERE shop = '$shop' and zahlweise_shop = '" . $this->app->DB->real_escape_string($warenkorb['zahlungsweise']) . "' LIMIT 1");
          if (!$checkzahl) {
            $this->app->DB->Insert("INSERT INTO shopexport_zahlweisen (shop, zahlweise_shop, aktiv) values ('$shop','" . $this->app->DB->real_escape_string($warenkorb['zahlungsweise']) . "',0)");
          }
        }`,
`        $shopexport_zahlweisen = $this->app->DatabaseService->select("SELECT * FROM shopexport_zahlweisen WHERE aktiv = 1 AND shop = :shop AND zahlweise_shop = :zw", ['shop' => (int) $shop, 'zw' => $warenkorb['zahlungsweise']]);
        if (!$shopexport_zahlweisen) {
          $checkzahl = $this->app->DatabaseService->selectValue("SELECT id FROM shopexport_zahlweisen WHERE shop = :shop AND zahlweise_shop = :zw LIMIT 1", ['shop' => (int) $shop, 'zw' => $warenkorb['zahlungsweise']]);
          if (!$checkzahl) {
            $this->app->DatabaseService->execute("INSERT INTO shopexport_zahlweisen (shop, zahlweise_shop, aktiv) VALUES (:shop, :zw, 0)", ['shop' => (int) $shop, 'zw' => $warenkorb['zahlungsweise']]);
          }
        }`
);

// Fix 6: vertrieb/vertriebid raw UPDATEs
fix('vertrieb_raw_updates',
`    if (isset($warenkorb['vertrieb'])) {
      $this->app->DB->Update("UPDATE $doctype SET vertrieb = '" . $warenkorb['vertrieb'] . "' WHERE id = '$auftrag' LIMIT 1");
    }
    if (isset($warenkorb['vertriebid']) && $warenkorb['vertriebid'] > 0) {
      $this->app->DB->Update("UPDATE $doctype SET vertriebid = '" . $warenkorb['vertriebid'] . "' WHERE id = '$auftrag' LIMIT 1");
    }`,
`    if (isset($warenkorb['vertrieb'])) {
      $_safeDTvert = $this->app->DatabaseService->validateIdentifier($doctype);
      $this->app->DatabaseService->execute("UPDATE \`{$_safeDTvert}\` SET vertrieb = :vertrieb WHERE id = :id LIMIT 1", ['vertrieb' => $warenkorb['vertrieb'], 'id' => (int) $auftrag]);
    }
    if (isset($warenkorb['vertriebid']) && $warenkorb['vertriebid'] > 0) {
      $_safeDTvertid = $this->app->DatabaseService->validateIdentifier($doctype);
      $this->app->DatabaseService->execute("UPDATE \`{$_safeDTvertid}\` SET vertriebid = :vertriebid WHERE id = :id LIMIT 1", ['vertriebid' => (int) $warenkorb['vertriebid'], 'id' => (int) $auftrag]);
    }`
);

// Fix 7: voucher SelectRow + INSERT + UPDATE
fix('voucher_block',
`    if (isset($warenkorb['gutscheincode'])) {
      $voucher = $this->app->DB->SelectRow(sprintf("SELECT v.id,v.agent_address_id, a.name AS agent_name, v.commission_rate, v.voucher_code FROM voucher v
        JOIN adresse a ON a.id=v.agent_address_id
        WHERE v.voucher_code<>'' AND v.voucher_code='%s' AND (v.project_id=0 OR v.project_id='%s') AND a.geloescht<>1 LIMIT 1",
        $warenkorb['gutscheincode'],
        $projekt
      ));

      if (!empty($voucher['id'])) {
        $this->app->DB->Insert(sprintf("INSERT INTO commission_rate_receipt (doctype_id,doctype,commission_rate,notice)
            VALUES ('%s','%s','%s','%s')", $auftrag, $doctype, $voucher['commission_rate'], 'Kommission für Gutschein: ' . $voucher['voucher_code']));

        $this->app->DB->Update(sprintf(
          "UPDATE $doctype SET vertrieb='%s', vertriebid='%s' WHERE id=%s",
          $voucher['agent_name'],
          $voucher['agent_address_id'],
          $auftrag
        ));
      }
    }`,
`    if (isset($warenkorb['gutscheincode'])) {
      $voucher = $this->app->DatabaseService->selectRow(
        "SELECT v.id, v.agent_address_id, a.name AS agent_name, v.commission_rate, v.voucher_code
         FROM voucher v JOIN adresse a ON a.id = v.agent_address_id
         WHERE v.voucher_code <> '' AND v.voucher_code = :code AND (v.project_id = 0 OR v.project_id = :projekt) AND a.geloescht <> 1 LIMIT 1",
        ['code' => $warenkorb['gutscheincode'], 'projekt' => (int) $projekt]
      );

      if (!empty($voucher['id'])) {
        $this->app->DatabaseService->execute(
          "INSERT INTO commission_rate_receipt (doctype_id, doctype, commission_rate, notice) VALUES (:did, :dtype, :rate, :notice)",
          ['did' => (int) $auftrag, 'dtype' => $doctype, 'rate' => $voucher['commission_rate'], 'notice' => 'Kommission für Gutschein: ' . $voucher['voucher_code']]
        );
        $_safeDTvoucher = $this->app->DatabaseService->validateIdentifier($doctype);
        $this->app->DatabaseService->execute(
          "UPDATE \`{$_safeDTvoucher}\` SET vertrieb = :vertrieb, vertriebid = :vertriebid WHERE id = :id",
          ['vertrieb' => $voucher['agent_name'], 'vertriebid' => (int) $voucher['agent_address_id'], 'id' => (int) $auftrag]
        );
      }
    }`
);

// Fix 8: artikel loop queries (lines 18047-18110)
// artikelimporteinzelngesetzt
fix('artikelimporteinzelngesetzt',
`        $artikelimporteinzelngesetzt = $this->app->DB->Select("SELECT autoabgleicherlaubt FROM artikel WHERE nummer='{$value['articleid']}' AND projekt='$projekt' LIMIT 1");`,
`        $artikelimporteinzelngesetzt = $this->app->DatabaseService->selectValue("SELECT autoabgleicherlaubt FROM artikel WHERE nummer = :nummer AND projekt = :projekt LIMIT 1", ['nummer' => $value['articleid'], 'projekt' => (int) $projekt]);`
);

fix('artikelprojekt_multi',
`          $artikelprojekt = $this->app->DB->Select("SELECT projekt FROM artikel WHERE nummer='{$value['articleid']}' LIMIT 1");// AND //TODO BENE`,
`          $artikelprojekt = $this->app->DatabaseService->selectValue("SELECT projekt FROM artikel WHERE nummer = :nummer LIMIT 1", ['nummer' => $value['articleid']]); // AND //TODO BENE`
);

fix('artikelprojekt_single',
`          $artikelprojekt = $this->app->DB->Select("SELECT projekt FROM artikel WHERE nummer='{$value['articleid']}' AND projekt='$projekt' LIMIT 1");// AND //TODO BENE`,
`          $artikelprojekt = $this->app->DatabaseService->selectValue("SELECT projekt FROM artikel WHERE nummer = :nummer AND projekt = :projekt LIMIT 1", ['nummer' => $value['articleid'], 'projekt' => (int) $projekt]); // AND //TODO BENE`
);

fix('zwangsprojekt',
`        $zwangsprojekt = $this->app->DB->Select("SELECT shopzwangsprojekt FROM projekt WHERE id='$artikelprojekt' LIMIT 1");

        if ($zwangsprojekt == 1) {
          $this->app->DB->Update("UPDATE $doctype SET projekt='$artikelprojekt' WHERE id='$auftrag'");
        }`,
`        $zwangsprojekt = $this->app->DatabaseService->selectValue("SELECT shopzwangsprojekt FROM projekt WHERE id = :id LIMIT 1", ['id' => (int) $artikelprojekt]);

        if ($zwangsprojekt == 1) {
          $_safeDTzwang = $this->app->DatabaseService->validateIdentifier($doctype);
          $this->app->DatabaseService->execute("UPDATE \`{$_safeDTzwang}\` SET projekt = :projekt WHERE id = :id", ['projekt' => (int) $artikelprojekt, 'id' => (int) $auftrag]);
        }`
);

fix('eigenernummernkreis_import',
`        $eigenernummernkreis = $this->app->DB->Select("SELECT eigenernummernkreis FROM projekt WHERE id='$projekt' LIMIT 1");`,
`        $eigenernummernkreis = $this->app->DatabaseService->selectValue("SELECT eigenernummernkreis FROM projekt WHERE id = :id LIMIT 1", ['id' => (int) $projekt]);`
);

fix('j_id_fremdnummer',
`          $j_id = $this->app->DB->Select("SELECT a.id FROM artikelnummer_fremdnummern af INNER JOIN artikel a on af.artikel = a.id WHERE af.nummer='{$value['fremdnummer']}' AND af.aktiv = 1 AND af.nummer <> '' AND (a.projekt='$projekt' OR af.shopid = '$shop') AND a.nummer <> 'DEL' AND IFNULL(a.geloescht,0) = 0 ORDER BY af.shopid = '$shop' DESC,IFNULL(a.intern_gesperrt,0) = 0 DESC, af.id LIMIT 1");`,
`          $j_id = $this->app->DatabaseService->selectValue("SELECT a.id FROM artikelnummer_fremdnummern af INNER JOIN artikel a ON af.artikel = a.id WHERE af.nummer = :nummer AND af.aktiv = 1 AND af.nummer <> '' AND (a.projekt = :projekt OR af.shopid = :shop) AND a.nummer <> 'DEL' AND IFNULL(a.geloescht,0) = 0 ORDER BY af.shopid = :shop DESC, IFNULL(a.intern_gesperrt,0) = 0 DESC, af.id LIMIT 1", ['nummer' => $value['fremdnummer'], 'projekt' => (int) $projekt, 'shop' => (int) $shop]);`
);

fix('j_id_artikel_multi',
`            $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer='{$value['articleid']}' AND IFNULL(intern_gesperrt,0) = 0 AND IFNULL(geloescht,0) = 0 LIMIT 1");  //TODO BENE`,
`            $j_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nummer AND IFNULL(intern_gesperrt,0) = 0 AND IFNULL(geloescht,0) = 0 LIMIT 1", ['nummer' => $value['articleid']]); // TODO BENE`
);

fix('j_id_artikel_single',
`            $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer='{$value['articleid']}' AND IFNULL(intern_gesperrt,0) = 0 AND IFNULL(geloescht,0) = 0 AND projekt='$projekt' LIMIT 1");  //TODO BENE`,
`            $j_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nummer AND IFNULL(intern_gesperrt,0) = 0 AND IFNULL(geloescht,0) = 0 AND projekt = :projekt LIMIT 1", ['nummer' => $value['articleid'], 'projekt' => (int) $projekt]); // TODO BENE`
);

fix('j_id_hersteller_multi',
`            $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE herstellernummer='{$value['articleid']}' AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");  //TODO BENE`,
`            $j_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE herstellernummer = :nummer AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['nummer' => $value['articleid']]); // TODO BENE`
);

fix('j_id_hersteller_single',
`            $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE herstellernummer='{$value['articleid']}' AND nummer <> 'DEL' AND projekt='$projekt' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");  //TODO BENE`,
`            $j_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE herstellernummer = :nummer AND nummer <> 'DEL' AND projekt = :projekt AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['nummer' => $value['articleid'], 'projekt' => (int) $projekt]); // TODO BENE`
);

fix('j_id_ean_multi',
`            $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE ean='{$value['articleid']}' AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC  LIMIT 1");  //TODO BENE`,
`            $j_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE ean = :ean AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['ean' => $value['articleid']]); // TODO BENE`
);

fix('j_id_ean_single',
`            $j_id = $this->app->DB->Select("SELECT id FROM artikel WHERE ean='{$value['articleid']}' AND nummer <> 'DEL' AND projekt='$projekt' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC  LIMIT 1");  //TODO BENE`,
`            $j_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE ean = :ean AND nummer <> 'DEL' AND projekt = :projekt AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['ean' => $value['articleid'], 'projekt' => (int) $projekt]); // TODO BENE`
);

fix('check_verkaufspreise',
`          $check = $this->app->DB->Select("SELECT id FROM verkaufspreise WHERE artikel='$j_id'
              AND (gueltig_bis='0000-00-00' OR gueltig_bis >=NOW()) AND ab_menge=1
              AND ((objekt='Standard' AND adresse=0) OR (objekt='' AND adresse=0)) AND geloescht=0 LIMIT 1");`,
`          $check = $this->app->DatabaseService->selectValue("SELECT id FROM verkaufspreise WHERE artikel = :artikel AND (gueltig_bis = '0000-00-00' OR gueltig_bis >= NOW()) AND ab_menge = 1 AND ((objekt = 'Standard' AND adresse = 0) OR (objekt = '' AND adresse = 0)) AND geloescht = 0 LIMIT 1", ['artikel' => (int) $j_id]);`
);

// j_umsatzsteuer (both branches are identical)
fix('j_umsatzsteuer_1',
`          $j_umsatzsteuer = $this->app->DB->Select("SELECT umsatzsteuer FROM artikel WHERE id = '$j_id' LIMIT 1");
        } else {
          $j_umsatzsteuer = $this->app->DB->Select("SELECT umsatzsteuer FROM artikel WHERE id = '$j_id' LIMIT 1");`,
`          $j_umsatzsteuer = $this->app->DatabaseService->selectValue("SELECT umsatzsteuer FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $j_id]);
        } else {
          $j_umsatzsteuer = $this->app->DatabaseService->selectValue("SELECT umsatzsteuer FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $j_id]);`
);

// varj_id lookups
fix('varj_id_nummer_multi',
`              $varj_id = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer='{$value['variante_von']}' AND IFNULL(geloescht,0) = 0 AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1");`,
`              $varj_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nummer AND IFNULL(geloescht,0) = 0 AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1", ['nummer' => $value['variante_von']]);`
);

fix('varj_id_nummer_single',
`              $varj_id = $this->app->DB->Select("SELECT id FROM artikel WHERE nummer='{$value['variante_von']}' AND IFNULL(geloescht,0) = 0 AND projekt='$projekt' AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1");`,
`              $varj_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE nummer = :nummer AND IFNULL(geloescht,0) = 0 AND projekt = :projekt AND IFNULL(intern_gesperrt,0) = 0 LIMIT 1", ['nummer' => $value['variante_von'], 'projekt' => (int) $projekt]);`
);

fix('varj_id_hersteller_multi',
`                $varj_id = $this->app->DB->Select("SELECT id FROM artikel WHERE herstellernummer='{$value['variante_von']}' AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");`,
`                $varj_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE herstellernummer = :nummer AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['nummer' => $value['variante_von']]);`
);

fix('varj_id_hersteller_single',
`                $varj_id = $this->app->DB->Select("SELECT id FROM artikel WHERE herstellernummer='{$value['variante_von']}' AND nummer <> 'DEL' AND projekt='$projekt' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");`,
`                $varj_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE herstellernummer = :nummer AND nummer <> 'DEL' AND projekt = :projekt AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['nummer' => $value['variante_von'], 'projekt' => (int) $projekt]);`
);

fix('varj_id_ean_multi',
`                $varj_id = $this->app->DB->Select("SELECT id FROM artikel WHERE ean='{$value['variante_von']}' AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");`,
`                $varj_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE ean = :ean AND nummer <> 'DEL' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['ean' => $value['variante_von']]);`
);

fix('varj_id_ean_single',
`                $varj_id = $this->app->DB->Select("SELECT id FROM artikel WHERE ean='{$value['variante_von']}' AND nummer <> 'DEL' AND projekt='$projekt' AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1");`,
`                $varj_id = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE ean = :ean AND nummer <> 'DEL' AND projekt = :projekt AND IFNULL(geloescht,0) = 0 ORDER BY IFNULL(intern_gesperrt,0) = 0 DESC LIMIT 1", ['ean' => $value['variante_von'], 'projekt' => (int) $projekt]);`
);

fix('lagerartikel_varjid',
`              $warenkorb['articlelist'][$key]['lagerartikel'] = $this->app->DB->Select("SELECT lagerartikel FROM artikel WHERE id = '$varj_id' LIMIT 1");
              $generierenummerbeioption = $this->app->DB->Select("SELECT generierenummerbeioption from artikel where id = '$varj_id' LIMIT 1");`,
`              $warenkorb['articlelist'][$key]['lagerartikel'] = $this->app->DatabaseService->selectValue("SELECT lagerartikel FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $varj_id]);
              $generierenummerbeioption = $this->app->DatabaseService->selectValue("SELECT generierenummerbeioption FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $varj_id]);`
);

// position steuersatz updates
fix('steuersatz_ermaessigt_update',
`                $this->app->DB->Update("UPDATE $doctype" . "_position SET steuersatz = " . $warenkorb['steuersatz_ermaessigt'] . " WHERE id = '$ap' LIMIT 1");`,
`                $_safeDTpos1 = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
                $this->app->DatabaseService->execute("UPDATE \`{$_safeDTpos1}\` SET steuersatz = :steuersatz WHERE id = :id LIMIT 1", ['steuersatz' => (float) $warenkorb['steuersatz_ermaessigt'], 'id' => (int) $ap]);`
);

fix('steuersatz_normal_update',
`              if ($positionsteuersaetzeerlauben && !empty($warenkorb['steuersatz_normal']) && !isset($value['steuersatz'])) {
                $this->app->DB->Update("UPDATE $doctype" . "_position SET steuersatz = " . $warenkorb['steuersatz_normal'] . " WHERE id = '$ap' LIMIT 1");
              }`,
`              if ($positionsteuersaetzeerlauben && !empty($warenkorb['steuersatz_normal']) && !isset($value['steuersatz'])) {
                $_safeDTpos2 = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
                $this->app->DatabaseService->execute("UPDATE \`{$_safeDTpos2}\` SET steuersatz = :steuersatz WHERE id = :id LIMIT 1", ['steuersatz' => (float) $warenkorb['steuersatz_normal'], 'id' => (int) $ap]);
              }`
);

fs.writeFileSync(path, content, 'utf8');
console.log(`\nTotal fixes applied: ${fixCount}`);
