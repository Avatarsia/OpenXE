<?php
/*
**** COPYRIGHT & LICENSE NOTICE *** DO NOT REMOVE ****
* 
* Xentral (c) Xentral ERP Sorftware GmbH, Fuggerstrasse 11, D-86150 Augsburg, * Germany 2019
*
* This file is licensed under the Embedded Projects General Public License *Version 3.1. 
*
* You should have received a copy of this license from your vendor and/or *along with this file; If not, please visit www.wawision.de/Lizenzhinweis 
* to obtain the text of the corresponding license version.  
*
**** END OF COPYRIGHT & LICENSE NOTICE *** DO NOT REMOVE ****
*/
?>
<?php

use Xentral\Components\Http\Session\SessionHandler;
use Xentral\Modules\LexwareOffice\Bootstrap as LexwareOfficeBootstrap;
use Xentral\Modules\LexwareOffice\Exception\LexwareOfficeException;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficeService;

class Lexwareoffice
{
  /** @var Application */
  public $app;

  public function __construct($app, $intern = false)
  {
    $this->app = $app;
    if($intern) {
      return;
    }

    $this->ensureSuperSearchIndex();

    $this->app->ActionHandlerInit($this);
    $this->app->ActionHandler('edit','LexwareOfficeEdit');
    $this->app->ActionHandler('upload','LexwareOfficeUpload');
    $this->app->ActionHandler('upload_creditnote','LexwareOfficeUploadCreditNote');
    $this->app->DefaultActionHandler('edit');

    $this->app->Tpl->Set('UEBERSCHRIFT','Lexware Office');
    $this->app->Tpl->Set('FARBE','[FARBE5]');

    $this->app->ActionHandlerListen($app);
  }

