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

// Fix: positions SelectArr - the lines have trailing space after FROM (4 spaces indent before table names)
fix('positions_auftrag',
`        $positions = $this->app->DB->SelectArr(
          sprintf(
            "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer
            FROM auftrag_position
            WHERE preis > 0 AND auftrag = %d
            GROUP BY steuersatz, umsatzsteuer",
            $auftrag
          )
        );`,
`        $positions = $this->app->DatabaseService->select(
          "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer FROM auftrag_position WHERE preis > 0 AND auftrag = :auftrag GROUP BY steuersatz, umsatzsteuer",
          ['auftrag' => (int) $auftrag]
        );`
);

fix('positions_angebot',
`        $positions = $this->app->DB->SelectArr(
          sprintf(
            "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer
            FROM angebot_position
            WHERE preis > 0 AND angebot = %d
            GROUP BY steuersatz, umsatzsteuer",
            $auftrag
          )
        );`,
`        $positions = $this->app->DatabaseService->select(
          "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer FROM angebot_position WHERE preis > 0 AND angebot = :auftrag GROUP BY steuersatz, umsatzsteuer",
          ['auftrag' => (int) $auftrag]
        );`
);

// Fix nachnahme selects
fix('nachnahme_name_de',
`        $nachnahme = $this->app->DB->Select("SELECT name_de FROM artikel WHERE id='$artikelnachnahme' LIMIT 1");
        $umsatzsteuer_nachnahme = $this->app->DB->Select("SELECT umsatzsteuer FROM artikel WHERE id='$artikelnachnahme' LIMIT 1");`,
`        $nachnahme = $this->app->DatabaseService->selectValue("SELECT name_de FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $artikelnachnahme]);
        $umsatzsteuer_nachnahme = $this->app->DatabaseService->selectValue("SELECT umsatzsteuer FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $artikelnachnahme]);`
);

// Fix keinrabatterlaubt update (nachnahme)
fix('keinrabatterlaubt_nachnahme',
`            $this->app->DB->Update("UPDATE $doctype" . "_position SET keinrabatterlaubt=1 WHERE id='$tmpposid' LIMIT 1");
            if (isset($warenkorb['nachnahmepreisnetto']) || isset($warenkorb['nachnahmepreis'])) {`,
`            $_safeDTnach = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
            $this->app->DatabaseService->execute("UPDATE \`{$_safeDTnach}\` SET keinrabatterlaubt = 1 WHERE id = :id LIMIT 1", ['id' => (int) $tmpposid]);
            if (isset($warenkorb['nachnahmepreisnetto']) || isset($warenkorb['nachnahmepreis'])) {`
);

// Fix portoartikelanlegen select
fix('portoartikelanlegen',
`        if ($this->app->DB->Select("SELECT portoartikelanlegen FROM shopexport WHERE id = '$shop' LIMIT 1")) {`,
`        if ($this->app->DatabaseService->selectValue("SELECT portoartikelanlegen FROM shopexport WHERE id = :id LIMIT 1", ['id' => (int) $shop])) {`
);

// Fix shopexport UPDATE artikelporto
fix('shopexport_artikelporto',
`              $this->app->DB->Update("UPDATE shopexport SET artikelporto = '$artikelporto' WHERE id = '$shop' AND artikelporto = 0 LIMIT 1");`,
`              $this->app->DatabaseService->execute("UPDATE shopexport SET artikelporto = :artikelporto WHERE id = :id AND artikelporto = 0 LIMIT 1", ['artikelporto' => (int) $artikelporto, 'id' => (int) $shop]);`
);

// Fix umsatzsteuer_porto select
fix('umsatzsteuer_porto',
`      $umsatzsteuer_porto = $this->app->DB->Select("SELECT umsatzsteuer FROM artikel WHERE id='$artikelporto' LIMIT 1");`,
`      $umsatzsteuer_porto = $this->app->DatabaseService->selectValue("SELECT umsatzsteuer FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $artikelporto]);`
);

