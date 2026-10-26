<?php
// src/Services/PaymentService.php

namespace App\Services;

use App\Helpers\Config;
use App\Repositories\DatabaseLock;
use App\Repositories\PaymentLedgerRepository;
use App\Repositories\PaymentProofRepository;
use App\Repositories\ReservationRepository;
use InvalidArgumentException;

/** Modules 5 and 6: proof review and a small, auditable payment ledger. */
class PaymentService
{
    private ReservationRepository $reservations;
    private PaymentProofRepository $proofs;
    private PaymentLedgerRepository $payments;

    public function __construct(?ReservationRepository $reservations = null)
    {
        $this->reservations = $reservations ?? new ReservationRepository();
        $this->proofs = new PaymentProofRepository();
        $this->payments = new PaymentLedgerRepository();
    }

    public function submitProof(int $reservationId, string $email, float $amount, array $file): array
    {
        $upload = (new FileUploadValidator())->store($file);
        try {
            return DatabaseLock::withLock(function () use ($reservationId, $email, $amount, $upload): array {
                $reservations = $this->reservations->all();
                $index = $this->reservationIndex($reservations, $reservationId);
                if ($index === null || strcasecmp((string) $reservations[$index]['guest_email'], trim($email)) !== 0) {
                    throw new InvalidArgumentException('Reservation reference or email did not match.');
                }
                $reservation = $reservations[$index];
                if (($reservation['status'] ?? '') === ReservationRepository::STATUS_CANCELLED) {
                    throw new InvalidArgumentException('A cancelled reservation cannot receive a payment proof.');
                }
                $balance = max(0.0, (float) ($reservation['total'] ?? 0) - (float) ($reservation['amount_paid'] ?? 0));
                if (!is_finite($amount) || $amount <= 0 || $amount > $balance + 0.001) {
                    throw new InvalidArgumentException('Enter an amount greater than zero and no more than the remaining balance.');
                }
                $proofRecords = $this->proofs->read();
                foreach ($proofRecords as $proof) {
                    if ((int) ($proof['reservation_id'] ?? 0) === $reservationId && ($proof['status'] ?? '') === 'pending') {
                        throw new InvalidArgumentException('A proof is already awaiting staff review.');
                    }
                }
                $id = $this->nextId($proofRecords);
                $record = [
                    'id' => $id,
                    'reservation_id' => $reservationId,
                    'reservation_reference' => (string) $reservation['reference'],
                    'amount' => round($amount, 2),
                    'filename' => $upload['filename'],
                    'mime' => $upload['mime'],
                    'original_name' => $upload['original_name'],
                    'status' => 'pending',
                    'previous_reservation_status' => (string) ($reservation['status'] ?? ReservationRepository::STATUS_PENDING_PAYMENT),
                    'review_note' => '',
                    'created_at' => date('c'),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ];
                $proofRecords[] = $record;
                $this->proofs->replace($proofRecords);
                $reservations[$index]['status'] = ReservationRepository::STATUS_PENDING_VERIFICATION;
                $reservations[$index]['updated_at'] = date('c');
                $this->reservations->writeAll($reservations);
                unset($record['filename']);
                return $record;
            });
        } catch (\Throwable $error) {
            @unlink((string) Config::get('uploads.dir') . '/' . $upload['filename']);
            throw $error;
        }
    }

