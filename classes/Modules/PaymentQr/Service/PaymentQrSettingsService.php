<?php

namespace Xentral\Modules\PaymentQr\Service;

/**
 * Laedt die QR-Konfigurationen aus der Tabelle `zahlungsweisen`.
 *
 * Projektlogik wie erpAPI::Zahlungsweisetext: der projektspezifische
 * Eintrag gewinnt die Auswahl pro type VOR jeder Settings-Pruefung.
 * Ist beim ausgewaehlten Eintrag qr_aktiv leer oder das JSON kaputt,
 * entfaellt der type komplett - KEIN stiller Fallback auf den
 * globalen Eintrag (projekt = 0).
 */
class PaymentQrSettingsService
{
    /** Zahlungsweisen-Module, die QR-Einstellungen tragen */
    private const QR_MODULES = ['rechnung_qr', 'paypal_qr', 'wero'];

    /** @var object DB-Objekt mit SelectArr() (Signatur wie $app->DB) */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param int $projekt Projekt-ID des Belegs (0 = keins)
     *
     * @return array type => ['id'=>..,'type'=>..,'modul'=>..,'projekt'=>..,'settings'=>array]
     *               nur Eintraege mit gesetztem qr_aktiv
     */
    public function getActiveQrConfigs($projekt): array
    {
        $projekt = (int)$projekt;
        $moduleList = "'" . implode("','", self::QR_MODULES) . "'";
        $rows = $this->db->SelectArr(
            "SELECT z.id, z.type, z.modul, z.projekt, z.einstellungen_json
             FROM `zahlungsweisen` AS `z`
             WHERE z.modul IN ($moduleList) AND z.aktiv = 1 AND z.geloescht = 0
               AND (z.projekt = 0 OR z.projekt = $projekt)
             ORDER BY z.projekt DESC"
        );
        if (empty($rows)) {
            return [];
        }
        $configs = [];
        $seen = [];
        foreach ($rows as $r) {
            $type = (string)$r['type'];
            if (isset($seen[$type])) {
                continue; // erste Zeile pro type (projektspezifisch dank ORDER BY) hat entschieden
            }
            $seen[$type] = true;
            $settings = json_decode((string)$r['einstellungen_json'], true);
            if (!is_array($settings) || empty($settings['qr_aktiv'])) {
                continue; // ausgewaehlter Eintrag inaktiv/defekt -> type entfaellt, kein Fallback
            }
            $configs[$type] = [
                'id' => (int)$r['id'],
                'type' => $type,
                'modul' => (string)$r['modul'],
                'projekt' => (int)$r['projekt'],
                'settings' => $settings,
            ];
        }
        return $configs;
    }

    /**
     * Ersetzt {BELEGNR} und {KUNDENNUMMER}; leere Vorlage = Belegnummer.
     *
     * @return string
     */
    public function resolveVerwendungszweck($vorlage, array $beleg): string
    {
        $vorlage = trim((string)$vorlage);
        if ($vorlage === '') {
            $vorlage = '{BELEGNR}';
        }
        return strtr($vorlage, [
            '{BELEGNR}' => (string)($beleg['belegnr'] ?? ''),
            '{KUNDENNUMMER}' => (string)($beleg['kundennummer'] ?? ''),
        ]);
    }
}
