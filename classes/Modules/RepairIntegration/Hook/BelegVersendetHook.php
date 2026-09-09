<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Hook;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Enum\ServiceType;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Service\RepairSyncService;

/**
 * Reagiert auf den Core-Hook DokumentMaskVersendet(typ, id): wurde ein aus
 * einem Repair-Ticket erzeugter Beleg versendet, wird das Ticket nachgezogen.
 *
 * - immer: Belegnummer im Link (repair_ticket_beleg) aktualisieren
 * - angebot:  quote_amount = Angebotssumme, Status -> quote_sent-Slug
 * - auftrag:  Status -> approved-Slug
 * - rechnung: actual_cost = Rechnungssumme, Status -> repaired-Slug
 *
 * Der Ziel-Slug wird ueber das WP-Mapping in der Kategorie des Service-Typs
 * aufgeloest (kv_gesendet/re_angebot/ind_angebot usw.). Ein Statuswechsel
 * passiert nur vorwaerts, siehe shouldAdvance(). Jeder Statuswechsel und
 * jeder neue KVA-Betrag laeuft ueber den bestehenden WP-Push.
 */
final class BelegVersendetHook
{
    /** Belegtyp -> WP-Status, dessen OpenXE-Slug das Ticket erhalten soll. */
    public const BELEG_WP_STATUS = [
        'angebot' => 'quote_sent',
        'auftrag' => 'approved',
        'rechnung' => 'repaired',
    ];

    /** Belegtyp -> Betragsfeld in ticket_repair_details. */
    private const BELEG_AMOUNT_FIELD = [
        'angebot' => 'quote_amount',
        'rechnung' => 'actual_cost',
    ];

    /**
     * Belegtyp -> Brutto-Summenspalte der Belegtabelle. Angebot/Auftrag
     * fuehren `gesamtsumme`, die Rechnung hat keine solche Spalte, dort ist
     * `soll` der Bruttobetrag.
     */
    private const BELEG_SUM_COLUMN = [
        'angebot' => 'gesamtsumme',
        'auftrag' => 'gesamtsumme',
        'rechnung' => 'soll',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly RepairBelegGateway $belegGateway,
        private readonly RepairDetailsGateway $detailsGateway,
        private readonly RepairStatusConfigGateway $statusConfigGateway,
        private readonly RepairSyncService $syncService,
    ) {}

    /**
     * @return array<int, array{ticket_id: int, old_status: string, new_status: ?string, amount: ?string}>
     */
    public function onBelegVersendet(string $belegTyp, int $belegId): array
    {
        if (!isset(self::BELEG_WP_STATUS[$belegTyp]) || $belegId <= 0) {
            return [];
        }

        $links = $this->belegGateway->getByBeleg($belegTyp, $belegId);
        if ($links === []) {
            return [];
        }

        // $belegTyp ist ueber BELEG_WP_STATUS auf angebot|auftrag|rechnung begrenzt.
        $sumColumn = self::BELEG_SUM_COLUMN[$belegTyp];
        $beleg = $this->db->fetchRow(
            "SELECT `belegnr`, `{$sumColumn}` AS `summe` FROM `{$belegTyp}` WHERE `id` = :id",
            ['id' => $belegId]
        );
        if (!$beleg) {
            return [];
        }

        $belegNr = trim((string)($beleg['belegnr'] ?? ''));
        $amount = RepairSyncService::formatQuoteAmount($beleg['summe'] ?? null);
        $amountField = self::BELEG_AMOUNT_FIELD[$belegTyp] ?? null;

        $results = [];
        foreach ($links as $link) {
            $ticketId = (int)$link['ticket_id'];

            if ($belegNr !== '' && $belegNr !== (string)($link['beleg_nr'] ?? '')) {
                $this->belegGateway->updateBelegNr((int)$link['id'], $belegNr);
            }

            $details = $this->detailsGateway->getByTicketId($ticketId);
            if ($details === null) {
                continue;
            }

            $amountWritten = null;
            if ($amountField !== null && $amount !== null) {
                $this->detailsGateway->setAmountField($ticketId, $amountField, $amount);
                $amountWritten = $amount;
            }

            $currentSlug = (string)$this->db->fetchValue(
                'SELECT `status` FROM `ticket` WHERE `id` = :id',
                ['id' => $ticketId]
            );
            $serviceType = ServiceType::tryFrom((string)($details['service_type'] ?? ''));
            $category = $serviceType !== null ? $serviceType->statusCategory() : 'repair';
            $target = $this->statusConfigGateway->getByWpMapping(self::BELEG_WP_STATUS[$belegTyp], $category);
            $current = $currentSlug !== '' ? $this->statusConfigGateway->getBySlug($currentSlug) : null;

            $newStatus = null;
            if ($target !== null && self::shouldAdvance($current, $target)) {
                $newStatus = (string)$target['slug'];
                $this->db->perform(
                    'UPDATE `ticket` SET `status` = :status WHERE `id` = :id',
                    ['status' => $newStatus, 'id' => $ticketId]
                );
            }

            // Push bei Statuswechsel oder neuem KVA-Betrag (quote_amount ist
            // Teil des Status-Payloads, siehe RepairSyncService).
            if ($newStatus !== null || ($amountWritten !== null && $amountField === 'quote_amount')) {
                $this->syncService->queueAndPushStatusChange($ticketId);
            }

            $results[] = [
                'ticket_id' => $ticketId,
                'old_status' => $currentSlug,
                'new_status' => $newStatus,
                'amount' => $amountWritten,
            ];
        }

        return $results;
    }

    /**
     * Entscheidet, ob das Ticket von $current auf $target wechseln darf.
     * Reine Funktion auf ticket_status_config-Zeilen (slug, category,
     * sort_order, is_terminal), damit sie ohne DB testbar ist.
     *
     * - unbekannter/leerer Ist-Status: ja
     * - gleicher Slug oder terminaler Ist-Status: nein
     * - Ist-Status aus 'general' (neu/offen/...): ja, liegt immer davor
     * - andere Kategorie als das Ziel: ja (Ziel folgt dem Service-Typ)
     * - sonst nur vorwaerts nach sort_order
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $target
     */
    public static function shouldAdvance(?array $current, array $target): bool
    {
        if ($current === null) {
            return true;
        }
        if ((string)($current['slug'] ?? '') === (string)($target['slug'] ?? '')) {
            return false;
        }
        if ((int)($current['is_terminal'] ?? 0) === 1) {
            return false;
        }
        $currentCategory = (string)($current['category'] ?? '');
        if ($currentCategory === 'general') {
            return true;
        }
        if ($currentCategory !== (string)($target['category'] ?? '')) {
            return true;
        }
        return (int)($current['sort_order'] ?? 0) < (int)($target['sort_order'] ?? 0);
    }
}
