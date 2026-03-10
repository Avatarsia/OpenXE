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

// ---- ArtikelBestellung ----
$old = '    $summe_in_bestellung = $this->app->DB->Select("SELECT " . ($format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)") . "
  FROM bestellung_position bp
  LEFT JOIN bestellung b ON b.id=bp.bestellung
  WHERE bp.artikel=\'$artikel\' " . ($ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "") . " AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen!=1) AND b.status!=\'abgeschlossen\' AND b.status!=\'freigegeben\' AND b.status!=\'angelegt\' AND b.status!=\'storniert\'");

    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }

  // @refactor Bestellung Modul
  function ArtikelBestellungNichtVersendet';
$new = '    $selectExprAB = $format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)";
    $ohnebestellauftragWhereAB = $ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "";
    $summe_in_bestellung = $this->app->DatabaseService->selectValue(
      "SELECT $selectExprAB FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung
      WHERE bp.artikel = :artikel $ohnebestellauftragWhereAB AND bp.geliefert < bp.menge
      AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1)
      AND b.status != \'abgeschlossen\' AND b.status != \'freigegeben\' AND b.status != \'angelegt\' AND b.status != \'storniert\'",
      [\'artikel\' => $artikel]
    );

    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }

  // @refactor Bestellung Modul
  function ArtikelBestellungNichtVersendet';
rep($content, $old, $new, 'ArtikelBestellung', $changes);

// ---- ArtikelBestellungNichtVersendet ----
$old = '    $summe_in_bestellung = $this->app->DB->Select("SELECT " . ($format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)") . "
  FROM bestellung_position bp
  LEFT JOIN bestellung b ON b.id=bp.bestellung
  WHERE bp.artikel=\'$artikel\' " . ($ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "") . " AND bp.geliefert < bp.menge AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen!=1) AND (b.status=\'freigegeben\' OR b.status=\'angelegt\')");


    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }';
