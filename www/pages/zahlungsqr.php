<?php

use Xentral\Modules\PaymentQr\Service\EpcQrPayloadBuilder;
use Xentral\Modules\PaymentQr\Service\PaymentQrSettingsService;
use Xentral\Modules\PaymentQr\Service\QrBlockRenderer;
use Xentral\Modules\PaymentQr\Service\QrItemAssembler;

/**
 * ZahlungsQR: rendert GiroCode- (EPC-QR), PayPal.me- und statische
 * Wero-QR-Codes auf Rechnungs-PDFs.
 *
 * Die PDF-Einbindung laeuft ausschliesslich ueber den DB-Hook
 * briefpapier_render_footer_hook2 (RunHook instanziiert dieses Modul
 * mit $intern = true und ruft RenderQrBlock($pdf) auf). Die eigentliche
 * Logik liegt in classes/Modules/PaymentQr/Service/ - dieser Controller
 * verdrahtet nur. Null Aenderungen an Core-Dateien.
 */
class Zahlungsqr
{
  /** @var Application */
  protected $app;

  const MODULE_NAME = 'PaymentQr';

  /** Name des Beleg-PDF-Hooks (class.briefpapier.php, renderFooter) */
  const HOOK_NAME = 'briefpapier_render_footer_hook2';

  /** Zahlungsweisen-Module dieses Pakets (www/lib/zahlungsweisen/) */
  const QR_MODULES = ['rechnung_qr', 'paypal_qr', 'wero'];

  /** Upload-Limit fuer das Wero-Bild: 2 MB */
  const MAX_UPLOAD_BYTES = 2097152;

  public function __construct($app, $intern = false)
  {
    $this->app = $app;
    if($intern) {
      return;
    }

    $this->app->ActionHandlerInit($this);
    $this->app->ActionHandler('list', 'ZahlungsqrList');
    $this->app->ActionHandler('upload', 'ZahlungsqrUpload');
    $this->app->ActionHandler('uninstall', 'ZahlungsqrUninstall');
    $this->app->DefaultActionHandler('list');

    $this->app->Tpl->Set('UEBERSCHRIFT', 'ZahlungsQR');
    $this->app->Tpl->Set('FARBE', '[FARBE5]');

    $this->app->ActionHandlerListen($app);
  }

  /**
   * Registriert Hook, Menuepunkt und Zahlungsweisen-Eintraege.
   * Idempotent: kann beliebig oft aufgerufen werden (Install-Button
   * auf der Modulseite sowie einmalig durch den OpenXE-Installer).
   */
  public function Install()
  {
    // 1. Hook-Stammsatz sicherstellen + Handler registrieren
    $this->app->erp->GenerateHook(self::HOOK_NAME, 1, 1);
    $this->app->erp->RegisterHook(self::HOOK_NAME, 'zahlungsqr', 'RenderQrBlock');

    // 2. Menuepunkt (Bereich admin)
    $this->app->erp->RegisterNavigationHook('zahlungsqr', 'list', 'admin', 'zahlungsqr');

    // 3. Zahlungsweisen idempotent anlegen bzw. auf die QR-Module umstellen
    $this->EnsureZahlungsweiseRechnung();
    $this->EnsureZahlungsweise('paypal', 'PayPal', 'paypal_qr');
    $this->EnsureZahlungsweise('wero', 'Wero', 'wero');
  }

  /**
   * Zahlungsart Ueberweisung (type=rechnung): existiert sie (aktiv oder
   * nicht), wird NUR das modul-Feld auf rechnung_qr gestellt - alle
   * anderen Felder (Texte, Skonto, aktiv, ...) bleiben unangetastet.
   * Zahlungsweise_rechnung_qr erbt das komplette Verhalten des
   * Standard-Moduls und ergaenzt nur die QR-Einstellungen.
   */
  private function EnsureZahlungsweiseRechnung()
  {
    $vorhanden = $this->app->DB->Select(
      "SELECT id FROM zahlungsweisen WHERE type = 'rechnung' AND geloescht = 0 LIMIT 1"
    );
    if($vorhanden) {
      $this->app->DB->Update(
        "UPDATE zahlungsweisen SET modul = 'rechnung_qr' WHERE type = 'rechnung' AND geloescht = 0"
      );
      return;
    }
    $this->app->DB->Insert(
      "INSERT INTO zahlungsweisen
       (type, bezeichnung, freitext, aktiv, geloescht, projekt, verhalten, modul, einstellungen_json)
       VALUES ('rechnung', 'Ueberweisung', '', 0, 0, 0, 'rechnung', 'rechnung_qr', '')"
    );
  }

