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

// Fix: lieferung1/2/3 real_escape_string remnants (these vars are now only used as prepared params)
fix('lieferung1_real_escape',
`      $lieferung1 = $this->app->DB->real_escape_string($warenkorb['lieferung']);\r\n      $lieferung2 = $this->app->DB->real_escape_string($this->app->erp->ConvertForDBUTF8($warenkorb['lieferung']));\r\n      if ($lieferung2 !== '' && $lieferung2 != $lieferung1) {\r\n        $extrasel .= " OR versandart_shop = '$lieferung2' ";\r\n      }\r\n      $lieferung3 = $this->app->DB->real_escape_string($this->app->erp->ConvertForDB($warenkorb['lieferung']));\r\n      if ($lieferung3 !== '' && $lieferung3 != $lieferung1) {\r\n        $extrasel .= " OR versandart_shop = '$lieferung3' ";\r\n      }`,
`      $lieferung1 = $warenkorb['lieferung'];\r\n      $lieferung2 = $this->app->erp->ConvertForDBUTF8($warenkorb['lieferung']);\r\n      if ($lieferung2 !== '' && $lieferung2 != $lieferung1) {\r\n        $extrasel .= " OR versandart_shop = :lief2_extra ";\r\n      }\r\n      $lieferung3 = $this->app->erp->ConvertForDB($warenkorb['lieferung']);\r\n      if ($lieferung3 !== '' && $lieferung3 != $lieferung1) {\r\n        $extrasel .= " OR versandart_shop = :lief3_extra ";\r\n      }`
);

// Read what's actually around line 18300-18400 to understand current state
const lines = content.split('\n');

// Show key lines
const keyLines = [18312, 18313, 18314, 18315, 18316, 18317, 18318, 18319, 18320, 18325, 18326, 18327, 18328, 18329, 18330];
keyLines.forEach(n => {
  if (lines[n-1]) console.log(`L${n}: ` + JSON.stringify(lines[n-1]));
});

// Fix webid update (line 18313)
fix('webid_update',
`            if (isset($value['webid'])) {
              $this->app->DB->Update("UPDATE $doctype" . "_position SET webid = '" . $this->app->DB->real_escape_string($value['webid']) . "' WHERE id = '$ap' LIMIT 1");
            }`,
`            if (isset($value['webid'])) {
              $_safeDTwebid = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
              $this->app->DatabaseService->execute("UPDATE \`{$_safeDTwebid}\` SET webid = :webid WHERE id = :id LIMIT 1", ['webid' => $value['webid'], 'id' => (int) $ap]);
            }`
);

// Fix artap SELECT
fix('artap_select',
`            $artap = $this->app->DB->Select("SELECT artikel FROM $doctype" . "_position WHERE id = '$ap' LIMIT 1");`,
`            $_safeDTartap = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
            $artap = $this->app->DatabaseService->selectValue("SELECT artikel FROM \`{$_safeDTartap}\` WHERE id = :id LIMIT 1", ['id' => (int) $ap]);`
);

// Fix steuersatz einzelposition update
fix('steuersatz_einzelpos',
`              $this->app->DB->Update("UPDATE $doctype" . "_position SET steuersatz = " . $value['steuersatz'] . " WHERE id = '$ap' LIMIT 1");`,
`              $_safeDTsteuers = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
              $this->app->DatabaseService->execute("UPDATE \`{$_safeDTsteuers}\` SET steuersatz = :steuersatz WHERE id = :id LIMIT 1", ['steuersatz' => (float) $value['steuersatz'], 'id' => (int) $ap]);`
);

// Fix dateiname SELECT (line 18330)
fix('dateiname_sort_select',
`                  $dateiname = $this->app->DB->Select("SELECT sort FROM $doctype" . "_position WHERE id=$ap");`,
`                  $_safeDTdatei = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
                  $dateiname = $this->app->DatabaseService->selectValue("SELECT sort FROM \`{$_safeDTdatei}\` WHERE id = :id", ['id' => (int) $ap]);`
);

// Fix varj_id lieferant stuff (around line 18351-18368)
fix('lieferant_varjid',
`              $lieferant = $this->app->DB->Select("SELECT adresse FROM artikel WHERE id = '$varj_id' LIMIT 1");`,
`              $lieferant = $this->app->DatabaseService->selectValue("SELECT adresse FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $varj_id]);`
);

