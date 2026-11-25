<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;

final class SnapshotStore
{
    public const TABLE = 'paymos_invoice_snapshots';

    private DbDriverInterface $db;

    /** @var callable */
    private $clock;

    public function __construct(DbDriverInterface $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?: static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(array $snapshot): void
    {
        $externalOrderId = trim((string) ($snapshot['external_order_id'] ?? ''));
        if ($externalOrderId === '') {
            throw new \InvalidArgumentException('Snapshot external_order_id is required.');
        }

        $this->db->ensureSchema();

        $row = [
            'external_order_id' => $externalOrderId,
            'paymos_invoice_id' => trim((string) ($snapshot['paymos_invoice_id'] ?? '')),
            'platform_ref' => trim((string) ($snapshot['platform_ref'] ?? '')),
            'client_id' => self::nullableString($snapshot['client_id'] ?? null),
            'payload_meta' => self::nullableString($snapshot['payload_meta'] ?? null),
            'amount' => trim((string) ($snapshot['amount'] ?? '')),
            'currency' => strtoupper(trim((string) ($snapshot['currency'] ?? ''))),
            'environment' => strtolower(trim((string) ($snapshot['environment'] ?? ''))),
            'project_id' => trim((string) ($snapshot['project_id'] ?? '')),
            'payment_url' => trim((string) ($snapshot['payment_url'] ?? '')),
            'transaction_id' => self::nullableString($snapshot['transaction_id'] ?? null),
            'expires_at' => self::nullableInt($snapshot['expires_at'] ?? null),
            'renewal_attempt' => max(0, (int) ($snapshot['renewal_attempt'] ?? 0)),
            'final_status' => self::nullableString($snapshot['final_status'] ?? null),
            'updated_at' => isset($snapshot['updated_at']) && is_numeric($snapshot['updated_at'])
                ? (int) $snapshot['updated_at']
                : $this->now(),
        ];

        $this->db->upsert(self::TABLE, $row, 'external_order_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId(string $externalOrderId): ?array
    {
        $externalOrderId = trim($externalOrderId);
        if ($externalOrderId === '') {
            return null;
        }

        $this->db->ensureSchema();

        return $this->db->selectOne(self::TABLE, ['external_order_id' => $externalOrderId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPaymosInvoiceId(string $paymosInvoiceId): ?array
    {
        $paymosInvoiceId = trim($paymosInvoiceId);
        if ($paymosInvoiceId === '') {
            return null;
        }

        $this->db->ensureSchema();

        return $this->db->selectOne(self::TABLE, ['paymos_invoice_id' => $paymosInvoiceId], 'updated_at DESC');
    }

    /**
     * Unambiguous snapshot lookup by confirmed transaction_id (for Paymenter refunds).
     *
     * @return array<string, mixed>|null
     */
    public function findByTransactionId(string $transactionId): ?array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        $this->db->ensureSchema();

        return $this->db->selectOne(self::TABLE, ['transaction_id' => $transactionId], 'updated_at DESC');
    }

    /**
     * Find the latest snapshot for a billing invoice (optionally filtered by normalized amount,
     * currency, environment, and project_id).
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForPlatformRef(
        string $platformRef,
        ?string $amount = null,
        ?string $currency = null,
        ?string $environment = null,
        ?string $projectId = null
    ): ?array {
        $platformRef = trim($platformRef);
        if ($platformRef === '') {
            return null;
        }

        $this->db->ensureSchema();
        $where = ['platform_ref' => $platformRef];
        if ($environment !== null && trim($environment) !== '') {
            $where['environment'] = strtolower(trim($environment));
        }
        if ($projectId !== null && trim($projectId) !== '') {
            $where['project_id'] = trim($projectId);
        }

        $rows = $this->db->selectAll(self::TABLE, $where, 'renewal_attempt DESC, updated_at DESC');
        $targetCurrency = $currency !== null ? strtoupper(trim($currency)) : null;

        foreach ($rows as $row) {
            if ($amount !== null && !AmountGuard::amountsEqual($row['amount'] ?? '', $amount)) {
                continue;
            }
            if ($targetCurrency !== null && strtoupper(trim((string) ($row['currency'] ?? ''))) !== $targetCurrency) {
                continue;
            }
            return $row;
        }

        return null;
    }

    /**
     * Return all paid snapshots associated with a Paymenter invoice id.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaidByPlatformRef(string $platformRef): array
    {
        $platformRef = trim($platformRef);
        if ($platformRef === '') {
            return [];
        }

        $this->db->ensureSchema();
        $rows = $this->db->selectAll(self::TABLE, ['platform_ref' => $platformRef], 'updated_at DESC');

        $paid = [];
        foreach ($rows as $row) {
            $status = (string) ($row['final_status'] ?? '');
            if (StatusMapper::invoiceAction('', $status) === StatusMapper::ACTION_PAYMENT_COMPLETE) {
                $paid[] = $row;
            }
        }

        return $paid;
    }

    public function markCompleted(string $externalOrderId, string $transactionId, string $finalStatus): void
    {
        $this->db->ensureSchema();
        $this->db->updateWhere(
            self::TABLE,
            [
                'transaction_id' => trim($transactionId),
                'final_status' => strtolower(trim($finalStatus)),
                'updated_at' => $this->now(),
            ],
            [
                'external_order_id' => trim($externalOrderId),
            ]
        );
    }

    public function markFinalStatus(string $externalOrderId, string $finalStatus): void
    {
        $this->db->ensureSchema();
        $this->db->updateWhere(
            self::TABLE,
            [
                'final_status' => strtolower(trim($finalStatus)),
                'updated_at' => $this->now(),
            ],
            [
                'external_order_id' => trim($externalOrderId),
            ]
        );
    }

    /**
     * Record idempotent paid_late marker in payload_meta.
     * Returns true on the first call for this snapshot, false if already noted.
     */
    public function markPaidLateNoted(string $externalOrderId, string $eventId): bool
    {
        $row = $this->findByExternalOrderId($externalOrderId);
        if ($row === null) {
            return false;
        }

        $meta = [];
        $rawMeta = $row['payload_meta'] ?? null;
        if (is_string($rawMeta) && trim($rawMeta) !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if (!empty($meta['paid_late_noted'])) {
            return false;
        }

        $meta['paid_late_noted'] = $eventId !== '' ? $eventId : '1';
        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $encoded = '{"paid_late_noted":"1"}';
        }

        $this->db->updateWhere(
            self::TABLE,
            [
                'payload_meta' => $encoded,
                'updated_at' => $this->now(),
            ],
            [
                'external_order_id' => trim($externalOrderId),
            ]
        );

        return true;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
