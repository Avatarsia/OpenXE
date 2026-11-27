| Datum | Tag | Fehler | Vermeidung |
|-------|-----|--------|------------|
| 2026-10-21 | php,sql | Download-Overrides ausserhalb des Artikel-Hashes wurden vom Autoexport uebersehen. | Bei Override-Aenderungen die shopbezogene Export-Aenderungskennung fuer Artikel und betroffene Parent-Artikel aktualisieren und echten Cron-Hash-Pfad testen. |
| 2026-10-21 | php,sql | Unescaped Unicode-JSON kann vierbyte Zeichen nicht in utf8mb3 speichern. | JSON fuer konfiguration mit Unicode-Escaping speichern und Emoji-Roundtrip testen. |
| 2026-10-21 | html,ui | Native URL-Validierung blockierte auch das Verwerfen ungueltiger Formulareingaben. | Reine Reset-/Fallback-Submitaktionen mit formnovalidate versehen; Schreibvalidierung fuer Speichern beibehalten. |
| 2026-10-21 | git | Unabhaengig angelegte NOT-TO-DO.md erzeugten add/add-Konflikte im Production-Build. | Fuer das gemeinsame Fehlerprotokoll merge=union nutzen und Erhalt aller Zeilen pruefen. |
