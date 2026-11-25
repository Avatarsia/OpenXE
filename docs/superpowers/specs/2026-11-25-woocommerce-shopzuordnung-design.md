# WooCommerce-Shopzuordnung: Kategorien-Baum, Shop-Zuordnung, Lieferstatus

Datum: 2026-11-25
Branch: `feature/woocommerce-artikeldaten` (zweiter Teil, baut auf `2026-11-25-woocommerce-artikeldaten-design.md` auf)
Status: Entwurf, wartet auf Freigabe

## Warum

Der Nutzer will an einer Stelle in OpenXE steuern, **welche Artikel in welchem WooCommerce-Shop erscheinen, in welcher Shop-Kategorie, und ob sie lieferbar sind**, ohne in WordPress arbeiten zu muessen. Heute:

- Die Zuordnung Artikel <-> Shop (`artikel_onlineshops`) wird pro Artikel gepflegt; es gibt keine Gesamtsicht.
- Wird eine Zuordnung entfernt oder deaktiviert, passiert in WooCommerce nichts: Der Export ignoriert den Artikel, das Produkt bleibt kaufbar.
- WooCommerce-Kategorien kommen aus dem OpenXE-Artikelbaum (`artikelbaum_artikel`/`artikelkategorien`, optional ab `category_root_id`), der auch ERP-intern genutzt wird. Der Nutzer will einen davon **unabhaengigen** Shop-Kategorienbaum.
- Fuer "nicht mehr lieferbar" gibt es nur "Artikel ausverkauft", fuer "auf Nachfrage lieferbar" nichts.

## Ziele

1. **Menuepunkt "WooCommerce" in der Artikelliste** (neben "Uebersicht", `artikel.php` ~6877). Layout: links Shop-Kategorienbaum, rechts Artikel der gewaehlten Kategorie; Shop-Auswahl oben (heute ein Shop).
2. **Kategorienbaum je Shop**, unabhaengig von OpenXE-Kategorien: anlegen, umbenennen, verschieben (Elternkategorie, Reihenfolge), loeschen (nur leer). Artikel einer oder mehreren Kategorien zuordnen. Knoten "ohne Kategorie" und Filter "nicht im Shop".
3. **Kaestchen pro Artikel in der Liste:** "im Shop", "nicht mehr lieferbar", "auf Nachfrage lieferbar". Sofort speichern (AJAX) oder Sammel-Speichern, Builder-Entscheidung.
4. **Export-Wirkung** in WooCommerce (siehe unten), inklusive Ausblenden bei entfernter Zuordnung.
5. **Einmalige Uebernahme** der bestehenden WooCommerce-Kategorien (Staging: 11, davon 5 oberste Ebene) samt Produktzuordnungen in den Baum.

```
Artikel > WooCommerce                Shop: [partner-3d.de v]
+ Shop-Kategorien -------+  Artikel in "Hotends"           [+ Artikel zuordnen]
| > Ersatzteile          |  Nr.      Name          im Shop  nicht lieferbar  auf Nachfrage
|   v Hotends       (12) |  1000001  Heizblock V1    [x]        [ ]              [ ]
|   > Duesen         (8) |  1000002  Volcano Duese   [x]        [x]              [ ]
| > Zubehoer             |
| o ohne Kategorie       |
| [+ Kategorie] [Umbenennen] [Verschieben] [Loeschen]
+------------------------+
```

## Nicht-Ziele

- Kategorie-Beschreibung, Kategoriebild und Rank-Math-SEO der Kategorieseiten bleiben in WordPress und werden von OpenXE nicht angefasst (Staging: 8 Kategorien mit Beschreibung, 2 mit SEO-Werten).
- Kein Loeschen von Produkten in WooCommerce.
- Der Warenkorb-/Anfrage-Ablauf fuer "auf Nachfrage" in WordPress ist ein eigenes Projekt (`Website/docs/05_Website_Technik/Handoff_Anfrage-Warenkorb.md`). Hier wird nur der Zustand am Produkt gesetzt.
- Keine Aenderung am OpenXE-Artikelbaum.

## Anti-Ziele

- Kategorie-URLs (Slugs) aendern sich durch Umbenennen -> gebrochene Links/Rankings.
- Ein Produkt verschwindet aus dem Shop, obwohl es zugeordnet ist, oder bleibt kaufbar, obwohl die Zuordnung entfernt ist.
- Die Erstuebernahme erzeugt doppelte Kategorien in WooCommerce.

## Ansaetze

- **Baum-Speicher:** (a) eigene Tabellen im WooCommerce-Bereich (gewaehlt, Nutzervorgabe "unabhaengig"); (b) Teilbaum des OpenXE-Artikelbaums ueber `category_root_id` (verworfen: bleibt an OpenXE-Kategorien gekoppelt).
- **"Nicht mehr lieferbar":** neues Kaestchen in der Oberflaeche, gespeichert im bestehenden Feld `artikel.ausverkauft` (Nutzervorgabe: Kaestchen, in der DB gespiegelt). Kein zweites Feld.
- **"Aus dem Shop nehmen":** Produktstatus `private` (gewaehlt, umkehrbar, Bestellbezuege bleiben) statt Loeschen/Papierkorb.