  public function LexwareOfficeEdit()
  {
    $this->app->erp->MenuEintrag('index.php?module=einstellungen&action=list','Zur&uuml;ck');
    $this->app->erp->MenuEintrag('index.php?module=lexwareoffice&action=edit','Lexware Office');

    $service = $this->getService();
    $message = '';
    $hasApiKey = $service->hasApiKey();

    /** @var \Xentral\Components\Http\Session\Session $session */
    $session = $this->app->Container->get('Session');
    $csrfTokenKey = 'lexwareoffice_edit';
    $isAdmin = ($this->app->User->GetType() === 'admin');

    $hasIncomingPost = ($this->app->Secure->GetPOST('save') !== ''
        || $this->app->Secure->GetPOST('delete') !== ''
        || $this->app->Secure->GetPOST('init') !== '');
    $postAllowed = false;
    if($hasIncomingPost) {
      if(!$isAdmin) {
        $message = '<div class="error">Diese Aktion ist Administratoren vorbehalten.</div>';
      } elseif(!$session->isCsrfTokenValid($csrfTokenKey, (string)$this->app->Secure->GetPOST('csrf_token'), true)) {
        $message = '<div class="error">Sicherheits-Token ung&uuml;ltig oder abgelaufen. Bitte Seite neu laden und erneut versuchen.</div>';
      } else {
        $postAllowed = true;
      }
    }

    if($postAllowed && $this->app->Secure->GetPOST('delete') !== '') {
      try {
        $service->deleteApiKey();
        $hasApiKey = false;
        $message = '<div class="info">API-Schl&uuml;ssel wurde gel&ouml;scht.</div>';
      } catch (LexwareOfficeException $exception) {
        $message = '<div class="error">'.htmlspecialchars($exception->getMessage()).'</div>';
      }
    } elseif($postAllowed && $this->app->Secure->GetPOST('save') !== '') {
      try {
        $apiKey = (string)$this->app->Secure->GetPOST('api_key');
        $service->saveApiKey($apiKey);
        $hasApiKey = true;
        $message = '<div class="success">API-Schl&uuml;ssel wurde gespeichert.</div>';
      } catch (LexwareOfficeException $exception) {
        $message = '<div class="error">'.htmlspecialchars($exception->getMessage()).'</div>';
      }
    } elseif($postAllowed && $this->app->Secure->GetPOST('init') !== '') {
      try {
        /** @var \Xentral\Components\Database\Database $db */
        $db = $this->app->Container->get('Database');
        LexwareOfficeBootstrap::ensureSchema($db);
        $this->Install();

        /** @var \Xentral\Modules\LexwareOffice\Service\LexwareOfficeConfigService $configService */
        $configService = $this->app->Container->get('LexwareOfficeConfigService');
        $masterKeyPath = $configService->getMasterKeyPath();
        $masterKeyCreated = LexwareOfficeBootstrap::ensureMasterKeyFile($masterKeyPath);

        if($masterKeyCreated) {
          $masterKeyMessage = ' Master-Key neu angelegt unter <code>'.htmlspecialchars($masterKeyPath, ENT_QUOTES, 'UTF-8').'</code>. <strong>Diese Datei muss in dein Backup mit aufgenommen werden &mdash; bei Verlust ist der gespeicherte API-Schl&uuml;ssel nicht mehr entschl&uuml;sselbar.</strong>';
        } else {
          $masterKeyMessage = ' Master-Key bereits vorhanden unter <code>'.htmlspecialchars($masterKeyPath, ENT_QUOTES, 'UTF-8').'</code>.';
        }

        $message = '<div class="success">Initial Setup ausgef&uuml;hrt: Datenbank-Spalten gepr&uuml;ft, Hooks registriert. Mehrfachausf&uuml;hrung ist unsch&auml;dlich.'.$masterKeyMessage.'</div>';
      } catch (\Throwable $exception) {
        $message = '<div class="error">Setup fehlgeschlagen: '.htmlspecialchars($exception->getMessage()).'</div>';
      }
    }

    if($message !== '') {
      $this->app->Tpl->Set('MESSAGE',$message);
    }

    if(!$isAdmin) {
      $this->app->Tpl->Set('API_KEY_SECTION', '<div class="info">Diese Seite ist Administratoren vorbehalten.</div>');
      $this->app->Tpl->Set('INIT_SECTION', '');
      $this->app->Tpl->Set('DELETE_SECTION', '');
      $this->app->Tpl->Parse('PAGE','lexwareoffice_settings.tpl');
      return;
    }

    $csrfToken = $session->createCsrfToken($csrfTokenKey);
    // OpenXE persistiert die Session NICHT automatisch am Request-Ende; ohne
    // expliziten commitSession-Aufruf landet der frisch erzeugte CSRF-Token
    // nur im in-memory Manager und nicht in $_SESSION, sodass die Validation
    // beim naechsten POST fehlschlaegt ("Sicherheits-Token ungueltig").
    SessionHandler::commitSession($session);
    $csrfTokenField = '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8').'">';

    $apiKeyPlaceholder = $hasApiKey ? '******** (gespeichert)' : '';
    $apiKeySection = '
        <h2>Lexware Office API-Schl&uuml;ssel</h2>
        <form method="post" action="">
          <fieldset>
            <legend>API-Schl&uuml;ssel</legend>
            <p>
              <label for="lexware-api-key"><strong>API-Schl&uuml;ssel</strong></label><br>
              <input type="password" name="api_key" id="lexware-api-key" value="" placeholder="'.htmlspecialchars($apiKeyPlaceholder, ENT_QUOTES, 'UTF-8').'" style="width: 100%;">
            </p>
            <p class="hint">Der API-Schl&uuml;ssel wird verschl&uuml;sselt in der Systemkonfiguration abgelegt.</p>
            '.$csrfTokenField.'
            <p>
              <input type="submit" class="btnBlue" name="save" value="Speichern">
            </p>
          </fieldset>
        </form>
      ';
    $this->app->Tpl->Set('API_KEY_SECTION', $apiKeySection);