  /**
   * Legt eine fehlende Zahlungsart (paypal/wero) inaktiv an. Existiert
   * sie bereits, wird nur ein LEERES modul-Feld auf das QR-Modul
   * gesetzt - ein bereits gepflegtes Modul wird nie ueberschrieben.
   *
   * @param string $type        zahlungsweisen.type
   * @param string $bezeichnung Anzeigename bei Neuanlage
   * @param string $modul       Zahlungsweisen-Modulklasse (Dateiname)
   */
  private function EnsureZahlungsweise($type, $bezeichnung, $modul)
  {
    $typeSql = $this->app->DB->real_escape_string($type);
    $bezeichnungSql = $this->app->DB->real_escape_string($bezeichnung);
    $modulSql = $this->app->DB->real_escape_string($modul);

    $vorhanden = $this->app->DB->Select(
      "SELECT id FROM zahlungsweisen WHERE type = '$typeSql' AND geloescht = 0 LIMIT 1"
    );
    if($vorhanden) {
      $this->app->DB->Update(
        "UPDATE zahlungsweisen SET modul = '$modulSql'
         WHERE type = '$typeSql' AND geloescht = 0 AND (modul = '' OR modul IS NULL)"
      );
      return;
    }
    $this->app->DB->Insert(
      "INSERT INTO zahlungsweisen
       (type, bezeichnung, freitext, aktiv, geloescht, projekt, verhalten, modul, einstellungen_json)
       VALUES ('$typeSql', '$bezeichnungSql', '', 0, 0, 0, 'rechnung', '$modulSql', '')"
    );
  }

  /**
   * Modulweiter Rechte-Check: nur Admins duerfen die Modulseite nutzen.
   * Der Hook-Handler RenderQrBlock ist davon unabhaengig (RunHook).
   */
  public function CheckRights()
  {
    return $this->app->User->GetType() === 'admin';
  }

  /**
   * Hook-Handler: briefpapier_render_footer_hook2.
   * Wird fuer JEDES Beleg-PDF aufgerufen - alle Gates liegen hier.
   * Darf unter keinen Umstaenden eine Exception nach aussen lassen,
   * sonst scheitert der Beleg-Druck.
   *
   * @param object $pdf Briefpapier/FPDF-Objekt (RechnungPDF etc.)
   */
  public function RenderQrBlock($pdf)
  {
    try {
      if(!is_object($pdf) || ($pdf->doctype ?? '') !== 'rechnung') {
        return;
      }
      $id = (int)($pdf->id ?? 0); // class.briefpapier.php:728
      if($id <= 0) {
        $id = (int)($pdf->doctypeid ?? 0); // Fallback, class.rechnung.php:64
      }
      if($id <= 0) {
        return;
      }

      $rechnung = $this->app->DB->SelectRow(
        "SELECT belegnr, zahlungsweise, soll, waehrung, projekt, kundennummer
         FROM rechnung WHERE id = $id" // Bruttobetrag = soll (struktur.sql)
      );
      if(empty($rechnung) || empty($rechnung['belegnr'])) {
        return;
      }

      $settingsService = new PaymentQrSettingsService($this->app->DB);
      $configs = $settingsService->getActiveQrConfigs((int)$rechnung['projekt']);
      if(empty($configs)) {
        return;
      }

      $barcodeFactory = $this->app->Container->get('BarcodeFactory');
      $payloadBuilder = new EpcQrPayloadBuilder();
      $items = QrItemAssembler::build(
        $rechnung,
        $configs,
        function ($payload) use ($barcodeFactory) {
          return $barcodeFactory->createQrCode($payload, 'M')->toPng(300, 300);
        },
        function ($dateiId) {
          return $this->WeroImagePath((int)$dateiId);
        },
        $payloadBuilder,
        $settingsService
      );
      if(!empty($items)) {
        (new QrBlockRenderer())->render($pdf, $items);
      }
    } catch (\Throwable $e) {
      $this->LogError('zahlungsqr RenderQrBlock: '.$e->getMessage());
    }
  }

