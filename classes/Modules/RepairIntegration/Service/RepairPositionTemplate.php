<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use InvalidArgumentException;

final class RepairPositionTemplate
{
    public const VARIABLES = [
        'hersteller' => 'Hersteller', 'modell' => 'Modell', 'seriennummer' => 'Seriennummer',
        'fehlerbeschreibung' => 'Fehlerbeschreibung', 'kundenhinweise' => 'Kundenhinweise',
        'wartungspaket' => 'Wartungspaket', 'eingangsdiagnose' => 'Eingangsdiagnose',
        'reparaturarbeiten' => 'Reparatur-/Wartungsarbeiten', 'leistungsbeschreibung' => 'Leistungsbeschreibung',
        'endkontrolle' => 'Endkontrolle', 'vorgangsnummer' => 'Vorgangsnummer',
        'kva_preis' => 'KVA-Preis laut Formular', 'paketpreis' => 'Paketpreis laut Formular',
    ];

    public static function defaults(): array
    {
        $device = "Geraet: {hersteller} {modell}\nFehlerbild: {fehlerbeschreibung}\n\nEingangsdiagnose:\n{eingangsdiagnose}";
        return [
            'reparatur' => $device . "\n\nGeplante Reparatur- und Wartungsarbeiten:\n{reparaturarbeiten}\n\nVorgesehene Endkontrolle:\n{endkontrolle}",
            'reparatur_rechnung' => $device . "\n\nDurchgefuehrte Reparatur- und Wartungsarbeiten:\n{reparaturarbeiten}\n\nEndkontrolle:\n{endkontrolle}",
            'fee' => $device . "\n\nDiagnose und Erstellung des Kostenvoranschlags. Keine Reparatur durchgefuehrt. Abrechnung der vereinbarten KVA-Gebuehr.",
            'wartung' => "Geraet: {hersteller} {modell}\nWartungspaket: {wartungspaket}\nKundenhinweise: {kundenhinweise}\n\nEingangsdiagnose:\n{eingangsdiagnose}\n\nLeistungsumfang:\n{leistungsbeschreibung}\n\nEndkontrolle:\n{endkontrolle}",
            'reverse_engineering' => "Anforderung: {fehlerbeschreibung}\n\nLeistungsumfang:\n{leistungsbeschreibung}\n\nVorgang: {vorgangsnummer}",
            'individualisierung' => "Anforderung: {fehlerbeschreibung}\n\nLeistungsumfang:\n{leistungsbeschreibung}\n\nVorgang: {vorgangsnummer}",
        ];
    }

    public static function serviceFieldDefaults(string $serviceType): array
    {
        if (!in_array($serviceType, ['reparatur', 'wartung'], true)) return [];
        return [
            'public_diagnosis' => "- Sichtpr\u{00FC}fung auf mechanische Besch\u{00E4}digungen und Verschlei\u{00DF}\n- Pr\u{00FC}fung von Verkabelung, Steckverbindungen und Elektronik\n- Kontrolle von Hotend, Extruder und Filamentf\u{00FC}hrung\n- Pr\u{00FC}fung von Achsen, F\u{00FC}hrungen und Riemenspannung\n- Kontrolle von Firmware und Druckereinstellungen",
            'final_check' => "- Reinigung und Schmierung der Achsen und F\u{00FC}hrungen\n- Kalibrierung\n- Funktionstest aller Komponenten\n- Qualit\u{00E4}tskontrolle mit Testdruck\n- Endreinigung des Ger\u{00E4}ts",
        ];
    }

    public static function values(array $details, string $ticketKey): array
    {
        $fieldDefaults = self::serviceFieldDefaults((string)($details['service_type'] ?? ''));
        $package = (string)($details['wartung_paket'] ?? '');
        $package = ['basis' => 'Basiswartung', 'voll' => 'Vollwartung', 'komplett' => 'Komplettwartung'][$package] ?? $package;
        return [
            'hersteller' => $details['manufacturer'] ?? '', 'modell' => $details['model'] ?? '',
            'seriennummer' => $details['serial_number'] ?? '',
            'fehlerbeschreibung' => $details['issue_description'] ?? '',
            'kundenhinweise' => ($details['service_type'] ?? '') === 'wartung' ? ($details['wartung_notes'] ?? '') : ($details['issue_description'] ?? ''),
            'wartungspaket' => $package, 'eingangsdiagnose' => $details['public_diagnosis'] ?? ($fieldDefaults['public_diagnosis'] ?? ''),
            'reparaturarbeiten' => $details['service_work'] ?? '',
            'leistungsbeschreibung' => $details['service_work'] ?? '',
            'endkontrolle' => $details['final_check'] ?? ($fieldDefaults['final_check'] ?? ''), 'vorgangsnummer' => $ticketKey,
            'kva_preis' => self::displayAmount($details['diagnosis_cost_snapshot'] ?? null),
            'paketpreis' => self::displayAmount($details['wartung_paket_price_snapshot'] ?? null),
        ];
    }

    public static function validate(string $template): void
    {
        if (trim($template) === '' || strlen($template) > 12000) {
            throw new InvalidArgumentException('Produkttext-Vorlage muss 1 bis 12000 Bytes enthalten.');
        }
        preg_match_all('/\{([^{}\r\n]*)\}/', $template, $matches);
        foreach ($matches[1] as $name) {
            if (!isset(self::VARIABLES[$name])) {
                throw new InvalidArgumentException('Unbekannter Platzhalter: {' . $name . '}');
            }
        }
    }

    public static function render(string $template, array $details, string $ticketKey): string
    {
        self::validate($template);
        $values = self::values($details, $ticketKey);
        $missing = [];
        $text = preg_replace_callback('/\{([^{}\r\n]*)\}/', static function (array $match) use ($values, &$missing): string {
            $value = trim((string)$values[$match[1]]);
            if ($value === '') {
                $missing[] = self::VARIABLES[$match[1]];
            }
            return $value;
        }, $template);
        if ($missing !== []) {
            throw new InvalidArgumentException('Produkttext unvollstaendig: ' . implode(', ', array_unique($missing)) . '. Leistungsdaten ergaenzen oder Vorlage anpassen.');
        }
        if (strlen($text) > 50000) {
            throw new InvalidArgumentException('Produkttext ist zu lang (maximal 50000 Bytes).');
        }
        return $text;
    }

    public static function toDocumentHtml(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], '<br>', htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public static function normalizeAmount($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Ungueltiger Betrag.');
        }
        $value = str_replace(' ', '', trim((string)$value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ',')) {
            if (!preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/', $value)) {
                throw new InvalidArgumentException('Ungueltiger Betrag.');
            }
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || (float)$value > 99999999.99) {
            throw new InvalidArgumentException('Betrag muss zwischen 0 und 99999999,99 liegen, mit maximal zwei Nachkommastellen.');
        }
        return number_format((float)$value, 2, '.', '');
    }

    public static function grossToNet(string $gross, float $taxRate): string
    {
        if (!is_finite($taxRate) || $taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('ERP-Steuersatz ist ungueltig.');
        }
        return number_format((float)self::normalizeAmount($gross) / (1 + $taxRate / 100), 6, '.', '');
    }

    private static function displayAmount($value): string
    {
        return $value === null || $value === '' ? '' : number_format((float)$value, 2, ',', '.') . ' EUR';
    }
}