    $initSection = '
        <form method="post" action="">
          <fieldset>
            <legend>Initial Setup</legend>
            <p>F&uuml;hrt einmalig drei Schritte aus:</p>
            <ul>
              <li>Datenbank-Migration: Spalten <code>lexware_*</code> in <code>adresse</code>, <code>rechnung</code>, <code>gutschrift</code></li>
              <li>Hook-Registrierung: Aktionsmen&uuml; in Rechnungs- und Gutschriftslisten</li>
              <li>Master-Key-Datei f&uuml;r die Verschl&uuml;sselung des API-Schl&uuml;ssels (au&szlig;erhalb der Datenbank)</li>
            </ul>
            <p>Idempotent &mdash; mehrfaches Ausf&uuml;hren ist unsch&auml;dlich. <strong>Hinweis:</strong> Die Master-Key-Datei muss in dein Backup mit aufgenommen werden &mdash; bei Verlust ist ein gespeicherter API-Schl&uuml;ssel nicht mehr entschl&uuml;sselbar.</p>
            '.$csrfTokenField.'
            <p>
              <input type="submit" class="btnGrey" name="init" value="Initial Setup ausf&uuml;hren">
            </p>
          </fieldset>
        </form>
      ';
    $this->app->Tpl->Set('INIT_SECTION', $initSection);

    $deleteSection = '';
    if($hasApiKey) {
      $deleteSection = '
        <form method="post" action="" onsubmit="return confirm(\'API-Schlüssel wirklich löschen?\');">
          <fieldset>
            <legend>API-Schl&uuml;ssel entfernen</legend>
            <p>Entfernt den hinterlegten API-Schl&uuml;ssel aus der Systemkonfiguration.</p>
            '.$csrfTokenField.'
            <p>
              <input type="submit" class="btnGrey" name="delete" value="API-Schl&uuml;ssel l&ouml;schen">
            </p>
          </fieldset>
        </form>
      ';
    }
    $this->app->Tpl->Set('DELETE_SECTION', $deleteSection);

