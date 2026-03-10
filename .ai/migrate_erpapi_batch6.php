<?php
$file = 'C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php';
$content = file_get_contents($file);
$original = $content;
$changes = [];

function rep(&$content, $old, $new, $label, &$changes) {
    $count = substr_count($content, $old);
    if ($count > 0) {
        $content = str_replace($old, $new, $content);
        $changes[] = "Fixed ($count): $label";
    } else {
        $changes[] = "NOT FOUND: $label";
    }
}

$R = "\r\n"; // Windows line endings

// ---- ArtikelBestellung line 14101 ----
$old = "    \$summe_in_bestellung = \$this->app->DB->Select(\"SELECT \" . (\$format ? \"trim(SUM(bp.menge-bp.geliefert))+0\" : \"SUM(bp.menge-bp.geliefert)\") . \" {$R}  FROM bestellung_position bp {$R}  LEFT JOIN bestellung b ON b.id=bp.bestellung {$R}  WHERE bp.artikel='\$artikel' \" . (\$ohnebestellauftrag ? \" AND bp.auftrag_position_id = 0 \" : \"\") . \" AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen!=1) AND b.status!='abgeschlossen' AND b.status!='freigegeben' AND b.status!='angelegt' AND b.status!='storniert'\");";
$new = "    \$selectExprAB = \$format ? \"trim(SUM(bp.menge-bp.geliefert))+0\" : \"SUM(bp.menge-bp.geliefert)\";{$R}    \$ohnebestellauftragWhereAB = \$ohnebestellauftrag ? \" AND bp.auftrag_position_id = 0 \" : \"\";{$R}    \$summe_in_bestellung = \$this->app->DatabaseService->selectValue({$R}      \"SELECT \$selectExprAB FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung{$R}      WHERE bp.artikel = :artikel \$ohnebestellauftragWhereAB AND bp.geliefert < bp.menge{$R}      AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1){$R}      AND b.status != 'abgeschlossen' AND b.status != 'freigegeben' AND b.status != 'angelegt' AND b.status != 'storniert'\",{$R}      ['artikel' => \$artikel]{$R}    );";
rep($content, $old, $new, 'ArtikelBestellung', $changes);

// ---- ArtikelBestellungNichtVersendet line 14117 ----
$old = "    \$summe_in_bestellung = \$this->app->DB->Select(\"SELECT \" . (\$format ? \"trim(SUM(bp.menge-bp.geliefert))+0\" : \"SUM(bp.menge-bp.geliefert)\") . \" {$R}  FROM bestellung_position bp {$R}  LEFT JOIN bestellung b ON b.id=bp.bestellung {$R}  WHERE bp.artikel='\$artikel' \" . (\$ohnebestellauftrag ? \" AND bp.auftrag_position_id = 0 \" : \"\") . \" AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen!=1) AND (b.status='freigegeben' OR b.status='angelegt')\");";
$new = "    \$selectExprABNV = \$format ? \"trim(SUM(bp.menge-bp.geliefert))+0\" : \"SUM(bp.menge-bp.geliefert)\";{$R}    \$ohnebestellauftragWhereABNV = \$ohnebestellauftrag ? \" AND bp.auftrag_position_id = 0 \" : \"\";{$R}    \$summe_in_bestellung = \$this->app->DatabaseService->selectValue({$R}      \"SELECT \$selectExprABNV FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung{$R}      WHERE bp.artikel = :artikel \$ohnebestellauftragWhereABNV AND bp.geliefert < bp.menge{$R}      AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1){$R}      AND (b.status = 'freigegeben' OR b.status = 'angelegt')\",{$R}      ['artikel' => \$artikel]{$R}    );";
rep($content, $old, $new, 'ArtikelBestellungNichtVersendet', $changes);