// Fix versandname english select block
fix('versandname_englisch',
`        if ($warenkorb['kunde_sprache'] === 'englisch') {
          $versandname = $this->app->DB->Select("SELECT name_en FROM artikel WHERE id = '$artikelporto'");
        }
      } else {
        if ($this->app->DB->Select("SELECT adr.id FROM $doctype auf INNER JOIN adresse adr ON auf.adresse = adr.id AND adr.sprache = 'englisch' WHERE auf.id = '$auftrag' LIMIT 1")) {
          $versandname = $this->app->DB->Select("SELECT name_en FROM artikel WHERE id = '$artikelporto'");
        }
      }
      if ($versandname === '') {
        $versandname = $this->app->DB->Select("SELECT name_de FROM artikel WHERE id = '$artikelporto'");
      }`,
`        if ($warenkorb['kunde_sprache'] === 'englisch') {
          $versandname = $this->app->DatabaseService->selectValue("SELECT name_en FROM artikel WHERE id = :id", ['id' => (int) $artikelporto]);
        }
      } else {
        $_safeDTlangvs = $this->app->DatabaseService->validateIdentifier($doctype);
        if ($this->app->DatabaseService->selectValue("SELECT adr.id FROM \`{$_safeDTlangvs}\` auf INNER JOIN adresse adr ON auf.adresse = adr.id AND adr.sprache = 'englisch' WHERE auf.id = :id LIMIT 1", ['id' => (int) $auftrag])) {
          $versandname = $this->app->DatabaseService->selectValue("SELECT name_en FROM artikel WHERE id = :id", ['id' => (int) $artikelporto]);
        }
      }
      if ($versandname === '') {
        $versandname = $this->app->DatabaseService->selectValue("SELECT name_de FROM artikel WHERE id = :id", ['id' => (int) $artikelporto]);
      }`
);

// Fix belegsprache select
fix('belegsprache_select',
`      $belegsprache = $this->app->DB->Select("SELECT sprache FROM $doctype WHERE id = '$auftrag'");`,
`      $_safeDTbl = $this->app->DatabaseService->validateIdentifier($doctype);
      $belegsprache = $this->app->DatabaseService->selectValue("SELECT sprache FROM \`{$_safeDTbl}\` WHERE id = :id", ['id' => (int) $auftrag]);`
);

// Fix uebersetztername porto select
fix('uebersetztername_porto',
`      $uebersetztername = $this->app->DB->Select("SELECT name FROM artikel_texte WHERE artikel='$artikelporto' AND sprache='$belegsprache' AND aktiv='1' AND (shop=0 OR shop='$shop') ORDER BY shop DESC LIMIT 1");`,
`      $uebersetztername = $this->app->DatabaseService->selectValue("SELECT name FROM artikel_texte WHERE artikel = :artikel AND sprache = :sprache AND aktiv = '1' AND (shop = 0 OR shop = :shop) ORDER BY shop DESC LIMIT 1", ['artikel' => (int) $artikelporto, 'sprache' => $belegsprache, 'shop' => (int) $shop]);`
);

// Fix rabattname englisch select block
fix('rabattname_englisch',
`        if ($warenkorb['kunde_sprache'] === 'englisch') {
          $rabattname = (String) $this->app->DB->Select("SELECT name_en FROM artikel WHERE id = '$rabattartikel'");
        }
      } else {
        if ($this->app->DB->Select("SELECT adr.id FROM $doctype auf INNER JOIN adresse adr ON auf.adresse = adr.id AND adr.sprache = 'englisch' WHERE auf.id = '$auftrag' LIMIT 1")) {
          $rabattname = (String) $this->app->DB->Select("SELECT name_en FROM artikel WHERE id = '$rabattartikel'");
        }
      }
      if ($rabattname === '') {
        $rabattname = $this->app->DB->Select("SELECT name_de FROM artikel WHERE id = '$rabattartikel'");
      }
      $uebersetztername = $this->app->DB->Select("SELECT name FROM artikel_texte WHERE artikel='$rabattartikel' AND sprache='$belegsprache' AND aktiv='1' AND (shop=0 OR shop='$shop') ORDER BY shop DESC LIMIT 1");`,
`        if ($warenkorb['kunde_sprache'] === 'englisch') {
          $rabattname = (String) $this->app->DatabaseService->selectValue("SELECT name_en FROM artikel WHERE id = :id", ['id' => (int) $rabattartikel]);
        }
      } else {
        $_safeDTlangr = $this->app->DatabaseService->validateIdentifier($doctype);
        if ($this->app->DatabaseService->selectValue("SELECT adr.id FROM \`{$_safeDTlangr}\` auf INNER JOIN adresse adr ON auf.adresse = adr.id AND adr.sprache = 'englisch' WHERE auf.id = :id LIMIT 1", ['id' => (int) $auftrag])) {
          $rabattname = (String) $this->app->DatabaseService->selectValue("SELECT name_en FROM artikel WHERE id = :id", ['id' => (int) $rabattartikel]);
        }
      }
      if ($rabattname === '') {
        $rabattname = $this->app->DatabaseService->selectValue("SELECT name_de FROM artikel WHERE id = :id", ['id' => (int) $rabattartikel]);
      }
      $uebersetztername = $this->app->DatabaseService->selectValue("SELECT name FROM artikel_texte WHERE artikel = :artikel AND sprache = :sprache AND aktiv = '1' AND (shop = 0 OR shop = :shop) ORDER BY shop DESC LIMIT 1", ['artikel' => (int) $rabattartikel, 'sprache' => $belegsprache, 'shop' => (int) $shop]);`
);

