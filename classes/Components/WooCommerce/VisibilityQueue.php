<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use Closure;
use InvalidArgumentException;
use JsonException;
use LengthException;
use Throwable;
use UnexpectedValueException;

final class VisibilityQueue
{
    private const MAX_ENTRIES = 2000;
    private const MAX_BYTES = 65000;
    private const DRAIN_LIMIT = 20;
    private const DRAIN_SECONDS = 20;
    private const MAX_RETRY_DELAY = 86400;
    private const MAX_ERROR_CHARS = 200;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
    private const FALLBACK_ERROR = 'Ausblenden fehlgeschlagen.';

    private string $key;
    private Closure $monotonicClock;

    public function __construct(private $database, private int $shopId, ?callable $monotonicClock = null)
    {
        $this->key = 'woocommerce_visibility_' . $shopId;
        $this->monotonicClock = $monotonicClock === null
            ? static fn (): float => hrtime(true) / 1e9
            : Closure::fromCallable($monotonicClock);
    }

    public function enqueue(int $articleId, string $sku): void
    {
        $sku = trim($sku);
        if ($articleId < 1 || $sku === '') {
            throw new InvalidArgumentException('WooCommerce-Ausblendung erfordert eine Artikel-ID und SKU.');
        }
        $this->transactional(function () use ($articleId, $sku): void {
            $entries = $this->lockedEntries();
            if (($entries[$articleId]['sku'] ?? null) === $sku) {
                return;
            }
            $now = time();
            $entries[$articleId] = [
                'sku' => $sku, 'attempts' => 0, 'lastError' => '', 'queuedAt' => $now, 'nextAttemptAt' => $now,
            ];
            if (count($entries) > self::MAX_ENTRIES || !$this->fitsWithRetryReserve($entries)) {
                throw new LengthException('WooCommerce-Sichtbarkeitsqueue ist voll (maximal 2000 Eintraege und 65000 Bytes).');
            }
            $this->save($entries);
        });
    }

    public function cancel(int $articleId): void
    {
        $this->transactional(function () use ($articleId): void {
            $entries = $this->lockedEntries();
            if (isset($entries[$articleId])) {
                unset($entries[$articleId]);
                $this->save($entries);
            }
        });
    }

    /**
     * @param callable(string, callable(): bool): mixed $hide Returns false when the deadline prevents a request.
     * @return array{processed: list<int>, failed: array<int, string>}
     */
    public function drain(callable $hide): array
    {
        $deadline = ($this->monotonicClock)() + self::DRAIN_SECONDS;
        $mayStartRequest = fn (): bool => ($this->monotonicClock)() < $deadline;
        $now = time();
        $due = array_filter($this->pending(), static fn (array $entry): bool =>
            empty($entry['manualAction']) && ($entry['nextAttemptAt'] ?? 0) <= $now);
        uasort($due, static fn (array $a, array $b): int =>
            ($a['lastAttemptAt'] ?? 0) <=> ($b['lastAttemptAt'] ?? 0));

        $result = ['processed' => [], 'failed' => []];
        foreach (array_slice($due, 0, self::DRAIN_LIMIT, true) as $articleId => $snapshot) {
            if (!$mayStartRequest()) {
                break;
            }
            $this->transactional(function () use ($articleId, $snapshot, $hide, $mayStartRequest, &$result): void {
                // Lock in assignment-then-queue order, as when editing a shop assignment.
                $assigned = $this->database->fetchValue(
                    'SELECT id FROM artikel_onlineshops WHERE artikel = :article AND shop = :shop AND aktiv = 1 LIMIT 1 FOR UPDATE',
                    ['article' => $articleId, 'shop' => $this->shopId]
                );
                $entries = $this->lockedEntries();
                if (($entries[$articleId] ?? null) !== $snapshot) {
                    return;
                }
                if (!$assigned) {
                    try {
                        if ($hide($snapshot['sku'], $mayStartRequest) === false) {
                            return;
                        }
                    } catch (Throwable $error) {
                        $reason = RefundStatusService::sanitizeError($error->getMessage());
                        preg_match('/^.{0,' . self::MAX_ERROR_CHARS . '}/us', $reason, $match);
                        $attempts = $snapshot['attempts'] < PHP_INT_MAX ? $snapshot['attempts'] + 1 : PHP_INT_MAX;
                        $now = time();
                        $entries[$articleId]['attempts'] = $attempts;
                        $entries[$articleId]['lastAttemptAt'] = $now;
                        $reason = ($match[0] ?? '') ?: self::FALLBACK_ERROR;
                        $entries[$articleId]['lastError'] = $reason;
                        if ($error instanceof VisibilityManualActionRequired) {
                            $entries[$articleId]['manualAction'] = true;
                            $entries[$articleId]['nextAttemptAt'] = null;
                        } else {
                            $delay = min(self::MAX_RETRY_DELAY, 60 * (1 << min($attempts - 1, 11)));
                            $entries[$articleId]['nextAttemptAt'] = $now + $delay;
                        }
                        $this->save($entries, $articleId);
                        $result['failed'][$articleId] = $reason;
                        return;
                    }
                }
                unset($entries[$articleId]);
                $this->save($entries);
                $result['processed'][] = $articleId;
            });
        }
        return $result;
    }