// ---- ReserviertAuftrag ----
$old = "    return \$this->app->DB->Select(\"SELECT trim(SUM(ifnull(r.menge,0)))+0{$R}  FROM lager_reserviert r INNER JOIN{$R}  (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge {$R}  FROM auftrag_position ap {$R}  \" . (\$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\") . \"{$R}  INNER JOIN auftrag a ON a.id=ap.auftrag {$R}  WHERE ap.artikel='\$artikel' \" . (\$ohnebestellung ? \" AND isnull(bp.id) \" : \"\") . \" AND a.status='freigegeben' \" . (\$auftrag ? \" AND ap.auftrag = '\$auftrag' \" : \"\") . \"\"{$R}      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\"){$R}      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' {$R}  \" : \"\") . \" GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = '\$artikel' AND r.objekt = 'auftrag'\"{$R}    );{$R}  }{$R}{$R}  // @refactor Bestellung Modul{$R}  function ReserviertLieferschein";
$new = "    \$ohnebestellungJoinRA = \$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\";{$R}    \$ohnebestellungWhereRA = \$ohnebestellung ? \" AND isnull(bp.id) \" : \"\";{$R}    \$auftragWhereRA = \$auftrag ? \" AND ap.auftrag = :auftrag \" : \"\";{$R}    \$vonWhereRA = (\$von && \$von != '0000-00-00') ? \" AND a.datum >= :von \" : \"\";{$R}    \$bisWhereRA = (\$bis && \$bis != '0000-00-00') ? \" AND a.datum <= :bis \" : \"\";{$R}    \$paramsRA = ['artikel' => \$artikel];{$R}    if (\$auftrag) \$paramsRA['auftrag'] = \$auftrag;{$R}    if (\$von && \$von != '0000-00-00') \$paramsRA['von'] = \$von;{$R}    if (\$bis && \$bis != '0000-00-00') \$paramsRA['bis'] = \$bis;{$R}    return \$this->app->DatabaseService->selectValue({$R}      \"SELECT trim(SUM(ifnull(r.menge,0)))+0 FROM lager_reserviert r INNER JOIN{$R}      (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge FROM auftrag_position ap{$R}      \$ohnebestellungJoinRA INNER JOIN auftrag a ON a.id = ap.auftrag{$R}      WHERE ap.artikel = :artikel \$ohnebestellungWhereRA AND a.status = 'freigegeben' \$auftragWhereRA \$vonWhereRA \$bisWhereRA{$R}      GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = :artikel AND r.objekt = 'auftrag'\",{$R}      \$paramsRA{$R}    );{$R}  }{$R}{$R}  // @refactor Bestellung Modul{$R}  function ReserviertLieferschein";
rep($content, $old, $new, 'ReserviertAuftrag', $changes);

// ---- ReserviertLieferschein ----
$old = "    return \$this->app->DB->Select({$R}      \"SELECT trim(SUM(ifnull(r.menge,0)))+0{$R}  FROM \`lager_reserviert\` AS \`r\` INNER JOIN{$R}  (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as \`menge\` {$R}  FROM \`lieferschein_position\` AS \`lp\` {$R}  INNER JOIN \`lieferschein\` AS \`l\` ON l.id = lp.lieferschein {$R}  WHERE lp.artikel = '\$artikel'  AND l.status = 'freigegeben'\"{$R}      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\"){$R}      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' {$R}  \" : \"\") . \" GROUP BY lp.lieferschein, lp.artikel) AS \`lb\` ON r.parameter = lb.lieferschein AND r.artikel = '\$artikel' AND r.objekt = 'lieferschein'\"{$R}    );{$R}  }";
$new = "    \$vonWhereRL = (\$von && \$von != '0000-00-00') ? \" AND l.datum >= :von \" : \"\";{$R}    \$bisWhereRL = (\$bis && \$bis != '0000-00-00') ? \" AND l.datum <= :bis \" : \"\";{$R}    \$paramsRL = ['artikel' => \$artikel];{$R}    if (\$von && \$von != '0000-00-00') \$paramsRL['von'] = \$von;{$R}    if (\$bis && \$bis != '0000-00-00') \$paramsRL['bis'] = \$bis;{$R}    return \$this->app->DatabaseService->selectValue({$R}      \"SELECT trim(SUM(ifnull(r.menge,0)))+0{$R}      FROM \`lager_reserviert\` AS \`r\` INNER JOIN{$R}      (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as \`menge\`{$R}      FROM \`lieferschein_position\` AS \`lp\`{$R}      INNER JOIN \`lieferschein\` AS \`l\` ON l.id = lp.lieferschein{$R}      WHERE lp.artikel = :artikel AND l.status = 'freigegeben' \$vonWhereRL \$bisWhereRL{$R}      GROUP BY lp.lieferschein, lp.artikel) AS \`lb\` ON r.parameter = lb.lieferschein AND r.artikel = :artikel AND r.objekt = 'lieferschein'\",{$R}      \$paramsRL{$R}    );{$R}  }";
rep($content, $old, $new, 'ReserviertLieferschein', $changes);

