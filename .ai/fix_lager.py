import sys

filepath = r"C:\Users\3D Partner\Documents\openxe_rework\OpenXE\www\lib\class.erpapi.php"
with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

changes = []

# Fix 1: lager_platz_vpe selects
changes.append((
    "        $check = $this->app->DB->Select(\"SELECT id FROM lager_platz_inhalt WHERE lager_platz_vpe = '$lager_platz_vpe' AND menge >= $menge AND  artikel='$artikel' AND lager_platz='$regal' ORDER BY id = '$lpiid' DESC LIMIT 1\");\n"
    "        if ($check) {\n"
    "          $vpe_found = true;\n"
    "          $subwhere = \" AND id = '$check' \";\n"
    "        }\n"
    "      } else {\n"
    "        $check = $this->app->DB->Select(\"SELECT id FROM lager_platz_inhalt WHERE lager_platz_vpe = '0' AND menge >= $menge AND  artikel='$artikel' AND lager_platz='$regal' LIMIT 1\");\n"
    "        if ($check) {\n"
    "          $subwhere = \" AND id = '$check' \";\n"
    "        }",

    "        $check = $this->app->DatabaseService->selectValue(\"SELECT id FROM lager_platz_inhalt WHERE lager_platz_vpe = :vpe AND menge >= :menge AND artikel = :artikel AND lager_platz = :regal ORDER BY id = :lpiid DESC LIMIT 1\", ['vpe' => (int)$lager_platz_vpe, 'menge' => $menge, 'artikel' => (int)$artikel, 'regal' => (int)$regal, 'lpiid' => (int)$lpiid]);\n"
    "        if ($check) {\n"
    "          $vpe_found = true;\n"
    "          $subwhere = sprintf(\" AND id = %d \", (int)$check);\n"
    "        }\n"
    "      } else {\n"
    "        $check = $this->app->DatabaseService->selectValue(\"SELECT id FROM lager_platz_inhalt WHERE lager_platz_vpe = 0 AND menge >= :menge AND artikel = :artikel AND lager_platz = :regal LIMIT 1\", ['menge' => $menge, 'artikel' => (int)$artikel, 'regal' => (int)$regal]);\n"
    "        if ($check) {\n"
    "          $subwhere = sprintf(\" AND id = %d \", (int)$check);\n"
    "        }"
))

# Fix 2: Update + Delete + Insert in $check branch
changes.append((
    "        $this->app->DB->Update(\"UPDATE lager_platz_inhalt SET menge=menge-$menge WHERE artikel='$artikel' AND lager_platz='$regal' $subwhere LIMIT 1\");\n"
    "        $this->app->DB->Delete(\n"
    "          sprintf(\n"
    "            'DELETE FROM `lager_platz_inhalt` WHERE `id` = %d AND `menge` <= 0 AND `lager_platz_vpe` <= 0',\n"
    "            $check\n"
    "          )\n"
    "        );\n"
    "        // Bewegung buchen\n"
    "        $bestand = $this->ArtikelImLagerPlatz($artikel, $regal);\n"
    "        $this->app->DB->Insert(\"INSERT INTO lager_bewegung (id,lager_platz,artikel,menge,vpe,eingang,zeit,referenz,bearbeiter,projekt,firma,bestand,doctype,doctypeid) VALUES\n"
    "          ('','$regal','$artikel','$menge','','0',NOW(),'$grund','\" . $username . \"','$projekt','','$bestand','$doctype','$doctypeid')\");",

    "        $this->app->DatabaseService->execute(\"UPDATE lager_platz_inhalt SET menge = menge - :menge WHERE artikel = :artikel AND lager_platz = :regal $subwhere LIMIT 1\", ['menge' => $menge, 'artikel' => (int)$artikel, 'regal' => (int)$regal]);\n"
    "        $this->app->DatabaseService->execute(sprintf('DELETE FROM `lager_platz_inhalt` WHERE `id` = %d AND `menge` <= 0 AND `lager_platz_vpe` <= 0', (int)$check));\n"
    "        // Bewegung buchen\n"
    "        $bestand = $this->ArtikelImLagerPlatz($artikel, $regal);\n"
    "        $this->app->DatabaseService->execute(\n"
    "          \"INSERT INTO lager_bewegung (id,lager_platz,artikel,menge,vpe,eingang,zeit,referenz,bearbeiter,projekt,firma,bestand,doctype,doctypeid) VALUES ('', :regal, :artikel, :menge, '', 0, NOW(), :grund, :bearbeiter, :projekt, '', :bestand, :doctype, :doctypeid)\",\n"
    "          ['regal' => (int)$regal, 'artikel' => (int)$artikel, 'menge' => $menge, 'grund' => $grund, 'bearbeiter' => $username, 'projekt' => $projekt, 'bestand' => $bestand, 'doctype' => $doctype, 'doctypeid' => (int)$doctypeid]\n"
    "        );"
))

