<?php
require_once __DIR__.'/rechnung.php';

/**
 * Ueberweisung mit GiroCode (EPC-QR) auf dem Rechnungs-PDF.
 * Erbt die kompletten Text-/Skonto-Einstellungen der Zahlungsweise
 * "rechnung" und ergaenzt die QR-Felder. Das Rendering uebernimmt
 * das Modul zahlungsqr (Hook briefpapier_render_footer_hook2).
 */
class Zahlungsweise_rechnung_qr extends Zahlungsweise_rechnung
{
  /** @var array (in der Klassenhierarchie nicht deklariert - dynamic property vermeiden) */
  public $einstellungen = array();

  public function EinstellungenStruktur()
  {
    $struktur = parent::EinstellungenStruktur();
    $struktur['qr_aktiv'] = [
      'bezeichnung' => 'GiroCode (EPC-QR) auf Rechnung anzeigen',
      'typ' => 'checkbox',
    ];
    $struktur['qr_nur_bei_passender_zahlungsweise'] = [
      'bezeichnung' => 'Nur anzeigen, wenn Zahlungsweise der Rechnung Ueberweisung ist (sonst auf allen Rechnungen)',
      'typ' => 'checkbox',
    ];
    $struktur['qr_iban'] = ['bezeichnung' => 'IBAN (Pflicht fuer GiroCode)', 'typ' => 'text', 'size' => 40];
    $struktur['qr_bic'] = ['bezeichnung' => 'BIC (optional)', 'typ' => 'text', 'size' => 40];
    $struktur['qr_kontoinhaber'] = [
      'bezeichnung' => 'Kontoinhaber',
      'typ' => 'text',
      'size' => 40,
      'info' => 'Muss exakt dem Namen beim Kontoinstitut entsprechen (Verification of Payee), sonst warnt die Banking-App des Kunden',
    ];
    $struktur['qr_verwendungszweck'] = [
      'bezeichnung' => 'Verwendungszweck-Vorlage (Platzhalter: {BELEGNR}, {KUNDENNUMMER}; leer = Belegnummer)',
      'typ' => 'text',
      'size' => 40,
    ];
    $struktur['qr_beschriftung'] = [
      'bezeichnung' => 'Beschriftung unter dem QR-Code (leer = "Mit Banking-App scannen & bezahlen")',
      'typ' => 'text',
      'size' => 40,
    ];
    return $struktur;
  }
}