// ---- ReserviertAuftragLiefertermin ----
$old = "    return \$this->app->DB->Select(\"SELECT trim(SUM(ifnull(r.menge,0)))+0 {$R}  FROM lager_reserviert r INNER JOIN{$R}  (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge {$R}  FROM auftrag_position ap {$R}  \" . (\$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\") . \"{$R}  INNER JOIN auftrag a ON a.id=ap.auftrag {$R}  WHERE ap.artikel='\$artikel' \" . (\$ohnebestellung ? \" AND isnull(bp.id) \" : \"\") . \" AND a.status='freigegeben' \" . (\$auftrag ? \" AND ap.auftrag = '\$auftrag' \" : \"\") . \"\"{$R}      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\"){$R}      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' {$R}  AND (ifnull(ap.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(ap.lieferdatum,'0000-00-00') <= '\$bis'){$R}  AND (ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') <= '\$bis'){$R}  AND (ifnull(a.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.lieferdatum,'0000-00-00') <= '\$bis'){$R}  {$R}  \" : \"\") . \" GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = '\$artikel' AND r.objekt = 'auftrag'\"{$R}    );{$R}  }";
$new = "    \$ohnebestellungJoinRALT = \$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\";{$R}    \$ohnebestellungWhereRALT = \$ohnebestellung ? \" AND isnull(bp.id) \" : \"\";{$R}    \$auftragWhereRALT = \$auftrag ? \" AND ap.auftrag = :auftrag \" : \"\";{$R}    \$vonWhereRALT = (\$von && \$von != '0000-00-00') ? \" AND a.datum >= :von \" : \"\";{$R}    \$bisWhereRALT = (\$bis && \$bis != '0000-00-00') ? \" AND a.datum <= :bis AND (ifnull(ap.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(ap.lieferdatum,'0000-00-00') <= :bis) AND (ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') <= :bis) AND (ifnull(a.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.lieferdatum,'0000-00-00') <= :bis) \" : \"\";{$R}    \$paramsRALT = ['artikel' => \$artikel];{$R}    if (\$auftrag) \$paramsRALT['auftrag'] = \$auftrag;{$R}    if (\$von && \$von != '0000-00-00') \$paramsRALT['von'] = \$von;{$R}    if (\$bis && \$bis != '0000-00-00') \$paramsRALT['bis'] = \$bis;{$R}    return \$this->app->DatabaseService->selectValue({$R}      \"SELECT trim(SUM(ifnull(r.menge,0)))+0 FROM lager_reserviert r INNER JOIN{$R}      (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge FROM auftrag_position ap{$R}      \$ohnebestellungJoinRALT INNER JOIN auftrag a ON a.id = ap.auftrag{$R}      WHERE ap.artikel = :artikel \$ohnebestellungWhereRALT AND a.status = 'freigegeben' \$auftragWhereRALT \$vonWhereRALT \$bisWhereRALT{$R}      GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = :artikel AND r.objekt = 'auftrag'\",{$R}      \$paramsRALT{$R}    );{$R}  }";
rep($content, $old, $new, 'ReserviertAuftragLiefertermin', $changes);

