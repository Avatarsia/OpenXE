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

// Fix artikeldaten loop with real_escape_string for dynamic SET
fix('artikeldaten_loop',
`          foreach ($value['artikeldaten'] as $feldname => $wert) {
            $query = sprintf(
              "UPDATE artikel SET %s='%s' WHERE id=%d",
              $this->app->DB->real_escape_string($feldname),
              $this->app->DB->real_escape_string($wert),
              $artap
            );
            $this->app->DB->Update($query);
          }`,
`          foreach ($value['artikeldaten'] as $feldname => $wert) {
            // validate column name to prevent injection
            $safeFieldname = preg_replace('/[^a-zA-Z0-9_]/', '', $feldname);
            if ($safeFieldname !== '') {
              $this->app->DatabaseService->execute("UPDATE artikel SET \`$safeFieldname\` = :wert WHERE id = :id", ['wert' => $wert, 'id' => (int) $artap]);
            }
          }`
);

// Fix explodiert parent UPDATE (uses sprintf with doctype)
fix('explodiert_parent_update',
`        $query = sprintf(
          'UPDATE \`%s_position\` SET \`explodiert\` = 1 WHERE \`id\` = %d',
          $doctype,
          $warenkorb['articlelist'][$item['parentInCart']]['databaseId']
        );
        $this->app->DB->Update($query);`,
`        $_safeDTexp = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
        $this->app->DatabaseService->execute("UPDATE \`{$_safeDTexp}\` SET \`explodiert\` = 1 WHERE \`id\` = :id", ['id' => (int) $warenkorb['articlelist'][$item['parentInCart']]['databaseId']]);`
);

// Fix the commands SET block
fix('commands_update',
`      if (!empty($commands)) {
        $query = sprintf(
          'UPDATE \`%s_position\` SET %s WHERE \`id\` = %d',
          $doctype,
          implode(', ', $commands),
          $item['databaseId']
        );
        $this->app->DB->Update($query);
      }`,
`      if (!empty($commands)) {
        // $commands contains only vetted literal column names + int values — safe
        $_safeDTcmd = $this->app->DatabaseService->validateIdentifier($doctype . '_position');
        $this->app->DatabaseService->execute(
          "UPDATE \`{$_safeDTcmd}\` SET " . implode(', ', $commands) . " WHERE \`id\` = :id",
          ['id' => (int) $item['databaseId']]
        );
      }`
);

// Fix positions SelectArr (auftrag_position)
fix('positions_selectarr_auftrag',
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

fix('positions_selectarr_angebot',
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

// Now read the current state of lines 18525-18830
const lines = content.split('\n');
const range = lines.slice(18524, 18830).join('\n');
// find remaining DB-> patterns
const matches = range.match(/\$this->app->DB->[A-Za-z]+\([^;]+\);/g);
if (matches) {
  console.log('\nRemaining DB patterns in 18525-18830:');
  matches.slice(0, 20).forEach(m => console.log('  ' + m.substring(0, 100)));
}

fs.writeFileSync(path, content, 'utf8');
console.log(`\nTotal fixes applied: ${fixCount}`);