    $this->app->Tpl->Parse('PAGE','lexwareoffice_settings.tpl');
  }

  private function getService(): LexwareOfficeService
  {
    return $this->app->Container->get('LexwareOfficeService');
  }

  /**
   * Wird einmalig vom OpenXE-Installer aufgerufen.
   * Registriert die Hook-Listener fuer das Rechnungs-Aktionsmenue.
   */
  public function Install()
  {
    $this->app->erp->RegisterHook(
      'Rechnung_Aktion_option',
      'lexwareoffice',
      'LexwareOfficeAktionOption'
    );
    $this->app->erp->RegisterHook(
      'Rechnung_Aktion_case',
      'lexwareoffice',
      'LexwareOfficeAktionCase'
    );
    $this->app->erp->RegisterHook(
      'Gutschrift_Aktion_option',
      'lexwareoffice',
      'LexwareOfficeGutschriftAktionOption'
    );
    $this->app->erp->RegisterHook(
      'Gutschrift_Aktion_case',
      'lexwareoffice',
      'LexwareOfficeGutschriftAktionCase'
    );
    $this->app->erp->RegisterHook(
      'Rechnung_Stapel_option',
      'lexwareoffice',
      'LexwareOfficeStapelOption'
    );
    $this->app->erp->RegisterHook(
      'Rechnung_Stapel_case',
      'lexwareoffice',
      'LexwareOfficeStapelCase'
    );
  }

  /**
   * Hook-Listener: haengt den Dropdown-Eintrag an die Rechnungs-Aktionsliste.
   *
   * @param int    $id
   * @param string $projectStatus
   * @param string $option
   */
  public function LexwareOfficeAktionOption($id, $projectStatus, &$option)
  {
    if(!$this->hasLexwareOfficeApiKey()) {
      return;
    }
    $option .= '<option value="lexwareofficeupload">An Lexware Office senden</option>';
  }

  /**
   * Hook-Listener: haengt den zugehoerigen JS-Case an das Rechnungs-Aktionsmenue.
   *
   * Hinweis zum Selector: das aktive Haupt-Dropdown in rechnung.php verwendet
   * id="aktion$prefix" mit default $prefix='', folglich ist die effektive ID
   * schlicht "aktion". Der Null-Check haelt den Handler robust, falls das
   * DOM-Element spaeter umbenannt wird.
   *
   * @param int    $id
   * @param string $projectStatus
   * @param string $case
   */
  public function LexwareOfficeAktionCase($id, $projectStatus, &$case)
  {
    if(!$this->hasLexwareOfficeApiKey()) {
      return;
    }
    $case .= "case 'lexwareofficeupload':"
      ." if(!confirm('Rechnung an Lexware Office senden?')) {"
      ."   var el = document.getElementById('aktion'); if(el) el.selectedIndex = 0;"
      ."   return;"
      ." }"
      ." window.location.href='index.php?module=lexwareoffice&action=upload&id=%value%';"
      ." break;";
  }

  /**
   * Hook-Listener: Dropdown-Eintrag in der Gutschrifts-Aktionsliste.
   *
   * @param int    $id
   * @param string $projectStatus
   * @param string $option
   */
  public function LexwareOfficeGutschriftAktionOption($id, $projectStatus, &$option)
  {
    if(!$this->hasLexwareOfficeApiKey()) {
      return;
    }
    $option .= '<option value="lexwareofficeuploadcreditnote">An Lexware Office senden</option>';
  }

  /**
   * Hook-Listener: JS-Case fuer Gutschrifts-Aktionsmenue.
   *
   * @param int    $id
   * @param string $projectStatus
   * @param string $case
   */
  public function LexwareOfficeGutschriftAktionCase($id, $projectStatus, &$case)
  {
    if(!$this->hasLexwareOfficeApiKey()) {
      return;
    }
    $case .= "case 'lexwareofficeuploadcreditnote':"
      ." if(!confirm('Gutschrift an Lexware Office senden?')) {"
      ."   var el = document.getElementById('aktion'); if(el) el.selectedIndex = 0;"
      ."   return;"
      ." }"
      ." window.location.href='index.php?module=lexwareoffice&action=upload_creditnote&id=%value%';"
      ." break;";
  }

  /**
   * Prueft, ob ein Lexware-Office-API-Key hinterlegt ist.
   * Nutzt den im Bootstrap registrierten Container-Service.
   */
  private function hasLexwareOfficeApiKey(): bool
  {
    try {
      $config = $this->app->Container->get('LexwareOfficeConfigService');
      return $config->hasApiKey();
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * Action-Handler: nimmt die Rechnungs-ID aus dem Dropdown entgegen und
   * uebergibt sie dem LexwareOfficeService. Mirrors the old
   * RechnungLexwareOfficeUpload-Controller aus rechnung.php.
   */
  public function LexwareOfficeUpload()
  {
    $id = (int)$this->app->Secure->GetGET('id');
    if($id <= 0) {
      $msg = $this->app->erp->base64_url_encode('<div class="error">Rechnung wurde nicht gefunden.</div>');
      $this->app->Location->execute('index.php?module=rechnung&action=list&msg='.$msg);
      return;
    }

    if(!$this->app->erp->RechteVorhanden('rechnung','edit')) {
      $msg = $this->app->erp->base64_url_encode('<div class="error">Keine Berechtigung f&uuml;r diese Aktion.</div>');
      $this->app->Location->execute('index.php?module=rechnung&action=edit&id='.$id.'&msg='.$msg);
      return;
    }

    try {
      $result = $this->getService()->pushInvoice($id);
      $lexwareId = !empty($result['invoiceId']) ? $result['invoiceId'] : '';
      $contactId = !empty($result['contactId']) ? $result['contactId'] : '';
      $pdfErr = isset($result['pdfUploadError']) ? (string)$result['pdfUploadError'] : '';
      $text = 'Rechnung wurde an Lexware Office &uuml;bergeben.';
      if($lexwareId !== '') {
        $text .= ' Beleg-ID: '.htmlspecialchars($lexwareId);
      }
      if($contactId !== '') {
        $text .= ' Kontakt-ID: '.htmlspecialchars($contactId);
      }
      if($pdfErr !== '') {
        // Voucher steht, PDF fehlt: Warnung statt Erfolgsmeldung.
        $message = '<div class="info">'.$text.'<br>Hinweis: '.htmlspecialchars($pdfErr).'</div>';
        $this->app->erp->RechnungProtokoll($id, 'Lexware Office: '.$text.' | PDF-Hinweis: '.$pdfErr);
      } else {
        $message = '<div class="success">'.$text.'</div>';
        $this->app->erp->RechnungProtokoll($id, 'Lexware Office: '.$text);
      }
    } catch (LexwareOfficeException $exception) {
      $errorText = htmlspecialchars($exception->getMessage());
      $message = '<div class="error">'.$errorText.'</div>';
      $this->app->erp->RechnungProtokoll($id, 'Lexware Office Fehler: '.$errorText);
    } catch (\Throwable $exception) {
      // Generischer Fallback fuer alles was nicht domain-spezifisch ist:
      // DB-Ausfall, TypeError im Mapper, JSON-Encode-Fehler etc.
      $errorText = htmlspecialchars('Interner Fehler beim Lexware Office Upload: '.$exception->getMessage());
      $message = '<div class="error">'.$errorText.'</div>';
      $this->app->erp->RechnungProtokoll($id, 'Lexware Office Fehler (intern): '.$errorText);
    }

    $msg = $this->app->erp->base64_url_encode($message);
    $this->app->Location->execute('index.php?module=rechnung&action=edit&id='.$id.'&msg='.$msg);
  }

  /**
   * Action-Handler fuer den Gutschrifts-Upload. Spiegelt
   * LexwareOfficeUpload(), nutzt aber LexwareOfficeService::pushCreditNote()
   * und kehrt zur Gutschrifts-Detailseite zurueck.
   */
  public function LexwareOfficeUploadCreditNote()
  {
    $id = (int)$this->app->Secure->GetGET('id');
    if($id <= 0) {
      $msg = $this->app->erp->base64_url_encode('<div class="error">Gutschrift wurde nicht gefunden.</div>');
      $this->app->Location->execute('index.php?module=gutschrift&action=list&msg='.$msg);
      return;
    }

    if(!$this->app->erp->RechteVorhanden('gutschrift','edit')) {
      $msg = $this->app->erp->base64_url_encode('<div class="error">Keine Berechtigung f&uuml;r diese Aktion.</div>');
      $this->app->Location->execute('index.php?module=gutschrift&action=edit&id='.$id.'&msg='.$msg);
      return;
    }

    try {
      $result = $this->getService()->pushCreditNote($id);
      $lexwareId = !empty($result['creditNoteId']) ? $result['creditNoteId'] : '';
      $contactId = !empty($result['contactId']) ? $result['contactId'] : '';
      $pdfErr = isset($result['pdfUploadError']) ? (string)$result['pdfUploadError'] : '';
      $text = 'Gutschrift wurde an Lexware Office &uuml;bergeben.';
      if($lexwareId !== '') {
        $text .= ' Beleg-ID: '.htmlspecialchars($lexwareId);
      }
      if($contactId !== '') {
        $text .= ' Kontakt-ID: '.htmlspecialchars($contactId);
      }
      if($pdfErr !== '') {
        $message = '<div class="info">'.$text.'<br>Hinweis: '.htmlspecialchars($pdfErr).'</div>';
        $this->app->erp->GutschriftProtokoll($id, 'Lexware Office: '.$text.' | PDF-Hinweis: '.$pdfErr);
      } else {
        $message = '<div class="success">'.$text.'</div>';
        $this->app->erp->GutschriftProtokoll($id, 'Lexware Office: '.$text);
      }
    } catch (LexwareOfficeException $exception) {
      $errorText = htmlspecialchars($exception->getMessage());
      $message = '<div class="error">'.$errorText.'</div>';
      $this->app->erp->GutschriftProtokoll($id, 'Lexware Office Fehler: '.$errorText);
    } catch (\Throwable $exception) {
      $errorText = htmlspecialchars('Interner Fehler beim Lexware Office Upload (Gutschrift): '.$exception->getMessage());
      $message = '<div class="error">'.$errorText.'</div>';
      $this->app->erp->GutschriftProtokoll($id, 'Lexware Office Fehler (intern): '.$errorText);
    }

    $msg = $this->app->erp->base64_url_encode($message);
    $this->app->Location->execute('index.php?module=gutschrift&action=edit&id='.$id.'&msg='.$msg);
  }

  /**
   * Modul-weiter Rechte-Check: nur Admins duerfen Settings verwalten oder
   * manuelle Uploads triggern. Adminrechte() existiert auf class.erpapi.php
   * nicht, daher direkter User->GetType() Check wie in artikel.php/welcome.php.
   */
  public function CheckRights()
  {
    return $this->app->User->GetType() === 'admin';
  }

  private function ensureSuperSearchIndex(): void
  {
    if(!$this->app->Container->has('SuperSearchService') || !$this->app->Container->has('SuperSearchIndexer')) {
      return;
    }

    /** @var \Xentral\Modules\SuperSearch\SuperSearchService $service */
    $service = $this->app->Container->get('SuperSearchService');
    /** @var \Xentral\Modules\SuperSearch\SuperSearchIndexer $indexer */
    $indexer = $this->app->Container->get('SuperSearchIndexer');

    $indexName = 'lexwareoffice';
    if(!$service->existsIndex($indexName)) {
      $service->createIndex($indexName, 'Lexware Office', 'lexwareoffice');
    }

    $count = (int)$this->app->DB->Select(
      sprintf(
        "SELECT COUNT(id) FROM supersearch_index_item WHERE index_name = '%s' AND outdated = 0",
        $indexName
      )
    );
    if($count === 0) {
      $indexer->updateIndexFull($indexName);
    }
  }

  /**
   * Hook-Listener: ergaenzt das Stapelverarbeitungs-Dropdown auf der
   * Rechnungs-Liste um den Eintrag "An Lexware Office senden". Wird nur
   * angezeigt, wenn ein API-Key hinterlegt ist.
   *
   * @param string $option
   */
  public function LexwareOfficeStapelOption(&$option)
  {
    if(!$this->hasLexwareOfficeApiKey()) {
      return;
    }
    $option .= '<option value="lexwareofficeupload">An Lexware Office senden</option>';
  }

  /**
   * Hook-Listener: fuehrt den Bulk-Upload an Lexware Office aus. Iteriert
   * ueber die ausgewaehlten Rechnungs-IDs, ruft pro Rechnung pushInvoice()
   * auf und protokolliert Erfolg/Fehler pro Rechnung im Rechnungsprotokoll.
   * Eine Sammelmeldung wird zusaetzlich oben auf der Liste angezeigt.
   *
   * @param string $aktion
   * @param array  $auswahl Array von Rechnungs-IDs.
   */
  public function LexwareOfficeStapelCase($aktion, $auswahl)
  {
    if($aktion !== 'lexwareofficeupload') {
      return;
    }
    if(!$this->hasLexwareOfficeApiKey()) {
      return;
    }
    if(!is_array($auswahl) || empty($auswahl)) {
      return;
    }

    $service = $this->app->Container->get('LexwareOfficeService');
    $okCount = 0;
    $failCount = 0;
    $errors = [];
    foreach($auswahl as $invoiceId) {
      $invoiceId = (int)$invoiceId;
      if($invoiceId <= 0) {
        continue;
      }
      try {
        $result = $service->pushInvoice($invoiceId);
        $okCount++;
        $belegnr = $this->app->DB->Select(
          sprintf("SELECT belegnr FROM rechnung WHERE id=%d LIMIT 1", $invoiceId)
        );
        $protMsg = 'Lexware Office (Bulk): Rechnung &uuml;bergeben.';
        if(!empty($result['invoiceId'])) {
          $protMsg .= ' Beleg-ID: '.$result['invoiceId'];
        }
        if(!empty($result['pdfUploadError'])) {
          $protMsg .= ' | PDF-Hinweis: '.$result['pdfUploadError'];
        }
        $this->app->erp->RechnungProtokoll($invoiceId, $protMsg);
      } catch (LexwareOfficeException $e) {
        $failCount++;
        $errors[] = sprintf('Rechnung %d: %s', $invoiceId, $e->getMessage());
        $this->app->erp->RechnungProtokoll($invoiceId, 'Lexware Office Fehler (Bulk): '.$e->getMessage());
      } catch (\Throwable $e) {
        $failCount++;
        $errors[] = sprintf('Rechnung %d: %s', $invoiceId, $e->getMessage());
        $this->app->erp->RechnungProtokoll($invoiceId, 'Lexware Office Fehler (Bulk, intern): '.$e->getMessage());
      }
    }

    $summary = sprintf(
      'Lexware Office Bulk-Upload: %d erfolgreich, %d fehlgeschlagen.',
      $okCount,
      $failCount
    );
    if($failCount > 0) {
      // Erste 3 Fehler in die UI-Message; alle stehen ohnehin im
      // jeweiligen Rechnungsprotokoll.
      $summary .= ' Fehler: '.implode(' / ', array_slice($errors, 0, 3));
      if(count($errors) > 3) {
        $summary .= sprintf(' (+%d weitere im Protokoll)', count($errors) - 3);
      }
    }

    $cssClass = $failCount === 0 ? 'success' : ($okCount === 0 ? 'error' : 'info');
    $this->app->Tpl->Add(
      'MESSAGE',
      '<div class="'.$cssClass.'">'.htmlspecialchars($summary).'</div>'
    );

    // UX: Auswahl-Haken nach dem Bulk wieder setzen, damit der User direkt
    // eine Folge-Aktion (z.B. "als bezahlt markieren") auf dieselben
    // Rechnungen anwenden kann. Tabellenbody wird vom YUI-TableSearch
    // asynchron nachgeladen, daher kurzes Polling auf das Erscheinen der
    // Checkboxen.
    $reselectIds = array_values(array_filter(
      array_map('intval', $auswahl),
      static fn($i) => $i > 0
    ));
    if (!empty($reselectIds)) {
      $idsJson = json_encode($reselectIds);
      $script = '<script>
(function(){
  var ids = '.$idsJson.';
  if(!ids.length) return;
  console.log("[lexware-bulk] re-selecting ids:", ids);
  var pending = ids.slice();
  var tries = 0;
  var iv = setInterval(function(){
    tries++;
    var stillPending = [];
    pending.forEach(function(id){
      var cb = document.querySelector(
        \'input[type="checkbox"][name="auswahl[]"][value="\' + id + \'"]\'
      );
      if(cb) {
        cb.checked = true;
      } else {
        stillPending.push(id);
      }
    });
    pending = stillPending;
    if(pending.length === 0) {
      console.log("[lexware-bulk] all checkboxes restored");
      clearInterval(iv);
    } else if(tries > 75) {
      console.log("[lexware-bulk] timeout, still missing:", pending);
      clearInterval(iv);
    }
  }, 200);
})();
</script>';
      $this->app->Tpl->Add('SCRIPT', $script);
    }
  }
}
