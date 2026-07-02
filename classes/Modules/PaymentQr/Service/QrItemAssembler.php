<?php

namespace Xentral\Modules\PaymentQr\Service;

/**
 * Setzt aus einer Rechnung und den aktiven QR-Configs die Item-Liste
 * fuer den QrBlockRenderer zusammen (GiroCode, PayPal, Wero).
 *
 * Reine Funktion ohne Seiteneffekte: PNG-Erzeugung und Wero-Datei-
 * Aufloesung werden als Callables injiziert. Fehler eines Items
 * (fehlende Pflichtdaten, Builder-Exception) lassen nur dieses Item
 * entfallen - der Beleg-Druck darf nie an einem QR-Code scheitern.
 */
class QrItemAssembler
{
    const LABEL_GIROCODE = 'Mit Banking-App scannen & bezahlen';
    const LABEL_PAYPAL = 'Mit PayPal zahlen';
    const LABEL_WERO = 'Mit Wero zahlen';

    /** Feste Ausgabe-Reihenfolge der Zahlungsweisen-Module */
    const MODULE_ORDER = ['rechnung_qr', 'paypal_qr', 'wero'];

    /**
     * @param array    $rechnung         Row: belegnr, zahlungsweise, soll (Brutto),
     *                                   waehrung, projekt, kundennummer
     * @param array    $configs          type => ['id','type','modul','projekt','settings'=>array]
     *                                   (Rueckgabe von PaymentQrSettingsService::getActiveQrConfigs)
     * @param callable $pngFactory       fn(string $payload): string - PNG-Binaerdaten
     * @param callable $weroFileResolver fn(int $dateiId): ?string - Pfad zur Bilddatei oder null
     *
     * @return array Items fuer QrBlockRenderer::render():
     *               ['png'=>binaer,'label'=>string] oder ['imagefile'=>pfad,'label'=>string]
     */
    public static function build(
        array $rechnung,
        array $configs,
        callable $pngFactory,
        callable $weroFileResolver,
        EpcQrPayloadBuilder $payloadBuilder,
        PaymentQrSettingsService $settingsService
    ): array {
        $betrag = (float)($rechnung['soll'] ?? 0);
        $waehrung = \strtoupper(\trim((string)($rechnung['waehrung'] ?? '')));
        $istEur = ($waehrung === '' || $waehrung === 'EUR'); // leere Waehrung = EUR (DB-Default)
        $zahlungsweise = (string)($rechnung['zahlungsweise'] ?? '');

        $items = [];
        foreach (self::MODULE_ORDER as $modul) {
            foreach ($configs as $config) {
                if (!\is_array($config) || (string)($config['modul'] ?? '') !== $modul) {
                    continue;
                }
                $settings = \is_array($config['settings'] ?? null) ? $config['settings'] : [];
                if (empty($settings['qr_aktiv'])) {
                    continue;
                }
                if (!empty($settings['qr_nur_bei_passender_zahlungsweise'])
                    && (string)($config['type'] ?? '') !== $zahlungsweise) {
                    continue;
                }
                try {
                    $item = null;
                    if ($modul === 'wero') {
                        // Wero: statisches Bild, unabhaengig von Waehrung und Betrag
                        $item = self::weroItem($settings, $weroFileResolver);
                    } elseif ($istEur && $betrag > 0) {
                        // Waehrungs- und Betrags-Gate fuer GiroCode + PayPal
                        $item = ($modul === 'rechnung_qr')
                            ? self::giroCodeItem($settings, $rechnung, $betrag, $pngFactory, $payloadBuilder, $settingsService)
                            : self::paypalItem($settings, $betrag, $pngFactory);
                    }
                    if ($item !== null) {
                        $items[] = $item;
                    }
                } catch (\Throwable $e) {
                    // Item entfaellt still (z.B. ungueltige IBAN im EpcQrPayloadBuilder)
                }
            }
        }
        return $items;
    }

    private static function giroCodeItem(
        array $settings,
        array $rechnung,
        float $betrag,
        callable $pngFactory,
        EpcQrPayloadBuilder $payloadBuilder,
        PaymentQrSettingsService $settingsService
    ): ?array {
        $iban = \trim((string)($settings['qr_iban'] ?? ''));
        $inhaber = \trim((string)($settings['qr_kontoinhaber'] ?? ''));
        if ($iban === '' || $inhaber === '') {
            return null;
        }
        $payload = $payloadBuilder->build([
            'kontoinhaber' => $inhaber,
            'iban' => $iban,
            'bic' => (string)($settings['qr_bic'] ?? ''),
            'betrag' => $betrag,
            'verwendungszweck' => $settingsService->resolveVerwendungszweck(
                $settings['qr_verwendungszweck'] ?? '',
                $rechnung
            ),
        ]);
        return ['png' => $pngFactory($payload), 'label' => self::label($settings, self::LABEL_GIROCODE)];
    }

    private static function paypalItem(array $settings, float $betrag, callable $pngFactory): ?array
    {
        $handle = \trim((string)($settings['paypalme_handle'] ?? ''));
        // Nutzer-Eingaben wie '@handle' oder 'https://paypal.me/handle' bereinigen
        $handle = (string)\preg_replace('~^(https?://)?(www\.)?paypal\.me/~i', '', $handle);
        $handle = \trim(\ltrim($handle, '@/'));
        // Reste wie '/50', Trailing-Slash oder Query-Strings abschneiden;
        // gueltige PayPal.me-Handles sind rein alphanumerisch - alles andere
        // wuerde einen still defekten Link in den gedruckten QR bringen
        $handle = \explode('/', $handle, 2)[0];
        if ($handle === '' || !\preg_match('/^[A-Za-z0-9]+$/', $handle)) {
            return null;
        }
        $link = 'https://paypal.me/' . $handle . '/' . \number_format($betrag, 2, '.', '') . 'EUR';
        return ['png' => $pngFactory($link), 'label' => self::label($settings, self::LABEL_PAYPAL)];
    }

    private static function weroItem(array $settings, callable $weroFileResolver): ?array
    {
        $pfad = $weroFileResolver((int)($settings['qr_datei'] ?? 0));
        if (!\is_string($pfad) || $pfad === '' || !\is_file($pfad)) {
            return null;
        }
        return ['imagefile' => $pfad, 'label' => self::label($settings, self::LABEL_WERO)];
    }

    private static function label(array $settings, string $default): string
    {
        $label = \trim((string)($settings['qr_beschriftung'] ?? ''));
        return $label === '' ? $default : $label;
    }
}
