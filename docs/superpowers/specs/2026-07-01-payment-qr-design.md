# Spec: Modul "ZahlungsQR" — Zahlungs-QR-Codes auf Rechnungen (OpenXE)

**Datum:** 2026-07-01
**Repo:** https://github.com/Avatarsia/OpenXE (Fork von openxe-org/openxe)
**Basis-Branch:** `production` · **Feature-Branch:** `feature/payment-qr-codes`
**Status:** Entwurf, vom Nutzer freigegebenes Design

---

## 1. Ziel

Rechnungs-PDFs zeigen nach dem Zahlungsweise-Hinweistext einen Block mit bis zu drei Zahlungs-QR-Codes, damit Kunden die Rechnung per Scan bezahlen können:

| Zahlungsweg | QR-Quelle | Inhalt |
|---|---|---|
| Überweisung | dynamisch generiert (GiroCode / EPC069-12) | IBAN, Kontoinhaber, Bruttobetrag, Rechnungsnummer als Verwendungszweck |
| PayPal | dynamisch generiert | Link `https://paypal.me/<Handle>/<Betrag>EUR` |
| Wero | statisches, vom Nutzer hochgeladenes QR-Bild | unverändert eingebettet (kein öffentliches Wero-QR-Format existiert; Nutzer besitzt bereits einen QR aus seiner Banking-App) |

Pro Zahlungsweg konfigurierbar: aktiv ja/nein, Anzeige auf allen Rechnungen vs. nur bei passender Zahlungsweise der Rechnung, Beschriftung unter dem QR.

## 2. Scope / Nicht-Ziele

**In Scope (V1):**
- Nur Belegtyp Rechnung (`doctype === 'rechnung'`; schließt Rechnungskopien im Mahnwesen ein — erwünscht)
- Nur EUR-Belege für GiroCode und PayPal-Betrags-QR
- Voller Bruttobetrag im QR (kein offener Restbetrag, keine Skonto-Verrechnung)
- Projektspezifische Konfiguration über den vorhandenen `zahlungsweisen.projekt`-Mechanismus

**Nicht-Ziele (V1):**
- Keine Wero-/PayPal-API-Anbindung, kein Zahlungsabgleich (Webhooks)
- Keine QR-Codes auf Angebot/Auftrag/Gutschrift (Erweiterung später trivial, da der Hook in der Basisklasse aller Belege sitzt)
- Keine strukturierte ISO-11649-RF-Referenz (in DE unüblich); Verwendungszweck unstrukturiert
- Keine Änderung bestehender Core-Dateien

## 3. Architektur

Alle Dateien sind **neu** — null Änderungen an bestehenden Core-Dateien (update-sicher, Muster: LexwareOffice-Modul des Forks).

```
www/pages/zahlungsqr.php                        Modulseite (Klasse Zahlungsqr): Settings-UI,
                                                Wero-Bild-Upload, Install/Uninstall, Hook-Handler
www/lib/zahlungsweisen/rechnung_qr.php          Zahlungsweise_rechnung_qr extends Zahlungsweise_rechnung
www/lib/zahlungsweisen/paypal_qr.php            Zahlungsweise_paypal_qr extends Zahlungsweisenmodul
www/lib/zahlungsweisen/wero.php                 Zahlungsweise_wero extends Zahlungsweisenmodul
classes/Modules/PaymentQr/
  ├─ Bootstrap.php                              Container-Service-Registrierung
  ├─ Service/EpcQrPayloadBuilder.php            EPC069-12-Payload bauen + validieren
  ├─ Service/QrBlockRenderer.php                QR-Block ins FPDF-Objekt zeichnen
  └─ Service/PaymentQrSettingsService.php       Settings projektbewusst laden
```

### 3.1 PDF-Einbindung über DB-Hook (verifiziert)

- `Briefpapier::renderFooter()` feuert `RunHook('briefpapier_render_footer_hook2', 1, $this)` **direkt nach** dem Rendern des Zahlungsweise-Hinweistexts (`www/lib/dokumente/class.briefpapier.php:2417`).
- Der Hook sitzt in der Basisklasse aller Beleg-PDFs → erwischt **alle** Erzeugungspfade: `www/pages/rechnung.php`, Cronjobs (`pdfarchiv.php`, `rechnungslauf` etc.), Mahnwesen, `exportbuchhaltung.php` sowie die zwei Direktinstanziierungen ohne Custom-Check in `www/pages/auftrag.php:4029,4039`.
- Registrierung in `Zahlungsqr::Install()`:
  1. `$app->erp->GenerateHook('briefpapier_render_footer_hook2', 1, 1)` — legt den Hook-Stammsatz an, falls er noch nie gefeuert hat (`class.erpapi.php:10580`)
  2. `$app->erp->RegisterHook('briefpapier_render_footer_hook2', 'zahlungsqr', 'RenderQrBlock')` (`class.erpapi.php:10694`)
