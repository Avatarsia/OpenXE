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

$n = "\r\n";

// porto_ok=1 when $porto > 0 branch
rep($content,
    "        \$this->app->DB->Update(\"UPDATE auftrag SET porto_ok='1' WHERE id='\$auftrag' LIMIT 1\");{$n}      } else {{$n}        \$portoFreeLimit = (double) \$this->app->DB->Select(\"SELECT portofreiab FROM adresse WHERE id={\$adresse} LIMIT 1\");",
    "        \$this->app->DatabaseService->execute(\"UPDATE auftrag SET porto_ok = 1 WHERE id = :id LIMIT 1\", ['id' => (int) \$auftrag]);{$n}      } else {{$n}        \$portoFreeLimit = (double) \$this->app->DatabaseService->selectValue(\"SELECT portofreiab FROM adresse WHERE id = :id LIMIT 1\", ['id' => (int) \$adresse]);",
    'AuftragAutoversandBerechnen porto_ok + portoFreeLimit'
);

// ============================================================
// Now continue with lines 12700-20000 patterns
// ============================================================

// ReplaceBeleg (~12914) — uses $table which is a validated identifier, treat as safe skip
// The $table comes from controlled switch cases — leave as-is

// ReplaceLager_Platz (~12951)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT kurzbezeichnung FROM lager_platz WHERE id ='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT kurzbezeichnung FROM lager_platz WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceLager_Platz SELECT kurzbezeichnung'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM lager_platz WHERE kurzbezeichnung LIKE '\$value' AND kurzbezeichnung!='' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM lager_platz WHERE kurzbezeichnung LIKE :value AND kurzbezeichnung != '' LIMIT 1\", ['value' => \$value]);",
    'ReplaceLager_Platz SELECT id'
);

// ReplaceLager (~12981)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT bezeichnung FROM lager WHERE id ='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT bezeichnung FROM lager WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceLager SELECT bezeichnung'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM lager WHERE bezeichnung LIKE '\$value' AND bezeichnung!='' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM lager WHERE bezeichnung LIKE :value AND bezeichnung != '' LIMIT 1\", ['value' => \$value]);",
    'ReplaceLager SELECT id'
);

// ReplaceKonten (~13012)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT bezeichnung FROM konten WHERE id ='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT bezeichnung FROM konten WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceKonten SELECT bezeichnung'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM konten WHERE bezeichnung LIKE '\$value' AND bezeichnung!='' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM konten WHERE bezeichnung LIKE :value AND bezeichnung != '' LIMIT 1\", ['value' => \$value]);",
    'ReplaceKonten SELECT id'
);

// ReplaceKostenstelle (~13043)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(nummer,' ',beschreibung) FROM kostenstelle WHERE nummer='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(nummer,' ',beschreibung) FROM kostenstelle WHERE nummer = :nummer LIMIT 1\", ['nummer' => \$id]);",
    'ReplaceKostenstelle SELECT CONCAT'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT nummer FROM kostenstelle WHERE CONCAT(nummer,' ',beschreibung)='\$value' AND CONCAT(nummer,' ',beschreibung)!='' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT nummer FROM kostenstelle WHERE CONCAT(nummer,' ',beschreibung) = :value AND CONCAT(nummer,' ',beschreibung) != '' LIMIT 1\", ['value' => \$value]);",
    'ReplaceKostenstelle SELECT nummer'
);

// ReplaceGruppen (~13074)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(name,' ',kennziffer) as name FROM gruppen WHERE id='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(name,' ',kennziffer) as name FROM gruppen WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceGruppen SELECT CONCAT'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM gruppen WHERE CONCAT(name,' ',kennziffer)='\$value' OR (kennziffer='\$value' AND kennziffer!='') LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM gruppen WHERE CONCAT(name,' ',kennziffer) = :value OR (kennziffer = :kennziffer AND kennziffer != '') LIMIT 1\", ['value' => \$value, 'kennziffer' => \$value]);",
    'ReplaceGruppen SELECT id'
);