// ---- ArtikelImAuftragLiefertermin ----
$old = "    return \$this->app->DB->Select({$R}      \"SELECT \" . (\$format ? \"trim(SUM(menge))+0\" : \"sum(menge)\") . \" {$R}  FROM auftrag_position ap {$R}  LEFT JOIN auftrag a ON a.id=ap.auftrag {$R}  \" . (\$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\") . \"{$R}  WHERE ap.artikel='\$artikel' \" . (\$ohnebestellung ? \" AND isnull(bp.id) \" : \"\") . \" AND a.status='freigegeben' \" . (\$auftrag ? \" AND ap.auftrag = '\$auftrag' \" : \"\") . \"\"{$R}      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\"){$R}      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' {$R}  AND (ifnull(ap.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(ap.lieferdatum,'0000-00-00') <= '\$bis'){$R}  AND (ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') <= '\$bis'){$R}  AND (ifnull(a.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.lieferdatum,'0000-00-00') <= '\$bis'){$R}  {$R}  \" : \"\"){$R}    );{$R}{$R}    return \$summe_im_auftrag;{$R}  }";
$new = "    \$selectExprIALT = \$format ? \"trim(SUM(menge))+0\" : \"sum(menge)\";{$R}    \$ohnebestellungJoinIALT = \$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\";{$R}    \$ohnebestellungWhereIALT = \$ohnebestellung ? \" AND isnull(bp.id) \" : \"\";{$R}    \$auftragWhereIALT = \$auftrag ? \" AND ap.auftrag = :auftrag \" : \"\";{$R}    \$vonWhereIALT = (\$von && \$von != '0000-00-00') ? \" AND a.datum >= :von \" : \"\";{$R}    \$bisWhereIALT = (\$bis && \$bis != '0000-00-00') ? \" AND a.datum <= :bis AND (ifnull(ap.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(ap.lieferdatum,'0000-00-00') <= :bis) AND (ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') <= :bis) AND (ifnull(a.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.lieferdatum,'0000-00-00') <= :bis) \" : \"\";{$R}    \$paramsIALT = ['artikel' => \$artikel];{$R}    if (\$auftrag) \$paramsIALT['auftrag'] = \$auftrag;{$R}    if (\$von && \$von != '0000-00-00') \$paramsIALT['von'] = \$von;{$R}    if (\$bis && \$bis != '0000-00-00') \$paramsIALT['bis'] = \$bis;{$R}    return \$this->app->DatabaseService->selectValue({$R}      \"SELECT \$selectExprIALT FROM auftrag_position ap LEFT JOIN auftrag a ON a.id = ap.auftrag{$R}      \$ohnebestellungJoinIALT{$R}      WHERE ap.artikel = :artikel \$ohnebestellungWhereIALT AND a.status = 'freigegeben' \$auftragWhereIALT \$vonWhereIALT \$bisWhereIALT\",{$R}      \$paramsIALT{$R}    );{$R}{$R}    return \$summe_im_auftrag;{$R}  }";
rep($content, $old, $new, 'ArtikelImAuftragLiefertermin', $changes);

// ---- ArtikelImAuftrag ----
$old = "    return \$this->app->DB->Select({$R}      \"SELECT \" . (\$format ? \"trim(SUM(ap.menge))+0\" : \"SUM(ap.menge)\") . \" {$R}  FROM auftrag_position ap {$R}  \" . (\$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\") . \"{$R}  LEFT JOIN auftrag a ON a.id=ap.auftrag {$R}  WHERE ap.artikel='\$artikel' \" . (\$ohnebestellung ? \" AND isnull(bp.id) \" : \"\") . \"  AND a.status='freigegeben' \" . (\$auftrag ? \" AND ap.auftrag = '\$auftrag' \" : \"\") . \"  \"{$R}      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\"){$R}      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' \" : \"\"){$R}    );{$R}  }";
$new = "    \$selectExprIA = \$format ? \"trim(SUM(ap.menge))+0\" : \"SUM(ap.menge)\";{$R}    \$ohnebestellungJoinIA = \$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\";{$R}    \$ohnebestellungWhereIA = \$ohnebestellung ? \" AND isnull(bp.id) \" : \"\";{$R}    \$auftragWhereIA = \$auftrag ? \" AND ap.auftrag = :auftrag \" : \"\";{$R}    \$vonWhereIA = (\$von && \$von != '0000-00-00') ? \" AND a.datum >= :von \" : \"\";{$R}    \$bisWhereIA = (\$bis && \$bis != '0000-00-00') ? \" AND a.datum <= :bis \" : \"\";{$R}    \$paramsIA = ['artikel' => \$artikel];{$R}    if (\$auftrag) \$paramsIA['auftrag'] = \$auftrag;{$R}    if (\$von && \$von != '0000-00-00') \$paramsIA['von'] = \$von;{$R}    if (\$bis && \$bis != '0000-00-00') \$paramsIA['bis'] = \$bis;{$R}    return \$this->app->DatabaseService->selectValue({$R}      \"SELECT \$selectExprIA FROM auftrag_position ap{$R}      \$ohnebestellungJoinIA LEFT JOIN auftrag a ON a.id = ap.auftrag{$R}      WHERE ap.artikel = :artikel \$ohnebestellungWhereIA AND a.status = 'freigegeben' \$auftragWhereIA \$vonWhereIA \$bisWhereIA\",{$R}      \$paramsIA{$R}    );{$R}  }";
rep($content, $old, $new, 'ArtikelImAuftrag', $changes);