- `RunHook` lädt das Modul über `www/pages/zahlungsqr.php` und ruft `Zahlungsqr::RenderQrBlock($pdf)` mit dem Briefpapier-Objekt auf (`class.erpapi.php:11044ff`).
- Menüpunkt: `$app->erp->RegisterNavigationHook('zahlungsqr', 'list', ...)` (Tabelle `hook_navigation`), Bereich Einstellungen. Zugriff: `CheckRights()` → nur admin.

### 3.2 QR-Erzeugung (verifiziert vorhanden)

- `classes/Components/Barcode/BarcodeFactory.php:15` — `createQrCode($codeText, $ecLevel)`; Container-Service `BarcodeFactory`.
- Einbettungs-Rezept aus `www/lib/dokumente/class.etiketten.php:189`: `createQrCode(...)->toPng($w,$h)` → Temp-Datei → `$pdf->Image($file,$x,$y,$w,$h,'png')` → `unlink()`.
- GiroCode zwingend mit Error-Correction-Level **M** (EPC-Vorgabe). PayPal-QR ebenfalls M.

## 4. Einstellungen & Datenmodell

Keine Schema-Änderungen. Alle Einstellungen liegen in `zahlungsweisen.einstellungen_json`.

**Verifiziertes Risiko + Lösung:** `Zahlungsweisenmodul::Einstellungen()` überschreibt `einstellungen_json` komplett mit nur den Feldern aus `EinstellungenStruktur()` (`www/lib/class.zahlungsweise.php:17-37`). Deshalb werden unsere Felder Teil der `EinstellungenStruktur()` der jeweiligen (eigenen) Zahlungsweisen-Modulklasse — dann sind sie vor dem Überschreiben sicher.

### 4.1 Felder je Zahlungsweise

**`rechnung_qr`** (erbt alle Felder von `Zahlungsweise_rechnung`, ergänzt):
| Key | Typ | Bedeutung |
|---|---|---|
| `qr_aktiv` | checkbox | GiroCode auf Rechnung anzeigen |
| `qr_nur_bei_passender_zahlungsweise` | checkbox | sonst: auf allen Rechnungen |
| `qr_iban` | text | Pflicht für GiroCode; normalisiert (Leerzeichen raus, Uppercase) |
| `qr_bic` | text | optional (EPC-Version 002) |
| `qr_kontoinhaber` | text | Pflicht; UI-Hinweis auf Verification of Payee (muss exakt dem Kontonamen entsprechen, seit 10/2025 warnen Banking-Apps sonst) |
| `qr_verwendungszweck` | text | Vorlage, Default `{BELEGNR}`; Platzhalter `{BELEGNR}`, `{KUNDENNUMMER}` |
| `qr_beschriftung` | text | Text unter dem QR, Default "Mit Banking-App scannen & bezahlen" |

**`paypal_qr`**: `qr_aktiv`, `qr_nur_bei_passender_zahlungsweise`, `paypalme_handle` (Pflicht), `qr_beschriftung` (Default "Mit PayPal zahlen").

**`wero`**: `qr_aktiv`, `qr_nur_bei_passender_zahlungsweise`, `qr_beschriftung` (Default "Mit Wero zahlen"), `qr_datei` (Datei-ID des hochgeladenen Bildes, wird von der Modulseite gesetzt, im nativen Dialog read-only angezeigt).

### 4.2 Wero-Bild-Upload

- Upload auf der Modulseite `zahlungsqr` (der native Zahlungsarten-Dialog unterstützt keine Datei-Uploads).
- Ablage über das vorhandene OpenXE-Datei-System (`CreateDatei`/Datei-Tabellen, gleicher Mechanismus wie Briefpapier-Uploads); akzeptiert PNG/JPG, Empfehlung ≥ 300×300 px.
- Die Datei-ID landet in `einstellungen_json.qr_datei` des Wero-Eintrags.

### 4.3 Anlage der Zahlungsweisen-Einträge

