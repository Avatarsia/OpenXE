# ZahlungsQR — Zahlungs-QR-Codes auf Rechnungen

Rendert bis zu drei Zahlungs-QR-Codes auf Rechnungs-PDFs, direkt nach dem
Zahlungsweise-Hinweistext:

| Zahlungsweg | QR-Quelle | Inhalt |
|---|---|---|
| Überweisung | dynamisch generiert (GiroCode / EPC069-12) | IBAN, Kontoinhaber, Bruttobetrag, Rechnungsnummer |
| PayPal | dynamisch generiert | `paypal.me/<Handle>/<Betrag>EUR` |
| Wero | hochgeladenes statisches Bild | unverändert (es existiert kein öffentliches Wero-QR-Format) |

Update-sicher: **null Änderungen an Core-Dateien.** Die PDF-Einbindung läuft
über den DB-Hook `briefpapier_render_footer_hook2` (Tabelle `hook_register`),
die Klassen laden per Composer-PSR-4 (`Xentral\` → `classes/`), die
QR-Erzeugung nutzt die vorhandene `BarcodeFactory`.

## Installation (Schritt für Schritt)

### Schritt 1: Dateien deployen

Den Branch `feature/payment-qr-codes` auf die OpenXE-Instanz ausrollen.
Das Modul besteht ausschließlich aus **neuen** Dateien (Liste unten) —
keine Core-Änderungen, keine DB-Schema-Migration, kein
`composer dump-autoload`, kein Cache-Rebuild nötig. Beim Deployment per
Datei-Kopie genügt es, die unter [Dateien](#dateien) gelisteten Pfade in
den OpenXE-Root zu übernehmen.

### Schritt 2: Modul installieren

Als **Admin** anmelden und aufrufen:

```
https://<deine-instanz>/index.php?module=zahlungsqr&action=list
```

Dort den Button **Installieren / Reparieren** klicken. Das registriert
idempotent (beliebig oft wiederholbar):

- den PDF-Hook (`hook`/`hook_register` → `briefpapier_render_footer_hook2`)
- den Menüpunkt (`hook_navigation`, Bereich Administration)
- die Zahlungsarten: bestehender Eintrag `type='rechnung'` wird auf das
  Modul `rechnung_qr` umgestellt (alle vorhandenen Einstellungen bleiben
  erhalten); `paypal` und `wero` werden bei Bedarf **inaktiv** angelegt.

Die Statusübersicht auf derselben Seite zeigt danach je Zahlungsart, was
noch fehlt (z. B. „IBAN fehlt", „Wero-QR-Bild fehlt").

### Schritt 3: Überweisung (GiroCode) konfigurieren

Administration → Einstellungen → **Zahlungsweisen** → Eintrag
*Überweisung* (`type=rechnung`) → **Einstellungen**:

1. Haken **„GiroCode (EPC-QR) auf Rechnung anzeigen"** setzen
2. **IBAN** eintragen (Pflicht)
3. **Kontoinhaber** eintragen (Pflicht) — muss **exakt** dem Namen beim
   Kontoinstitut entsprechen, sonst warnt die Banking-App des Kunden
   (Verification of Payee, seit 10/2025)
4. Optional: BIC, Verwendungszweck-Vorlage (`{BELEGNR}`,
   `{KUNDENNUMMER}`; leer = Rechnungsnummer), Beschriftung
5. Optional Haken „nur wenn Zahlungsweise der Rechnung passt" — ohne den
   Haken erscheint der GiroCode auf **allen** Rechnungen
6. Die Zahlungsart muss **aktiv** sein

### Schritt 4: PayPal konfigurieren (optional)

Gleicher Weg, Eintrag *PayPal*: Haken setzen, **PayPal.me-Handle**
eintragen (nur der Handle, z. B. `MeineFirma` — URLs/`@` werden
automatisch bereinigt), Zahlungsart **aktivieren**.

### Schritt 5: Wero einrichten (optional)

1. Eigenen Wero-QR-Code aus der Banking-App als **PNG** exportieren
   (max. 2 MB)
2. Auf der Modulseite (`index.php?module=zahlungsqr&action=list`) unter
   **„Wero-QR-Bild hochladen"** hochladen
3. Zahlungsart *Wero* aktivieren und den Anzeige-Haken in deren
   Einstellungen setzen

### Schritt 6: Funktionstest (Abnahme)

1. Test-Rechnung anlegen, freigeben und als **PDF** erzeugen — der
   QR-Block erscheint direkt unter dem Zahlungshinweistext
2. **GiroCode mit einer echten Banking-App scannen**: Empfänger, IBAN,
   Betrag und Rechnungsnummer müssen korrekt vorbelegt sein
3. PayPal-QR mit der Handy-Kamera scannen → PayPal.me-Seite mit
   vorbelegtem Betrag
4. Wero: gedruckten QR mit der Wero-fähigen App scannen
5. Regression: Angebot/Auftrag/Lieferschein zeigen **keinen** QR-Block;
   eine Rechnung mit deaktivierten QRs rendert unverändert; eine
   Nicht-EUR-Rechnung zeigt keinen GiroCode/PayPal-QR

Bei Problemen: Statusübersicht auf der Modulseite prüfen (Warnungen je
Zahlungsart, Hook-/Menüstatus) und ggf. **Installieren / Reparieren**
erneut ausführen. Der QR-Block kann die PDF-Erzeugung nie blockieren —
fehlt er, liegt es an den Gates (Zahlungsart inaktiv, QR-Haken fehlt,
Pflichtfelder leer, Währung ≠ EUR, Betrag ≤ 0) oder am Log
(`error_log`).

## Konfigurations-Referenz

Alle QR-Einstellungen liegen in den regulären Einstellungen der jeweiligen
Zahlungsart (Administration → Einstellungen → Zahlungsweisen → Einstellungen):

- **Überweisung (`rechnung_qr`)**: QR aktivieren, IBAN (Pflicht),
  BIC (optional, EPC-Version 002), Kontoinhaber (Pflicht — muss exakt dem
  Namen beim Kontoinstitut entsprechen, sonst warnt die Banking-App des
  Kunden wegen Verification of Payee), Verwendungszweck-Vorlage
  (Platzhalter `{BELEGNR}`, `{KUNDENNUMMER}`; leer = Belegnummer),
  Beschriftung.
- **PayPal (`paypal_qr`)**: QR aktivieren, PayPal.me-Handle (Pflicht,
  rein alphanumerisch), Beschriftung.
- **Wero (`wero`)**: QR aktivieren, Beschriftung; das QR-Bild wird auf der
  Modulseite hochgeladen (nur PNG, max. 2 MB) und über das OpenXE-Dateisystem
  referenziert (`qr_datei`).

Je Zahlungsart wählbar: Anzeige auf **allen** Rechnungen oder **nur wenn die
Zahlungsweise der Rechnung passt**. Projektspezifische Zahlungsarten-Einträge
gewinnen gegen den globalen Eintrag (kein stiller Fallback, wenn der
Projekt-Eintrag deaktiviert ist). Die Zahlungsart muss `aktiv` sein.

## Funktionsweise

`Briefpapier::renderFooter()` feuert den Hook nach dem Zahlungshinweistext →
`Zahlungsqr::RenderQrBlock($pdf)` prüft die Gates (nur Rechnungen, Beleg
freigegeben, Betrag > 0, EUR für GiroCode/PayPal — Wero währungsunabhängig)
und rendert die QRs (25 mm, Beschriftung darunter, sauberer Seitenumbruch).
**Die PDF-Erzeugung kann nie am QR-Block scheitern**: jede Störung lässt nur
den betroffenen QR (oder den Block) entfallen und wird geloggt.

## Deinstallation

Modulseite → **Deaktivieren**: entfernt Hook + Menüpunkt, deaktiviert
PayPal/Wero, stellt `type='rechnung'` auf das Standard-Modul zurück.
Es werden keine Daten gelöscht; erneute Installation stellt alles wieder her.

## Tests

Standalone-Tests (kein PHPUnit nötig), aus dem Repo-Root:

```
php classes/Modules/PaymentQr/tests/EpcQrPayloadBuilderTest.php
php classes/Modules/PaymentQr/tests/PaymentQrSettingsServiceTest.php
php classes/Modules/PaymentQr/tests/QrBlockRendererTest.php
php classes/Modules/PaymentQr/tests/ZahlungsqrItemsTest.php
OPENXE_CORE=/pfad/zum/openxe-root php classes/Modules/PaymentQr/tests/ZahlungsweisenStrukturTest.php
```

## Bekannte Grenzen (bewusste Entscheidungen)

- Nur Belegtyp **Rechnung** (inkl. Rechnungskopien im Mahnwesen); Erweiterung
  auf andere Belege ist über den Hook trivial möglich.
- GiroCode/PayPal nur bei **EUR**-Belegen; QR trägt immer den vollen
  Bruttobetrag (kein offener Restbetrag, keine Skonto-Verrechnung).
- **PayPal**: Betrag ist vom Zahler änderbar, eine Rechnungsnummer kann
  PayPal.me nicht übergeben — Zahlungsabgleich bleibt manuell.
- **Wero**: statisches Bild ohne Betrag/Referenz; Gültigkeit des persönlichen
  Wero-QR liegt in der Verantwortung des Nutzers. Upload akzeptiert nur PNG
  (bewusste Vereinfachung gegenüber der Spec, die auch JPG vorsah).
- GiroCode nach EPC069-12 v3.1: Version 002 (BIC optional, EWR),
  Fehlerkorrektur-Level M, max. 331 Bytes Payload.

## Dateien

```
www/pages/zahlungsqr.php                       Modulseite + Hook-Handler
www/pages/content/zahlungsqr_settings.tpl      Template
www/lib/zahlungsweisen/{rechnung_qr,paypal_qr,wero}.php   Settings-Module
classes/Modules/PaymentQr/Service/             EpcQrPayloadBuilder, QrItemAssembler,
                                               PaymentQrSettingsService, QrBlockRenderer
classes/Modules/PaymentQr/tests/               Standalone-Tests
```

Spec: `docs/superpowers/specs/2026-07-01-payment-qr-design.md` ·
Plan: `docs/superpowers/plans/2026-07-01-payment-qr-codes.md`
