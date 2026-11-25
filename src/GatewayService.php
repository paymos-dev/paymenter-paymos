<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\WebhookEvent;
use Paymos\Webhook\WebhookEventProcessor;
use Paymos\Webhook\WebhookVerifier;

final class GatewayService
{
    private const WEBHOOK_TOLERANCE_SECONDS = 300;
    private const DEDUP_TTL_SECONDS = 604800;

    private Config $config;
    private Client $client;
    private PdoEventStore $eventStore;
    private SnapshotStore $snapshots;

    /** @var callable */
    private $clock;

    /** @var callable|null */
    private $logger;

    public function __construct(
        Config $config,
        DbDriverInterface $db,
        ?TransportInterface $transport = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->clock = $clock ?: static fn (): int => time();
        $this->logger = $logger;
        $this->client = new Client($config->toClientConfig(), $transport, $this->clock);
        $this->eventStore = new PdoEventStore($db, $this->clock);
        $this->snapshots = new SnapshotStore($db, $this->clock);
    }

    public function eventStore(): PdoEventStore
    {
        return $this->eventStore;
    }

    public function snapshots(): SnapshotStore
    {
        return $this->snapshots;
    }

    /**
     * Create or reuse a Paymos hosted checkout invoice for a Paymenter invoice.
     *
     * @return array{
     *     payment_url: string,
     *     invoice_id: string,
     *     external_order_id: string,
     *     amount: string,
     *     currency: string,
     *     renewal_attempt: int
     * }
     */
    public function createCheckout(
        string $invoiceId,
        string|int|float $total,
        string $currency,
        ?string $clientId = null
    ): array {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            throw new \InvalidArgumentException('Paymenter invoice ID is required.');
        }

        $amount = AmountGuard::normalizeDecimal($total);
        if ($amount === null || $amount === '0' || $amount[0] === '-') {
            throw new \InvalidArgumentException('Invoice amount must be a positive decimal number.');
        }

        $normalizedCurrency = strtoupper(trim($currency));
        if ($normalizedCurrency === '') {
            throw new \InvalidArgumentException('Invoice currency is required.');
        }

        $normalizedClientId = null;
        if ($clientId !== null) {
            $trimmedClient = trim($clientId);
            if ($trimmedClient !== '') {
                if (str_contains($trimmedClient, '@')) {
                    throw new \InvalidArgumentException('client_id must be a native user identifier, never an email address.');
                }
                $normalizedClientId = $trimmedClient;
            }
        }

        $now = $this->now();
        $renewalAttempt = $this->resolveRenewalAttempt($invoiceId, $amount, $normalizedCurrency, $now);
        $externalOrderId = $this->buildExternalOrderId($invoiceId, $amount, $normalizedCurrency, $renewalAttempt);

        $payload = [
            'project_id' => $this->config->projectId(),
            'amount' => $amount,
            'currency' => $normalizedCurrency,
            'external_order_id' => $externalOrderId,
        ];
        if ($normalizedClientId !== null) {
            $payload['client_id'] = $normalizedClientId;
        }

        try {
            $response = $this->client->invoices()->create($payload);
        } catch (ApiException $e) {
            $safe = $this->formatApiException('Paymos invoice creation failed', $e);
            $this->log('error', $safe, ['platform_ref' => $invoiceId, 'status' => $e->statusCode()]);
            throw new \RuntimeException($safe, $e->statusCode(), $e);
        } catch (\Throwable $e) {
            $safe = $this->config->sanitizeMessage($e->getMessage());
            $this->log('error', 'Paymos invoice creation failed: ' . $safe, ['platform_ref' => $invoiceId]);
            throw new \RuntimeException('Paymos invoice creation failed: ' . $safe, 0, $e);
        }

        $paymosInvoiceId = isset($response['invoice_id']) && is_string($response['invoice_id'])
            ? trim($response['invoice_id'])
            : '';
        $rawPaymentUrl = isset($response['payment_url']) && is_string($response['payment_url'])
            ? $response['payment_url']
            : '';
        $paymentUrl = trim($rawPaymentUrl, ' ');