## Datenmodell (Vorschlag, Details Builder)

- `woocommerce_category`: id, shop, parent, name, sort, wc_term_id, slug (bei Anlage einmalig erzeugt, danach unveraendert), geloescht.
- `woocommerce_category_article`: shop, category, artikel (eindeutig je Tripel).
- Kaestchen "auf Nachfrage lieferbar": neues Feld je Artikel und Shop (z. B. Spalte in `artikel_onlineshops` oder eigene Tabelle; Builder-Entscheidung, Schema-Eingriff in Kerntabellen vermeiden).
- "im Shop" = `artikel_onlineshops.aktiv` fuer diesen Shop (bestehende Zuordnung, keine Kopie). Haken setzen legt die Zuordnung an bzw. aktiviert sie.

## Export-Wirkung in WooCommerce

| Zustand in OpenXE | WooCommerce |
|---|---|
| im Shop, normal | `publish`, Lager/Backorders wie bisher |
| im Shop, "nicht mehr lieferbar" (= `ausverkauft`) | sichtbar, `outofstock`, `backorders = no`, Lieferzeit "derzeit nicht lieferbar" (German Market) |
| im Shop, "auf Nachfrage lieferbar" | sichtbar, in den Warenkorb legbar: `backorders = notify`, `stock_status = onbackorder`, Meta `openxe_on_request = 1`. Checkout-Verhalten regelt das separate WordPress-Projekt. |
| beide Kaestchen | "nicht mehr lieferbar" gewinnt |
| Zuordnung entfernt/deaktiviert | `status = private` (einmalig beim Entfernen gesendet) |
| Zuordnung wieder gesetzt | normaler Export, `status = publish` |
| Kategorien | aus dem Shop-Baum statt aus dem Artikelbaum; Modus wie `categorySyncMode` (ergaenzen/ersetzen) |

- Das Ausblenden braucht einen eigenen Ausloeser, weil der normale Export nicht zugeordnete Artikel nicht mehr betrachtet: beim Entfernen des Hakens einen Auftrag vormerken, den der naechste Exportlauf (oder ein Cronjob) abarbeitet; Fehlschlaege bleiben vorgemerkt und sind in der WooCommerce-Verwaltung sichtbar.
- Varianten: Lieferstatus wird vom Hauptartikel uebernommen, sofern die Variante keinen eigenen hat (Builder pruefen, was `ProductSynchronizer` fuer Varianten sendet).

## Kategorien gegenueber WooCommerce

- Anlegen in OpenXE -> Term in WooCommerce anlegen (beim naechsten Export oder sofort, Builder).
- Umbenennen -> nur `name` aendern, `slug` bleibt.
- Verschieben -> `parent` aendern.
- Loeschen -> nur leere Kategorien; Term in WooCommerce loeschen, wenn dort ebenfalls leer, sonst Hinweis.
- Erstuebernahme: alle `product_cat`-Terms paginiert lesen, mit `wc_term_id` und bestehendem Slug in den Baum uebernehmen, Produktzuordnungen per SKU auf Artikel abbilden. Vorschau vor dem Schreiben, nicht zuordenbare Produkte listen. Die bestehende `CategorySynchronizer`-Identitaet (shop-/quellspezifische Slugs) wird fuer diesen Shop durch `wc_term_id` ersetzt.

## Einfuehrungsreihenfolge (zusammen mit Teil 1)

1. Deploy, neue Kategorie-Quelle und Ausblend-Logik pro Shop **aus** (Shop-Einstellung).
2. Erstuebernahme der Kategorien, Pruefung im Baum.
3. Einschalten, an einem Testartikel pruefen (Kategorie, Ausblenden, beide Kaestchen).

## Getroffene Festlegungen (Vorschlaege von Claude)

- Ausblenden statt Loeschen (`private`).
- Slugs bleiben beim Umbenennen stabil.
- Kategorie-Beschreibung/-Bild/-SEO bleiben in WordPress.
- Mehrfachzuordnung erlaubt.
- Loeschen nur leerer Kategorien.
- Meta-Name `openxe_on_request` als Schnittstelle zum WordPress-Projekt.
- Einschalten per Shop-Einstellung, Standard aus.

## Dem Builder ueberlassen

- Baum-Bedienung (Drag-and-Drop oder Knoepfe), sofort speichern vs. Sammel-Speichern.
- Ablage des Kaestchens "auf Nachfrage" ohne Kerntabellen-Schema-Eingriff, sofern moeglich.
- Ausloeser fuer das Ausblenden (Export-Lauf vs. Cronjob) innerhalb der bestehenden Prozessstarter-Mechanik.
- Rechte: Artikel-Bearbeitungsrecht plus Shop-Recht, CSRF wie bestehende WooCommerce-Verwaltung.