  /**
   * Loest die Datei-ID des Wero-Bildes ueber das OpenXE-Dateisystem
   * (DMS) in einen lesbaren Pfad auf. GetDateiPfad liefert den Pfad
   * der neuesten datei_version; existiert die Datei dort nicht,
   * entfaellt das Wero-Item.
   *
   * @param int $dateiId zahlungsweisen.einstellungen_json -> qr_datei
   *
   * @return string|null
   */
  private function WeroImagePath($dateiId)
  {
    $dateiId = (int)$dateiId;
    if($dateiId <= 0) {
      return null;
    }
    $pfad = $this->app->erp->GetDateiPfad($dateiId);
    if(!is_string($pfad) || $pfad === '' || !is_file($pfad)) {
      return null;
    }
    return $pfad;
  }

  /**
   * Loggt ueber die vorhandene erpAPI-Methode LogWithTime (erpapi:550;
   * nur wirksam, wenn SCRIPT_START_TIME definiert ist) und immer
   * zusaetzlich per error_log als Fallback. Darf selbst nie werfen.
   *
   * @param string $message
   */
  private function LogError($message)
  {
    try {
      if(isset($this->app->erp) && method_exists($this->app->erp, 'LogWithTime')) {
        $this->app->erp->LogWithTime($message);
      }
    } catch (\Throwable $e) {
      // Logging darf den Beleg-Druck nie gefaehrden
    }
    @error_log($message);
  }

