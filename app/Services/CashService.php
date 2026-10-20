<?php

namespace App\Services;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementDirection;
use App\Enums\CashSessionStatus;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CashService
{
    public function currentOpenSession(): ?CashSession
    {
        return CashSession::query()->open()->latest('id')->first();
    }

    public function requireOpenSession(): CashSession
    {
        $session = $this->currentOpenSession();

        if (! $session instanceof CashSession) {
            throw new DomainException('Chưa mở ca quỹ tiền mặt. Vào Thu ngân → Sổ quỹ để mở ca.');
        }

        return $session;
    }

    public function openSession(int $userId, int|float|string $openingBalance, ?string $note = null): CashSession
    {
        return DB::transaction(function () use ($userId, $openingBalance, $note): CashSession {
            $existing = CashSession::query()->open()->lockForUpdate()->first();

            if ($existing instanceof CashSession) {
                throw new DomainException('Đang có ca quỹ mở (từ '.$existing->opened_at?->timezone((string) config('garage.timezone'))->format('d/m/Y H:i').'). Hãy đóng ca trước.');
            }

            $balance = round((float) $openingBalance, 2);

            if ($balance < 0) {
                throw new DomainException('Số dư đầu ca không được âm.');
            }

            return CashSession::query()->create([
                'opened_at' => now(),
                'opened_by' => $userId,
                'opening_balance' => $balance,
                'status' => CashSessionStatus::Open,
                'note' => filled($note) ? $note : null,
            ]);
        });
    }

    public function closeSession(CashSession $session, int $userId, int|float|string $closingCounted, ?string $note = null): CashSession
    {
        return DB::transaction(function () use ($session, $userId, $closingCounted, $note): CashSession {
            /** @var CashSession $locked */
            $locked = CashSession::query()->whereKey($session->id)->lockForUpdate()->with('movements')->firstOrFail();

            if ($locked->status !== CashSessionStatus::Open) {
                throw new DomainException('Ca đã đóng.');
            }

            $counted = round((float) $closingCounted, 2);

            if ($counted < 0) {
                throw new DomainException('Tiền đếm thực tế không được âm.');
            }

            $expected = $locked->computedExpectedBalance();

            $locked->closing_counted = $counted;
            $locked->expected_balance = $expected;
            $locked->difference = round($counted - $expected, 2);
            $locked->closed_at = now();
            $locked->closed_by = $userId;
            $locked->status = CashSessionStatus::Closed;

            if (filled($note)) {
                $locked->note = trim(($locked->note ? $locked->note."\n" : '').$note);
            }

            $locked->save();

            return $locked;
        });
    }

    public function recordMovement(
        CashSession $session,
        CashMovementDirection $direction,
        int|float|string $amount,
        CashMovementCategory $category,
        int $userId,
        ?Model $reference = null,
        ?string $note = null,
    ): CashMovement {
        $value = round((float) $amount, 2);

        if ($value <= 0) {
            throw new DomainException('Số tiền quỹ phải lớn hơn 0.');
        }

        return DB::transaction(function () use ($session, $direction, $value, $category, $userId, $reference, $note): CashMovement {
            /** @var CashSession $locked */
            $locked = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CashSessionStatus::Open) {
                throw new DomainException('Không ghi quỹ trên ca đã đóng.');
            }

            return CashMovement::query()->create([
                'cash_session_id' => $locked->id,
                'direction' => $direction,
                'amount' => $value,
                'category' => $category,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'occurred_at' => now(),
                'created_by' => $userId,
                'note' => filled($note) ? $note : null,
            ]);
        });
    }

    /**
     * Ghi thu tiền mặt vào ca đang mở (bắt buộc có ca).
     */
    public function recordInvoiceCashPayment(Invoice $invoice, float $amount, int $userId): CashMovement
    {
        $session = $this->requireOpenSession();

        return $this->recordMovement(
            $session,
            CashMovementDirection::In,
            $amount,
            CashMovementCategory::InvoicePayment,
            $userId,
            $invoice,
            'Thu HĐ '.$invoice->invoice_number.' — '.VietnameseMoney::format($amount),
        );
    }
}
