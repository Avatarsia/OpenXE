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

## Installation

1. Feature-Branch deployen (neue Dateien, keine Core-Änderungen, keine
   Schema-Migration, kein `composer dump-autoload` nötig).
2. Als Admin aufrufen: `index.php?module=zahlungsqr&action=list`
3. Button **Installieren / Reparieren** klicken. Das registriert idempotent:
   - den PDF-Hook (`hook`/`hook_register`)
   - den Menüpunkt (`hook_navigation`, Bereich Administration)
   - die Zahlungsarten: bestehender Eintrag `type='rechnung'` wird auf das
     Modul `rechnung_qr` umgestellt (alle vorhandenen Einstellungen bleiben
     erhalten); `paypal` und `wero` werden bei Bedarf **inaktiv** angelegt.

## Konfiguration

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