// ReplaceWiedervorlageStage (~13105)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(id,' ',kurzbezeichnung,' (',name,')') FROM wiedervorlage_stages WHERE id='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(id,' ',kurzbezeichnung,' (',name,')') FROM wiedervorlage_stages WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceWiedervorlageStage SELECT CONCAT'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM wiedervorlage_stages WHERE id='\$rest' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM wiedervorlage_stages WHERE id = :id LIMIT 1\", ['id' => (int) \$rest]);",
    'ReplaceWiedervorlageStage SELECT id'
);

// ReplaceArbeitspaket (~13139)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(a.id,' ',p.abkuerzung,' ',a.aufgabe) FROM arbeitspaket a LEFT JOIN projekt p ON a.projekt=p.id WHERE a.id='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(a.id,' ',p.abkuerzung,' ',a.aufgabe) FROM arbeitspaket a LEFT JOIN projekt p ON a.projekt = p.id WHERE a.id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceArbeitspaket SELECT CONCAT'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM arbeitspaket WHERE id='\$rest' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM arbeitspaket WHERE id = :id LIMIT 1\", ['id' => (int) \$rest]);",
    'ReplaceArbeitspaket SELECT id'
);

// ReplaceProjekt first instance (~13172)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(abkuerzung,' ',name) FROM projekt WHERE id='\$id' LIMIT 1\");{$n}      } else {{$n}        \$dbformat = 0;{$n}        \$abkuerzung = \$value;{$n}        // wenn nummer keine DB id ist!{$n}        \$tmp = trim(\$value);{$n}        \$rest = explode(\" \", \$tmp);{$n}        \$rest = \$rest[0];{$n}        \$id = \$this->app->DB->Select(\"SELECT id FROM projekt WHERE CONCAT(abkuerzung,' ',name)='\$value' AND abkuerzung!='' LIMIT 1\");{$n}        if (\$id <= 0){$n}          \$id = 0;{$n}      }{$n}    }",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(abkuerzung,' ',name) FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);{$n}      } else {{$n}        \$dbformat = 0;{$n}        \$abkuerzung = \$value;{$n}        // wenn nummer keine DB id ist!{$n}        \$tmp = trim(\$value);{$n}        \$rest = explode(\" \", \$tmp);{$n}        \$rest = \$rest[0];{$n}        \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM projekt WHERE CONCAT(abkuerzung,' ',name) = :value AND abkuerzung != '' LIMIT 1\", ['value' => \$value]);{$n}        if (\$id <= 0){$n}          \$id = 0;{$n}      }{$n}    }",
    'ReplaceProjekt first instance SELECT CONCAT + id'
);

// ReplaceProjekt second instance (~13207)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT CONCAT(abkuerzung,' ',name) FROM projekt WHERE id='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT CONCAT(abkuerzung,' ',name) FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceProjekt second CONCAT'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM projekt WHERE CONCAT(abkuerzung,' ',name)='\$value' AND abkuerzung!='' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM projekt WHERE CONCAT(abkuerzung,' ',name) = :value AND abkuerzung != '' LIMIT 1\", ['value' => \$value]);",
    'ReplaceProjekt second id by CONCAT'
);

// ReplaceProjektAbkuerzung (~13238)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT abkuerzung FROM projekt WHERE id='\$id' LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT abkuerzung FROM projekt WHERE id = :id LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceProjektAbkuerzung SELECT abkuerzung'
);

rep($content,
    "      \$id = \$this->app->DB->Select(\"SELECT id FROM projekt WHERE abkuerzung='\$value' AND abkuerzung!='' LIMIT 1\");",
    "      \$id = \$this->app->DatabaseService->selectValue(\"SELECT id FROM projekt WHERE abkuerzung = :value AND abkuerzung != '' LIMIT 1\", ['value' => \$value]);",
    'ReplaceProjektAbkuerzung SELECT id'
);

// ReplaceLieferant (~13268)
rep($content,
    "        \$abkuerzung = \$this->app->DB->Select(\"SELECT lieferantennummer as name FROM adresse WHERE id='\$id' AND geloescht=0 LIMIT 1\");",
    "        \$abkuerzung = \$this->app->DatabaseService->selectValue(\"SELECT lieferantennummer as name FROM adresse WHERE id = :id AND geloescht = 0 LIMIT 1\", ['id' => (int) \$id]);",
    'ReplaceLieferant SELECT lieferantennummer'
);

// SaveFile
file_put_contents($filepath, $content);
echo "\nTotal: $total\n";
echo "Done.\n";
