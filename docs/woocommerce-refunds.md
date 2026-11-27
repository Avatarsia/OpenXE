# WooCommerce Refund-Sync

Der Sync liest ausschliesslich `GET orders/{orderId}/refunds`. Er erzeugt keine
WooCommerce-Zahlung und ruft keinen WooCommerce-Restock auf. ERP-Gutschriften
entstehen ueber `Shopimport::Refund`. Eine fehlende Rechnung wird nicht automatisch
angelegt. Teilrefunds lassen den Status der Ursprungsrechnung unveraendert, damit
weitere Refunds verarbeitet werden koennen. Bestehende andere Refund-Aufrufer
behalten das bisherige Stornierungsverhalten.

```php
$refunds = new \Xentral\Components\WooCommerce\RefundSynchronizer(
    $client,
    $shopId,
    new \Xentral\Components\WooCommerce\RefundStateStore($app->Container('Database')),
    function ($data) use ($shopimport, $shopId) {
        return $shopimport->Refund($shopId, $data);
    },
    [new \Xentral\Components\WooCommerce\RefundPreflight($app->Container('Database'), $shopId), 'validate']
);
$creditNoteIds = $refunds->sync($wooOrder);
```

`$wooOrder` muss das vollstaendige WooCommerce-Order-Objekt einschliesslich
`line_items`, `shipping_lines`, `fee_lines` und `currency` sein. Die Rueckgabe
ordnet jedem Source-Refund seine ERP-Gutschrift-ID zu, auch bei Wiederholungen.

Der Mapper verwendet `_refunded_item_id` aus den Refund-Line-Metadaten, sofern
vorhanden; andernfalls muss `line_items[].id` auf eine urspruengliche Order-Line
zeigen. Mengen werden positiv, `price` ist der Brutto-Stueckpreis aus negativem
`total + total_tax` geteilt durch die negative Menge. Positionen ohne Menge,
unbekannte IDs, positive Refund-Totals und Differenzen zwischen Positionensumme
und Refund-Betrag werden vor der ERP-Buchung zurueckgestellt. Insbesondere wird
ein money-only-, Fee- oder Shipping-Refund mit Menge 0 niemals als Vollrefund
interpretiert. Dafuer ist eine explizite manuelle Zuordnung erforderlich.

## Idempotenz und Recovery

Die vorhandene InnoDB-Tabelle `konfiguration` dient als Journal; eine neue Tabelle
ist nicht erforderlich. Der Primaerschluessel ist
`woocommerce_refund:<shopId>:<orderId>:<refundId>`. Ein MySQL-Named-Lock je Shop
und Order verhindert konkurrierende Buchungen. Die Journal-Verbindung muss im
Autocommit-Modus sein; bestehende Transaktionen werden abgelehnt.

Vor dem ERP-Aufruf wird `{"status":"pending"}` dauerhaft geschrieben. Erst nach
Rueckgabe einer gueltigen Gutschrift-ID folgt
`{"status":"complete","credit_note_id":123}`. Die Gutschrift erhaelt den Marker
`woocommerce-refund:<shopId>:<orderId>:<refundId>` in `internebemerkung`.

Die bestehenden ERP-Methoden haben mehrere Datenbank- und Hook-Seiteneffekte und
werden deshalb nicht als atomar angenommen. Ein Prozessabbruch oder eine Exception
nach Buchungsbeginn laesst `pending` bestehen. Ein erneuter Sync stoppt mit einer
Recovery-Meldung; spaetere Refunds derselben Order ueberholen diesen Refund nicht.
Mapping- und Transportfehler vor Buchungsbeginn bleiben normal wiederholbar.
Der read-only Preflight prueft vor `pending`, ob eine aktive Rechnung mit passender
Waehrung und den Source-Positionen existiert. Eine noch fehlende Rechnung erzeugt
deshalb keinen unklaren Buchungsversuch; nach ihrer Erstellung ist ein normaler
Retry moeglich.

Eine fachkundige Person muss bei `pending` die Gutschriften anhand des Markers,
der Rechnung und des Buchungszeitpunkts pruefen. Der Marker kann fehlen, falls der
Prozess bereits innerhalb der Gutschrifterstellung abgebrochen wurde.

- Existiert eine vollstaendige, korrekte Gutschrift, den zugehoerigen Journalwert
  nach Pruefung ihrer ID auf `complete` setzen.
- Ist sicher nachgewiesen, dass keine Buchung oder sonstige relevante Seiteneffekte
  bestehen, kann der konkrete Journal-Eintrag entfernt und der Sync wiederholt werden.
- Bei unvollstaendiger Buchung zuerst die Buchung fachgerecht bereinigen oder
  vervollstaendigen. Ungeklaerte Eintraege bleiben `pending`.

Journal-Eintraege werden niemals automatisch auf Verdacht geloescht. ERP-Freigabe-
Hooks und konfigurierte Folgeprozesse bleiben Teil des bestehenden ERP-Verhaltens.

## Dauerhafte Retry-Queue

`RefundRetryQueue($database, $shopId, $clock = null)` entkoppelt Refund-Retries vom
Orderfeed. `enqueue($orderId)` schreibt vor dem Feed-Checkpoint einen dauerhaften
Marker in `konfiguration`, unter `woocommerce_refund_retry:<shopId>:<orderId>`.
Der Marker enthaelt nur Scheduling-Daten (`attempts`, `next_attempt`); die Order
wird beim Retry erneut aus WooCommerce gelesen.

`hasDue(): bool` ist ein read-only Hinweis fuer den Cron-Counter. Auch ohne neue
WooCommerce-Orderaenderung muss ein faelliger Retry einen Polling-Durchlauf ausloesen.
`drain($callback, $limit = 10)` ruft den Callback mit der WooCommerce-Order-ID als
String auf, hoechstens zehnmal je Durchlauf. Der Callback liest die aktuelle Order
und ruft `RefundSynchronizer::sync` auf. Ein MySQL-Lock je Shop verhindert parallele
Drains. Draining erfolgt vor einer neuen Order-Deliveryreservierung.

Erfolgreiche Retries entfernen ihren Queue-Marker. Eine Exception oder ein `Error`
laesst den Marker bestehen und setzt Backoff: 60, 120, 240 Sekunden bis maximal
3600 Sekunden. Wiederholtes Enqueue setzt Backoff nicht zurueck. Andere faellige
Orders werden trotz einzelner Fehler verarbeitet. `drain` liefert
`['processed' => [orderId, ...], 'failed' => [orderId => Fehlermeldung, ...]]`;
der Aufrufer entscheidet ueber das Logging.

## Statusansicht

Shopverwaltung > WooCommerce > Refund-Status zeigt die Daten shopbezogen und
paginiert. Alle Zeiten sind UTC. Erneut versuchen setzt ausschliesslich die
bestehende Queue-Zeitplanung auf faellig; Journal-pending/complete und vorhandene
Gutschriften bleiben unveraendert. Kein direktes Wiederbuchen oder Journal-Reset
ist ueber diese Oberflaeche moeglich. Die automatische Verarbeitung setzt den
aktivierten Refund-Abgleich und einen laufenden Import voraus.