`Install()` (idempotent):
- `type='wero'`: anlegen falls nicht vorhanden (`bezeichnung='Wero'`, `modul='wero'`, `verhalten='rechnung'`, `aktiv=0` initial — Nutzer aktiviert bewusst)
- `type='paypal'`: anlegen falls nicht vorhanden (`modul='paypal_qr'`); **Randfall:** `GetZahlungsweise()` (`class.erpapi.php:26362`) mischt hartkodierte Standardoptionen (Firmendaten-Checkbox `zahlung_paypal`) mit Tabelleneinträgen — bei Duplikat im Dropdown wird in der Modul-UI ein Hinweis angezeigt, die Firmendaten-Checkbox zu deaktivieren. Verhalten wird bei Implementierung verifiziert.
- Überweisung: Es wird **exakt** der Eintrag mit `type='rechnung'` behandelt (andere Typen werden nicht angefasst). Existiert er: `modul` wird auf `rechnung_qr` gestellt; bestehende Einstellungen bleiben erhalten (Vererbung der Struktur, JSON-Keys unverändert). Existiert er nicht (frische Installation): anlegen (`bezeichnung='Überweisung'`, `modul='rechnung_qr'`, `verhalten='rechnung'`, `aktiv=0` — Nutzer aktiviert bewusst).

## 5. Rendering-Ablauf (`Zahlungsqr::RenderQrBlock($pdf)`)

1. Gate: `$pdf->doctype === 'rechnung'`, sonst return
2. Rechnung laden über `$pdf->id`: `belegnr`, `zahlungsweise`, Bruttobetrag (`gesamtsumme`), `waehrung`, `projekt`, `kundennummer`
3. Gates: Betrag > 0; Beleg hat Belegnummer (freigegeben)
4. Settings projektbewusst laden — nur Einträge mit `aktiv=1` und `geloescht=0`; projektspezifischer `zahlungsweisen`-Eintrag gewinnt, Fallback `projekt=0` (gleiche Logik wie `Zahlungsweisetext`, `class.erpapi.php:3671`)
5. Filter je Eintrag: `qr_aktiv` UND (`qr_nur_bei_passender_zahlungsweise` = aus ODER `zahlungsweisen.type == rechnung.zahlungsweise`)
6. Währungs-Gate: bei Währung ≠ EUR entfallen GiroCode und PayPal-QR; Wero-Bild erscheint weiterhin, wenn aktiv
7. Platz-Check: verbleibender Platz bis zur unteren Umbruchgrenze < Blockhöhe (~35 mm) → `AddPage()`
8. Rendern: QRs nebeneinander, 25 mm Kantenlänge, Beschriftung (8 pt) darunter, linksbündig am Seitenrand (`abstand_seitenrandlinks`)

### 5.1 EPC-Payload (EpcQrPayloadBuilder)

Format EPC069-12 v3.1, 12 Zeilen, LF-Trenner (`\n`), **kein** Trenner nach dem letzten belegten Element, leere optionale Felder am Ende weglassen:

```
BCD
002
1                  ← Charset UTF-8
SCT
<BIC oder leer>
<Kontoinhaber, ≤70>
<IBAN, ≤34, ohne Leerzeichen>
EUR<Betrag>        ← Punkt als Dezimaltrenner, 2 Nachkommastellen, 0.01–999999999.99
                   ← Purpose leer
                   ← RF-Referenz leer
<Verwendungszweck, ≤140>
```

Validierung im Builder: Feldlängen, Betragsformat/-bereich, Gesamt-Payload ≤ 331 Bytes (Byte-Länge, UTF-8!), IBAN-Plausibilität (Länge/Format). Bei Validierungsfehler: Exception → QR entfällt, Log-Eintrag.

### 5.2 PayPal-Link

`https://paypal.me/<Handle>/<Betrag>EUR` (Betrag mit Punkt, 2 Nachkommastellen). Bekannte Grenzen (dokumentiert in Settings-UI): Betrag vom Zahler änderbar, keine Rechnungsnummer übertragbar → Zahlungsabgleich manuell.

## 6. Fehlerbehandlung

- **Oberste Regel: Die PDF-Erzeugung darf niemals am QR-Block scheitern.** Gesamter Hook-Handler in try/catch; bei Fehler Block (oder einzelner QR) weglassen, Fehler ins Systemlog (`$app->erp->LogFile(...)`).
- Fehlende Pflichtfelder (IBAN, Kontoinhaber, Handle, Wero-Bild) → betroffener QR entfällt still im PDF; die Modulseite zeigt den Konfigurationsstand mit Warnungen.
- Temp-Dateien immer aufräumen (finally/unlink).

## 7. Deinstallation

Uninstall-Aktion auf der Modulseite (Muster: LexwareOffice-Uninstall-Script):
- `hook_register`-Eintrag entfernen, `hook_navigation`-Eintrag entfernen
- Zahlungsweisen-Einträge **deaktivieren, nicht löschen** (Belege referenzieren `type`-Strings); `modul` des Überweisungs-Eintrags zurück auf `rechnung`
- Hochgeladene Wero-Datei bleibt im Datei-System erhalten