// Fix umsatzsteuer_rabatt + rabattsteuer selects
fix('umsatzsteuer_rabattsteuer',
`      $umsatzsteuer_rabatt = $this->app->DB->Select("SELECT umsatzsteuer FROM artikel WHERE id='$rabattartikel' LIMIT 1");


      $rabattsteuer = $this->app->DB->Select("SELECT artikelrabattsteuer FROM shopexport WHERE id = '$shop' LIMIT 1");
      if ($this->app->DB->error()) {`,
`      $umsatzsteuer_rabatt = $this->app->DatabaseService->selectValue("SELECT umsatzsteuer FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $rabattartikel]);


      $rabattsteuer = $this->app->DatabaseService->selectValue("SELECT artikelrabattsteuer FROM shopexport WHERE id = :id LIMIT 1", ['id' => (int) $shop]);
      if ($this->app->DB->error()) {`
);

// Fix rabattsteuer position UPDATE
fix('rabattsteuer_pos_update',
`      if ($rabattsteuer >= 0) {
        $this->app->DB->Update("UPDATE $doctype" . "_position SET steuersatz = '$rabattsteuer' WHERE id = '$tmpposid' LIMIT 1");
      }
      if (isset($warenkorb['rabattsteuer']) && is_numeric($warenkorb['rabattsteuer'])) {
        $this->app->DB->Update("UPDATE $doctype" . "_position SET steuersatz = '" . $warenkorb['rabattsteuer'] . "' WHERE id = '$tmpposid' LIMIT 1");
        if ($warenkorb['rabattsteuer'] > 0) {
          $ermaessigt = !empty($warenkorb['steuersatz_ermaessigt']) ? $warenkorb['steuersatz_ermaessigt'] : $this->app->DB->Select("SELECT steuersatz_ermaessigt FROM $doctype WHERE id = $auftrag");
          $normal = !empty($warenkorb['steuersatz_normal']) ? $warenkorb['steuersatz_normal'] : $this->app->DB->Select("SELECT steuersatz_normal FROM $doctype WHERE id = $auftrag");
          if ($warenkorb['rabattsteuer'] == $ermaessigt) {
            $this->app->DB->Update("UPDATE $doctype" . "_position SET umsatzsteuer = 'ermaessigt' WHERE id = '$tmpposid' LIMIT 1");
          } elseif ($warenkorb['rabattsteuer'] == $normal) {
            $this->app->DB->Update("UPDATE $doctype" . "_position SET umsatzsteuer = 'normal' WHERE id = '$tmpposid' LIMIT 1");
          }
        } elseif ($warenkorb['rabattsteuer'] == 0) {
          $this->app->DB->Update("UPDATE $doctype" . "_position SET umsatzsteuer = 'befreit' WHERE id = '$tmpposid' LIMIT 1");
        }
      }`,
`      if ($rabattsteuer >= 0) {
        $_safeDTrabs = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
        $this->app->DatabaseService->execute("UPDATE \`{$_safeDTrabs}\` SET steuersatz = :steuersatz WHERE id = :id LIMIT 1", ['steuersatz' => (float) $rabattsteuer, 'id' => (int) $tmpposid]);
      }
      if (isset($warenkorb['rabattsteuer']) && is_numeric($warenkorb['rabattsteuer'])) {
        $_safeDTrabss = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
        $this->app->DatabaseService->execute("UPDATE \`{$_safeDTrabss}\` SET steuersatz = :steuersatz WHERE id = :id LIMIT 1", ['steuersatz' => (float) $warenkorb['rabattsteuer'], 'id' => (int) $tmpposid]);
        if ($warenkorb['rabattsteuer'] > 0) {
          $_safeDTrse = $this->app->DatabaseService->validateIdentifier($doctype);
          $ermaessigt = !empty($warenkorb['steuersatz_ermaessigt']) ? $warenkorb['steuersatz_ermaessigt'] : $this->app->DatabaseService->selectValue("SELECT steuersatz_ermaessigt FROM \`{$_safeDTrse}\` WHERE id = :id", ['id' => (int) $auftrag]);
          $normal = !empty($warenkorb['steuersatz_normal']) ? $warenkorb['steuersatz_normal'] : $this->app->DatabaseService->selectValue("SELECT steuersatz_normal FROM \`{$_safeDTrse}\` WHERE id = :id", ['id' => (int) $auftrag]);
          $_safeDTrstpos = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
          if ($warenkorb['rabattsteuer'] == $ermaessigt) {
            $this->app->DatabaseService->execute("UPDATE \`{$_safeDTrstpos}\` SET umsatzsteuer = 'ermaessigt' WHERE id = :id LIMIT 1", ['id' => (int) $tmpposid]);
          } elseif ($warenkorb['rabattsteuer'] == $normal) {
            $this->app->DatabaseService->execute("UPDATE \`{$_safeDTrstpos}\` SET umsatzsteuer = 'normal' WHERE id = :id LIMIT 1", ['id' => (int) $tmpposid]);
          }
        } elseif ($warenkorb['rabattsteuer'] == 0) {
          $_safeDTrabsb = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
          $this->app->DatabaseService->execute("UPDATE \`{$_safeDTrabsb}\` SET umsatzsteuer = 'befreit' WHERE id = :id LIMIT 1", ['id' => (int) $tmpposid]);
        }
      }`
);