// ---- ArtikelImAuftragStuecklisteLiefertermin ----
$old = "    return \$this->app->DB->Select({$R}      \"SELECT \" . (\$format ? \"trim(SUM(ap.menge * s.menge))+0\" : \"SUM(ap.menge * s.menge)\") . \" {$R}  FROM auftrag_position ap {$R}  \" . (\$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\") . \"{$R}  INNER JOIN auftrag a ON a.id=ap.auftrag {$R}  INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel {$R}  INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel  {$R}  WHERE s.artikel='\$artikel' \" . (\$ohnebestellung ? \" AND isnull(bp.id) \" : \"\") . \"  AND a.status='freigegeben' AND a2.produktion = 1 \" . (\$auftrag ? \" AND ap.auftrag = '\$auftrag' \" : \"\") . \" \"{$R}      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\"){$R}      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' {$R}    AND (ifnull(ap.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(ap.lieferdatum,'0000-00-00') <= '\$bis'){$R}  AND (ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') <= '\$bis'){$R}  AND (ifnull(a.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.lieferdatum,'0000-00-00') <= '\$bis'){$R}  \" : \"\"){$R}    );{$R}{$R}    return \$summe_im_auftrag;{$R}  }";
$new = "    \$selectExprIASLT = \$format ? \"trim(SUM(ap.menge * s.menge))+0\" : \"SUM(ap.menge * s.menge)\";{$R}    \$ohnebestellungJoinIASLT = \$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\";{$R}    \$ohnebestellungWhereIASLT = \$ohnebestellung ? \" AND isnull(bp.id) \" : \"\";{$R}    \$auftragWhereIASLT = \$auftrag ? \" AND ap.auftrag = :auftrag \" : \"\";{$R}    \$vonWhereIASLT = (\$von && \$von != '0000-00-00') ? \" AND a.datum >= :von \" : \"\";{$R}    \$bisWhereIASLT = (\$bis && \$bis != '0000-00-00') ? \" AND a.datum <= :bis AND (ifnull(ap.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(ap.lieferdatum,'0000-00-00') <= :bis) AND (ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.tatsaechlicheslieferdatum,'0000-00-00') <= :bis) AND (ifnull(a.lieferdatum,'0000-00-00') = '0000-00-00' OR ifnull(a.lieferdatum,'0000-00-00') <= :bis) \" : \"\";{$R}    \$paramsIASLT = ['artikel' => \$artikel];{$R}    if (\$auftrag) \$paramsIASLT['auftrag'] = \$auftrag;{$R}    if (\$von && \$von != '0000-00-00') \$paramsIASLT['von'] = \$von;{$R}    if (\$bis && \$bis != '0000-00-00') \$paramsIASLT['bis'] = \$bis;{$R}    return \$this->app->DatabaseService->selectValue({$R}      \"SELECT \$selectExprIASLT FROM auftrag_position ap{$R}      \$ohnebestellungJoinIASLT INNER JOIN auftrag a ON a.id = ap.auftrag{$R}      INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel{$R}      INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel{$R}      WHERE s.artikel = :artikel \$ohnebestellungWhereIASLT AND a.status = 'freigegeben' AND a2.produktion = 1 \$auftragWhereIASLT \$vonWhereIASLT \$bisWhereIASLT\",{$R}      \$paramsIASLT{$R}    );{$R}{$R}    return \$summe_im_auftrag;{$R}  }";
rep($content, $old, $new, 'ArtikelImAuftragStuecklisteLiefertermin', $changes);

