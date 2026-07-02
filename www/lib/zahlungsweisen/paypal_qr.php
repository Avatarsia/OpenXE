<?php
require_once dirname(__DIR__).'/class.zahlungsweise.php';

/**
 * PayPal-Zahlung per PayPal.me-QR auf dem Rechnungs-PDF.
 */
class Zahlungsweise_paypal_qr extends Zahlungsweisenmodul
{
  /** @var Application */
  var $app;
  /** @var array */
  protected $data;
  /** @var array (in der abstrakten Basisklasse nicht deklariert - dynamic property vermeiden) */
  public $einstellungen = array();

  public function __construct($app, $id)
  {
    $this->app = $app;
    $this->id = $id;
    $this->data = $this->app->DB->SelectRow(
      sprintf('SELECT * FROM zahlungsweisen WHERE id = %d', $id)
    );
    $einstellungen_json = $this->data['einstellungen_json'] ?? '';
    $decoded = !empty($einstellungen_json) ? json_decode($einstellungen_json, true) : null;
    $this->einstellungen = is_array($decoded) ? $decoded : array();
  }

  public function EinstellungenStruktur()
  {
    return [
      'qr_aktiv' => ['bezeichnung' => 'PayPal-QR auf Rechnung anzeigen', 'typ' => 'checkbox'],
      'qr_nur_bei_passender_zahlungsweise' => [
        'bezeichnung' => 'Nur anzeigen, wenn Zahlungsweise der Rechnung PayPal ist (sonst auf allen Rechnungen)',
        'typ' => 'checkbox',
      ],
      'paypalme_handle' => ['bezeichnung' => 'PayPal.me-Handle (paypal.me/<Handle>, Pflicht)', 'typ' => 'text', 'size' => 40],
      'qr_beschriftung' => ['bezeichnung' => 'Beschriftung unter dem QR-Code (leer = "Mit PayPal zahlen")', 'typ' => 'text', 'size' => 40],
    ];
  }

  // Pflicht: abstrakte Methode der Basisklasse; dieses Modul wickelt keine
  // Zahllaeufe ab (No-Op-Struktur wie Zahlungsweise_rechnung, rechnung.php:306)
  public function ProcessPayment(array $transaction_block): array
  {
    return [
      'success' => false,
      'successful_transactions' => [],
      'errors' => [],
      'payment_objects' => [],
    ];
  }
}
