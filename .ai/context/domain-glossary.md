# Domänen-Glossar — OpenXE ERP

> Fachbegriffe im OpenXE-Code mit deutscher Bedeutung und englischer Übersetzung.
> Wichtig für KI-Agenten, die den deutschsprachigen Code verstehen müssen.

## Belegwesen (Documents)

| Code-Begriff | Deutsch | English | Tabelle |
|-------------|---------|---------|---------|
| `auftrag` | Auftrag | Sales Order | `auftrag` |
| `rechnung` | Rechnung | Invoice | `rechnung` |
| `lieferschein` | Lieferschein | Delivery Note | `lieferschein` |
| `gutschrift` | Gutschrift | Credit Note | `gutschrift` |
| `angebot` | Angebot | Quote/Offer | `angebot` |
| `bestellung` | Bestellung (Einkauf) | Purchase Order | `bestellung` |
| `retoure` | Retoure | Return | `retoure` |
| `proformarechnung` | Proformarechnung | Proforma Invoice | `proformarechnung` |
| `belegnr` | Belegnummer | Document Number | — |

## Artikelverwaltung (Article/Product Management)

| Code-Begriff | Deutsch | English | Tabelle |
|-------------|---------|---------|---------|
| `artikel` | Artikel | Article/Product | `artikel` |
| `stueckliste` | Stückliste | Bill of Materials (BOM) | `stueckliste` |
| `eigenschaften` | Eigenschaften | Properties/Attributes | `eigenschaften` |
| `verkaufspreis` | Verkaufspreis | Selling Price | `verkaufspreise` |
| `einkaufspreis` | Einkaufspreis | Purchase Price | `einkaufspreise` |
| `lagerplatz` | Lagerplatz | Storage Location | `lager_platz` |

## Adress-/Kundenverwaltung (CRM)

| Code-Begriff | Deutsch | English | Tabelle |
|-------------|---------|---------|---------|
| `adresse` | Adresse | Address/Contact | `adresse` |
| `lieferant` | Lieferant | Supplier/Vendor | — |
| `kunde` | Kunde | Customer | — |
| `ansprechpartner` | Ansprechpartner | Contact Person | `ansprechpartner` |
| `gruppe` | Gruppe | Group | `gruppen` |

## Finanzen (Finance)

| Code-Begriff | Deutsch | English | Hinweis |
|-------------|---------|---------|---------|
| `ustfrei` | Umsatzsteuer-frei | VAT exempt | Boolean |
| `umsatzsteuer` | Umsatzsteuer | VAT/Sales Tax | Betrag |
| `steuersatz` | Steuersatz | Tax Rate | Prozent |
| `waehrung` | Währung | Currency | ISO Code |
| `zahlungsweise` | Zahlungsweise | Payment Method | — |
| `kontorahmen` | Kontorahmen | Chart of Accounts | — |

## System-Begriffe

| Code-Begriff | Deutsch | English | Hinweis |
|-------------|---------|---------|---------|
| `projekt` | Projekt | Project | Kostenstelle |
| `cronjob` | Zeitgesteuerte Aufgabe | Scheduled Task | — |
| `shopexport` | Shop-Export | Shop Integration | WooCommerce, etc. |
| `versandart` | Versandart | Shipping Method | — |
| `drucker` | Drucker | Printer | PDF-Ausgabe |
| `briefpapier` | Briefpapier | Letterhead | PDF-Template |

## Häufige Code-Patterns

| Pattern | Bedeutung |
|---------|-----------|
| `AARLG` | Auftrag, Angebot, Rechnung, Lieferschein, Gutschrift (Belegtypen-Gruppe) |
| `$id` | Primärschlüssel der aktuellen Entität |
| `$input` | Formulardaten aus POST |
| `GetGET('id')` | Sicherer(?) GET-Parameter-Zugriff |
| `ModulVorhanden('x')` | Prüft ob optionales Modul aktiv ist |
