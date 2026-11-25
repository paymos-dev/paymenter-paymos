<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class PdoEventStore implements CommitAwareEventStoreInterface
{
    public const TABLE = 'paymos_webhook_events';
    public const RESERVATION_SECONDS = 300;
    public const CLEANUP_BATCH_LIMIT = 100;

    private const STATE_PROCESSING = 'processing';
    private const STATE_COMMITTED = 'committed';

    private DbDriverInterface $db;

    /** @var callable */
    private $clock;

    /**
     * Owner tokens for active reservations held by this store instance.
     *
     * @var array<string, array{token: string, ttl: int}>
     */
    private array $ownerTokens = [];

    public function __construct(DbDriverInterface $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?: static fn (): int => time();
    }

    /**
     * Atomically reserve an event_id for processing (with bounded expired cleanup
     * and CAS takeover of expired in-flight locks).
     *
     * @param string $eventId
     * @param int    $ttlSeconds
     */
    public function remember($eventId, $ttlSeconds): bool
    {
        $eventId = trim((string) $eventId);
        if ($eventId === '') {
            throw new \InvalidArgumentException('Webhook event_id must be a non-empty string.');
        }

        $ttl = max(1, (int) $ttlSeconds);
        $now = $this->now();

        $this->db->ensureSchema();

        // Bounded cleanup of expired rows to prevent unbounded table growth without full-table locks.
        $this->db->deleteWhere(
            self::TABLE,
            [],
            ['expires_at' => $now],
            self::CLEANUP_BATCH_LIMIT
        );

        $ownerToken = bin2hex(random_bytes(16));
        $row = [
            'event_id' => $eventId,
            'owner_token' => $ownerToken,
            'state' => self::STATE_PROCESSING,
            'expires_at' => $now + self::RESERVATION_SECONDS,
            'created_at' => $now,
        ];

        $acquired = $this->db->insertUnique(self::TABLE, $row, 'event_id');

        if (!$acquired) {
            // Atomic CAS takeover of an expired in-flight reservation.
            $takenOver = $this->db->updateWhere(
                self::TABLE,
                [
                    'owner_token' => $ownerToken,
                    'expires_at' => $now + self::RESERVATION_SECONDS,
                ],
                [
                    'event_id' => $eventId,
                    'state' => self::STATE_PROCESSING,
                ],
                [
                    'expires_at' => $now,
                ]
            );
            $acquired = ($takenOver === 1);
        }

        if (!$acquired) {
            // If an expired committed row survived the bounded cleanup batch (or expired exactly at $now),
            // purge only this expired committed row and retry atomic insert once.
            $purged = $this->db->deleteWhere(
                self::TABLE,
                [
                    'event_id' => $eventId,
                    'state' => self::STATE_COMMITTED,
                ],
                [],
                null,
                [
                    'expires_at' => $now,
                ]
            );
            if ($purged === 1) {
                $acquired = $this->db->insertUnique(self::TABLE, $row, 'event_id');
            }
        }

        if ($acquired) {
            $this->ownerTokens[$eventId] = [
                'token' => $ownerToken,
                'ttl' => $ttl,
            ];
            return true;
        }

        return false;
    }

    /**
     * True only when the event has been committed and its dedup TTL has not expired.
     *
     * @param string $eventId
     */
    public function isCommitted($eventId): bool
    {
        $eventId = trim((string) $eventId);
        if ($eventId === '') {
            return false;
        }

        $this->db->ensureSchema();
        $row = $this->db->selectOne(self::TABLE, ['event_id' => $eventId]);
        if ($row === null) {
            return false;
        }

        return (string) ($row['state'] ?? '') === self::STATE_COMMITTED
            && (int) ($row['expires_at'] ?? 0) > $this->now();
    }

    /**
     * Owner-fenced commit. Returns true only if this instance still owned the active reservation.
     */
    public function commit(string $eventId): bool
    {
        $eventId = trim($eventId);
        if ($eventId === '' || !isset($this->ownerTokens[$eventId])) {
            return false;
        }

        $entry = $this->ownerTokens[$eventId];
        unset($this->ownerTokens[$eventId]);

        $this->db->ensureSchema();
        $affected = $this->db->updateWhere(
            self::TABLE,
            [
                'state' => self::STATE_COMMITTED,
                'expires_at' => $this->now() + $entry['ttl'],
            ],
            [
                'event_id' => $eventId,
                'state' => self::STATE_PROCESSING,
                'owner_token' => $entry['token'],
            ]
        );

        return $affected === 1;
    }

    /**
     * Owner-fenced release. Never deletes another worker's reservation or a committed row.
     */
    public function release(string $eventId): void
    {
        $eventId = trim($eventId);
        if ($eventId === '' || !isset($this->ownerTokens[$eventId])) {
            return;
        }

        $entry = $this->ownerTokens[$eventId];
        unset($this->ownerTokens[$eventId]);

        $this->db->ensureSchema();
        $this->db->deleteWhere(
            self::TABLE,
            [
                'event_id' => $eventId,
                'state' => self::STATE_PROCESSING,
                'owner_token' => $entry['token'],
            ]
        );
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