  /**
   * Modulseite: Statusuebersicht der drei Zahlungsarten, Install-Button,
   * Wero-Upload-Formular und Deinstallations-Hinweis.
   */
  public function ZahlungsqrList()
  {
    $this->app->erp->MenuEintrag('index.php?module=einstellungen&action=list', 'Zur&uuml;ck');
    $this->app->erp->MenuEintrag('index.php?module=zahlungsqr&action=list', 'ZahlungsQR');

    if($this->app->Secure->GetPOST('install') !== '') {
      try {
        $this->Install();
        $this->app->Tpl->Set(
          'MESSAGE',
          '<div class="success">Installation ausgef&uuml;hrt: PDF-Hook und Men&uuml;punkt registriert, Zahlungsarten angelegt bzw. verkn&uuml;pft.</div>'
        );
      } catch (\Throwable $e) {
        $this->app->Tpl->Set(
          'MESSAGE',
          '<div class="error">Installation fehlgeschlagen: '.htmlspecialchars($e->getMessage()).'</div>'
        );
      }
    }

    // Meldungen aus Redirects (Upload/Deinstallation): strukturierte
    // Parameter statt HTML in der URL; Fehlertexte werden escaped
    $ok = (string)$this->app->Secure->GetGET('ok');
    if($ok === 'upload') {
      $fid = (int)$this->app->Secure->GetGET('fid');
      $this->app->Tpl->Set(
        'MESSAGE',
        '<div class="success">Wero-QR-Bild wurde gespeichert (Datei-ID '.$fid.').</div>'
      );
    } elseif($ok === 'uninstall') {
      $this->app->Tpl->Set(
        'MESSAGE',
        '<div class="info">ZahlungsQR wurde deaktiviert: Hook und Men&uuml;punkt entfernt, '
        .'PayPal/Wero deaktiviert, Zahlungsart &Uuml;berweisung auf das Standard-Modul '
        .'zur&uuml;ckgestellt. Es wurden keine Daten gel&ouml;scht.</div>'
      );
    }
    $err = (string)$this->app->Secure->GetGET('err');
    if($err !== '') {
      $errText = (string)$this->app->erp->base64_url_decode($err);
      $this->app->Tpl->Set('MESSAGE', '<div class="error">'.htmlspecialchars($errText).'</div>');
    }

    // Hook-/Menuestatus
    $hookAktiv = (int)$this->app->DB->Select(
      "SELECT COUNT(hr.id) FROM hook_register AS hr WHERE hr.module = 'zahlungsqr' AND hr.aktiv = 1"
    );
    $navAktiv = (int)$this->app->DB->Select(
      "SELECT COUNT(hn.id) FROM hook_navigation AS hn WHERE hn.module = 'zahlungsqr' AND hn.aktiv = 1"
    );
    $this->app->Tpl->Set(
      'HOOK_STATUS',
      'PDF-Hook: '.($hookAktiv > 0 ? 'registriert' : '<b>nicht registriert</b>')
      .' &middot; Men&uuml;punkt: '.($navAktiv > 0 ? 'registriert' : '<b>nicht registriert</b>')
    );

    // Statusuebersicht der drei Zahlungsarten
    $rows = $this->app->DB->SelectArr(
      "SELECT z.id, z.type, z.bezeichnung, z.modul, z.aktiv, z.projekt, z.einstellungen_json
       FROM zahlungsweisen AS z
       WHERE z.geloescht = 0
         AND (z.modul IN ('rechnung_qr','paypal_qr','wero') OR z.type IN ('rechnung','paypal','wero'))
       ORDER BY z.type, z.projekt, z.id"
    );

    $statusRows = '';
    if(!empty($rows)) {
      foreach($rows as $row) {
        $statusRows .= $this->StatusRow($row);
      }
    } else {
      $statusRows = '<tr><td colspan="7">Keine passenden Zahlungsarten gefunden &ndash; bitte die Installation ausf&uuml;hren.</td></tr>';
    }
    $this->app->Tpl->Set('STATUS_ROWS', $statusRows);

    // Wero-Upload-Status (Zieleintrag = globaler wero-Eintrag)
    $wero = $this->app->DB->SelectRow(
      "SELECT z.id, z.einstellungen_json FROM zahlungsweisen AS z
       WHERE z.modul = 'wero' AND z.geloescht = 0
       ORDER BY z.projekt ASC, z.id ASC LIMIT 1"
    );
    if(empty($wero)) {
      $weroStatus = 'Zahlungsart Wero ist noch nicht angelegt &ndash; bitte zuerst die Installation ausf&uuml;hren.';
    } else {
      $weroSettings = json_decode((string)$wero['einstellungen_json'], true);
      $weroDateiId = is_array($weroSettings) ? (int)($weroSettings['qr_datei'] ?? 0) : 0;
      if($weroDateiId <= 0) {
        $weroStatus = 'Noch kein Wero-QR-Bild hochgeladen.';
      } elseif($this->WeroImagePath($weroDateiId) !== null) {
        $weroStatus = 'Aktuelles Bild: Datei-ID '.$weroDateiId.' (Datei vorhanden). Ein neuer Upload ersetzt die Zuordnung.';
      } else {
        $weroStatus = 'Aktuelles Bild: Datei-ID '.$weroDateiId.', <b>Datei im Dateisystem nicht gefunden</b> &ndash; bitte erneut hochladen.';
      }
    }
    $this->app->Tpl->Set('WERO_STATUS', $weroStatus);

    $this->app->Tpl->Parse('PAGE', 'zahlungsqr_settings.tpl');
  }

