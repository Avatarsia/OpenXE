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

$n = "\r\n"; // CRLF

// ============================================================
// GenerateHook Update new-branch parametercount
// ============================================================
rep($content,
    "        \$this->app->DB->Update({$n}          \"UPDATE `hook` {$n}          SET `parametercount` = '\$parametercount' {$n}          WHERE `id` = '\" . \$checkarr['id'] . \"' LIMIT 1\"{$n}        );",
    "        \$this->app->DatabaseService->execute({$n}          \"UPDATE `hook` SET `parametercount` = :parametercount WHERE `id` = :id LIMIT 1\",{$n}          ['parametercount' => \$parametercount, 'id' => (int) \$checkarr['id']]{$n}        );",
    'GenerateHook Update new-branch parametercount'
);

// ============================================================
// RegisterHook Insert hook_register
// ============================================================
rep($content,
    "    \$this->app->DB->Insert({$n}      sprintf(\"INSERT INTO `hook_register` (`hook`, `module`, `function`, `aktiv`, `position`, `module_parameter`) {$n}        VALUES (%d,'%s','%s',%d,%d,%d)\",{$n}        \$hook,{$n}        \$callmodule,{$n}        \$funktion,{$n}        (int) \$aktiv,{$n}        (int) \$position,{$n}        (int) \$moduleParameter{$n}      ){$n}    );",
    "    \$this->app->DatabaseService->execute({$n}      \"INSERT INTO `hook_register` (`hook`, `module`, `function`, `aktiv`, `position`, `module_parameter`) VALUES (:hook, :module, :function, :aktiv, :position, :module_parameter)\",{$n}      ['hook' => (int) \$hook, 'module' => \$callmodule, 'function' => \$funktion, 'aktiv' => (int) \$aktiv, 'position' => (int) \$position, 'module_parameter' => (int) \$moduleParameter]{$n}    );",
    'RegisterHook Insert hook_register'
);

// ============================================================
// RemoveHook Delete
// ============================================================
rep($content,
    "    \$this->app->DB->Delete({$n}      sprintf({$n}        \"DELETE `h`, `hr` {$n}        FROM `hook` AS `h` {$n}        LEFT JOIN `hook_register` AS `hr` ON h.id = hr.hook {$n}        WHERE h.name = '%s'\",{$n}        \$this->app->DB->real_escape_string(\$name){$n}      ){$n}    );",
    "    \$this->app->DatabaseService->execute({$n}      \"DELETE `h`, `hr` FROM `hook` AS `h` LEFT JOIN `hook_register` AS `hr` ON h.id = hr.hook WHERE h.name = :name\",{$n}      ['name' => \$name]{$n}    );",
    'RemoveHook Delete'
);

// ============================================================
// RunMenuHook SelectArr hook_menu_register
// ============================================================
rep($content,
    "    \$hooks = \$this->app->DB->SelectArr({$n}      \"SELECT hmr.module, hmr.funktion {$n}      FROM `hook_menu_register` AS `hmr` {$n}      WHERE hmr.hook_menu = '\$hook_menu' AND hmr.aktiv = 1 AND hmr.module <> '' AND hmr.funktion <> '' {$n}      GROUP BY hmr.module, hmr.funktion {$n}      ORDER BY hmr.position\"{$n}    );",
    "    \$hooks = \$this->app->DatabaseService->select({$n}      \"SELECT hmr.module, hmr.funktion FROM `hook_menu_register` AS `hmr` WHERE hmr.hook_menu = :hook_menu AND hmr.aktiv = 1 AND hmr.module <> '' AND hmr.funktion <> '' GROUP BY hmr.module, hmr.funktion ORDER BY hmr.position\",{$n}      ['hook_menu' => (int) \$hook_menu]{$n}    );",
    'RunMenuHook SelectArr hook_menu_register'
);

// ============================================================
// AuftragAutoversandBerechnen porto_ok=1 (in projektportocheck block) + portoFreeLimit
// ============================================================
rep($content,
    "        \$this->app->DatabaseService->execute(\"UPDATE auftrag SET porto_ok = 1 WHERE id = :id LIMIT 1\", ['id' => (int) \$auftrag]);{$n}      } else {{$n}        \$portoFreeLimit = (double) \$this->app->DB->Select(\"SELECT portofreiab FROM adresse WHERE id={\$adresse} LIMIT 1\");",
    "        \$this->app->DatabaseService->execute(\"UPDATE auftrag SET porto_ok = 1 WHERE id = :id LIMIT 1\", ['id' => (int) \$auftrag]);{$n}      } else {{$n}        \$portoFreeLimit = (double) \$this->app->DatabaseService->selectValue(\"SELECT portofreiab FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);",
    'AuftragAutoversandBerechnen SELECT portofreiab'
);

// ============================================================
// checkkeinportocheck
// ============================================================
rep($content,
    "      \$checkkeinportocheck = \$this->app->DB->Select(\"SELECT keinportocheck FROM versandarten WHERE type = '\" . \$this->app->DB->real_escape_string(\$auftragarr['versandart']) . \"' AND{$n}        (projekt = '\$projekt' OR projekt = 0) ORDER BY projekt = '\$projekt' DESC LIMIT 1\");",
    "      \$checkkeinportocheck = \$this->app->DatabaseService->selectValue(\"SELECT keinportocheck FROM versandarten WHERE type = :type AND (projekt = :projekt OR projekt = 0) ORDER BY projekt = :projekt2 DESC LIMIT 1\", ['type' => \$auftragarr['versandart'], 'projekt' => (int) \$projekt, 'projekt2' => (int) \$projekt]);",
    'AuftragAutoversandBerechnen SELECT checkkeinportocheck'
);

// ============================================================
// ReplacePreisgruppe SELECT id (multi-line, actual content)
// ============================================================
rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id {$n}        FROM gruppen AS g {$n}        WHERE (CONCAT(g.kennziffer,' ',g.name)='\$value' OR g.kennziffer = '\$rest') AND {$n}              (g.name!='' OR g.kennziffer != '') AND g.art = 'preisgruppe' AND g.aktiv=1 {$n}        LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue({$n}        \"SELECT id FROM gruppen AS g WHERE (CONCAT(g.kennziffer,' ',g.name) = :value OR g.kennziffer = :rest) AND (g.name != '' OR g.kennziffer != '') AND g.art = 'preisgruppe' AND g.aktiv = 1 LIMIT 1\",{$n}        ['value' => \$value, 'rest' => \$rest]{$n}      );",
    'ReplacePreisgruppe SELECT id'
);

// ============================================================
// Save
// ============================================================
file_put_contents($filepath, $content);
echo "\nTotal: $total\n";
echo "Done.\n";
