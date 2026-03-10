<?php
$file = 'C:/Users/3D Partner/Documents/openxe_rework/OpenXE/www/lib/class.erpapi.php';
$content = file_get_contents($file);

// Find the function and replace it
$pos = strpos($content, 'function ReserviertLieferschein');
if ($pos === false) {
    echo "Function not found!\n";
    exit;
}

// Find end of function
$endSearch = '// @refactor Bestellung Modul' . "\r\n" . '  function ReserviertAuftragLiefertermin';
$endPos = strpos($content, $endSearch, $pos);
if ($endPos === false) {
    echo "End not found!\n";
    // Try different end marker
    $endSearch2 = 'function ReserviertAuftragLiefertermin';
    $endPos2 = strpos($content, $endSearch2, $pos + 10);
    echo "Alt end: $endPos2\n";
    exit;
}

$funcEnd = $endPos;
$oldFunc = substr($content, $pos, $funcEnd - $pos);
echo "Old function length: " . strlen($oldFunc) . "\n";
echo "First 100: " . json_encode(substr($oldFunc, 0, 100)) . "\n";

$newFunc = 'function ReserviertLieferschein($artikel, $von = null, $bis = null)' . "\r\n" .
'  {' . "\r\n" .
'    $vonWhereRL = ($von && $von != \'0000-00-00\') ? " AND l.datum >= :von " : "";' . "\r\n" .
'    $bisWhereRL = ($bis && $bis != \'0000-00-00\') ? " AND l.datum <= :bis " : "";' . "\r\n" .
'    $paramsRL = [\'artikel\' => $artikel];' . "\r\n" .
'    if ($von && $von != \'0000-00-00\') $paramsRL[\'von\'] = $von;' . "\r\n" .
'    if ($bis && $bis != \'0000-00-00\') $paramsRL[\'bis\'] = $bis;' . "\r\n" .
'    return $this->app->DatabaseService->selectValue(' . "\r\n" .
'      "SELECT trim(SUM(ifnull(r.menge,0)))+0' . "\r\n" .
'      FROM `lager_reserviert` AS `r` INNER JOIN' . "\r\n" .
'      (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as `menge`' . "\r\n" .
'      FROM `lieferschein_position` AS `lp`' . "\r\n" .
'      INNER JOIN `lieferschein` AS `l` ON l.id = lp.lieferschein' . "\r\n" .
'      WHERE lp.artikel = :artikel AND l.status = \'freigegeben\' $vonWhereRL $bisWhereRL' . "\r\n" .
'      GROUP BY lp.lieferschein, lp.artikel) AS `lb` ON r.parameter = lb.lieferschein AND r.artikel = :artikel AND r.objekt = \'lieferschein\'",'. "\r\n" .
'      $paramsRL' . "\r\n" .
'    );' . "\r\n" .
'  }' . "\r\n" .
"\r\n  ";

$content = substr($content, 0, $pos) . $newFunc . $endSearch;
// Find the rest and append
$restStart = strpos($content, $endSearch);
if ($restStart !== false) {
    // The content now has $newFunc + $endSearch at the end, need to append rest
    $originalRest = substr($content, $endPos + strlen($endSearch));
    // Wait, we already sliced content at funcEnd, need to recalculate
    // Re-read and do it properly
}

// Redo properly
$content = file_get_contents($file);
$pos = strpos($content, 'function ReserviertLieferschein');
$endSearch = '// @refactor Bestellung Modul' . "\r\n" . '  function ReserviertAuftragLiefertermin';
$endPos = strpos($content, $endSearch, $pos);
$before = substr($content, 0, $pos);
$after = substr($content, $endPos);

$newFunc = 'function ReserviertLieferschein($artikel, $von = null, $bis = null)' . "\r\n" .
'  {' . "\r\n" .
'    $vonWhereRL = ($von && $von != \'0000-00-00\') ? " AND l.datum >= :von " : "";' . "\r\n" .
'    $bisWhereRL = ($bis && $bis != \'0000-00-00\') ? " AND l.datum <= :bis " : "";' . "\r\n" .
'    $paramsRL = [\'artikel\' => $artikel];' . "\r\n" .
'    if ($von && $von != \'0000-00-00\') $paramsRL[\'von\'] = $von;' . "\r\n" .
'    if ($bis && $bis != \'0000-00-00\') $paramsRL[\'bis\'] = $bis;' . "\r\n" .
'    return $this->app->DatabaseService->selectValue(' . "\r\n" .
'      "SELECT trim(SUM(ifnull(r.menge,0)))+0' . "\r\n" .
'      FROM `lager_reserviert` AS `r` INNER JOIN' . "\r\n" .
'      (SELECT lp.lieferschein, lp.artikel, sum(lp.menge) as `menge`' . "\r\n" .
'      FROM `lieferschein_position` AS `lp`' . "\r\n" .
'      INNER JOIN `lieferschein` AS `l` ON l.id = lp.lieferschein' . "\r\n" .
'      WHERE lp.artikel = :artikel AND l.status = \'freigegeben\' $vonWhereRL $bisWhereRL' . "\r\n" .
'      GROUP BY lp.lieferschein, lp.artikel) AS `lb` ON r.parameter = lb.lieferschein AND r.artikel = :artikel AND r.objekt = \'lieferschein\'",'. "\r\n" .
'      $paramsRL' . "\r\n" .
'    );' . "\r\n" .
'  }' . "\r\n" . "\r\n" . '  ';

$newContent = $before . $newFunc . $after;
file_put_contents($file, $newContent);
echo "Done. New content length: " . strlen($newContent) . "\n";
echo "Original length: " . strlen($content) . "\n";