fix('lieferant_update_artap',
`                $this->app->DB->Update("UPDATE artikel set adresse = '$lieferant' WHERE id = '$artap' LIMIT 1");`,
`                $this->app->DatabaseService->execute("UPDATE artikel SET adresse = :adresse WHERE id = :id LIMIT 1", ['adresse' => (int) $lieferant, 'id' => (int) $artap]);`
);

fix('lagerpl_select',
`              $lagerpl = $this->app->DB->Select("SELECT lager_platz FROM artikel WHERE id = '$varj_id' LIMIT 1");`,
`              $lagerpl = $this->app->DatabaseService->selectValue("SELECT lager_platz FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $varj_id]);`
);

fix('produktion_select',
`              $produktion = $this->app->DB->Select("SELECT produktion FROM artikel WHERE id = '$varj_id' LIMIT 1");`,
`              $produktion = $this->app->DatabaseService->selectValue("SELECT produktion FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $varj_id]);`
);

fix('produktion_update',
`                $this->app->DB->Update("UPDATE artikel set produktion = '1' WHERE id = '$artap' LIMIT 1");`,
`                $this->app->DatabaseService->execute("UPDATE artikel SET produktion = 1 WHERE id = :id LIMIT 1", ['id' => (int) $artap]);`
);

fix('stueckliste_select',
`              $stueckliste = $this->app->DB->Select("SELECT stueckliste FROM artikel WHERE id = '$varj_id' LIMIT 1");`,
`              $stueckliste = $this->app->DatabaseService->selectValue("SELECT stueckliste FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $varj_id]);`
);

fix('stueckliste_update',
`                $this->app->DB->Update("UPDATE artikel set stueckliste = '1' WHERE id = '$artap' LIMIT 1");`,
`                $this->app->DatabaseService->execute("UPDATE artikel SET stueckliste = 1 WHERE id = :id LIMIT 1", ['id' => (int) $artap]);`
);

fix('lagerpl_update',
`                $this->app->DB->Update("UPDATE artikel set lager_platz = '$lagerpl' WHERE id = '$artap' LIMIT 1");`,
`                $this->app->DatabaseService->execute("UPDATE artikel SET lager_platz = :lagerpl WHERE id = :id LIMIT 1", ['lagerpl' => $lagerpl, 'id' => (int) $artap]);`
);

fix('variante_update',
`              $this->app->DB->Update("UPDATE artikel set variante = 1, variante_von = '$varj_id' WHERE id = '$artap' LIMIT 1");`,
`              $this->app->DatabaseService->execute("UPDATE artikel SET variante = 1, variante_von = :varj_id WHERE id = :id LIMIT 1", ['varj_id' => (int) $varj_id, 'id' => (int) $artap]);`
);

// Fix anummer select (line 18388)
fix('anummer_select',
`          $anummer = $this->app->DB->Select("SELECT nummer FROM artikel WHERE id = '$j_id' LIMIT 1");`,
`          $anummer = $this->app->DatabaseService->selectValue("SELECT nummer FROM artikel WHERE id = :id LIMIT 1", ['id' => (int) $j_id]);`
);

// Fix webid for else branch (line 18413)
fix('webid_update2',
`            $this->app->DB->Update("UPDATE $doctype" . "_position SET webid = '" . $this->app->DB->real_escape_string($value['webid']) . "' WHERE id = '$ap' LIMIT 1");`,
`            $_safeDTwebid2 = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
            $this->app->DatabaseService->execute("UPDATE \`{$_safeDTwebid2}\` SET webid = :webid WHERE id = :id LIMIT 1", ['webid' => $value['webid'], 'id' => (int) $ap]);`
);

// Fix artap select (2nd occurrence - line 18438)
fix('artap_select2',
`          $artap = $this->app->DB->Select("SELECT artikel FROM $doctype" . "_position WHERE id = '$ap' LIMIT 1");`,
`          $_safeDTartap2 = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
          $artap = $this->app->DatabaseService->selectValue("SELECT artikel FROM \`{$_safeDTartap2}\` WHERE id = :id LIMIT 1", ['id' => (int) $ap]);`
);

fs.writeFileSync(path, content, 'utf8');
console.log(`\nTotal fixes applied: ${fixCount}`);