# Fix 3: SelectArr for lpis
changes.append((
    "        $lpis = $this->app->DB->SelectArr(\"SELECT id, menge,lager_platz_vpe FROM lager_platz_inhalt WHERE artikel = '$artikel' AND lager_platz='$regal' ORDER BY \" . ($lager_platz_vpe && $lpiid ? \" id = '$lpiid' DESC, \" : '') . \" lager_platz_vpe = '$lager_platz_vpe' DESC, id\");",

    "        $_lpiidOrder = ($lager_platz_vpe && $lpiid) ? sprintf(' id = %d DESC, ', (int)$lpiid) : '';\n"
    "        $lpis = $this->app->DatabaseService->select(\"SELECT id, menge, lager_platz_vpe FROM lager_platz_inhalt WHERE artikel = :artikel AND lager_platz = :regal ORDER BY {$_lpiidOrder} lager_platz_vpe = :vpe DESC, id\", ['artikel' => (int)$artikel, 'regal' => (int)$regal, 'vpe' => (int)$lager_platz_vpe]);"
))

# Fix 4: Update + Delete in foreach loop (menge-$_menge case)
changes.append((
    "              $this->app->DB->Update(\"UPDATE lager_platz_inhalt SET menge=menge-$_menge WHERE id = '\" . $v['id'] . \"' LIMIT 1\");\n"
    "              $this->app->DB->Delete(sprintf('DELETE FROM `lager_platz_inhalt` WHERE `id` = %d AND `menge` <= 0', $v['id']));",

    "              $this->app->DatabaseService->execute(\"UPDATE lager_platz_inhalt SET menge = menge - :menge WHERE id = :id LIMIT 1\", ['menge' => $_menge, 'id' => (int)$v['id']]);\n"
    "              $this->app->DatabaseService->execute(sprintf('DELETE FROM `lager_platz_inhalt` WHERE `id` = %d AND `menge` <= 0', (int)$v['id']));"
))

# Fix 5: two Delete calls in the equal and less-than-menge branches
changes.append((
    "              $this->app->DB->Delete(\"DELETE FROM lager_platz_inhalt WHERE id = '\" . $v['id'] . \"' LIMIT 1\");\n"
    "              $_menge = 0;\n"
    "              break;\n"
    "            } elseif ($v['menge'] < $_menge) {\n"
    "              if (!isset($vpemengen[$v['lager_platz_vpe']]))\n"
    "                $vpemengen[$v['lager_platz_vpe']] = 0;\n"
    "              $vpemengen[$v['lager_platz_vpe']] += $_menge;\n"
    "              $this->app->DB->Delete(\"DELETE FROM lager_platz_inhalt WHERE id = '\" . $v['id'] . \"' LIMIT 1\");",

    "              $this->app->DatabaseService->execute(\"DELETE FROM lager_platz_inhalt WHERE id = :id LIMIT 1\", ['id' => (int)$v['id']]);\n"
    "              $_menge = 0;\n"
    "              break;\n"
    "            } elseif ($v['menge'] < $_menge) {\n"
    "              if (!isset($vpemengen[$v['lager_platz_vpe']]))\n"
    "                $vpemengen[$v['lager_platz_vpe']] = 0;\n"
    "              $vpemengen[$v['lager_platz_vpe']] += $_menge;\n"
    "              $this->app->DatabaseService->execute(\"DELETE FROM lager_platz_inhalt WHERE id = :id LIMIT 1\", ['id' => (int)$v['id']]);"
))

# Fix 6: Insert in foreach vpemengen loop
changes.append((
    "              $this->app->DB->Insert(\"INSERT INTO lager_bewegung (id,lager_platz,artikel,menge,vpe,eingang,zeit,referenz,bearbeiter,projekt,firma,bestand,doctype,doctypeid) VALUES\n"
    "                ('','$regal','$artikel','$m','','0',NOW(),'$_grund','\" . $username . \"','$projekt','','$bestandalt','$doctype','$doctypeid')\");",

    "              $this->app->DatabaseService->execute(\n"
    "                \"INSERT INTO lager_bewegung (id,lager_platz,artikel,menge,vpe,eingang,zeit,referenz,bearbeiter,projekt,firma,bestand,doctype,doctypeid) VALUES ('', :regal, :artikel, :menge, '', 0, NOW(), :grund, :bearbeiter, :projekt, '', :bestand, :doctype, :doctypeid)\",\n"
    "                ['regal' => (int)$regal, 'artikel' => (int)$artikel, 'menge' => $m, 'grund' => $_grund, 'bearbeiter' => $username, 'projekt' => $projekt, 'bestand' => $bestandalt, 'doctype' => $doctype, 'doctypeid' => (int)$doctypeid]\n"
    "              );"
))

# Fix 7: laststorage_changed at end of LagerAuslagernRegal
changes.append((
    "    $this->app->DB->Update(sprintf('UPDATE `artikel`SET `laststorage_changed` = NOW() WHERE `id` = %d', $artikel));\n"
    "    return 1;\n"
    "  }\n"
    "\n"
    "  function AddBaugruppenChargeMHD",

    "    $this->app->DatabaseService->execute(sprintf('UPDATE `artikel` SET `laststorage_changed` = NOW() WHERE `id` = %d', (int)$artikel));\n"
    "    return 1;\n"
    "  }\n"
    "\n"
    "  function AddBaugruppenChargeMHD"
))

for old, new in changes:
    if old in content:
        content = content.replace(old, new, 1)
        print(f"OK: {old[:50]!r}")
    else:
        print(f"NOT FOUND: {old[:50]!r}")

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)
print("Done writing.")
