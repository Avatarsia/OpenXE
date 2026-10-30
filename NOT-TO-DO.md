# NOT-TO-DO Fehlerprotokoll

| Datum | Tag | Fehler | Vermeidung |
|-------|-----|--------|------------|
| 2026-10-30 | js,chart | Chart.js 2.7 BarController liefert bei linearer Timestamp-X-Achse NaN-Positionen trotz gueltiger Daten | Echte Bar-Model-Pixel testen; Runtime-Chart positioniert eigene Saeulen direkt ueber den Timestamp-Scale |
| 2026-10-30 | git,config | core.autocrlf=true normalisiert eine upstream als CRLF gespeicherte Datei beim Staging auf LF und erzeugt Ganzdatei-Mergekonflikte | Byte-genau editieren, CRCRLF pruefen, bestehende CRLF-Blobs gezielt mit git -c core.autocrlf=false add stagen und finale EOL-Zaehler pruefen |
