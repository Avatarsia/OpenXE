<?php
$filepath = __DIR__ . '/www/lib/class.erpapi.php';
$content = file_get_contents($filepath);
$changes = 0;

function replace_once(&$content, $old, $new, $label) {
    global $changes;
    $pos = strpos($content, $old);
    if ($pos !== false) {
        $content = substr_replace($content, $new, $pos, strlen($old));
        echo "REPLACED: $label\n";
        $changes++;
    } else {
        echo "NOT FOUND: $label\n";
    }
}

// CreateAufgabe - actual whitespace
replace_once($content,
    "  function CreateAufgabe(\$adresse, \$aufgabe, \$kunde = 0)\n  {\n    \$this->app->DB->Insert(\"INSERT INTO aufgabe (id,adresse,initiator,aufgabe,status,kunde) \n          VALUES ('','\$adresse','\" . \$this->app->User->GetAdresse() . \"','\$aufgabe','offen','\$kunde')\");\n    return \$this->app->DB->GetInsertID();\n  }",
    "  function CreateAufgabe(\$adresse, \$aufgabe, \$kunde = 0)\n  {\n    \$this->app->DatabaseService->insert(\n      \"INSERT INTO aufgabe (adresse,initiator,aufgabe,status,kunde) VALUES (:adresse,:initiator,:aufgabe,'offen',:kunde)\",\n      [':adresse' => \$adresse, ':initiator' => \$this->app->User->GetAdresse(), ':aufgabe' => \$aufgabe, ':kunde' => \$kunde]\n    );\n    return \$this->app->DB->GetInsertID();\n  }",
    'CreateAufgabe'
);

// Now handle the large KopiereArtikelEigenschaften / KopiereArtikelDateistichwoerter block
// These use (int) cast so they're lower risk, but still have string interpolation
replace_once($content,
    '    $vonartikel = $this->app->DB->Select("SELECT id FROM artikel WHERE geloescht <> 1 AND id = \'" . (int) $vonartikel . "\' LIMIT 1");
    $nachartikel = $this->app->DB->Select("SELECT id FROM artikel WHERE geloescht <> 1 AND id = \'" . (int) $nachartikel . "\' LIMIT 1");
    if (!$vonartikel || !$nachartikel || $vonartikel == $nachartikel) {
      return;
    }
    $artikeleigenschaftenwerte = $this->app->DB->SelectArr("SELECT id FROM artikeleigenschaftenwerte WHERE artikel = \'$vonartikel\' ORDER by id");
    if ($artikeleigenschaftenwerte) {
      foreach ($artikeleigenschaftenwerte as $v) {
        $idnew = $this->app->DB->MysqlCopyRow("artikeleigenschaftenwerte", "id", $v[\'id\']);
        $this->app->DB->Update("UPDATE artikeleigenschaftenwerte SET artikel = \'$nachartikel\' WHERE id = \'$idnew\' LIMIT 1");
      }
    }
  }

  function KopiereArtikelDateistichwoerter($vonartikel, $nachartikel)
  {
    $vonartikel = $this->app->DB->Select("SELECT id FROM artikel WHERE geloescht <> 1 AND id = \'" . (int) $vonartikel . "\' LIMIT 1");
    $nachartikel = $this->app->DB->Select("SELECT id FROM artikel WHERE geloescht <> 1 AND id = \'" . (int) $nachartikel . "\' LIMIT 1");
    if (!$vonartikel || !$nachartikel || $vonartikel == $nachartikel)
      return;
    $dateistichwoerter = $this->app->DB->SelectArr("SELECT id FROM datei_stichwoerter WHERE objekt LIKE \'Artikel\' AND parameter = \'$vonartikel\' ORDER by id");
    if ($dateistichwoerter) {
      foreach ($dateistichwoerter as $v) {
        $idnew = $this->app->DB->MysqlCopyRow("datei_stichwoerter", "id", $v[\'id\']);
        $this->app->DB->Update("UPDATE datei_stichwoerter SET parameter = \'$nachartikel\' WHERE id = \'$idnew\' LIMIT 1");
      }
    }
  }',
    '    $vonartikel = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE geloescht <> 1 AND id = :id LIMIT 1", [\':id\' => (int)$vonartikel]);
    $nachartikel = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE geloescht <> 1 AND id = :id LIMIT 1", [\':id\' => (int)$nachartikel]);
    if (!$vonartikel || !$nachartikel || $vonartikel == $nachartikel) {
      return;
    }
    $artikeleigenschaftenwerte = $this->app->DatabaseService->select("SELECT id FROM artikeleigenschaftenwerte WHERE artikel = :id ORDER by id", [\':id\' => $vonartikel]);
    if ($artikeleigenschaftenwerte) {
      foreach ($artikeleigenschaftenwerte as $v) {
        $idnew = $this->app->DB->MysqlCopyRow("artikeleigenschaftenwerte", "id", $v[\'id\']);
        $this->app->DatabaseService->execute("UPDATE artikeleigenschaftenwerte SET artikel = :nachartikel WHERE id = :id LIMIT 1", [\':nachartikel\' => $nachartikel, \':id\' => $idnew]);
      }
    }
  }

  function KopiereArtikelDateistichwoerter($vonartikel, $nachartikel)
  {
    $vonartikel = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE geloescht <> 1 AND id = :id LIMIT 1", [\':id\' => (int)$vonartikel]);
    $nachartikel = $this->app->DatabaseService->selectValue("SELECT id FROM artikel WHERE geloescht <> 1 AND id = :id LIMIT 1", [\':id\' => (int)$nachartikel]);
    if (!$vonartikel || !$nachartikel || $vonartikel == $nachartikel)
      return;
    $dateistichwoerter = $this->app->DatabaseService->select("SELECT id FROM datei_stichwoerter WHERE objekt LIKE \'Artikel\' AND parameter = :id ORDER by id", [\':id\' => $vonartikel]);
    if ($dateistichwoerter) {
      foreach ($dateistichwoerter as $v) {
        $idnew = $this->app->DB->MysqlCopyRow("datei_stichwoerter", "id", $v[\'id\']);
        $this->app->DatabaseService->execute("UPDATE datei_stichwoerter SET parameter = :nachartikel WHERE id = :id LIMIT 1", [\':nachartikel\' => $nachartikel, \':id\' => $idnew]);
      }
    }
  }',
    'KopiereArtikelEigenschaften + KopiereArtikelDateistichwoerter'
);

file_put_contents($filepath, $content);
echo "Total changes: $changes\n";
