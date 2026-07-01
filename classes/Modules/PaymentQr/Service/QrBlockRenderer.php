<?php

namespace Xentral\Modules\PaymentQr\Service;

/**
 * Zeichnet den Zahlungs-QR-Block in ein FPDF-basiertes Beleg-PDF
 * (Aufruf aus dem Hook briefpapier_render_footer_hook2, d.h. im
 * normalen Content-Flow direkt nach dem Zahlungsweise-Hinweistext).
 *
 * Items: ['png' => <PNG-Binaerdaten>, 'label' => string]
 *   oder ['imagefile' => <Pfad>, 'label' => string]
 *
 * Bewusste Grenzen: bei mehr als 4 Items laeuft der Block ueber den
 * rechten Seitenrand hinaus (aktuell existieren nur 3 Zahlarten);
 * sehr lange Labels nahe der Umbruchgrenze koennen via FPDF-
 * AutoPageBreak mitten im Block umbrechen.
 */
class QrBlockRenderer
{
    const QR_SIZE_MM = 25;
    const GAP_MM = 10;
    const LABEL_H_MM = 8;
    const BLOCK_TOP_MARGIN_MM = 4;
    const FALLBACK_PAGE_BREAK_Y = 260.0;
    const FALLBACK_LEFT_MARGIN = 20.0;

    /** PNG-Signatur: FPDF::Error() ruft die() - ungueltige Bilddaten duerfen FPDF nie erreichen */
    const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /**
     * @param object $pdf   Briefpapier/FPDF-Objekt
     * @param array  $items s. Klassenkommentar; fehlerhafte Items werden uebersprungen
     */
    public function render($pdf, array $items)
    {
        if (empty($items)) {
            return;
        }

        // Temp-Dateien erst NACH der Schleife loeschen: FPDF cached Bilder
        // pro Dateipfad - ein von tempnam wiederverwendeter Pfad wuerde sonst
        // still den QR eines frueheren Items rendern.
        $cleanup = [];

        try {
            $blockHeight = self::BLOCK_TOP_MARGIN_MM + self::QR_SIZE_MM + self::LABEL_H_MM;
            $breakY = isset($pdf->PageBreakTrigger) ? (float)$pdf->PageBreakTrigger : self::FALLBACK_PAGE_BREAK_Y;
            if ($pdf->GetY() + $blockHeight > $breakY) {
                $pdf->AddPage();
            }

            $x = isset($pdf->lMargin) ? (float)$pdf->lMargin : self::FALLBACK_LEFT_MARGIN;
            $top = $pdf->GetY() + self::BLOCK_TOP_MARGIN_MM;
            $rendered = 0;
            $oldFontSize = $pdf->FontSizePt ?? null;
            $oldFontStyle = $pdf->FontStyle ?? null;

            foreach ($items as $item) {
                try {
                    if (isset($item['png'])) {
                        if (\substr((string)$item['png'], 0, 8) !== self::PNG_MAGIC) {
                            continue;
                        }
                        $tmpbase = \tempnam(\sys_get_temp_dir(), 'payqr');
                        if ($tmpbase === false) {
                            continue;
                        }
                        // tempnam legt bereits eine endungslose Datei an - beide vormerken
                        $cleanup[] = $tmpbase;
                        $tmpfile = $tmpbase . '.png';
                        $cleanup[] = $tmpfile;
                        if (\file_put_contents($tmpfile, $item['png']) === false) {
                            continue;
                        }
                        $file = $tmpfile;
                        $type = 'png';
                    } elseif (!empty($item['imagefile']) && \is_file($item['imagefile'])) {
                        $file = $item['imagefile'];
                        // Typ inhaltsbasiert bestimmen - nur PNG/JPEG erreichen FPDF
                        $info = @\getimagesize($file);
                        if ($info === false) {
                            continue;
                        }
                        if ($info[2] === \IMAGETYPE_PNG) {
                            $type = 'png';
                        } elseif ($info[2] === \IMAGETYPE_JPEG) {
                            $type = 'jpg';
                        } else {
                            continue;
                        }
                    } else {
                        continue;
                    }

                    $pdf->Image($file, $x, $top, self::QR_SIZE_MM, self::QR_SIZE_MM, $type);
                    $pdf->SetFont($pdf->GetFont(), '', 8);
                    $pdf->SetXY($x, $top + self::QR_SIZE_MM + 1);
                    $pdf->MultiCell(self::QR_SIZE_MM + self::GAP_MM - 2, 3, (string)($item['label'] ?? ''));

                    $x += self::QR_SIZE_MM + self::GAP_MM;
                    $rendered++;
                } catch (\Throwable $e) {
                    // einzelnes Item ueberspringen; PDF-Erzeugung nie gefaehrden
                }
            }

            if ($rendered > 0) {
                // Font-Zustand des Dokuments wiederherstellen (Labels nutzen 8pt)
                if ($oldFontSize !== null && $oldFontStyle !== null) {
                    $pdf->SetFont($pdf->GetFont(), (string)$oldFontStyle, (float)$oldFontSize);
                }
                $pdf->SetY($top + $blockHeight - self::BLOCK_TOP_MARGIN_MM);
            }
        } catch (\Throwable $e) {
            // aus render() darf niemals eine Exception entkommen
        } finally {
            foreach ($cleanup as $f) {
                if (\is_file($f)) {
                    @\unlink($f);
                }
            }
        }
    }
}
