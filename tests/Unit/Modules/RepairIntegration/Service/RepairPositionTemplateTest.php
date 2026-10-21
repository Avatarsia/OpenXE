<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\RepairIntegration\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xentral\Modules\RepairIntegration\Service\RepairPositionTemplate;

final class RepairPositionTemplateTest extends TestCase
{
    private function details(): array
    {
        return ['service_type' => 'reparatur', 'manufacturer' => 'Bambu Lab', 'model' => 'X1C',
            'issue_description' => "Fehler am TH Board <script>kein HTML</script>",
            'public_diagnosis' => "- Sichtpruefung\n- Verkabelung geprueft",
            'service_work' => "- Fehlerbehebung TH Board\n- Wechsel Messer",
            'final_check' => '- Testdruck', 'repair_notes' => 'VERTRAULICH', 'diagnosis_result' => 'INTERN'];
    }

    public function testOfferUsesCustomerBlocksAndPlannedWork(): void
    {
        $text = RepairPositionTemplate::render(RepairPositionTemplate::defaults()['reparatur'], $this->details(), 'T42');
        self::assertStringContainsString('Geraet: Bambu Lab X1C', $text);
        self::assertStringContainsString("Eingangsdiagnose:\n- Sichtpruefung", $text);
        self::assertStringContainsString("Geplante Reparatur- und Wartungsarbeiten:\n- Fehlerbehebung TH Board", $text);
        self::assertStringNotContainsString('VERTRAULICH', $text);
        self::assertStringNotContainsString('INTERN', $text);
    }

    public function testInvoiceHasCompletedWorkAndNoExtraKvaPrice(): void
    {
        $text = RepairPositionTemplate::render(RepairPositionTemplate::defaults()['reparatur_rechnung'], $this->details(), 'T42');
        self::assertStringContainsString('Durchgefuehrte Reparatur- und Wartungsarbeiten:', $text);
        self::assertStringContainsString("Endkontrolle:\n- Testdruck", $text);
        self::assertStringNotContainsString('KVA-Gebuehr', $text);
    }

    public function testFeeOnlyHasDiagnosisWithoutRepairOrFinalCheck(): void
    {
        $text = RepairPositionTemplate::render(RepairPositionTemplate::defaults()['fee'], $this->details(), 'T42');
        self::assertStringContainsString('Keine Reparatur durchgefuehrt', $text);
        self::assertStringNotContainsString('Wechsel Messer', $text);
        self::assertStringNotContainsString('Endkontrolle:', $text);
    }

    public function testMaintenanceUsesNotesInsteadOfUnrelatedRepairFields(): void
    {
        $details = array_merge($this->details(), ['service_type' => 'wartung', 'wartung_paket' => 'voll', 'wartung_notes' => 'Y-Achse pruefen']);
        $text = RepairPositionTemplate::render(RepairPositionTemplate::defaults()['wartung'], $details, 'T42');
        self::assertStringContainsString('Y-Achse pruefen', $text);
        self::assertStringContainsString('Vollwartung', $text);
        self::assertStringContainsString("Eingangsdiagnose:\n- Sichtpruefung", $text);
        self::assertStringContainsString("Endkontrolle:\n- Testdruck", $text);
        self::assertStringNotContainsString('TH Board <script>', $text);
    }

    public function testNewRepairAndMaintenanceUseReferenceChecklistsWithoutPredefinedWork(): void
    {
        $diagnosis = "- Sichtpr\u{00FC}fung auf mechanische Besch\u{00E4}digungen und Verschlei\u{00DF}\n- Pr\u{00FC}fung von Verkabelung, Steckverbindungen und Elektronik\n- Kontrolle von Hotend, Extruder und Filamentf\u{00FC}hrung\n- Pr\u{00FC}fung von Achsen, F\u{00FC}hrungen und Riemenspannung\n- Kontrolle von Firmware und Druckereinstellungen";
        $finalCheck = "- Reinigung und Schmierung der Achsen und F\u{00FC}hrungen\n- Kalibrierung\n- Funktionstest aller Komponenten\n- Qualit\u{00E4}tskontrolle mit Testdruck\n- Endreinigung des Ger\u{00E4}ts";
        foreach (['reparatur', 'wartung'] as $type) {
            $values = RepairPositionTemplate::values(['service_type' => $type, 'public_diagnosis' => null, 'final_check' => null], 'T42');
            self::assertSame($diagnosis, $values['eingangsdiagnose']);
            self::assertSame($finalCheck, $values['endkontrolle']);
            self::assertSame('', $values['reparaturarbeiten']);
        }
    }