$new = '    $selectExprABNV = $format ? "trim(SUM(bp.menge-bp.geliefert))+0" : "SUM(bp.menge-bp.geliefert)";
    $ohnebestellauftragWhereABNV = $ohnebestellauftrag ? " AND bp.auftrag_position_id = 0 " : "";
    $summe_in_bestellung = $this->app->DatabaseService->selectValue(
      "SELECT $selectExprABNV FROM bestellung_position bp LEFT JOIN bestellung b ON b.id = bp.bestellung
      WHERE bp.artikel = :artikel $ohnebestellauftragWhereABNV AND bp.geliefert < bp.menge
      AND (bp.abgeschlossen IS NULL OR bp.abgeschlossen != 1)
      AND (b.status = \'freigegeben\' OR b.status = \'angelegt\')",
      [\'artikel\' => $artikel]
    );


    if ($summe_in_bestellung <= 0)
      return 0;

    return $summe_in_bestellung;
  }';
rep($content, $old, $new, 'ArtikelBestellungNichtVersendet', $changes);

// ---- ReserviertAuftrag (complex dynamic SQL - keep as-is but migrate artikel param) ----
// These functions use $artikel in WHERE clause - migrate to prepared statement
$old = '    return $this->app->DB->Select("SELECT trim(SUM(ifnull(r.menge,0)))+0
  FROM lager_reserviert r INNER JOIN
  (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge
  FROM auftrag_position ap
  " . ($ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "") . "
  INNER JOIN auftrag a ON a.id=ap.auftrag
  WHERE ap.artikel=\'$artikel\' " . ($ohnebestellung ? " AND isnull(bp.id) " : "") . " AND a.status=\'freigegeben\' " . ($auftrag ? " AND ap.auftrag = \'$auftrag\' " : "") . ""
      . ($von && $von != \'0000-00-00\' ? " AND a.datum >= \'$von\' " : "")
      . ($bis && $bis != \'0000-00-00\' ? " AND a.datum <= \'$bis\'
  " : "") . " GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = \'$artikel\' AND r.objekt = \'auftrag\'"
    );
  }

  // @refactor Bestellung Modul
  function ReserviertLieferschein';
$new = '    $ohnebestellungJoin = $ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "";
    $ohnebestellungWhere = $ohnebestellung ? " AND isnull(bp.id) " : "";
    $auftragWhere = $auftrag ? " AND ap.auftrag = :auftrag " : "";
    $vonWhere = ($von && $von != \'0000-00-00\') ? " AND a.datum >= :von " : "";
    $bisWhere = ($bis && $bis != \'0000-00-00\') ? " AND a.datum <= :bis \n  " : "";
    $params = [\'artikel\' => $artikel];
    if ($auftrag) $params[\'auftrag\'] = $auftrag;
    if ($von && $von != \'0000-00-00\') $params[\'von\'] = $von;
    if ($bis && $bis != \'0000-00-00\') $params[\'bis\'] = $bis;
    return $this->app->DatabaseService->selectValue(
      "SELECT trim(SUM(ifnull(r.menge,0)))+0
      FROM lager_reserviert r INNER JOIN
      (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge FROM auftrag_position ap
      $ohnebestellungJoin INNER JOIN auftrag a ON a.id = ap.auftrag
      WHERE ap.artikel = :artikel $ohnebestellungWhere AND a.status = \'freigegeben\' $auftragWhere $vonWhere $bisWhere
      GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = :artikel AND r.objekt = \'auftrag\'",
      $params
    );
  }

  // @refactor Bestellung Modul
  function ReserviertLieferschein';
rep($content, $old, $new, 'ReserviertAuftrag', $changes);

// ---- ReserviertLieferschein ----
$old = '    return $this->app->DB->Select(
      "SELECT trim(SUM(ifnull(r.menge,0)))+0
  FROM `lager_reserviert` AS `r` INNER JOIN
  (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as `menge`
  FROM `lieferschein_position` AS `lp`
  INNER JOIN `lieferschein` AS `l` ON l.id = lp.lieferschein
  WHERE lp.artikel = \'$artikel\'  AND l.status = \'freigegeben\'"
      . ($von && $von != \'0000-00-00\' ? " AND a.datum >= \'$von\' " : "")
      . ($bis && $bis != \'0000-00-00\' ? " AND a.datum <= \'$bis\'
  " : "") . " GROUP BY lp.lieferschein, lp.artikel) AS `lb` ON r.parameter = lb.lieferschein AND r.artikel = \'$artikel\' AND r.objekt = \'lieferschein\'"
    );
  }';
$new = '    $vonWhereRL = ($von && $von != \'0000-00-00\') ? " AND a.datum >= :von " : "";
    $bisWhereRL = ($bis && $bis != \'0000-00-00\') ? " AND a.datum <= :bis \n  " : "";
    $paramsRL = [\'artikel\' => $artikel];
    if ($von && $von != \'0000-00-00\') $paramsRL[\'von\'] = $von;
    if ($bis && $bis != \'0000-00-00\') $paramsRL[\'bis\'] = $bis;
    return $this->app->DatabaseService->selectValue(
      "SELECT trim(SUM(ifnull(r.menge,0)))+0
      FROM `lager_reserviert` AS `r` INNER JOIN
      (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as `menge`
      FROM `lieferschein_position` AS `lp`
      INNER JOIN `lieferschein` AS `l` ON l.id = lp.lieferschein
      WHERE lp.artikel = :artikel AND l.status = \'freigegeben\' $vonWhereRL $bisWhereRL
      GROUP BY lp.lieferschein, lp.artikel) AS `lb` ON r.parameter = lb.lieferschein AND r.artikel = :artikel AND r.objekt = \'lieferschein\'",
      $paramsRL
    );
  }';
rep($content, $old, $new, 'ReserviertLieferschein', $changes);

// ---- ReserviertAuftragLiefertermin ----
$old = '    return $this->app->DB->Select("SELECT trim(SUM(ifnull(r.menge,0)))+0
  FROM lager_reserviert r INNER JOIN
  (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge
  FROM auftrag_position ap
  " . ($ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "") . "
  INNER JOIN auftrag a ON a.id=ap.auftrag
  WHERE ap.artikel=\'$artikel\' " . ($ohnebestellung ? " AND isnull(bp.id) " : "") . " AND a.status=\'freigegeben\' " . ($auftrag ? " AND ap.auftrag = \'$auftrag\' " : "") . ""
      . ($von && $von != \'0000-00-00\' ? " AND a.datum >= \'$von\' " : "")
      . ($bis && $bis != \'0000-00-00\' ? " AND a.datum <= \'$bis\'
  AND (ifnull(ap.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(ap.lieferdatum,\'0000-00-00\') <= \'$bis\')
  AND (ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') <= \'$bis\')
  AND (ifnull(a.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.lieferdatum,\'0000-00-00\') <= \'$bis\')

  " : "") . " GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = \'$artikel\' AND r.objekt = \'auftrag\'"
    );
  }';
$new = '    $ohnebestellungJoinRALT = $ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "";
    $ohnebestellungWhereRALT = $ohnebestellung ? " AND isnull(bp.id) " : "";
    $auftragWhereRALT = $auftrag ? " AND ap.auftrag = :auftrag " : "";
    $vonWhereRALT = ($von && $von != \'0000-00-00\') ? " AND a.datum >= :von " : "";
    $bisWhereRALT = ($bis && $bis != \'0000-00-00\') ? " AND a.datum <= :bis AND (ifnull(ap.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(ap.lieferdatum,\'0000-00-00\') <= :bis) AND (ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') <= :bis) AND (ifnull(a.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.lieferdatum,\'0000-00-00\') <= :bis) " : "";
    $paramsRALT = [\'artikel\' => $artikel];
    if ($auftrag) $paramsRALT[\'auftrag\'] = $auftrag;
    if ($von && $von != \'0000-00-00\') $paramsRALT[\'von\'] = $von;
    if ($bis && $bis != \'0000-00-00\') $paramsRALT[\'bis\'] = $bis;
    return $this->app->DatabaseService->selectValue(
      "SELECT trim(SUM(ifnull(r.menge,0)))+0 FROM lager_reserviert r INNER JOIN
      (SELECT ap.auftrag, ap.artikel, sum(ap.menge) as menge FROM auftrag_position ap
      $ohnebestellungJoinRALT INNER JOIN auftrag a ON a.id = ap.auftrag
      WHERE ap.artikel = :artikel $ohnebestellungWhereRALT AND a.status = \'freigegeben\' $auftragWhereRALT $vonWhereRALT $bisWhereRALT
      GROUP BY ap.auftrag, ap.artikel) ab ON r.parameter = ab.auftrag AND r.artikel = :artikel AND r.objekt = \'auftrag\'",
      $paramsRALT
    );
  }';
rep($content, $old, $new, 'ReserviertAuftragLiefertermin', $changes);

// ---- ArtikelImAuftragLiefertermin ----
$old = '    return $this->app->DB->Select(
      "SELECT " . ($format ? "trim(SUM(menge))+0" : "sum(menge)") . "
  FROM auftrag_position ap
  LEFT JOIN auftrag a ON a.id=ap.auftrag
  " . ($ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "") . "
  WHERE ap.artikel=\'$artikel\' " . ($ohnebestellung ? " AND isnull(bp.id) " : "") . " AND a.status=\'freigegeben\' " . ($auftrag ? " AND ap.auftrag = \'$auftrag\' " : "") . ""
      . ($von && $von != \'0000-00-00\' ? " AND a.datum >= \'$von\' " : "")
      . ($bis && $bis != \'0000-00-00\' ? " AND a.datum <= \'$bis\'
  AND (ifnull(ap.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(ap.lieferdatum,\'0000-00-00\') <= \'$bis\')
  AND (ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') <= \'$bis\')
  AND (ifnull(a.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.lieferdatum,\'0000-00-00\') <= \'$bis\')

  " : "")
    );

    return $summe_im_auftrag;
  }';
$new = '    $selectExprIALT = $format ? "trim(SUM(menge))+0" : "sum(menge)";
    $ohnebestellungJoinIALT = $ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "";
    $ohnebestellungWhereIALT = $ohnebestellung ? " AND isnull(bp.id) " : "";
    $auftragWhereIALT = $auftrag ? " AND ap.auftrag = :auftrag " : "";
    $vonWhereIALT = ($von && $von != \'0000-00-00\') ? " AND a.datum >= :von " : "";
    $bisWhereIALT = ($bis && $bis != \'0000-00-00\') ? " AND a.datum <= :bis AND (ifnull(ap.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(ap.lieferdatum,\'0000-00-00\') <= :bis) AND (ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') <= :bis) AND (ifnull(a.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.lieferdatum,\'0000-00-00\') <= :bis) " : "";
    $paramsIALT = [\'artikel\' => $artikel];
    if ($auftrag) $paramsIALT[\'auftrag\'] = $auftrag;
    if ($von && $von != \'0000-00-00\') $paramsIALT[\'von\'] = $von;
    if ($bis && $bis != \'0000-00-00\') $paramsIALT[\'bis\'] = $bis;
    return $this->app->DatabaseService->selectValue(
      "SELECT $selectExprIALT FROM auftrag_position ap LEFT JOIN auftrag a ON a.id = ap.auftrag
      $ohnebestellungJoinIALT
      WHERE ap.artikel = :artikel $ohnebestellungWhereIALT AND a.status = \'freigegeben\' $auftragWhereIALT $vonWhereIALT $bisWhereIALT",
      $paramsIALT
    );

    return $summe_im_auftrag;
  }';
rep($content, $old, $new, 'ArtikelImAuftragLiefertermin', $changes);

// ---- ArtikelImAuftrag ----
$old = '    return $this->app->DB->Select(
      "SELECT " . ($format ? "trim(SUM(ap.menge))+0" : "SUM(ap.menge)") . "
  FROM auftrag_position ap
  " . ($ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "") . "
  LEFT JOIN auftrag a ON a.id=ap.auftrag
  WHERE ap.artikel=\'$artikel\' " . ($ohnebestellung ? " AND isnull(bp.id) " : "") . "  AND a.status=\'freigegeben\' " . ($auftrag ? " AND ap.auftrag = \'$auftrag\' " : "") . "  "
      . ($von && $von != \'0000-00-00\' ? " AND a.datum >= \'$von\' " : "")
      . ($bis && $bis != \'0000-00-00\' ? " AND a.datum <= \'$bis\' " : "")
    );
  }

  // @refactor Bestellung Modul
  function ArtikelImAuftragStuecklisteLiefertermin';
$new = '    $selectExprIA = $format ? "trim(SUM(ap.menge))+0" : "SUM(ap.menge)";
    $ohnebestellungJoinIA = $ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "";
    $ohnebestellungWhereIA = $ohnebestellung ? " AND isnull(bp.id) " : "";
    $auftragWhereIA = $auftrag ? " AND ap.auftrag = :auftrag " : "";
    $vonWhereIA = ($von && $von != \'0000-00-00\') ? " AND a.datum >= :von " : "";
    $bisWhereIA = ($bis && $bis != \'0000-00-00\') ? " AND a.datum <= :bis " : "";
    $paramsIA = [\'artikel\' => $artikel];
    if ($auftrag) $paramsIA[\'auftrag\'] = $auftrag;
    if ($von && $von != \'0000-00-00\') $paramsIA[\'von\'] = $von;
    if ($bis && $bis != \'0000-00-00\') $paramsIA[\'bis\'] = $bis;
    return $this->app->DatabaseService->selectValue(
      "SELECT $selectExprIA FROM auftrag_position ap
      $ohnebestellungJoinIA LEFT JOIN auftrag a ON a.id = ap.auftrag
      WHERE ap.artikel = :artikel $ohnebestellungWhereIA AND a.status = \'freigegeben\' $auftragWhereIA $vonWhereIA $bisWhereIA",
      $paramsIA
    );
  }

  // @refactor Bestellung Modul
  function ArtikelImAuftragStuecklisteLiefertermin';
rep($content, $old, $new, 'ArtikelImAuftrag', $changes);

// ---- ArtikelImAuftragStuecklisteLiefertermin ----
$old = '    return $this->app->DB->Select(
      "SELECT " . ($format ? "trim(SUM(ap.menge * s.menge))+0" : "SUM(ap.menge * s.menge)") . "
  FROM auftrag_position ap
  " . ($ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "") . "
  INNER JOIN auftrag a ON a.id=ap.auftrag
  INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel
  INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel
  WHERE s.artikel=\'$artikel\' " . ($ohnebestellung ? " AND isnull(bp.id) " : "") . "  AND a.status=\'freigegeben\' AND a2.produktion = 1 " . ($auftrag ? " AND ap.auftrag = \'$auftrag\' " : "") . " "
      . ($von && $von != \'0000-00-00\' ? " AND a.datum >= \'$von\' " : "")
      . ($bis && $bis != \'0000-00-00\' ? " AND a.datum <= \'$bis\'
    AND (ifnull(ap.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(ap.lieferdatum,\'0000-00-00\') <= \'$bis\')
  AND (ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') <= \'$bis\')
  AND (ifnull(a.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.lieferdatum,\'0000-00-00\') <= \'$bis\')
  " : "")
    );

    return $summe_im_auftrag;
  }

  // @refactor Bestellung Modul
  function ArtikelImAuftragStueckliste';
$new = '    $selectExprIASLT = $format ? "trim(SUM(ap.menge * s.menge))+0" : "SUM(ap.menge * s.menge)";
    $ohnebestellungJoinIASLT = $ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "";
    $ohnebestellungWhereIASLT = $ohnebestellung ? " AND isnull(bp.id) " : "";
    $auftragWhereIASLT = $auftrag ? " AND ap.auftrag = :auftrag " : "";
    $vonWhereIASLT = ($von && $von != \'0000-00-00\') ? " AND a.datum >= :von " : "";
    $bisWhereIASLT = ($bis && $bis != \'0000-00-00\') ? " AND a.datum <= :bis AND (ifnull(ap.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(ap.lieferdatum,\'0000-00-00\') <= :bis) AND (ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.tatsaechlicheslieferdatum,\'0000-00-00\') <= :bis) AND (ifnull(a.lieferdatum,\'0000-00-00\') = \'0000-00-00\' OR ifnull(a.lieferdatum,\'0000-00-00\') <= :bis) " : "";
    $paramsIASLT = [\'artikel\' => $artikel];
    if ($auftrag) $paramsIASLT[\'auftrag\'] = $auftrag;
    if ($von && $von != \'0000-00-00\') $paramsIASLT[\'von\'] = $von;
    if ($bis && $bis != \'0000-00-00\') $paramsIASLT[\'bis\'] = $bis;
    return $this->app->DatabaseService->selectValue(
      "SELECT $selectExprIASLT FROM auftrag_position ap
      $ohnebestellungJoinIASLT INNER JOIN auftrag a ON a.id = ap.auftrag
      INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel
      INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel
      WHERE s.artikel = :artikel $ohnebestellungWhereIASLT AND a.status = \'freigegeben\' AND a2.produktion = 1 $auftragWhereIASLT $vonWhereIASLT $bisWhereIASLT",
      $paramsIASLT
    );

    return $summe_im_auftrag;
  }

  // @refactor Bestellung Modul
  function ArtikelImAuftragStueckliste';
rep($content, $old, $new, 'ArtikelImAuftragStuecklisteLiefertermin', $changes);

// ---- ArtikelImAuftragStueckliste ----
$old = '    return $this->app->DB->Select(
      "SELECT " . ($format ? " trim(SUM(ap.menge * s.menge))+0 " : " SUM(ap.menge * s.menge) ") . "
  FROM auftrag_position ap
  " . ($ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "") . "
  INNER JOIN auftrag a ON a.id=ap.auftrag
  INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel
  WHERE s.artikel=\'$artikel\'  " . ($ohnebestellung ? " AND isnull(bp.id) " : "") . "  AND a.status=\'freigegeben\' AND a2.produktion = 1 " . ($auftrag ? " AND ap.auftrag = \'$auftrag\' " : "") . ""
      . ($von && $von != \'0000-00-00\' ? " AND a.datum >= \'$von\' " : "")
      . ($bis && $bis != \'0000-00-00\' ? " AND a.datum <= \'$bis\' " : "")
    );
  }

  // @refactor Lager Modul
  function ArtikelImLagerPlatz';
$new = '    $selectExprIASL = $format ? " trim(SUM(ap.menge * s.menge))+0 " : " SUM(ap.menge * s.menge) ";
    $ohnebestellungJoinIASL = $ohnebestellung ? "  LEFT JOIN bestellung_position bp ON ap.id = bp.auftrag_position_id  " : "";
    $ohnebestellungWhereIASL = $ohnebestellung ? " AND isnull(bp.id) " : "";
    $auftragWhereIASL = $auftrag ? " AND ap.auftrag = :auftrag " : "";
    $vonWhereIASL = ($von && $von != \'0000-00-00\') ? " AND a.datum >= :von " : "";
    $bisWhereIASL = ($bis && $bis != \'0000-00-00\') ? " AND a.datum <= :bis " : "";
    $paramsIASL = [\'artikel\' => $artikel];
    if ($auftrag) $paramsIASL[\'auftrag\'] = $auftrag;
    if ($von && $von != \'0000-00-00\') $paramsIASL[\'von\'] = $von;
    if ($bis && $bis != \'0000-00-00\') $paramsIASL[\'bis\'] = $bis;
    return $this->app->DatabaseService->selectValue(
      "SELECT $selectExprIASL FROM auftrag_position ap
      $ohnebestellungJoinIASL INNER JOIN auftrag a ON a.id = ap.auftrag
      INNER JOIN stueckliste s ON ap.artikel = s.stuecklistevonartikel
      INNER JOIN artikel a2 ON a2.id = s.stuecklistevonartikel
      WHERE s.artikel = :artikel $ohnebestellungWhereIASL AND a.status = \'freigegeben\' AND a2.produktion = 1 $auftragWhereIASL $vonWhereIASL $bisWhereIASL",
      $paramsIASL
    );
  }

  // @refactor Lager Modul
  function ArtikelImLagerPlatz';
rep($content, $old, $new, 'ArtikelImAuftragStueckliste', $changes);

// ---- BestellungErweiterteVerbindlichkeiten (line 14347) ----
// This uses dynamic column names bestellung{$i} - can't fully parameterize column names
// but the $id value is a SQL injection risk
$old = '      $alleids[] = $this->app->DB->SelectArr("SELECT id, bestellung{$i}betrag as betrag FROM verbindlichkeit WHERE bestellung$i=\'$id\'");';
$new = '      $col = "bestellung{$i}";
      $alleids[] = $this->app->DB->SelectArr("SELECT id, {$col}betrag as betrag FROM verbindlichkeit WHERE {$col}=" . (int)$id);';
rep($content, $old, $new, 'BestellungErweiterteVerbindlichkeiten (int cast)', $changes);

// ---- AufragZuDTA (deprecated) line ~14386 ----
// This function uses $arr[0] data from a DB query - we fix the initial query
$old = '    $arr = $this->app->DB->Select("SELECT belegnr, bank_inhaber, bank_konto, bank_blz, gesamtsumme, adresse, name FROM auftrag WHERE id=\'$auftrag\'");


    if ($rechnung == "1") {
      $arr[0][\'vz1\'] = "RE " . $this->app->DB->Select("SELECT belegnr FROM rechnung WHERE auftrag=\'{$arr[0][\'belegnr\']}\' LIMIT 1");
    } else
      $arr[0][\'vz1\'] = "";

    $this->app->DB->Insert("INSERT INTO dta (id,adresse,datum,name,konto,blz,betrag,vz1,firma)
      VALUES(\'\',\'{$arr[0][\'adresse\']}\',NOW(),\'{$arr[0][\'name\']}\',\'{$arr[0][\'konto\']}\',\'{$arr[0][\'blz\']}\',\'{$arr[0][\'betrag\']}\',\'{$arr[0][\'vz1\']}\',\'" . $this->app->User->GetFirma() . "\')");';
$new = '    $arr = $this->app->DatabaseService->selectRow("SELECT belegnr, bank_inhaber, bank_konto, bank_blz, gesamtsumme, adresse, name FROM auftrag WHERE id = :id", [\'id\' => $auftrag]);


    if ($rechnung == "1") {
      $arr[\'vz1\'] = "RE " . $this->app->DatabaseService->selectValue("SELECT belegnr FROM rechnung WHERE auftrag = :belegnr LIMIT 1", [\'belegnr\' => $arr[\'belegnr\']]);
    } else
      $arr[\'vz1\'] = "";

    $this->app->DatabaseService->execute(
      "INSERT INTO dta (id,adresse,datum,name,konto,blz,betrag,vz1,firma) VALUES(\'\', :adresse, NOW(), :name, :konto, :blz, :betrag, :vz1, :firma)",
      [\'adresse\' => $arr[\'adresse\'], \'name\' => $arr[\'name\'], \'konto\' => $arr[\'bank_konto\'], \'blz\' => $arr[\'bank_blz\'], \'betrag\' => $arr[\'gesamtsumme\'], \'vz1\' => $arr[\'vz1\'], \'firma\' => $this->app->User->GetFirma()]
    );';
rep($content, $old, $new, 'AufragZuDTA (deprecated)', $changes);

// ---- TrackingNummerAnpassen line ~14409 ----
rep($content,
    '    $kundennummerdpd = $this->app->DB->Select("SELECT dpdkundennr FROM projekt WHERE id=\'$projekt\' LIMIT 1");',
    '    $kundennummerdpd = $this->app->DatabaseService->selectValue("SELECT dpdkundennr FROM projekt WHERE id = :id LIMIT 1", [\'id\' => $projekt]);',
    'TrackingNummerAnpassen', $changes
);

// ---- PaketmarkeGewichtForm line ~14423 ----
rep($content,
    '      $mitwaage = $this->app->DB->Select("SELECT seriennummer FROM adapterbox WHERE verwendenals = \'waage\' AND seriennummer <> \'\' LIMIT 1");',
    '      $mitwaage = $this->app->DatabaseService->selectValue("SELECT seriennummer FROM adapterbox WHERE verwendenals = \'waage\' AND seriennummer <> \'\' LIMIT 1", []);',
    'PaketmarkeGewichtForm', $changes
);

// ---- VersandartMindestgewicht lines ~14461-14466 ----
$old = '      $versandart = $this->app->DB->Select("SELECT versandart FROM lieferschein WHERE id=\'$id\' LIMIT 1");
      $projekt = $this->app->DB->Select("SELECT projekt FROM lieferschein WHERE id=\'$id\' LIMIT 1");
      $intraship_weightinkg = $this->app->DB->Select("SELECT intraship_weightinkg FROM projekt WHERE id=\'$projekt\' LIMIT 1");
      $versandart = strtolower($versandart);

      $modul = $this->app->DB->SelectArr("SELECT id, modul FROM `versandarten` WHERE aktiv = 1 AND ausprojekt = 0 AND modul <> \'\' AND type = \'" . $this->app->DB->real_escape_string($versandart) . "\' AND geloescht = 0 AND (projekt = 0 OR projekt = \'$projekt\') ORDER by projekt = \'$projekt\' DESC LIMIT 1");';
$new = '      $versandart = $this->app->DatabaseService->selectValue("SELECT versandart FROM lieferschein WHERE id = :id LIMIT 1", [\'id\' => $id]);
      $projekt = $this->app->DatabaseService->selectValue("SELECT projekt FROM lieferschein WHERE id = :id LIMIT 1", [\'id\' => $id]);
      $intraship_weightinkg = $this->app->DatabaseService->selectValue("SELECT intraship_weightinkg FROM projekt WHERE id = :id LIMIT 1", [\'id\' => $projekt]);
      $versandart = strtolower($versandart);

      $modul = $this->app->DatabaseService->select("SELECT id, modul FROM `versandarten` WHERE aktiv = 1 AND ausprojekt = 0 AND modul <> \'\' AND type = :type AND geloescht = 0 AND (projekt = 0 OR projekt = :projekt) ORDER BY projekt = :projekt DESC LIMIT 1", [\'type\' => $versandart, \'projekt\' => $projekt]);';
rep($content, $old, $new, 'VersandartMindestgewicht', $changes);

// ---- LieferscheinNettoGewicht (uses dynamic $doctype table name) ----
$old = '    if ($this->Firmendaten(\'stuecklistegewichtnurartikel\') != \'1\') {
      $nettogewicht = $this->app->DB->Select(
        "SELECT SUM(REPLACE(a.gewicht,\',\',\'.\')*ap.menge)
        FROM " . $doctype . "_position ap
        INNER JOIN artikel a ON ap.artikel=a.id WHERE ap." . $doctype . "=\'$id\'"
      );
    } else {
      $nettogewicht = $this->app->DB->Select(
        "SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),\',\',\'.\')*ap.menge)
        FROM " . $doctype . "_position ap
        INNER JOIN artikel a ON ap.artikel=a.id
        LEFT JOIN " . $doctype . "_position ap2 ON ap2.id=ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id=ap2.artikel
        WHERE ap." . $doctype . "=\'$id\'"
      );
    }

    if ($nettogewicht > 0) {
      return round($nettogewicht, 2);
    }
    return 0;
  }
  //@refactor versanddiestleister Modul

  /**
   * @param int $id
   *
   * @return float|int
   */
  public function AuftragNettoGewicht';
$new = '    // $doctype is validated by callers (internal method, not from user input)
    $doctypeTable = $doctype . "_position";
    $doctypeCol = $doctype;
    if ($this->Firmendaten(\'stuecklistegewichtnurartikel\') != \'1\') {
      $nettogewicht = $this->app->DatabaseService->selectValue(
        "SELECT SUM(REPLACE(a.gewicht,\',\',\'.\')*ap.menge) FROM `$doctypeTable` ap INNER JOIN artikel a ON ap.artikel = a.id WHERE ap.`$doctypeCol` = :id",
        [\'id\' => $id]
      );
    } else {
      $nettogewicht = $this->app->DatabaseService->selectValue(
        "SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),\',\',\'.\')*ap.menge) FROM `$doctypeTable` ap
        INNER JOIN artikel a ON ap.artikel = a.id
        LEFT JOIN `$doctypeTable` ap2 ON ap2.id = ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.`$doctypeCol` = :id",
        [\'id\' => $id]
      );
    }

    if ($nettogewicht > 0) {
      return round($nettogewicht, 2);
    }
    return 0;
  }
  //@refactor versanddiestleister Modul

  /**
   * @param int $id
   *
   * @return float|int
   */
  public function AuftragNettoGewicht';
rep($content, $old, $new, 'LieferscheinNettoGewicht', $changes);

// ---- AuftragNettoGewicht ----
$old = '    if ($this->Firmendaten(\'stuecklistegewichtnurartikel\') != \'1\') {
      $nettogewicht = $this->app->DB->Select(
        "SELECT SUM(REPLACE(a.gewicht,\',\',\'.\')*ap.menge)
        FROM auftrag_position ap
        INNER JOIN artikel a ON ap.artikel=a.id
        WHERE ap.auftrag=\'$id\'"
      );
    } else {
      $nettogewicht = $this->app->DB->Select(
        "SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),\',\',\'.\')*ap.menge)
        FROM auftrag_position ap
        INNER JOIN artikel a ON ap.artikel=a.id
        LEFT JOIN auftrag_position ap2 ON ap2.id=ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id=ap2.artikel
        WHERE ap.auftrag=\'$id\'"
      );
    }

    if ($nettogewicht > 0) {
      return round($nettogewicht, 2);
    }

    return 0;
  }

  function BestellungNettoGewicht';
$new = '    if ($this->Firmendaten(\'stuecklistegewichtnurartikel\') != \'1\') {
      $nettogewicht = $this->app->DatabaseService->selectValue(
        "SELECT SUM(REPLACE(a.gewicht,\',\',\'.\')*ap.menge) FROM auftrag_position ap INNER JOIN artikel a ON ap.artikel = a.id WHERE ap.auftrag = :id",
        [\'id\' => $id]
      );
    } else {
      $nettogewicht = $this->app->DatabaseService->selectValue(
        "SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),\',\',\'.\')*ap.menge) FROM auftrag_position ap
        INNER JOIN artikel a ON ap.artikel = a.id
        LEFT JOIN auftrag_position ap2 ON ap2.id = ap.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id = ap2.artikel WHERE ap.auftrag = :id",
        [\'id\' => $id]
      );
    }

    if ($nettogewicht > 0) {
      return round($nettogewicht, 2);
    }

    return 0;
  }

  function BestellungNettoGewicht';
rep($content, $old, $new, 'AuftragNettoGewicht', $changes);

// ---- BestellungNettoGewicht ----
$old = '    if ($this->Firmendaten(\'stuecklistegewichtnurartikel\') != \'1\') {
      $nettogewicht = $this->app->DB->Select(
        "SELECT SUM(REPLACE(a.gewicht,\',\',\'.\')*bp.menge)
        FROM bestellung_position bp
        INNER JOIN artikel a ON bp.artikel=a.id WHERE bp.bestellung=\'$id\'"
      );
    } else {
      $nettogewicht = $this->app->DB->Select(
        "SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),\',\',\'.\')*bp.menge)
        FROM bestellung_position bp
        INNER JOIN artikel a ON bp.artikel=a.id
        LEFT JOIN bestellung_position bp2 ON bp2.id=bp.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id=bp2.artikel
        WHERE bp.bestellung=\'$id\'"
      );
    }

    if ($nettogewicht > 0) {
      return round($nettogewicht, 2);
    }

    return 0;
  }

  function AngebotNettoGewicht';
$new = '    if ($this->Firmendaten(\'stuecklistegewichtnurartikel\') != \'1\') {
      $nettogewicht = $this->app->DatabaseService->selectValue(
        "SELECT SUM(REPLACE(a.gewicht,\',\',\'.\')*bp.menge) FROM bestellung_position bp INNER JOIN artikel a ON bp.artikel = a.id WHERE bp.bestellung = :id",
        [\'id\' => $id]
      );
    } else {
      $nettogewicht = $this->app->DatabaseService->selectValue(
        "SELECT SUM(REPLACE(if(a2.gewicht > 0,0,a.gewicht),\',\',\'.\')*bp.menge) FROM bestellung_position bp
        INNER JOIN artikel a ON bp.artikel = a.id
        LEFT JOIN bestellung_position bp2 ON bp2.id = bp.explodiert_parent
        LEFT JOIN artikel a2 ON a2.id = bp2.artikel WHERE bp.bestellung = :id",
        [\'id\' => $id]
      );
    }

    if ($nettogewicht > 0) {
      return round($nettogewicht, 2);
    }

    return 0;
  }

  function AngebotNettoGewicht';
rep($content, $old, $new, 'BestellungNettoGewicht', $changes);

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