    public function review(int $proofId, string $decision, string $note, string $reviewer): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }
        return DatabaseLock::withLock(function () use ($proofId, $decision, $note, $reviewer): array {
            $proofs = $this->proofs->read();
            $proofIndex = $this->indexById($proofs, $proofId);
            if ($proofIndex === null || ($proofs[$proofIndex]['status'] ?? '') !== 'pending') {
                throw new InvalidArgumentException('That proof is no longer awaiting review.');
            }
            $proof = $proofs[$proofIndex];
            $reservations = $this->reservations->all();
            $reservationIndex = $this->reservationIndex($reservations, (int) $proof['reservation_id']);
            if ($reservationIndex === null) {
                throw new InvalidArgumentException('The linked reservation no longer exists.');
            }
            $reservation = $reservations[$reservationIndex];
            if ($decision === 'approve' && ($reservation['status'] ?? '') === ReservationRepository::STATUS_CANCELLED) {
                throw new InvalidArgumentException('A cancelled reservation cannot be approved.');
            }
            $proofs[$proofIndex]['status'] = $decision === 'approve' ? 'approved' : 'rejected';
            $proofs[$proofIndex]['review_note'] = $note;
            $proofs[$proofIndex]['reviewed_at'] = date('c');
            $proofs[$proofIndex]['reviewed_by'] = $reviewer;
            if ($decision === 'approve') {
                $currentBalance = max(0.0, (float) ($reservation['total'] ?? 0) - (float) ($reservation['amount_paid'] ?? 0));
                if ((float) $proof['amount'] > $currentBalance + 0.001) {
                    throw new InvalidArgumentException('This proof now exceeds the remaining balance. Resolve the balance before approving it.');
                }
                $payments = $this->payments->read();
                $payments[] = $this->paymentRecord(
                    $this->nextId($payments), (int) $reservation['id'], (string) $reservation['reference'],
                    (float) $proof['amount'], (string) ($reservation['payment_method'] ?? 'manual'),
                    'proof', (int) $proof['id'], $reviewer
                );
                $this->payments->replace($payments);
                $reservations[$reservationIndex] = $this->applyBalance($reservation, $payments);
            } else {
                if (($reservation['status'] ?? '') !== ReservationRepository::STATUS_CANCELLED) {
                    $previous = (string) ($proof['previous_reservation_status'] ?? ReservationRepository::STATUS_PENDING_PAYMENT);
                    $reservations[$reservationIndex]['status'] = in_array($previous, ReservationRepository::BLOCKING_STATUSES, true)
                        ? $previous : ReservationRepository::STATUS_PENDING_PAYMENT;
                }
            }
            $reservations[$reservationIndex]['updated_at'] = date('c');
            $this->proofs->replace($proofs);
            $this->reservations->writeAll($reservations);
            unset($proofs[$proofIndex]['filename']);
            return ['proof' => $proofs[$proofIndex], 'reservation' => $reservations[$reservationIndex]];
        });
    }

    public function recordPayment(int $reservationId, float $amount, string $method, string $actor, string $note = ''): array
    {
        return DatabaseLock::withLock(function () use ($reservationId, $amount, $method, $actor, $note): array {
            $reservations = $this->reservations->all();
            $index = $this->reservationIndex($reservations, $reservationId);
            if ($index === null) {
                throw new InvalidArgumentException('Reservation not found.');
            }
            $reservation = $reservations[$index];
            if (($reservation['status'] ?? '') === ReservationRepository::STATUS_CANCELLED) {
                throw new InvalidArgumentException('A cancelled reservation cannot receive a payment.');
            }
            foreach ($this->proofs->read() as $proof) {
                if ((int) ($proof['reservation_id'] ?? 0) === $reservationId && ($proof['status'] ?? '') === 'pending') {
                    throw new InvalidArgumentException('Review the pending receipt before recording another payment.');
                }
            }
            $balance = max(0.0, (float) ($reservation['total'] ?? 0) - (float) ($reservation['amount_paid'] ?? 0));
            if (!is_finite($amount) || $amount <= 0 || $amount > $balance + 0.001) {
                throw new InvalidArgumentException('Payment amount must be greater than zero and no more than the remaining balance.');
            }
            $allowed = array_column(Config::get('payment_methods', []), 'code');
            if (!in_array($method, $allowed, true)) {
                throw new InvalidArgumentException('Choose one of the configured payment methods.');
            }
            $payments = $this->payments->read();
            $payments[] = $this->paymentRecord(
                $this->nextId($payments), $reservationId, (string) $reservation['reference'],
                $amount, $method, 'staff_recorded', null, $actor, $note
            );
            $this->payments->replace($payments);
            $reservations[$index] = $this->applyBalance($reservation, $payments);
            $reservations[$index]['updated_at'] = date('c');
            $this->reservations->writeAll($reservations);
            return ['reservation' => $reservations[$index], 'payment' => end($payments)];
        });
    }

    public function proofs(?string $status = null): array
    {
        $items = $this->proofs->read();
        if ($status !== null) {
            $items = array_values(array_filter($items, static fn (array $p): bool => ($p['status'] ?? '') === $status));
        }
        usort($items, static fn (array $a, array $b): int => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        return array_map(static function (array $p): array { unset($p['filename']); return $p; }, $items);
    }

    public function proofFile(int $proofId): ?array
    {
        foreach ($this->proofs->read() as $proof) {
            if ((int) ($proof['id'] ?? 0) === $proofId) {
                $path = (string) Config::get('uploads.dir') . '/' . basename((string) $proof['filename']);
                return is_file($path) ? ['path' => $path, 'mime' => (string) $proof['mime']] : null;
            }
        }
        return null;
    }

    public function ledger(?int $reservationId = null): array
    {
        $items = $this->payments->read();
        if ($reservationId !== null) {
            $items = array_values(array_filter($items, static fn (array $p): bool => (int) ($p['reservation_id'] ?? 0) === $reservationId));
        }
        usort($items, static fn (array $a, array $b): int => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        return $items;
    }

    private function paymentRecord(int $id, int $reservationId, string $reference, float $amount, string $method, string $source, ?int $proofId, string $actor, string $note = ''): array
    {
        return ['id' => $id, 'reservation_id' => $reservationId, 'reservation_reference' => $reference,
            'amount' => round($amount, 2), 'method' => $method, 'source' => $source, 'proof_id' => $proofId,
            'note' => $note, 'recorded_by' => $actor, 'created_at' => date('c')];
    }

    private function applyBalance(array $reservation, array $payments): array
    {
        $paid = 0.0;
        foreach ($payments as $payment) {
            if ((int) ($payment['reservation_id'] ?? 0) === (int) $reservation['id']) $paid += (float) ($payment['amount'] ?? 0);
        }
        $reservation['amount_paid'] = round($paid, 2);
        $reservation['balance_due'] = round(max(0.0, (float) ($reservation['total'] ?? 0) - $paid), 2);
        $reservation['status'] = $paid + 0.001 >= (float) ($reservation['deposit_due'] ?? 0)
            ? ReservationRepository::STATUS_CONFIRMED
            : ReservationRepository::STATUS_PENDING_PAYMENT;
        return $reservation;
    }

    private function reservationIndex(array $items, int $id): ?int
    {
        foreach ($items as $index => $item) if ((int) ($item['id'] ?? 0) === $id) return $index;
        return null;
    }

    private function indexById(array $items, int $id): ?int
    {
        foreach ($items as $index => $item) if ((int) ($item['id'] ?? 0) === $id) return $index;
        return null;
    }

    private function nextId(array $items): int
    {
        return 1 + array_reduce($items, static fn (int $max, array $item): int => max($max, (int) ($item['id'] ?? 0)), 0);
    }
}
