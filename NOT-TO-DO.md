| Datum | Tag | Fehler | Vermeidung |
|-------|-----|--------|------------|
| 2026-10-21 | php,sql | Download-Overrides ausserhalb des Artikel-Hashes wurden vom Autoexport uebersehen. | Bei Override-Aenderungen die shopbezogene Export-Aenderungskennung fuer Artikel und betroffene Parent-Artikel aktualisieren und echten Cron-Hash-Pfad testen. |
| 2026-10-21 | php,sql | Unescaped Unicode-JSON kann vierbyte Zeichen nicht in utf8mb3 speichern. | JSON fuer konfiguration mit Unicode-Escaping speichern und Emoji-Roundtrip testen. |
| 2026-10-21 | html,ui | Native URL-Validierung blockierte auch das Verwerfen ungueltiger Formulareingaben. | Reine Reset-/Fallback-Submitaktionen mit formnovalidate versehen; Schreibvalidierung fuer Speichern beibehalten. |
| 2026-10-21 | git | Unabhaengig angelegte NOT-TO-DO.md erzeugten add/add-Konflikte im Production-Build. | Fuer das gemeinsame Fehlerprotokoll merge=union nutzen und Erhalt aller Zeilen pruefen. |
| 2026-10-30 | php,test | WooCommerce-Feedback-Test wertete GET-Filter status=any als Statusaenderung und meldete einen False Positive. | Auf den verbotenen Zielstatus pruefen oder HTTP-Methoden getrennt aufzeichnen, statt jedes status-Feld als PUT zu werten. |
| 2026-10-30 | php,sql | create_shopimport_cart_entry liess shopimport_auftraege.trash aus; NOT NULL ohne DEFAULT scheitert im SQL-Strict-Mode. | Beim Warenkorb-INSERT trash explizit mit 0 belegen und Pflichtspalten gegen struktur.sql pruefen. |
