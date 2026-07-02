[MESSAGE]

<div class="row">
  <div class="col-lg-10">
    <h2>ZahlungsQR &ndash; Zahlungs-QR-Codes auf Rechnungen</h2>

    <fieldset>
      <legend>Status</legend>
      <p>[HOOK_STATUS]</p>
      <table border="0" cellpadding="4" cellspacing="0" width="100%">
        <tr>
          <th align="left">Zahlungsart</th>
          <th align="left">Typ</th>
          <th align="left">Modul</th>
          <th align="left">Projekt</th>
          <th align="left">Aktiv</th>
          <th align="left">QR-Status</th>
          <th align="left">Hinweise</th>
        </tr>
        [STATUS_ROWS]
      </table>
      <p class="hint">Die QR-Einstellungen (IBAN, Kontoinhaber, PayPal.me-Handle, Beschriftungen) werden in den regul&auml;ren Einstellungen der jeweiligen Zahlungsart gepflegt (Administration &rarr; Einstellungen &rarr; Zahlungsweisen). Der QR-Block erscheint nur auf Rechnungs-PDFs und nur f&uuml;r Zahlungsarten, bei denen die QR-Anzeige aktiviert und vollst&auml;ndig konfiguriert ist.</p>
    </fieldset>

    <form method="post" action="">
      <fieldset>
        <legend>Installation</legend>
        <p>Registriert den PDF-Hook und den Men&uuml;punkt und legt die Zahlungsarten &Uuml;berweisung (GiroCode), PayPal und Wero an bzw. verkn&uuml;pft bestehende Eintr&auml;ge mit den QR-Modulen. Der Vorgang ist idempotent und kann jederzeit wiederholt werden.</p>
        <p>
          <input type="submit" class="btnBlue" name="install" value="Installieren / Reparieren">
        </p>
      </fieldset>
    </form>

    <form method="post" action="index.php?module=zahlungsqr&amp;action=upload" enctype="multipart/form-data">
      <fieldset>
        <legend>Wero-QR-Bild hochladen</legend>
        <p>Statisches Wero-QR-Bild f&uuml;r die Rechnungs-PDFs. Es werden nur PNG-Dateien bis 2&nbsp;MB akzeptiert.</p>
        <p>[WERO_STATUS]</p>
        <p>
          <input type="file" name="wero_datei" accept="image/png">
        </p>
        <p>
          <input type="submit" class="btnBlue" name="upload" value="Hochladen">
        </p>
      </fieldset>
    </form>

    <form method="post" action="index.php?module=zahlungsqr&amp;action=uninstall" onsubmit="return confirm('ZahlungsQR wirklich deaktivieren?');">
      <fieldset>
        <legend>Deinstallation</legend>
        <p>Entfernt den PDF-Hook und den Men&uuml;punkt, deaktiviert die Zahlungsarten PayPal und Wero und stellt die Zahlungsart &Uuml;berweisung auf das Standard-Modul zur&uuml;ck. Es werden KEINE Daten gel&ouml;scht &ndash; alle Einstellungen und hochgeladenen Dateien bleiben erhalten. Eine erneute Installation stellt den vorherigen Zustand wieder her.</p>
        <p>
          <input type="submit" class="btnGrey" name="uninstall" value="Deaktivieren">
        </p>
      </fieldset>
    </form>
  </div>
</div>