    public function pending(): array
    {
        return $this->read();
    }

    private function lockedEntries(): array
    {
        // Create the row first to serialize the initial write as well.
        $this->database->perform(
            'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :wert, 0, 0)'
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name)',
            ['name' => $this->key, 'wert' => '{}']
        );
        return $this->read(true);
    }

    private function read(bool $forUpdate = false): array
    {
        $json = $this->database->fetchValue(
            'SELECT wert FROM konfiguration WHERE name = :name' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['name' => $this->key]
        );
        try {
            $entries = json_decode($json === false || $json === null ? '{}' : $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('WooCommerce-Sichtbarkeitsqueue enthaelt ungueltiges JSON.', 0, $error);
        }
        if (!is_array($entries)) {
            throw new UnexpectedValueException('WooCommerce-Sichtbarkeitsqueue enthaelt keine Eintragsliste.');
        }
        return $entries;
    }

    private function fitsWithRetryReserve(array $entries): bool
    {
        // Supplementary characters use two six-byte JSON escapes per character.
        $reservedError = str_repeat("\u{10000}", self::MAX_ERROR_CHARS);
        foreach ($entries as &$entry) {
            $entry['attempts'] = PHP_INT_MAX;
            $entry['lastAttemptAt'] = PHP_INT_MAX;
            $entry['nextAttemptAt'] = PHP_INT_MAX;
            $entry['manualAction'] = true;
            $entry['lastError'] = $reservedError;
        }
        return strlen(json_encode((object) $entries, self::JSON_FLAGS)) <= self::MAX_BYTES;
    }

    private function save(array $entries, ?int $failedArticleId = null): void
    {
        $value = json_encode((object) $entries, self::JSON_FLAGS);
        if ($failedArticleId !== null && strlen($value) > self::MAX_BYTES) {
            $entries[$failedArticleId]['lastError'] = '';
            $value = json_encode((object) $entries, self::JSON_FLAGS);
            if (strlen($value) > self::MAX_BYTES) {
                unset($entries[$failedArticleId]['lastError']);
                $value = json_encode((object) $entries, self::JSON_FLAGS);
            }
        }
        if (strlen($value) > self::MAX_BYTES) {
            throw new LengthException('WooCommerce-Sichtbarkeitsqueue ueberschreitet 65000 Bytes.');
        }
        $this->database->perform(
            'UPDATE konfiguration SET wert = :wert WHERE name = :name',
            ['name' => $this->key, 'wert' => $value]
        );
    }

    private function transactional(callable $operation): void
    {
        // Enqueue/cancel can share the transaction that changes the assignment.
        $ownTransaction = !$this->database->inTransaction();
        if ($ownTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $operation();
            if ($ownTransaction) {
                $this->database->commit();
            }
        } catch (Throwable $error) {
            if ($ownTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }
}