## 8. Tests & Abnahme

1. `php -l` auf jede neue Datei (Pflicht laut Projektstandard)
2. Test-Script für `EpcQrPayloadBuilder`: Beispiel-Payloads, Feldgrenzen (70/140/331 Bytes), Umlaute (UTF-8-Bytezählung), Betragsformate, IBAN-Normalisierung; Abgleich gegen Referenz-Beispiel aus der EPC-Spec/Wikipedia
3. Manuelle Verifikation (Nutzer): Test-Rechnung als PDF erzeugen → GiroCode mit echter Banking-App scannen (Empfänger/Betrag/Zweck korrekt vorbelegt), PayPal-QR mit Handy-Kamera, gedrucktes Wero-Bild mit Wero-fähiger App
4. Regression: Rechnung ohne aktivierte QRs rendert unverändert; Angebot/Auftrag/Lieferschein zeigen keinen QR-Block; Rechnung mit PDF-Anhängen (`addpdf`) bleibt korrekt
5. Mehrere Review-Runden durch Subagenten vor Merge (Projektstandard)

## 9. Risiken & bei Implementierung zu verifizieren

| # | Punkt | Plan |
|---|---|---|
| 1 | `RunHook` prüft `ModulVorhanden($module)` (`class.erpapi.php:11086`) — genaue Bedingung klären | Beim Implementieren prüfen; LexwareOffice-Modul als funktionierendes Vorbild |
| 2 | Doppeltes PayPal im Zahlungsweise-Dropdown (hartkodierte Standardoption + neuer Tabelleneintrag) | Verhalten von `GetZahlungsweise()` testen; ggf. UI-Hinweis |
| 3 | Feldtypen des Settings-Formulars (`typ` in `EinstellungenStruktur`): unterstützt es nur text/checkbox/textarea? | Falls kein select: checkbox-Variante nutzen (bereits so spezifiziert) |
| 4 | Exakter Spaltenname des Bruttobetrags/Währung auf `rechnung` (`gesamtsumme`/`waehrung`) | Am `db_schema.json`/Code verifizieren |
| 5 | `Zahlungsweise_rechnung_qr` erbt von `Zahlungsweise_rechnung`: Include-Reihenfolge (Datei muss Parent-Datei `include_once`n) | Im Modul-File lösen |
| 6 | EPC069-12 v3.1 PDF war automatisiert nicht abrufbar (403) | Einmal manuell laden und Feldtabelle final gegenprüfen |
| 7 | Git-Clone des Forks scheitert aktuell am Netzwerk | Vor Implementierung lösen (Retry/Nutzer-Clone); Zip-Stand `OpenXE-src/` dient nur der Analyse |
| 8 | Ist `$pdf->id` in allen Erzeugungspfaden gesetzt (Mahnwesen, `exportbuchhaltung.php`)? | Beim Implementieren prüfen; Gate im Handler: fehlende/leere `id` → kein QR-Block |

## 10. Verifizierte Referenzen (lokaler Quellcode, Stand `production`, Zip 2026-07-01)

- Hook-Punkt: `www/lib/dokumente/class.briefpapier.php:2417` (`briefpapier_render_footer_hook2`), Render-Ablauf `renderDocument()` ab `:1853`
- Hook-Infrastruktur: `www/lib/class.erpapi.php:10580` (`GenerateHook`), `:10694` (`RegisterHook`), `:11044` (`RunHook`)
- Autoloader-Erweiterungspunkte (Fallback-Option B): `xentral_autoloader.php:164-191`
- QR-Komponente: `classes/Components/Barcode/BarcodeFactory.php:15`; Einbettung: `www/lib/dokumente/class.etiketten.php:189`
- Settings-Überschreib-Verhalten: `www/lib/class.zahlungsweise.php:11-38`
- Zahlungsweisen-Datenmodell: Tabelle `zahlungsweisen` (`type`, `bezeichnung`, `freitext`, `verhalten`, `modul`, `einstellungen_json`, `projekt`), Service `classes/Modules/PaymentMethod/`
- Zahlungsweise auf Beleg: `rechnung.zahlungsweise` (varchar, type-String, kein FK)
- EPC-QR-Spezifikation: EPC069-12 v3.1 (europeanpaymentscouncil.eu), de.wikipedia.org/wiki/EPC-QR-Code, Referenzimplementierung github.com/MarvinLudwig/EPC069-12
- Wero-Faktenlage: support.wero-wallet.eu Artikel 25599245772305 / 25599309689873 (kein öffentliches statisches QR-Format)
- PayPal.me-Format: paypal.com/us/cshelp/article/help432