// ---- ArtikelImAuftragStueckliste ----
$old = "    return \$this->app->DB->Select({$R}      \"SELECT \" . (\$format ? \" trim(SUM(ap.menge * s.menge))+0 \" : \" SUM(ap.menge * s.menge) \") . \" {$R}  FROM auftrag_position ap {$R}  \" . (\$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\") . \"{$R}  INNER JOIN auftrag a ON a.id=ap.auftrag {$R}  INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel {$R}  WHERE s.artikel='\$artikel'  \" . (\$ohnebestellung ? \" AND isnull(bp.id) \" : \"\") . \"  AND a.status='freigegeben' AND a2.produktion = 1 \" . (\$auftrag ? \" AND ap.auftrag = '\$auftrag' \" : \"\") . \"\"{$R}      . (\$von && \$von != '0000-00-00' ? \" AND a.datum >= '\$von' \" : \"\"){$R}      . (\$bis && \$bis != '0000-00-00' ? \" AND a.datum <= '\$bis' \" : \"\"){$R}    );{$R}  }";
$new = "    \$selectExprIASL = \$format ? \" trim(SUM(ap.menge * s.menge))+0 \" : \" SUM(ap.menge * s.menge) \";{$R}    \$ohnebestellungJoinIASL = \$ohnebestellung ? \"  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  \" : \"\";{$R}    \$ohnebestellungWhereIASL = \$ohnebestellung ? \" AND isnull(bp.id) \" : \"\";{$R}    \$auftragWhereIASL = \$auftrag ? \" AND ap.auftrag = :auftrag \" : \"\";{$R}    \$vonWhereIASL = (\$von && \$von != '0000-00-00') ? \" AND a.datum >= :von \" : \"\";{$R}    \$bisWhereIASL = (\$bis && \$bis != '0000-00-00') ? \" AND a.datum <= :bis \" : \"\";{$R}    \$paramsIASL = ['artikel' => \$artikel];{$R}    if (\$auftrag) \$paramsIASL['auftrag'] = \$auftrag;{$R}    if (\$von && \$von != '0000-00-00') \$paramsIASL['von'] = \$von;{$R}    if (\$bis && \$bis != '0000-00-00') \$paramsIASL['bis'] = \$bis;{$R}    return \$this->app->DatabaseService->selectValue({$R}      \"SELECT \$selectExprIASL FROM auftrag_position ap{$R}      \$ohnebestellungJoinIASL INNER JOIN auftrag a ON a.id = ap.auftrag{$R}      INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel{$R}      INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel{$R}      WHERE s.artikel = :artikel \$ohnebestellungWhereIASL AND a.status = 'freigegeben' AND a2.produktion = 1 \$auftragWhereIASL \$vonWhereIASL \$bisWhereIASL\",{$R}      \$paramsIASL{$R}    );{$R}  }";
rep($content, $old, $new, 'ArtikelImAuftragStueckliste', $changes);

// ---- LieferscheinNettoGewicht ----
$old = "    if (\$this->Firmendaten('stuecklistegewichtnurartikel') != '1') {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge)\r\n        FROM \" . \$doctype . \"_position ap \r\n        INNER JOIN artikel a ON ap.artikel=a.id WHERE ap.\" . \$doctype . \"='\$id'\"\r\n      );
    } else {
      \$nettogewicht = \$this->app->DB->Select(
        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge)\r\n        FROM \" . \$doctype . \"_position ap \r\n        INNER JOIN artikel a ON ap.artikel=a.id \r\n        LEFT JOIN \" . \$doctype . \"_position ap2 ON ap2.id=ap.explodiert_parent \r\n        LEFT JOIN artikel a2 ON a2.id=ap2.artikel \r\n        WHERE ap.\" . \$doctype . \"='\$id'\"\r\n      );
    }";
