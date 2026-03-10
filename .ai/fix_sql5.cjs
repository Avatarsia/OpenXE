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

// Fix positions with trailing space in strings
fix('positions_auftrag',
`        $positions = $this->app->DB->SelectArr(\r\n          sprintf(\r\n            "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer \r\n            FROM auftrag_position \r\n            WHERE preis > 0 AND auftrag = %d\r\n            GROUP BY steuersatz, umsatzsteuer",\r\n            $auftrag\r\n          )\r\n        );`,
`        $positions = $this->app->DatabaseService->select(\r\n          "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer FROM auftrag_position WHERE preis > 0 AND auftrag = :auftrag GROUP BY steuersatz, umsatzsteuer",\r\n          ['auftrag' => (int) $auftrag]\r\n        );`
);

fix('positions_angebot',
`        $positions = $this->app->DB->SelectArr(\r\n          sprintf(\r\n            "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer \r\n            FROM angebot_position \r\n            WHERE preis > 0 AND angebot = %d\r\n            GROUP BY steuersatz, umsatzsteuer",\r\n            $auftrag\r\n          )\r\n        );`,
`        $positions = $this->app->DatabaseService->select(\r\n          "SELECT IFNULL(steuersatz,-1) as steuersatz, umsatzsteuer FROM angebot_position WHERE preis > 0 AND angebot = :auftrag GROUP BY steuersatz, umsatzsteuer",\r\n          ['auftrag' => (int) $auftrag]\r\n        );`
);

// Fix dokument abschicken SelectRow - check exact text
const lines = content.split('\n');
let l15619 = lines[15618];
console.log('L15619 actual: ' + JSON.stringify(l15619));
let l15620 = lines[15619];
console.log('L15620 actual: ' + JSON.stringify(l15620));

// Fix rabatt position updates (line ~18856-18860)
let l18855 = lines[18854];
console.log('L18855 actual: ' + JSON.stringify(l18855));
let l18856 = lines[18855];
console.log('L18856 actual: ' + JSON.stringify(l18856));

fix('rabatt_keinrabatt_update',
`      $this->app->DB->Update("UPDATE $doctype" . "_position set rabatt = 0, keinrabatterlaubt = 1, grundrabatt = 0, rabattsync = 1 WHERE $doctype = '$auftrag'");`,
`      $_safeDTrabk = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
      $_safeDTrabkd = $this->app->DatabaseService->validateIdentifier($doctype);
      $this->app->DatabaseService->execute("UPDATE \`{$_safeDTrabk}\` SET rabatt = 0, keinrabatterlaubt = 1, grundrabatt = 0, rabattsync = 1 WHERE \`{$_safeDTrabkd}\` = :auftrag", ['auftrag' => (int) $auftrag]);`
);

fix('rabatt_per_position_update',
`          $this->app->DB->Update("UPDATE $doctype" . "_position SET rabatt='$value' WHERE id='$key'");`,
`          $_safeDTrpp = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
          $this->app->DatabaseService->execute("UPDATE \`{$_safeDTrpp}\` SET rabatt = :rabatt WHERE id = :id", ['rabatt' => (float) $value, 'id' => (int) $key]);`
);

// Now handle lines 18866-18953 (reservierung and auftrag status updates)
// show those lines
for(let i=18864; i<=18980; i++) {
  if (lines[i] && lines[i].includes('app->DB->')) {
    process.stdout.write(`L${i+1}: ` + JSON.stringify(lines[i]) + '\n');
  }
}

fs.writeFileSync(path, content, 'utf8');
console.log(`\nTotal fixes applied: ${fixCount}`);
