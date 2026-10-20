<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Enum\ServiceType;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway;

final class RepairStatusExchangeService
{
    public function __construct(
        private readonly Database $db,
        private readonly RepairConfigService $config,
        private readonly RepairStatusConfigGateway $statuses,
    ) {}

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->get('sync_v2_enabled', '0') === '1';
    }

    public static function validateEvent(array $event): array
    {
        $date = is_string($event['changed_at_utc'] ?? null)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $event['changed_at_utc'], new \DateTimeZone('UTC')) : false;
        if (!is_string($event['request_number'] ?? null) || $event['request_number'] === '' || strlen($event['request_number']) > 255
            || !is_string($event['status'] ?? null) || !preg_match('/^[a-z0-9_\-]{1,50}$/D', $event['status'])
            || !is_string($event['event_id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $event['event_id'])
            || !in_array($event['origin'] ?? null, ['wp', 'openxe'], true)
            || !$date || $date->format('Y-m-d\TH:i:s.u\Z') !== $event['changed_at_utc']
            || !is_int($event['revision'] ?? null) || $event['revision'] < 0) {
            throw new \InvalidArgumentException('INVALID_STATUS_EVENT');
        }
        return ['request_number' => $event['request_number'], 'status' => $event['status'], 'event_id' => $event['event_id'],
            'origin' => $event['origin'], 'changed_at_utc' => $event['changed_at_utc'], 'revision' => $event['revision']];
    }

    public static function compareEvents(array $a, array $b): int
    {
        return strcmp($a['changed_at_utc'], $b['changed_at_utc'])
            ?: ((int)($a['origin'] === 'openxe') <=> (int)($b['origin'] === 'openxe'))
            ?: strcmp($a['event_id'], $b['event_id']);
    }

    public function getTicketId(string $requestNumber): ?int
    {
        $rows = $this->db->fetchAll('SELECT rd.ticket_id FROM ticket_repair_details rd
            JOIN ticket t ON t.id=rd.ticket_id AND t.schluessel=rd.ticket_schluessel
            WHERE rd.wp_request_number=:request', ['request' => $requestNumber]);
        return count($rows) === 1 ? (int)$rows[0]['ticket_id'] : null;
    }

    private function ticket(int $ticketId, bool $lock = false): ?array
    {
        $row = $this->db->fetchRow('SELECT t.*, rd.wp_request_number, rd.ticket_schluessel, rd.service_type
            FROM ticket t JOIN ticket_repair_details rd ON rd.ticket_id=t.id
            WHERE t.id=:id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $ticketId]);
        if (!$row || !empty($row['privat']) || empty($row['wp_request_number'])
            || $row['schluessel'] !== $row['ticket_schluessel']
            || $this->getTicketId($row['wp_request_number']) !== $ticketId
            || (int)$this->db->fetchValue('SELECT COUNT(*) FROM ticket WHERE schluessel=:key', ['key' => $row['schluessel']]) !== 1) {
            return null;
        }
        return $row;
    }

    private function stored(int $ticketId): ?array
    {
        return $this->db->fetchRow('SELECT * FROM repair_status_exchange WHERE ticket_id=:id', ['id' => $ticketId]) ?: null;
    }

    private function eventFromRow(array $row): array
    {
        return ['request_number' => $row['request_number'], 'status' => $row['status'], 'event_id' => $row['event_id'],
            'origin' => $row['origin'], 'changed_at_utc' => str_replace(' ', 'T', $row['changed_at_utc']) . 'Z',
            'revision' => (int)$row['revision']];
    }

    public function getSnapshot(int $ticketId): array
    {
        $result = ['status' => null, 'status_event' => null, 'history_status_changed_at_utc' => null, 'history_ambiguous' => true];
        $ticket = $this->ticket($ticketId);
        if ($ticket === null || ($mapping = $this->statuses->getWpMapping($ticket['status'])) === null) {
            return $result;
        }
        $result['status'] = $mapping;
        $stored = $this->stored($ticketId);
        $history = $this->history($ticket);
        if ($stored !== null && $stored['native_status'] === $ticket['status'] && !$this->hasNewHistory($stored, $history)) {
            $result['status_event'] = $this->eventFromRow($stored);
            $result['history_ambiguous'] = false;
            $result['history_status_changed_at_utc'] = $result['status_event']['changed_at_utc'];
        } else {
            $usableHistory = $stored === null || $this->hasNewHistory($stored, $history);
            $result['history_status_changed_at_utc'] = $usableHistory ? $history['time'] : null;
            $result['history_ambiguous'] = $result['history_status_changed_at_utc'] === null;
        }
        return $result;
    }

    /** Only actual Core status protocol entries count; generic updated_at never does. */
    private function history(array $ticket): array
    {
        try {
            $rows = $this->db->fetchAll('SELECT id,zeit,grund FROM ticket_protokoll WHERE ticket=:id ORDER BY zeit DESC,id DESC', ['id' => $ticket['id']]);
        } catch (\Throwable) {
            return ['time' => null, 'id' => 0];
        }
        $config = $this->statuses->getBySlug($ticket['status']);
        $labels = [$ticket['status'], $config['label_de'] ?? ''];
        $latest = null;
        foreach ($rows as $row) {
            $text = html_entity_decode($row['grund'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match("/^Geändert: .*?Status '([^']+)'/u", $text, $match)) {
                continue;
            }
            if ($latest !== null) {
                // Second-resolution history cannot order different transitions in the same second.
                if ($row['zeit'] === $latest['zeit'] && $match[1] !== $latest['label']) {
                    return ['time' => null, 'id' => 0];
                }
                break;
            }
            if (!in_array($match[1], $labels, true)) {
                return ['time' => null, 'id' => 0];
            }
            $latest = $row + ['label' => $match[1]];
        }
        if ($latest === null) {
            return ['time' => null, 'id' => 0];
        }
        $zone = new \DateTimeZone($this->config->get('status_history_timezone', 'Europe/Berlin'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $latest['zeit'], $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $latest['zeit']) {
            return ['time' => null, 'id' => 0];
        }
        foreach ([-3600, 3600] as $offset) {
            if ($date->setTimestamp($date->getTimestamp() + $offset)->format('Y-m-d H:i:s') === $latest['zeit']) {
                return ['time' => null, 'id' => 0];
            }
        }
        return ['time' => $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), 'id' => (int)$latest['id']];
    }

    private function hasNewHistory(array $stored, array $history): bool
    {
        return $history['time'] !== null && $history['id'] > (int)$stored['history_id']
            && $history['time'] > $this->eventFromRow($stored)['changed_at_utc'];
    }

    private function uuid(?string $seed = null): string
    {
        $hex = $seed === null ? bin2hex(random_bytes(16)) : substr(hash('sha256', $seed), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function newEvent(array $ticket, string $time, ?string $seed = null, string $origin = 'openxe'): array
    {
        return ['request_number' => $ticket['wp_request_number'], 'status' => $this->statuses->getWpMapping($ticket['status']),
            'event_id' => $this->uuid($seed), 'origin' => $origin, 'changed_at_utc' => $time, 'revision' => 0];
    }

    private function now(): string
    {
        return (string)$this->db->fetchValue("SELECT DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ')");
    }

    /** Must run under the ticket row lock in the same connection/transaction as native writes. */
    private function persist(array $ticket, array $event, bool $outbound, int $historyId = 0): array
    {
        $old = $this->stored((int)$ticket['id']);
        $event['revision'] = ($old === null ? 0 : (int)$old['revision']) + 1;
        $params = ['tid' => $ticket['id'], 'native' => $ticket['status'], 'request' => $event['request_number'],
            'status' => $event['status'], 'uuid' => $event['event_id'], 'origin' => $event['origin'],
            'time' => str_replace(['T', 'Z'], [' ', ''], $event['changed_at_utc']), 'revision' => $event['revision'], 'history' => $historyId];
        $this->db->perform('INSERT INTO repair_status_exchange (ticket_id,native_status,request_number,status,event_id,origin,changed_at_utc,revision,history_id)
            VALUES (:tid,:native,:request,:status,:uuid,:origin,:time,:revision,:history)
            ON DUPLICATE KEY UPDATE native_status=VALUES(native_status),request_number=VALUES(request_number),status=VALUES(status),
                event_id=VALUES(event_id),origin=VALUES(origin),changed_at_utc=VALUES(changed_at_utc),revision=VALUES(revision),history_id=VALUES(history_id)', $params);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $this->db->perform('INSERT INTO repair_status_exchange_events (event_id,ticket_id,payload) VALUES (:uuid,:tid,:payload)',
            ['uuid' => $event['event_id'], 'tid' => $ticket['id'], 'payload' => $payload]);
        if ($outbound) {
            (new RepairSyncQueueGateway($this->db))->enqueue((int)$ticket['id'], $ticket['schluessel'], 'status_exchange',
                $payload, $this->config->getWpApiUrl() . '/wp-json/p3d/v1/erp-sync/status', $this->config->getMaxRetries());
        }
        return $event;
    }

    public function captureLocalChange(int $ticketId, ?string $oldStatus = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $this->db->beginTransaction();
        try {
            $event = $this->captureLocked($ticketId, $oldStatus);
            $this->db->commit();
            return $event;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function captureLocked(int $ticketId, ?string $oldStatus): ?array
    {
        $ticket = $this->ticket($ticketId, true);
        if ($ticket === null || $ticket['status'] === $oldStatus || $this->statuses->getWpMapping($ticket['status']) === null) {
            return null;
        }
        $stored = $this->stored($ticketId);
        $history = $this->history($ticket);
        if ($stored !== null && $stored['native_status'] === $ticket['status'] && !$this->hasNewHistory($stored, $history)) {
            return null;
        }
        if ($oldStatus === null && ($history['time'] === null || ($stored !== null && !$this->hasNewHistory($stored, $history)))) {
            return null;
        }
        $time = $oldStatus !== null ? $this->now() : $history['time'];
        $event = $this->newEvent($ticket, $time, $oldStatus === null ? 'history:' . $ticketId . ':' . $history['id'] : null);
        return $this->persist($ticket, $event, true, $history['id']);
    }

    public function changeLocalStatus(int $ticketId, string $nativeStatus): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if ($this->statuses->getBySlug($nativeStatus) === null) {
            throw new \InvalidArgumentException('INVALID_STATUS');
        }
        $this->db->beginTransaction();
        try {
            $ticket = $this->db->fetchRow('SELECT * FROM ticket WHERE id=:id FOR UPDATE', ['id' => $ticketId]);
            if (!$ticket) {
                throw new \InvalidArgumentException('UNKNOWN_REQUEST');
            }
            $this->db->perform('UPDATE ticket SET status=:status WHERE id=:id', ['status' => $nativeStatus, 'id' => $ticketId]);
            $event = $this->captureLocked($ticketId, $ticket['status']);
            $this->db->commit();
            return $event;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function receive(array $event): array
    {
        $event = self::validateEvent($event);
        if (!$this->isEnabled()) {
            throw new \RuntimeException('SYNC_V2_DISABLED');
        }
        $ticketId = $this->getTicketId($event['request_number']);
        if ($ticketId === null) {
            throw new \InvalidArgumentException('UNKNOWN_REQUEST');
        }
        $this->db->beginTransaction();
        try {
            $ticket = $this->ticket($ticketId, true);
            if ($ticket === null || $this->statuses->getWpMapping($ticket['status']) === null) {
                throw new \InvalidArgumentException('UNMAPPED_REQUEST');
            }
            $stored = $this->stored($ticketId);
            // Recover a committed legacy Core write before comparing remote timestamps.
            if ($stored === null || $stored['native_status'] !== $ticket['status'] || $this->hasNewHistory($stored, $this->history($ticket))) {
                if ($this->captureLocked($ticketId, null) === null && $stored !== null && $stored['native_status'] !== $ticket['status']) {
                    throw new \RuntimeException('STATUS_HISTORY_UNCLEAR');
                }
                $stored = $this->stored($ticketId);
            }
            $current = $stored === null ? null : $this->eventFromRow($stored);
            $seen = $this->db->fetchRow('SELECT ticket_id,payload FROM repair_status_exchange_events WHERE event_id=:uuid', ['uuid' => $event['event_id']]);
            if ($seen) {
                $original = json_decode($seen['payload'], true, 512, JSON_THROW_ON_ERROR);
                $original['revision'] = $event['revision'];
                if ((int)$seen['ticket_id'] !== $ticketId || $original !== $event) {
                    throw new \InvalidArgumentException('STATUS_EVENT_CONFLICT');
                }
            }
            if ($seen || ($current !== null && self::compareEvents($event, $current) <= 0)) {
                $result = ['success' => true, 'applied' => false, 'event' => $current, 'reason' => $seen ? 'duplicate' : 'not_newer'];
            } else {
                $category = ServiceType::tryFrom($ticket['service_type'])?->statusCategory();
                $target = $category === null ? null : $this->statuses->getByWpMapping($event['status'], $category);
                if ($target === null) {
                    throw new \InvalidArgumentException('UNMAPPED_STATUS');
                }
                // A public projection echo must retain a more precise native status.
                if ($this->statuses->getWpMapping($ticket['status']) !== $event['status']) {
                    $ticket['status'] = $target['slug'];
                    $this->db->perform('UPDATE ticket SET status=:status WHERE id=:id', ['status' => $ticket['status'], 'id' => $ticketId]);
                }
                $history = $this->history($ticket);
                $canonical = $this->persist($ticket, $event, false, $history['id']);
                $result = ['success' => true, 'applied' => true, 'event' => $canonical, 'reason' => 'newer'];
            }
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function resolve(int $ticketId, string $publicStatus): array
    {
        $this->db->beginTransaction();
        try {
            $ticket = $this->ticket($ticketId, true);
            if ($ticket === null) {
                throw new \InvalidArgumentException('UNKNOWN_REQUEST');
            }
            $category = ServiceType::tryFrom($ticket['service_type'])?->statusCategory();
            $target = $category === null ? null : $this->statuses->getByWpMapping($publicStatus, $category);
            if ($target === null) {
                throw new \InvalidArgumentException('UNMAPPED_STATUS');
            }
            if ($this->statuses->getWpMapping($ticket['status']) !== $publicStatus) {
                $ticket['status'] = $target['slug'];
                $this->db->perform('UPDATE ticket SET status=:status WHERE id=:id', ['status' => $ticket['status'], 'id' => $ticketId]);
            }
            $event = $this->newEvent($ticket, $this->now());
            $history = $this->history($ticket);
            $event = $this->persist($ticket, $event, true, $history['id']);
            $this->db->commit();
            return $event;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reconcile(int $ticketId, array $remote, bool $apply = false, ?string $choice = null): array
    {
        if ($choice !== null && !in_array($choice, ['local', 'remote'], true)) {
            throw new \InvalidArgumentException('INVALID_CHOICE');
        }
        $local = $this->getSnapshot($ticketId);
        $ticket = $this->ticket($ticketId);
        $result = ['state' => 'unclear', 'event' => $local['status_event'], 'changed' => false];
        if ($ticket === null || $local['status'] === null || empty($remote['status'])) {
            $result['state'] = 'unmapped';
            return $result;
        }
        $remoteEvent = isset($remote['status_event']) ? self::validateEvent($remote['status_event']) : null;
        if ($remoteEvent !== null && ($remoteEvent['request_number'] !== $ticket['wp_request_number'] || $remoteEvent['status'] !== $remote['status'])) {
            throw new \InvalidArgumentException('STATUS_EVENT_MISMATCH');
        }
        if ($local['status_event'] !== null && $remoteEvent !== null) {
            $cmp = self::compareEvents($local['status_event'], $remoteEvent);
            $result['state'] = $cmp === 0 ? 'equal' : ($cmp > 0 ? 'local_newer' : 'remote_newer');
        } elseif ($local['status'] === $remote['status']) {
            $result['state'] = 'equal';
        } else {
            $lt = $local['status_event']['changed_at_utc'] ?? $local['history_status_changed_at_utc'];
            $rt = $remoteEvent['changed_at_utc'] ?? ($remote['history_status_changed_at_utc'] ?? null);
            if ($lt !== null && $rt !== null && !($local['history_ambiguous'] ?? true)
                && ($remoteEvent !== null || !($remote['history_ambiguous'] ?? true)) && $lt !== $rt) {
                $result['state'] = $lt > $rt ? 'local_newer' : 'remote_newer';
            }
        }
        if (!$apply || ($result['state'] === 'unclear' && $choice === null)) {
            return $result;
        }
        $takeRemote = $choice === 'remote' || ($choice === null && $result['state'] === 'remote_newer');
        if ($choice !== null) {
            // Explicit operator resolution is a new action and can resolve missing Core history.
            if (!$this->isEnabled()) {
                throw new \RuntimeException('SYNC_V2_DISABLED');
            }
            $result['event'] = $this->resolve($ticketId, $takeRemote ? $remote['status'] : $local['status']);
            $result['state'] = 'local_newer';
            $result['changed'] = true;
            return $result;
        } elseif ($takeRemote && $remoteEvent !== null) {
            $event = $remoteEvent;
        } elseif (!$takeRemote && $local['status_event'] !== null) {
            return $result;
        } elseif ($result['state'] === 'equal' && $remoteEvent !== null) {
            $event = $remoteEvent;
        } else {
            $time = $takeRemote ? ($remote['history_status_changed_at_utc'] ?? null) : $local['history_status_changed_at_utc'];
            $time ??= '1970-01-01T00:00:00.000000Z';
            $event = $this->newEvent($ticket, $time, 'baseline:' . $ticket['wp_request_number'] . ':' . ($takeRemote ? $remote['status'] : $local['status']) . ':' . $time, $takeRemote ? 'wp' : 'openxe');
            $event['status'] = $takeRemote ? $remote['status'] : $local['status'];
        }
        $received = $this->receive($event);
        $result['event'] = $received['event'];
        $result['changed'] = $received['applied'];
        return $result;
    }
}