// Just search for the key lines and do line-based replacement
// Use line-based approach instead
$lines = explode("\r\n", $content);
$newLines = [];
$i = 0;
while ($i < count($lines)) {
    $line = $lines[$i];
    // Detect LieferscheinNettoGewicht pattern (DB->Select with $doctype)
    if (strpos($line, '$this->app->DB->Select(') !== false
        && isset($lines[$i+1]) && strpos($lines[$i+1], '"SELECT SUM(REPLACE(a.gewicht') !== false
        && isset($lines[$i+2]) && strpos($lines[$i+2], 'FROM " . $doctype . "_position ap') !== false) {
        // Determine indent
        $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
        // Find end of statement (closing );)
        $j = $i;
        while ($j < count($lines) && !(strpos($lines[$j], ');') !== false && $j > $i)) {
            $j++;
        }
        // Replace with prepared statement
        $newLines[] = "{$indent}\$dtPos = \$doctype . '_position';";
        $newLines[] = "{$indent}\$dtCol = \$doctype;";
        $newLines[] = "{$indent}\$nettogewicht = \$this->app->DatabaseService->selectValue(";
        $newLines[] = "{$indent}  \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge) FROM `{\$dtPos}` ap INNER JOIN artikel a ON ap.artikel = a.id WHERE ap.`{\$dtCol}` = :id\",";
        $newLines[] = "{$indent}  ['id' => \$id]";
        $newLines[] = "{$indent});";
        $changes[] = "Fixed: LieferscheinNettoGewicht first branch (line " . ($i+1) . ")";
        $i = $j + 1;
        continue;
    }
    // Detect second branch (with if(a2.gewicht > 0...))
    if (strpos($line, '$this->app->DB->Select(') !== false
        && isset($lines[$i+1]) && strpos($lines[$i+1], '"SELECT SUM(REPLACE(if(a2.gewicht') !== false
        && isset($lines[$i+2]) && strpos($lines[$i+2], 'FROM " . $doctype . "_position ap') !== false) {
        $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
        $j = $i;
        while ($j < count($lines) && !(strpos($lines[$j], ');') !== false && $j > $i)) {
            $j++;
        }
        $newLines[] = "{$indent}\$nettogewicht = \$this->app->DatabaseService->selectValue(";
        $newLines[] = "{$indent}  \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge) FROM `{\$dtPos}` ap INNER JOIN artikel a ON ap.artikel = a.id LEFT JOIN `{\$dtPos}` ap2 ON ap2.id = ap.explodiert_parent LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.`{\$dtCol}` = :id\",";
        $newLines[] = "{$indent}  ['id' => \$id]";
        $newLines[] = "{$indent});";
        $changes[] = "Fixed: LieferscheinNettoGewicht second branch (line " . ($i+1) . ")";
        $i = $j + 1;
        continue;
    }
    $newLines[] = $line;
    $i++;
}
$content = implode("\r\n", $newLines);

// ---- AuftragNettoGewicht ----
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge)\r\n        FROM auftrag_position ap \r\n        INNER JOIN artikel a ON ap.artikel=a.id \r\n        WHERE ap.auftrag='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*ap.menge) FROM auftrag_position ap INNER JOIN artikel a ON ap.artikel = a.id WHERE ap.auftrag = :id\",\r\n        ['id' => \$id]\r\n      );",
    'AuftragNettoGewicht branch 1', $changes
);
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge)\r\n        FROM auftrag_position ap \r\n        INNER JOIN artikel a ON ap.artikel=a.id \r\n        LEFT JOIN auftrag_position ap2 ON ap2.id=ap.explodiert_parent \r\n        LEFT JOIN artikel a2 ON a2.id=ap2.artikel \r\n        WHERE ap.auftrag='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*ap.menge) FROM auftrag_position ap INNER JOIN artikel a ON ap.artikel = a.id LEFT JOIN auftrag_position ap2 ON ap2.id = ap.explodiert_parent LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.auftrag = :id\",\r\n        ['id' => \$id]\r\n      );",
    'AuftragNettoGewicht branch 2', $changes
);

// ---- BestellungNettoGewicht ----
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*bp.menge)\r\n        FROM bestellung_position bp \r\n        INNER JOIN artikel a ON bp.artikel=a.id WHERE bp.bestellung='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(a.gewicht,',','.')*bp.menge) FROM bestellung_position bp INNER JOIN artikel a ON bp.artikel = a.id WHERE bp.bestellung = :id\",\r\n        ['id' => \$id]\r\n      );",
    'BestellungNettoGewicht branch 1', $changes
);
rep($content,
    "      \$nettogewicht = \$this->app->DB->Select(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*bp.menge)\r\n        FROM bestellung_position bp \r\n        INNER JOIN artikel a ON bp.artikel=a.id \r\n        LEFT JOIN bestellung_position bp2 ON bp2.id=bp.explodiert_parent \r\n        LEFT JOIN artikel a2 ON a2.id=bp2.artikel \r\n        WHERE bp.bestellung='\$id'\"\r\n      );",
    "      \$nettogewicht = \$this->app->DatabaseService->selectValue(\r\n        \"SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),',','.')*bp.menge) FROM bestellung_position bp INNER JOIN artikel a ON bp.artikel = a.id LEFT JOIN bestellung_position bp2 ON bp2.id = bp.explodiert_parent LEFT JOIN artikel a2 ON a2.id = bp2.artikel WHERE bp.bestellung = :id\",\r\n        ['id' => \$id]\r\n      );",
    'BestellungNettoGewicht branch 2', $changes
);

// Write if changed
if ($content !== $original) {
    file_put_contents($file, $content);
    echo "File written successfully\n";
} else {
    echo "No changes made\n";
}

foreach ($changes as $c) {
    echo $c . "\n";
}
