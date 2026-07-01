<?php

namespace Xentral\Modules\PaymentQr\Service;

/**
 * Baut den Payload eines EPC-QR-Codes (GiroCode) nach EPC069-12 v3.1.
 *
 * Version 002 (BIC optional, EWR), Zeichensatz UTF-8, Trenner LF,
 * kein Trenner nach dem letzten belegten Element, Gesamtlaenge max. 331 Bytes.
 */
class EpcQrPayloadBuilder
{
    private const MAX_PAYLOAD_BYTES = 331;
    private const MAX_NAME_LENGTH = 70;
    private const MAX_REMITTANCE_LENGTH = 140;
    private const MIN_AMOUNT = 0.01;
    private const MAX_AMOUNT = 999999999.99;

    /**
     * Zaehlt UTF-8-Zeichen (nicht Bytes). Kein mbstring noetig.
     *
     * @throws \InvalidArgumentException bei ungueltigem UTF-8 (PCRE/u liefert false)
     */
    private function utf8Len(string $s): int
    {
        $count = \preg_match_all('/./us', $s);
        if ($count === false) {
            throw new \InvalidArgumentException('Ungueltiges UTF-8 in EPC-Daten');
        }
        return $count;
    }

    /**
     * @param array $data Keys: kontoinhaber (Pflicht), iban (Pflicht),
     *                    bic (optional), betrag (Pflicht, EUR),
     *                    verwendungszweck (optional, unstrukturiert)
     *
     * @throws \InvalidArgumentException bei ungueltigen Daten
     *
     * @return string EPC-Payload
     */
    public function build(array $data): string
    {
        $name = \trim((string)($data['kontoinhaber'] ?? ''));
        if (\preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException('Steuerzeichen im Kontoinhaber nicht erlaubt');
        }
        if ($name === '' || $this->utf8Len($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Kontoinhaber fehlt oder laenger als 70 Zeichen');
        }

        $iban = \strtoupper(\preg_replace('/\s+/', '', (string)($data['iban'] ?? '')));
        if (!\preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            throw new \InvalidArgumentException('IBAN ungueltig: ' . $iban);
        }

        $bic = \strtoupper(\trim((string)($data['bic'] ?? '')));
        if ($bic !== '' && !\preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic)) {
            throw new \InvalidArgumentException('BIC ungueltig: ' . $bic);
        }

        $raw = $data['betrag'] ?? null;
        if (!\is_numeric($raw)) {
            // verwirft u.a. Komma-Dezimalstrings ('123,45'), die (float) still zu 123.0 machen wuerde
            throw new \InvalidArgumentException('Betrag ist nicht numerisch');
        }
        $betrag = (float)$raw;
        if ($betrag < self::MIN_AMOUNT || $betrag > self::MAX_AMOUNT) {
            throw new \InvalidArgumentException('Betrag ausserhalb 0.01 bis 999999999.99');
        }
        // Betraege mit mehr als 2 Nachkommastellen werden kaufmaennisch gerundet (bewusste Entscheidung)
        $betragStr = 'EUR' . \number_format($betrag, 2, '.', '');

        $zweck = \trim((string)($data['verwendungszweck'] ?? ''));
        if (\preg_match('/[\x00-\x1F\x7F]/', $zweck)) {
            throw new \InvalidArgumentException('Steuerzeichen im Verwendungszweck nicht erlaubt');
        }
        if ($this->utf8Len($zweck) > self::MAX_REMITTANCE_LENGTH) {
            throw new \InvalidArgumentException('Verwendungszweck laenger als 140 Zeichen');
        }

        // Elementreihenfolge nach EPC069-12; Purpose (9) und
        // strukturierte Referenz (10) werden nicht genutzt.
        $elements = [
            'BCD',       // 1 Service Tag
            '002',       // 2 Version
            '1',         // 3 Zeichensatz: UTF-8
            'SCT',       // 4 Identification
            $bic,        // 5 BIC (bei 002 optional)
            $name,       // 6 Empfaengername
            $iban,       // 7 IBAN
            $betragStr,  // 8 Betrag
            '',          // 9 Purpose Code
            '',          // 10 strukturierte Referenz
            $zweck,      // 11 unstrukturierter Verwendungszweck
        ];

        // Leere Elemente am Ende entfernen (Spec: duerfen weggelassen werden)
        while ($elements !== [] && \end($elements) === '') {
            \array_pop($elements);
        }

        $payload = \implode("\n", $elements);
        if (\strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('EPC-Payload ueberschreitet 331 Bytes');
        }

        return $payload;
    }
}