  /**
   * Baut eine Statuszeile der Uebersichtstabelle. Alle DB-Werte werden
   * HTML-escaped.
   *
   * @param array $row zahlungsweisen-Row (id, type, bezeichnung, modul,
   *                   aktiv, projekt, einstellungen_json)
   *
   * @return string <tr>...</tr>
   */
  private function StatusRow(array $row)
  {
    $modul = (string)($row['modul'] ?? '');
    $settings = json_decode((string)($row['einstellungen_json'] ?? ''), true);
    $settingsLesbar = is_array($settings) || trim((string)($row['einstellungen_json'] ?? '')) === '';
    if(!is_array($settings)) {
      $settings = [];
    }

    $warnungen = [];
    if(!$settingsLesbar) {
      $warnungen[] = 'einstellungen_json ist kein g&uuml;ltiges JSON';
    }
    switch($modul) {
      case 'rechnung_qr':
        if(trim((string)($settings['qr_iban'] ?? '')) === '') {
          $warnungen[] = 'IBAN fehlt';
        }
        if(trim((string)($settings['qr_kontoinhaber'] ?? '')) === '') {
          $warnungen[] = 'Kontoinhaber fehlt';
        }
        break;
      case 'paypal_qr':
        if(trim((string)($settings['paypalme_handle'] ?? '')) === '') {
          $warnungen[] = 'PayPal.me-Handle fehlt';
        }
        break;
      case 'wero':
        $dateiId = (int)($settings['qr_datei'] ?? 0);
        if($dateiId <= 0) {
          $warnungen[] = 'Wero-QR-Bild fehlt (Upload unten)';
        } elseif($this->WeroImagePath($dateiId) === null) {
          $warnungen[] = 'Wero-Bilddatei (ID '.$dateiId.') im Dateisystem nicht gefunden';
        }
        break;
      default:
        $warnungen[] = 'Kein QR-Modul zugeordnet &ndash; Installation ausf&uuml;hren';
        break;
    }

    if(!in_array($modul, self::QR_MODULES, true)) {
      $qrStatus = 'nicht installiert';
    } elseif(empty($settings['qr_aktiv'])) {
      $qrStatus = 'QR-Anzeige deaktiviert';
      $warnungen[] = 'QR-Anzeige in den Einstellungen der Zahlungsart aktivieren (Haken "auf Rechnung anzeigen")';
    } elseif(!empty($warnungen)) {
      $qrStatus = '<b>unvollst&auml;ndig</b>';
    } else {
      $qrStatus = 'bereit';
    }

    return '<tr>'
      .'<td>'.htmlspecialchars((string)($row['bezeichnung'] ?? '')).'</td>'
      .'<td>'.htmlspecialchars((string)($row['type'] ?? '')).'</td>'
      .'<td>'.htmlspecialchars($modul).'</td>'
      .'<td>'.(int)($row['projekt'] ?? 0).'</td>'
      .'<td>'.((int)($row['aktiv'] ?? 0) === 1 ? 'ja' : 'nein').'</td>'
      .'<td>'.$qrStatus.'</td>'
      .'<td>'.implode('<br>', $warnungen).'</td>'
      .'</tr>';
  }