    public function testCustomOrExplicitlyClearedChecklistsAreNotReplaced(): void
    {
        foreach ([['public_diagnosis' => 'Eigene Diagnose', 'final_check' => 'Eigene Kontrolle'], ['public_diagnosis' => '', 'final_check' => '']] as $fields) {
            $values = RepairPositionTemplate::values(array_merge(['service_type' => 'reparatur'], $fields), 'T42');
            self::assertSame($fields['public_diagnosis'], $values['eingangsdiagnose']);
            self::assertSame($fields['final_check'], $values['endkontrolle']);
        }
        $other = RepairPositionTemplate::values(['service_type' => 'reverse_engineering'], 'T42');
        self::assertSame('', $other['eingangsdiagnose']);
        self::assertSame('', $other['endkontrolle']);
    }

    public function testUnknownUppercasePlaceholderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RepairPositionTemplate::render('{Modell}', $this->details(), 'T42');
    }

    public function testInternalNotesAreNotAvailableAsVariables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RepairPositionTemplate::render('{repair_notes}', $this->details(), 'T42');
    }

    public function testMissingRequiredValueStopsDocumentCreation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seriennummer');
        RepairPositionTemplate::render('{seriennummer}', $this->details(), 'T42');
    }

    public function testDataIsNotInterpretedAsAnotherPlaceholder(): void
    {
        self::assertSame('{repair_notes}', RepairPositionTemplate::render('{modell}', ['model' => '{repair_notes}'], 'T42'));
    }

    public function testTextIsEscapedForRichTextDocumentDescription(): void
    {
        self::assertSame("O&#039;Brien &amp; &lt;script&gt;<br>Zweite Zeile", RepairPositionTemplate::toDocumentHtml("O'Brien & <script>\nZweite Zeile"));
    }

    public function testDocumentLineBreaksKeepChecklistBlocksWithoutDuplicatePdfBreaks(): void
    {
        foreach (["\n", "\r\n", "\r"] as $newline) {
            $text = implode($newline, ['Eingangsdiagnose:', '- Sichtpruefung', '- Elektronik', '', 'Endkontrolle:', '- Testdruck']);
            self::assertSame('Eingangsdiagnose:<br>- Sichtpruefung<br>- Elektronik<br><br>Endkontrolle:<br>- Testdruck', RepairPositionTemplate::toDocumentHtml($text));
        }
    }

    /** @dataProvider validAmounts */
    public function testAmountsAreUnambiguous(string $raw, string $expected): void
    {
        self::assertSame($expected, RepairPositionTemplate::normalizeAmount($raw));
    }

    public function validAmounts(): array
    {
        return [['0', '0.00'], ['0,00', '0.00'], ['49,90', '49.90'], ['49.9', '49.90'], ['1.234,56', '1234.56'], ['1.234', '1234.00']];
    }

    /** @dataProvider invalidAmounts */
    public function testInvalidAmountsAreRejected($raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        RepairPositionTemplate::normalizeAmount($raw);
    }

    public function invalidAmounts(): array
    {
        return [['-1'], ['abc'], ['49,999'], ['1.2.3'], ['100000000'], [true], [[]]];
    }

    public function testMissingPriceIsDistinctFromFreeService(): void
    {
        self::assertNull(RepairPositionTemplate::normalizeAmount(''));
        self::assertNull(RepairPositionTemplate::normalizeAmount(null));
        self::assertSame('0.00', RepairPositionTemplate::normalizeAmount('0'));
    }

    public function testTaxConversionUsesProvidedRateIncludingExemption(): void
    {
        self::assertSame('41.176471', RepairPositionTemplate::grossToNet('49.00', 19.0));
        self::assertSame('45.794393', RepairPositionTemplate::grossToNet('49.00', 7.0));
        self::assertSame('49.000000', RepairPositionTemplate::grossToNet('49.00', 0.0));
    }
}
