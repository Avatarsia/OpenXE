# WooCommerce-Verwaltung: lokale UI-Konventionen

## Overview

**Mode: Operate.** Downloads bearbeiten und Refund-Abgleiche innerhalb der bestehenden OpenXE-Shopverwaltung prüfen. Die Oberfläche ist ein präziser lokaler Anbau an `onlineshops/edit`; sie übernimmt die vorhandene visuelle Sprache.

Dieses Dokument gilt ausschließlich für die WooCommerce-Ansichten unter `.wc-admin` und ihre zugehörigen `wc-`-Klassen. Es definiert kein globales Design System. Ein `PRODUCT.md` liegt nicht vor; Produktpositionierung oder zusätzliche Nutzerrollen werden daraus nicht abgeleitet.

Source of truth:

- Host und Stylesheet-Reihenfolge: `www/themes/new/templates/page.tpl`.
- Bestehende Theme-Regeln: `www/themes/new/css/styles.css`, `www/themes/new/css/color3.css`, `www/css/bootstrap.min.css`.
- Einbettung: `www/pages/content/onlineshops_woocommerce.tpl`.
- Markup, Zustände und Aktionen: `classes/Components/WooCommerce/AdminPage.php`, `classes/Components/WooCommerce/AdminView.php`.
- Lokale Ergänzungen: `classes/Modules/Onlineshop/www/css/woocommerce-admin.css`, `classes/Modules/Onlineshop/www/js/woocommerce-admin.js`.

Die genannten Implementierungen bleiben maßgeblich. Dieses Dokument extrahiert vorhandene Konventionen und führt keine eigenständigen Tokens ein.

## Colors

Text, Hintergrund, Links, Buttons und Meldungen übernehmen das aktive OpenXE-Theme. `color3.css` ist eine vorhandene Theme-Referenz, keine lokal festgeschriebene Palette. Die lokalen Ansichten verwenden die bestehenden Meldungsklassen `info`, `warning` und `error`; zusätzliche Statusfarben sind nicht vorgesehen. Die lokale Tabellenlinie stammt aus `woocommerce-admin.css`.

## Typography

Schriftfamilie und Grundgrößen bleiben beim Host-Theme. Dessen Body verwendet `Inter` mit Arial-/Helvetica-/sans-serif-Fallback. Die lokale Ergänzung begrenzt Absatzbreiten auf `75ch` und setzt ihre Zeilenhöhe auf `1.5`. Überschriften, Legends und Feldtexte folgen den vorhandenen HTML- und Theme-Regeln.

## Layout

Die WooCommerce-Ausgabe sitzt in der bestehenden `inside_white`-Fläche. `.wc-admin` ergänzt Innenabstand; Navigation, Suche, Aktionszeile und Pagination umbrechen mit Flexbox. Die beiden Ansichten bleiben über Textlinks erreichbar; der aktive Link erhält `aria-current="page"`, Fettschrift und Unterstreichung.

Breite Tabellen scrollen im inneren `.wc-admin-scroll`-Container horizontal. Die Download-Tabelle hält ihre Mindestbreite von `560px`, die Refund-Tabelle von `850px`; lange Fehlertexte in der letzten Refund-Spalte dürfen umbrechen. `.wc-admin fieldset { min-inline-size: 0; }` ist erforderlich, damit die intrinsische Tabellenbreite das Fieldset und die äußere Seite nicht aufzieht. Diese Einschränkung beim Ergänzen weiterer Felder oder Wrapper erhalten.

## Shapes

Die Formensprache stammt aus dem Host: bestehende `inside_white`-Fläche und native Fieldsets, Inputs, Checkboxes und Buttons. Keine eigenen Cards, dekorativen Container oder abweichenden Control-Skins ergänzen.

## Components

### Formulare und Aktionen

Suchfelder erhalten sichtbare Labels mit `for`/`id`; Checkboxes liegen in ihren Labels. Wiederholte Download-Felder verwenden ihre Spaltenüberschrift und einen passenden `aria-label`. Native Input-Typen und Browser-Validierung bleiben erhalten, insbesondere `type="url"` für Datei-URLs.

Suchen und Navigation verwenden GET. Schreibaktionen verwenden POST mit verstecktem `csrf_token`; `AdminPage` prüft Shop-/Projektrechte und das Session-Token. Labels benennen die tatsächliche Aktion: „Downloads speichern“, „Smarty-Vorgabe verwenden“ und „Erneut versuchen“. Die Fallback-Aktion „Smarty-Vorgabe verwenden“ trägt `formnovalidate`, damit ungültige verworfene URLs diese Aktion nicht blockieren.

Buttons und Inputs außer Checkboxes erhalten lokal eine Mindesthöhe von `32px`. `:focus-visible` ergänzt einen sichtbaren Outline in `currentColor`; die Tastaturbedienbarkeit bei Erweiterungen erhalten.

### Download-Zeilen

Die Tabelle verwendet semantische Spaltenüberschriften mit `scope="col"`. JavaScript ergänzt Zeilen aus dem vorhandenen Template, fokussiert das erste neue Input und deaktiviert „Download hinzufügen“ bei 50 Zeilen. Nach dem Entfernen wandert der Fokus zurück zu diesem Button. Hinzufügen und Entfernen verwenden `type="button"` und lösen keinen Submit aus.

### Refund-Tabelle und Feedback

Die Refund-Tabelle zeigt Status als Text, Zeiten ausdrücklich in UTC und verfügbare Wiederholungsaktionen direkt am betroffenen Datensatz. Ihr Scroll-Container ist als benannte Region fokussierbar. Pagination bleibt bei gewöhnlichen Links mit sichtbarer Seiten- und Eintragszahl.

Speicher- und Aktionsrückmeldungen verwenden die vorhandenen Meldungsflächen: reguläres Feedback mit `role="status"`, Fehler mit `role="alert"`. Eingabefehler nennen, dass nicht gespeichert wurde; der erfasste Download-Entwurf bleibt zur Korrektur sichtbar. Leere Suche, fehlende Refund-Einträge und deaktivierter Download-Export erhalten konkrete vorhandene Hinweise. Dynamische Inhalte werden serverseitig escaped; Fehlermeldungen werden vor der Ausgabe bereinigt.

## Do's and Don'ts

- **Do:** Neue UI-Regeln im lokalen WooCommerce-Scope halten und die bestehenden Theme-Regeln als Ausgangspunkt verwenden.
- **Do:** Rechteprüfung, CSRF, native Validierung, Labels, Fokus und Feedback zusammen mit der sichtbaren Aktion erhalten.
- **Do:** Bei schmalen Viewports den inneren Tabellen-Scroll und das schrumpfbare Fieldset gemeinsam prüfen.
- **Do:** Lokale Screenshots aus `tests/woocommerce/fixtures/admin-router.php` als synthetische UI-Fixture kennzeichnen. Sie belegen keine Live-ERP-, Shop- oder Buchungsintegration.
- **Don't:** Globale Theme-Selektoren, Palette, Fonts oder Host-Navigation für diese Ansichten ändern.
- **Don't:** Aus dem lokalen Anbau neue globale Tokens, Produktannahmen oder ein neues Design System ableiten.
- **Don't:** Status ausschließlich über Farbe vermitteln oder zusätzliche Buchungs-/Gateway-Wirkungen in Aktionslabels behaupten.