// Fix versandkostensteuersatz update
fix('versandkosten_steuersatz_update',
`            if ($tmpposid) {
              $this->app->DB->Update("UPDATE $doctype" . "_position SET steuersatz = " . $warenkorb['versandkostensteuersatz'] . " WHERE id = " . $tmpposid);
            }`,
`            if ($tmpposid) {
              $_safeDTvks = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
              $this->app->DatabaseService->execute("UPDATE \`{$_safeDTvks}\` SET steuersatz = :steuersatz WHERE id = :id", ['steuersatz' => (float) $warenkorb['versandkostensteuersatz'], 'id' => (int) $tmpposid]);
            }`
);

// Fix ermaessigt porto umsatzsteuer update
fix('porto_ermaessigt_update',
`              if ($umsatzsteuer_porto2 !== $umsatzsteuer_porto) {
                $this->app->DB->Update("UPDATE $doctype" . "_position SET umsatzsteuer = 'ermaessigt' WHERE id = " . $tmpposid);
              }`,
`              if ($umsatzsteuer_porto2 !== $umsatzsteuer_porto) {
                $_safeDTportoe = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
                $this->app->DatabaseService->execute("UPDATE \`{$_safeDTportoe}\` SET umsatzsteuer = 'ermaessigt' WHERE id = :id", ['id' => (int) $tmpposid]);
              }`
);

// Fix normal porto umsatzsteuer update
fix('porto_normal_update',
`              if ($umsatzsteuer_porto2 !== $umsatzsteuer_porto) {
                $this->app->DB->Update("UPDATE $doctype" . "_position SET umsatzsteuer = 'normal' WHERE id = " . $tmpposid);
              }`,
`              if ($umsatzsteuer_porto2 !== $umsatzsteuer_porto) {
                $_safeDTporton = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
                $this->app->DatabaseService->execute("UPDATE \`{$_safeDTporton}\` SET umsatzsteuer = 'normal' WHERE id = :id", ['id' => (int) $tmpposid]);
              }`
);