  /**
   * Nimmt das Wero-QR-Bild entgegen (nur PNG, max. 2 MB), legt es ueber
   * CreateDatei (erpapi:36940) im DMS ab und schreibt die Datei-ID per
   * Read-Modify-Write in einstellungen_json des wero-Eintrags (nur der
   * Key qr_datei wird veraendert, alle anderen Keys bleiben erhalten).
   */
  public function ZahlungsqrUpload()
  {
    $error = '';
    $fileId = 0;

    $file = isset($_FILES['wero_datei']) && is_array($_FILES['wero_datei']) ? $_FILES['wero_datei'] : null;
    if($file === null || (string)($file['tmp_name'] ?? '') === '') {
      $error = 'Keine Datei uebermittelt.';
    } elseif((int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
      $error = 'Upload fehlgeschlagen (Fehlercode '.(int)$file['error'].').';
    } elseif((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > self::MAX_UPLOAD_BYTES) {
      $error = 'Datei ist leer oder groesser als 2 MB.';
    }

    if($error === '') {
      $tmp = (string)$file['tmp_name'];
      if(!$this->IsPngUpload($tmp)) {
        $error = 'Es werden nur PNG-Dateien akzeptiert (MIME-Typ und Dateikopf werden geprueft).';
      }
    }

    $wero = null;
    if($error === '') {
      $wero = $this->app->DB->SelectRow(
        "SELECT z.id, z.einstellungen_json FROM zahlungsweisen AS z
         WHERE z.modul = 'wero' AND z.geloescht = 0
         ORDER BY z.projekt ASC, z.id ASC LIMIT 1"
      );
      if(empty($wero)) {
        $error = 'Zahlungsart Wero nicht gefunden - bitte zuerst die Installation ausfuehren.';
      }
    }

    if($error === '') {
      $fileId = (int)$this->app->erp->CreateDatei(
        'wero_qr.png',
        'Wero QR-Code',
        'Statischer Wero-QR-Code fuer Rechnungs-PDFs (Modul ZahlungsQR)',
        '',
        $tmp,
        $this->app->User->GetName()
      );
      if($fileId <= 0) {
        $error = 'Datei konnte nicht im Dateisystem abgelegt werden.';
      }
    }

    if($error === '') {
      // Read-Modify-Write: nur der Key qr_datei wird veraendert
      $settings = json_decode((string)$wero['einstellungen_json'], true);
      if(!is_array($settings)) {
        $settings = [];
      }
      $settings['qr_datei'] = (string)$fileId;
      $neu = json_encode($settings);
      if($neu === false) {
        $error = 'Einstellungen konnten nicht als JSON gespeichert werden.';
      } else {
        $this->app->DB->Update(
          "UPDATE zahlungsweisen
           SET einstellungen_json = '".$this->app->DB->real_escape_string($neu)."'
           WHERE id = ".(int)$wero['id'].' LIMIT 1'
        );
      }
    }

    // Strukturierte Parameter statt HTML in der URL (kein Reflected-XSS-Vektor);
    // die Uebersichtsseite baut und escaped die Meldung selbst.
    if($error === '') {
      $this->app->Location->execute(
        'index.php?module=zahlungsqr&action=list&ok=upload&fid='.$fileId
      );
    } else {
      $this->app->Location->execute(
        'index.php?module=zahlungsqr&action=list&err='.$this->app->erp->base64_url_encode($error)
      );
    }
  }

  /**
   * Prueft eine hochgeladene Datei auf PNG: MIME-Typ image/png (finfo,
   * inhaltbasiert) UND PNG-Magic-Bytes. Steht finfo nicht zur
   * Verfuegung, entscheidet der Magic-Byte-Check allein.
   *
   * @param string $tmpPath Pfad zur Upload-Tmp-Datei
   *
   * @return bool
   */
  private function IsPngUpload($tmpPath)
  {
    if($tmpPath === '' || !is_file($tmpPath)) {
      return false;
    }

    $magic = (string)@file_get_contents($tmpPath, false, null, 0, 8);
    if($magic !== "\x89PNG\r\n\x1a\n") {
      return false;
    }

    if(function_exists('finfo_open')) {
      $finfo = @finfo_open(FILEINFO_MIME_TYPE);
      if($finfo) {
        $mime = (string)@finfo_file($finfo, $tmpPath);
        @finfo_close($finfo);
        if($mime !== 'image/png') {
          return false;
        }
      }
    }

    return true;
  }

  /**
   * Deinstallation: entfernt Hook-Registrierung und Menuepunkt,
   * deaktiviert die Zahlungsarten PayPal und Wero und stellt die
   * Zahlungsart Ueberweisung auf das Standard-Modul zurueck.
   * Es werden KEINE Daten geloescht (Einstellungen, Dateien und
   * Zahlungsweisen-Eintraege bleiben erhalten).
   */
  public function ZahlungsqrUninstall()
  {
    if($this->app->Secure->GetPOST('uninstall') === '') {
      // kein bestaetigter POST -> zurueck zur Uebersicht
      $this->app->Location->execute('index.php?module=zahlungsqr&action=list');
      return;
    }

    $this->app->DB->Delete("DELETE FROM hook_register WHERE module = 'zahlungsqr'");

    if(method_exists($this->app->erp, 'RemoveNavigationHook')) {
      $this->app->erp->RemoveNavigationHook('zahlungsqr'); // erpapi:686
    } else {
      $this->app->DB->Delete("DELETE FROM hook_navigation WHERE module = 'zahlungsqr'");
    }

    $this->app->DB->Update(
      "UPDATE zahlungsweisen SET aktiv = 0
       WHERE modul IN ('paypal_qr','wero') AND geloescht = 0"
    );
    $this->app->DB->Update(
      "UPDATE zahlungsweisen SET modul = 'rechnung'
       WHERE type = 'rechnung' AND modul = 'rechnung_qr' AND geloescht = 0"
    );

    $this->app->Location->execute('index.php?module=zahlungsqr&action=list&ok=uninstall');
  }
}