        if ($paymosInvoiceId === '' || !self::isValidPaymentUrl($paymentUrl)) {
            throw new \RuntimeException('Paymos API returned an invalid invoice response.');
        }

        $expiresAt = isset($response['expires_at']) && is_numeric($response['expires_at'])
            ? (int) $response['expires_at']
            : null;

        $this->snapshots->save([
            'external_order_id' => $externalOrderId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'platform_ref' => $invoiceId,
            'client_id' => $normalizedClientId,
            'payload_meta' => null,
            'amount' => $amount,
            'currency' => $normalizedCurrency,
            'environment' => $this->config->environment(),
            'project_id' => $this->config->projectId(),
            'payment_url' => $paymentUrl,
            'transaction_id' => null,
            'expires_at' => $expiresAt,
            'renewal_attempt' => $renewalAttempt,
            'final_status' => null,
            'updated_at' => $now,
        ]);

        $this->debugLog('Created Paymos checkout invoice.', [
            'platform_ref' => $invoiceId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'renewal_attempt' => $renewalAttempt,
        ]);

        return [
            'payment_url' => $paymentUrl,
            'invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'amount' => $amount,
            'currency' => $normalizedCurrency,
            'renewal_attempt' => $renewalAttempt,
        ];
    }

    /**
     * Full Paymos webhook verification, dedup, reverse-verification, AmountGuard,
     * rollback-guard, and commit/release pipeline.
     *
     * @param callable(string): ?array{id: string|int, amount: string, currency: string, is_paid: bool} $loadBillingInvoice
     * @param callable(string, string, string, array<string, mixed>): void                                $applyPayment
     * @param callable(string, string, array<string, mixed>): void|null                                   $recordNote
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handleWebhook(
        string $rawBody,
        string $signatureHeader,
        callable $loadBillingInvoice,
        callable $applyPayment,
        ?callable $recordNote = null
    ): array {
        $now = $this->now();

        try {
            $verifier = new WebhookVerifier($this->config->webhookSecret(), self::WEBHOOK_TOLERANCE_SECONDS);
            $processor = new WebhookEventProcessor($verifier, $this->eventStore, self::DEDUP_TTL_SECONDS);
            $decoded = $processor->process($signatureHeader, $rawBody, $now);
        } catch (SignatureMismatchException $e) {
            $this->log('warning', 'Rejected webhook: invalid signature.', []);
            return ['status' => 401, 'body' => ['error' => 'invalid_signature']];
        } catch (TimestampSkewException $e) {
            $this->log('warning', 'Rejected webhook: timestamp outside tolerance.', []);
            return ['status' => 401, 'body' => ['error' => 'timestamp_skew']];
        } catch (DuplicateEventException $e) {
            $this->debugLog('Ignored committed duplicate webhook.', []);
            return ['status' => 200, 'body' => ['status' => 'duplicate']];
        } catch (EventInProgressException $e) {
            $this->debugLog('Deferred in-flight webhook delivery.', []);
            return ['status' => 409, 'body' => ['error' => 'event_in_progress']];
        } catch (\RuntimeException $e) {
            $this->log('warning', 'Rejected malformed webhook payload: ' . $this->config->sanitizeMessage($e->getMessage()), []);
            return ['status' => 400, 'body' => ['error' => 'invalid_payload']];
        } catch (\Throwable $e) {
            $this->log('error', 'Webhook storage or verification error: ' . $this->config->sanitizeMessage($e->getMessage()), []);
            return ['status' => 500, 'body' => ['error' => 'processing_failed']];
        }

        $event = new WebhookEvent($decoded);
        $eventId = $event->id();

        try {
            if (!$event->isInvoiceEvent()) {
                return $this->finishWithCommit($eventId, ['status' => 'ignored']);
            }

            $isTest = $event->isTest();
            if ($isTest === null || $isTest !== $this->config->isSandbox()) {
                $this->safeRelease($eventId);
                $this->log('warning', 'Rejected webhook: environment mismatch.', ['event_id' => $eventId]);
                return ['status' => 400, 'body' => ['error' => 'environment_mismatch']];
            }

            if ($event->projectId() === '' || $event->projectId() !== $this->config->projectId()) {
                $this->safeRelease($eventId);
                $this->log('warning', 'Rejected webhook: project_id mismatch.', ['event_id' => $eventId]);
                return ['status' => 400, 'body' => ['error' => 'project_mismatch']];
            }

            $action = StatusMapper::invoiceAction($event->type(), $event->status());

            // invoice.paid_late is strictly note-only: no payment mutation, no reverse-verify.
            if ($action === StatusMapper::ACTION_NOTE_PAID_LATE) {
                $snapshot = $this->findSnapshotForEvent($event);
                $platformRef = $snapshot !== null ? (string) ($snapshot['platform_ref'] ?? '') : '';
                $shouldNote = true;
                if ($snapshot !== null) {
                    $shouldNote = $this->snapshots->markPaidLateNoted((string) $snapshot['external_order_id'], $eventId);
                }

                if ($shouldNote) {
                    $note = 'Paymos invoice ' . $event->invoiceId()
                        . ' received late payment after closing; review manually to fulfil or refund.';
                    $this->log('info', $note, [
                        'event_id' => $eventId,
                        'platform_ref' => $platformRef,
                        'paid_late_at' => $event->paidLateAt(),
                    ]);
                    if ($recordNote !== null) {
                        $recordNote($platformRef, $note, [
                            'action' => StatusMapper::ACTION_NOTE_PAID_LATE,
                            'event_id' => $eventId,
                            'invoice_id' => $event->invoiceId(),
                            'paid_late_at' => $event->paidLateAt(),
                        ]);
                    }
                }

                return $this->finishWithCommit($eventId, ['status' => 'paid_late_noted']);
            }

            $snapshot = $this->findSnapshotForEvent($event);
            if ($snapshot === null) {
                $this->safeRelease($eventId);
                $this->log('warning', 'Rejected webhook: invoice snapshot not found.', ['event_id' => $eventId]);
                return ['status' => 400, 'body' => ['error' => 'snapshot_not_found']];
            }

            if (!$this->snapshotMatchesEvent($snapshot, $event)) {
                $this->safeRelease($eventId);
                $this->log('warning', 'Rejected webhook: snapshot identity mismatch.', ['event_id' => $eventId]);
                return ['status' => 400, 'body' => ['error' => 'snapshot_mismatch']];
            }

            $platformRef = (string) $snapshot['platform_ref'];
            $billingInvoice = $loadBillingInvoice($platformRef);
            if (!is_array($billingInvoice)) {
                $this->safeRelease($eventId);
                $this->log('warning', 'Rejected webhook: Paymenter invoice not found.', [
                    'event_id' => $eventId,
                    'platform_ref' => $platformRef,
                ]);
                return ['status' => 400, 'body' => ['error' => 'invoice_not_found']];
            }

            // Roll-back guard: finalized snapshot or already-paid billing invoice never rolls back or double-credits.
            $snapshotFinal = (string) ($snapshot['final_status'] ?? '');
            $snapshotIsFinal = $snapshotFinal !== '' && StatusMapper::isFinalStatus($snapshotFinal);
            $billingIsPaid = !empty($billingInvoice['is_paid']);

            if ($snapshotIsFinal || $billingIsPaid) {
                $note = 'Ignored out-of-order or rollback event (' . $event->type() . ') for finalized/paid invoice.';
                $this->log('info', $note, ['event_id' => $eventId, 'platform_ref' => $platformRef]);
                if ($recordNote !== null) {
                    $recordNote($platformRef, $note, [
                        'event_id' => $eventId,
                        'event_type' => $event->type(),
                    ]);
                }

                return $this->finishWithCommit($eventId, ['status' => 'ignored_final']);
            }

            // Reverse-verify all terminal actions directly against Paymos API.
            $verifiedInvoice = null;
            if ($this->isTerminalAction($action)) {
                $verification = (new InvoiceReverseVerifier($this->client))->verify($event, [
                    'invoice_id' => (string) $snapshot['paymos_invoice_id'],
                    'project_id' => (string) $snapshot['project_id'],
                    'external_order_id' => (string) $snapshot['external_order_id'],
                    'amount' => (string) $snapshot['amount'],
                    'currency' => (string) $snapshot['currency'],
                    'environment' => (string) $snapshot['environment'],
                ]);

                if (!$verification->isVerified()) {
                    $reason = $verification->reason();
                    $note = 'manual_review: Paymos reverse verification failed (' . $reason . ').';
                    $this->log('warning', $note, [
                        'event_id' => $eventId,
                        'platform_ref' => $platformRef,
                        'reason' => $reason,
                    ]);
                    if ($recordNote !== null) {
                        $recordNote($platformRef, $note, [
                            'action' => StatusMapper::ACTION_MANUAL_REVIEW,
                            'reason' => $reason,
                        ]);
                    }
                    $this->safeRelease($eventId);
                    return [
                        'status' => 400,
                        'body' => [
                            'error' => 'reverse_verification_failed',
                            'reason' => $reason,
                        ],
                    ];
                }

                $verifiedInvoice = $verification->invoice();
            }

            if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
                if ($action === StatusMapper::ACTION_FAIL_ORDER || $action === StatusMapper::ACTION_CANCEL_ORDER) {
                    $finalStatus = $event->status() !== ''
                        ? $event->status()
                        : ($action === StatusMapper::ACTION_FAIL_ORDER ? 'underpaid' : 'cancelled');
                    $this->snapshots->markFinalStatus((string) $snapshot['external_order_id'], $finalStatus);
                }

                return $this->finishWithCommit($eventId, [
                    'status' => 'processed',
                    'action' => $action,
                ]);
            }

            // AmountGuard triangulation: snapshot <-> current billing invoice <-> webhook order.
            $currentAmount = (string) ($billingInvoice['amount'] ?? '');
            $currentCurrency = (string) ($billingInvoice['currency'] ?? '');

            $safeToComplete = AmountGuard::isSafeToComplete(
                (string) $snapshot['amount'],
                (string) $snapshot['currency'],
                $currentAmount,
                $currentCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            );

            if (!$safeToComplete) {
                $summary = AmountGuard::mismatchSummary(
                    (string) $snapshot['amount'],
                    (string) $snapshot['currency'],
                    $currentAmount,
                    $currentCurrency,
                    $event->orderAmount(),
                    $event->orderCurrency()
                );
                $this->log('warning', 'manual_review: ' . $summary, [
                    'event_id' => $eventId,
                    'platform_ref' => $platformRef,
                ]);
                if ($recordNote !== null) {
                    $recordNote($platformRef, 'manual_review: ' . $summary, [
                        'action' => StatusMapper::ACTION_MANUAL_REVIEW,
                        'event_id' => $eventId,
                    ]);
                }

                // Do NOT credit payment and do NOT close final_status; release lock so manual correction/retry works.
                $this->safeRelease($eventId);
                return [
                    'status' => 400,
                    'body' => [
                        'error' => 'manual_review',
                        'detail' => $summary,
                    ],
                ];
            }

            $transactionId = $this->extractTransactionId($event, $verifiedInvoice);
            $applyPayment($platformRef, (string) $snapshot['amount'], $transactionId, $decoded);

            $finalStatus = $event->status() !== '' ? $event->status() : 'paid';
            $this->snapshots->markCompleted((string) $snapshot['external_order_id'], $transactionId, $finalStatus);

            return $this->finishWithCommit($eventId, [
                'status' => 'completed',
                'transaction_id' => $transactionId,
                'invoice_id' => $event->invoiceId(),
            ]);
        } catch (ApiException $e) {
            $this->safeRelease($eventId);
            $safe = $this->formatApiException('Paymos API error during webhook handling', $e);
            $this->log('error', $safe, ['event_id' => $eventId, 'status' => $e->statusCode()]);
            $httpStatus = ($e->statusCode() >= 500 || $e->statusCode() === 429) ? 500 : 400;
            return ['status' => $httpStatus, 'body' => ['error' => 'api_error']];
        } catch (\Throwable $e) {
            $this->safeRelease($eventId);
            $safe = $this->config->sanitizeMessage($e->getMessage());
            $this->log('error', 'Paymos webhook processing failed: ' . $safe, ['event_id' => $eventId]);
            return ['status' => 500, 'body' => ['error' => 'processing_failed']];
        }
    }

    /**
     * Issue a full Paymos refund for a paid Paymenter transaction.
     *
     * @return array{withdrawal_id: string, invoice_id: string, status: string, refund: array<string, mixed>}
     */
    public function refundTransaction(
        string $transactionId,
        string|int|float $requestedAmount,
        ?string $platformRef = null,
        ?string $transactionAmount = null
    ): array {
        $snapshot = $this->resolveRefundSnapshot($transactionId, $platformRef);

        $normalizedRequested = AmountGuard::normalizeDecimal($requestedAmount);
        if ($normalizedRequested === null || $normalizedRequested === '0' || $normalizedRequested[0] === '-') {
            throw new \InvalidArgumentException('Refund amount must be a positive decimal number.');
        }

        $paidAmount = (string) ($snapshot['amount'] ?? '');
        if (!AmountGuard::amountsEqual($normalizedRequested, $paidAmount)) {
            throw new \InvalidArgumentException(
                'Paymos supports refunds only in "full" or "overpayment" mode; partial refund amounts are not supported.'
            );
        }

        if (
            $transactionAmount !== null
            && trim($transactionAmount) !== ''
            && !AmountGuard::amountsEqual($normalizedRequested, $transactionAmount)
        ) {
            throw new \InvalidArgumentException(
                'Paymos supports refunds only in "full" or "overpayment" mode; requested refund amount must match the paid transaction amount.'
            );
        }

        $paymosInvoiceId = trim((string) ($snapshot['paymos_invoice_id'] ?? ''));
        if ($paymosInvoiceId === '') {
            throw new \RuntimeException('Paymos invoice snapshot is missing paymos_invoice_id.');
        }

        $txSeed = trim($transactionId) !== '' ? trim($transactionId) : $paymosInvoiceId;
        $refundExternalOrderId = 'pmt-refund-' . $paymosInvoiceId . '-' . substr(hash('sha256', $txSeed . ':full'), 0, 12);

        try {
            $refund = $this->client->refunds()->create($paymosInvoiceId, 'full', $refundExternalOrderId);
        } catch (ApiException $e) {
            $safe = $this->formatApiException('Paymos refund request failed', $e);
            $this->log('error', $safe, ['invoice_id' => $paymosInvoiceId, 'status' => $e->statusCode()]);
            throw new \RuntimeException($safe, $e->statusCode(), $e);
        } catch (\Throwable $e) {
            $safe = $this->config->sanitizeMessage($e->getMessage());
            $this->log('error', 'Paymos refund request failed: ' . $safe, ['invoice_id' => $paymosInvoiceId]);
            throw new \RuntimeException('Paymos refund request failed: ' . $safe, 0, $e);
        }

        $withdrawalId = isset($refund['withdrawal_id']) && is_string($refund['withdrawal_id'])
            ? trim($refund['withdrawal_id'])
            : '';
        if ($withdrawalId === '') {
            throw new \RuntimeException('Paymos refund response is missing required withdrawal_id.');
        }

        $status = isset($refund['status']) && is_string($refund['status'])
            ? strtolower(trim($refund['status']))
            : '';
        if ($status === 'failed' || $status === 'cancelled') {
            throw new \RuntimeException('Paymos refund was not accepted (status: ' . $status . ').');
        }
        if (!in_array($status, ['pending', 'processing', 'completed'], true)) {
            throw new \RuntimeException('Paymos refund returned an unrecognized status.');
        }

        if (isset($refund['invoice_id']) && is_string($refund['invoice_id']) && trim($refund['invoice_id']) !== $paymosInvoiceId) {
            throw new \RuntimeException('Paymos refund response invoice_id mismatch.');
        }

        $this->debugLog('Created Paymos full refund.', [
            'invoice_id' => $paymosInvoiceId,
            'withdrawal_id' => $withdrawalId,
            'status' => $status,
        ]);

        return [
            'withdrawal_id' => $withdrawalId,
            'invoice_id' => $paymosInvoiceId,
            'status' => $status,
            'refund' => $refund,
        ];
    }

    private function resolveRenewalAttempt(string $invoiceId, string $amount, string $currency, int $now): int
    {
        $existing = $this->snapshots->findLatestForPlatformRef(
            $invoiceId,
            $amount,
            $currency,
            $this->config->environment(),
            $this->config->projectId()
        );
        if ($existing === null) {
            return 0;
        }

        $attempt = max(0, (int) ($existing['renewal_attempt'] ?? 0));
        $finalStatus = trim((string) ($existing['final_status'] ?? ''));

        if ($finalStatus !== '') {
            $needsRenewal = InvoiceRenewal::isRequired([
                'status' => $finalStatus,
                'expires_at' => $existing['expires_at'] ?? null,
            ], $now);

            return $needsRenewal ? $attempt + 1 : $attempt;
        }

        $snapshotProbe = [
            'status' => 'awaiting_client',
            'expires_at' => $existing['expires_at'] ?? null,
        ];

        if (InvoiceRenewal::isRequired($snapshotProbe, $now) && !empty($existing['paymos_invoice_id'])) {
            try {
                $liveInvoice = $this->client->invoices()->get((string) $existing['paymos_invoice_id']);
                if (InvoiceRenewal::isRequired($liveInvoice, $now)) {
                    return $attempt + 1;
                }
            } catch (\Throwable $e) {
                // Fail-safe: do not mint a duplicate invoice beside an uncertain payable invoice.
                $this->log('warning', 'Unable to verify existing Paymos invoice expiry before renewal: ' . $this->config->sanitizeMessage($e->getMessage()), [
                    'paymos_invoice_id' => (string) $existing['paymos_invoice_id'],
                ]);
            }
        }

        return $attempt;
    }

    private function buildExternalOrderId(string $invoiceId, string $amount, string $currency, int $renewalAttempt): string
    {
        $sanitizedId = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoiceId);
        $sanitizedId = is_string($sanitizedId) && $sanitizedId !== '' ? $sanitizedId : 'inv';
        $hash = substr(hash('sha256', $amount . ':' . $currency), 0, 12);
        $suffix = $renewalAttempt > 0 ? '-r' . $renewalAttempt : '';
        $maxIdLen = 128 - strlen('pmt-') - strlen('-') - strlen($hash) - strlen($suffix);
        if (strlen($sanitizedId) > $maxIdLen) {
            $sanitizedId = substr($sanitizedId, 0, max(1, $maxIdLen));
        }

        return 'pmt-' . $sanitizedId . '-' . $hash . $suffix;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSnapshotForEvent(WebhookEvent $event): ?array
    {
        $externalOrderId = $event->externalOrderId();
        if ($externalOrderId !== '') {
            $snapshot = $this->snapshots->findByExternalOrderId($externalOrderId);
            if ($snapshot !== null) {
                return $snapshot;
            }
        }

        $paymosInvoiceId = $event->invoiceId();
        if ($paymosInvoiceId !== '') {
            return $this->snapshots->findByPaymosInvoiceId($paymosInvoiceId);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function snapshotMatchesEvent(array $snapshot, WebhookEvent $event): bool
    {
        if ((string) ($snapshot['environment'] ?? '') !== $this->config->environment()) {
            return false;
        }
        if ((string) ($snapshot['project_id'] ?? '') !== $event->projectId()) {
            return false;
        }
        if ($event->externalOrderId() !== '' && (string) ($snapshot['external_order_id'] ?? '') !== $event->externalOrderId()) {
            return false;
        }
        if ($event->invoiceId() !== '' && (string) ($snapshot['paymos_invoice_id'] ?? '') !== $event->invoiceId()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRefundSnapshot(string $transactionId, ?string $platformRef): array
    {
        $txId = trim($transactionId);
        if ($txId !== '') {
            $byTx = $this->snapshots->findByTransactionId($txId);
            if ($byTx !== null) {
                return $byTx;
            }

            $byInvoiceId = $this->snapshots->findByPaymosInvoiceId($txId);
            if ($byInvoiceId !== null) {
                return $byInvoiceId;
            }
        }

        if ($platformRef !== null && trim($platformRef) !== '') {
            $paidSnapshots = $this->snapshots->findPaidByPlatformRef(trim($platformRef));
            if (count($paidSnapshots) === 1) {
                return $paidSnapshots[0];
            }
            if (count($paidSnapshots) > 1) {
                throw new \RuntimeException(
                    'Ambiguous refund lookup: multiple paid Paymos snapshots exist for this invoice; matching transaction_id is required.'
                );
            }
        }

        throw new \RuntimeException('No matching Paymos invoice snapshot found for refund transaction.');
    }

    /**
     * Extract the last confirmed tx_hash from the webhook payload, falling back to
     * the reverse-verified live API invoice before falling back to invoice_id.
     *
     * @param array<string, mixed>|null $verifiedInvoice
     */
    private function extractTransactionId(WebhookEvent $event, ?array $verifiedInvoice = null): string
    {
        $payload = $event->toArray();
        $fromEvent = self::extractConfirmedTransferHash($payload['data']['payment']['transfers'] ?? null);
        if ($fromEvent !== '') {
            return $fromEvent;
        }

        if (is_array($verifiedInvoice)) {
            $fromVerified = self::extractConfirmedTransferHash($verifiedInvoice['payment']['transfers'] ?? null);
            if ($fromVerified !== '') {
                return $fromVerified;
            }
        }

        return $event->invoiceId();
    }

    private static function extractConfirmedTransferHash(mixed $transfers): string
    {
        $confirmedHash = '';
        if (!is_array($transfers)) {
            return '';
        }

        foreach ($transfers as $transfer) {
            if (!is_array($transfer)) {
                continue;
            }
            $status = strtolower(trim((string) ($transfer['status'] ?? '')));
            $txHash = trim((string) ($transfer['tx_hash'] ?? ''));
            if ($status === 'confirmed' && $txHash !== '') {
                $confirmedHash = $txHash;
            }
        }

        return $confirmedHash;
    }

    private static function isValidPaymentUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        return $scheme === 'https' && $host !== '';
    }

    private function isTerminalAction(string $action): bool
    {
        return in_array($action, [
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ], true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function finishWithCommit(string $eventId, array $body): array
    {
        if (!$this->eventStore->commit($eventId)) {
            $this->log('warning', 'Webhook reservation ownership lost before commit.', ['event_id' => $eventId]);
            return ['status' => 409, 'body' => ['error' => 'lock_lost']];
        }

        return ['status' => 200, 'body' => $body];
    }

    private function safeRelease(string $eventId): void
    {
        try {
            $this->eventStore->release($eventId);
        } catch (\Throwable $e) {
            // Preserve the primary error response; owner-fenced reservation expires automatically.
        }
    }

    private function formatApiException(string $prefix, ApiException $e): string
    {
        $detail = $e->detail();
        $code = $e->errorCode();
        $parts = [$prefix . ' (HTTP ' . $e->statusCode() . ')'];
        if ($code !== '') {
            $parts[] = '[' . $code . ']';
        }
        $summary = ($detail !== null && trim($detail) !== '') ? trim($detail) : $e->getMessage();
        $parts[] = $summary;

        return $this->config->sanitizeMessage(implode(' ', $parts));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function debugLog(string $message, array $context): void
    {
        if (!$this->config->debugLogging()) {
            return;
        }
        $this->log('debug', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        $safeMessage = $this->config->sanitizeMessage($message);
        $safeContext = [];
        foreach ($context as $key => $value) {
            $safeContext[$key] = is_string($value) ? $this->config->sanitizeMessage($value) : $value;
        }

        ($this->logger)($level, $safeMessage, $safeContext);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