// Fix keinrabatterlaubt + beschreibung updates for porto
fix('porto_keinrabatt_beschreibung',
`      if ($tmpposid > 0) {
        $this->app->DB->Update("UPDATE $doctype" . "_position SET keinrabatterlaubt=1 WHERE id='$tmpposid' LIMIT 1");
        if (isset($warenkorb['versandkostenbeschreibung'])) {
          $this->app->DB->Update("UPDATE $doctype" . "_position SET beschreibung='" . $this->app->DB->real_escape_string($warenkorb['versandkostenbeschreibung']) . "' WHERE id='$tmpposid' LIMIT 1");
        }
      }`,
`      if ($tmpposid > 0) {
        $_safeDTkrp = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
        $this->app->DatabaseService->execute("UPDATE \`{$_safeDTkrp}\` SET keinrabatterlaubt = 1 WHERE id = :id LIMIT 1", ['id' => (int) $tmpposid]);
        if (isset($warenkorb['versandkostenbeschreibung'])) {
          $this->app->DatabaseService->execute("UPDATE \`{$_safeDTkrp}\` SET beschreibung = :beschreibung WHERE id = :id LIMIT 1", ['beschreibung' => $warenkorb['versandkostenbeschreibung'], 'id' => (int) $tmpposid]);
        }
      }`
);

// Fix waehrung UPDATE
fix('waehrung_position_update',
`    if ($waehrung && $waehrung !== 'EUR') {
      $this->app->DB->Update("UPDATE $doctype" . "_position SET waehrung='$waehrung' WHERE $doctype='$auftrag' ");
    }`,
`    if ($waehrung && $waehrung !== 'EUR') {
      $_safeDTwae = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
      $_safeDTwaedoc = $this->app->DatabaseService->validateIdentifier($doctype);
      $this->app->DatabaseService->execute("UPDATE \`{$_safeDTwae}\` SET waehrung = :waehrung WHERE \`{$_safeDTwaedoc}\` = :auftrag", ['waehrung' => $waehrung, 'auftrag' => (int) $auftrag]);
    }`
);

// Fix wawisionpreise SELECT + loop
fix('wawisionpreise',
`      $wpositionen = $this->app->DB->SelectArr("SELECT * FROM $doctype" . "_position WHERE auftrag = '$auftrag'");
      if (!empty($wpositionen)) {
        $adresse = $this->app->DB->Select("SELECT adresse FROM $doctype WHERE id = '$auftrag' LIMIT 1");
        foreach ($wpositionen as $wpos) {
          $pr = $this->GetVerkaufspreis($wpos['artikel'], $wpos['menge'], $adresse);
          if ($pr) {
            $this->app->DB->Update("UPDATE $doctype" . "_position SET preis = '$pr' WHERE id = '" . $wpos['id'] . "' LIMIT 1");
          }
        }`,
`      $_safeDTww = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
      $_safeDTwwdoc = $this->app->DatabaseService->validateIdentifier($doctype);
      $wpositionen = $this->app->DatabaseService->select("SELECT * FROM \`{$_safeDTww}\` WHERE auftrag = :auftrag", ['auftrag' => (int) $auftrag]);
      if (!empty($wpositionen)) {
        $adresse = $this->app->DatabaseService->selectValue("SELECT adresse FROM \`{$_safeDTwwdoc}\` WHERE id = :id LIMIT 1", ['id' => (int) $auftrag]);
        foreach ($wpositionen as $wpos) {
          $pr = $this->GetVerkaufspreis($wpos['artikel'], $wpos['menge'], $adresse);
          if ($pr) {
            $this->app->DatabaseService->execute("UPDATE \`{$_safeDTww}\` SET preis = :preis WHERE id = :id LIMIT 1", ['preis' => $pr, 'id' => (int) $wpos['id']]);
          }
        }`
);

// Fix DokumentAbschicken SelectRow (line 15619)
fix('dokument_abschicken_selectrow',
`    $docRow = $this->app->DB->SelectRow(
      sprintf(
        'SELECT status, adresse, projekt FROM \`%s\` WHERE id=%d LIMIT 1',
        $typ,
        (int) $id
      )
    );`,
`    $_safeTypDoc = $this->app->DatabaseService->validateIdentifier($typ);
    $docRow = $this->app->DatabaseService->selectRow("SELECT status, adresse, projekt FROM \`{$_safeTypDoc}\` WHERE id = :id LIMIT 1", ['id' => (int) $id]);`
);

fs.writeFileSync(path, content, 'utf8');
console.log(`\nTotal fixes applied: ${fixCount}`);
