<?php

namespace App\Services;

use App\Enums\BankTransactionStatus;
use App\Enums\InvoicePayer;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Đối soát chuyển khoản: tiền về tài khoản → tìm mã lệnh/hóa đơn trong nội dung → ghi nhận thu tiền.
 *
 * Nội dung VietQR in trên hóa đơn là "TT <mã lệnh>"; ngân hàng hay bỏ dấu gạch/khoảng trắng nên so khớp
 * trên chuỗi đã bỏ hết ký tự đặc biệt.
 */
class BankReconciliationService
{
    public const PROVIDER_SEPAY = 'sepay';

    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly MessagingService $messaging,
    ) {}

    /**
     * Nhận một giao dịch theo định dạng webhook của SePay. Gửi lại cùng mã giao dịch không ghi nhận lần hai.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingestSepay(array $payload): BankTransaction
    {
        $providerId = (string) ($payload['id'] ?? '');
        $amount = (float) ($payload['transferAmount'] ?? 0);

        if ($providerId === '' || $amount <= 0) {
            throw new DomainException('Thiếu mã giao dịch hoặc số tiền.');
        }

        $existing = BankTransaction::query()->where('provider', self::PROVIDER_SEPAY)->where('provider_id', $providerId)->first();

        if ($existing instanceof BankTransaction) {
            return $existing;
        }

        $incoming = ($payload['transferType'] ?? 'in') === 'in';
        $transaction = BankTransaction::query()->create([
            'provider' => self::PROVIDER_SEPAY,
            'provider_id' => $providerId,
            'bank' => $payload['gateway'] ?? null,
            'account_number' => $payload['accountNumber'] ?? null,
            'amount' => $amount,
            'content' => $payload['content'] ?? ($payload['description'] ?? null),
            'reference_code' => $payload['referenceCode'] ?? null,
            'transacted_at' => $this->parseTime($payload['transactionDate'] ?? null),
            'status' => $incoming ? BankTransactionStatus::Unmatched : BankTransactionStatus::Ignored,
            'note' => $incoming ? null : 'Tiền ra khỏi tài khoản, không đối soát.',
            'payload' => $payload,
        ]);

        if ($incoming) {
            $this->autoMatch($transaction);
        }

        return $transaction->refresh();
    }

    public function autoMatch(BankTransaction $transaction): void
    {
        $candidates = $this->candidateInvoices($transaction);

        if ($candidates->count() === 1) {
            $this->matchToInvoice($transaction, $candidates->first());

            return;
        }

        $transaction->note = $candidates->isEmpty()
            ? 'Không thấy mã lệnh/hóa đơn còn nợ trong nội dung chuyển khoản.'
            : 'Nội dung khớp nhiều hóa đơn: '.$candidates->pluck('invoice_number')->implode(', ');
        $transaction->save();
    }

    /**
     * Hóa đơn khách còn nợ có mã lệnh hoặc số hóa đơn nằm trong nội dung chuyển khoản (ưu tiên mã dài nhất).
     *
     * @return Collection<int, Invoice>
     */
    public function candidateInvoices(BankTransaction $transaction): Collection
    {
        $content = self::clean($transaction->content);

        if ($content === '') {
            return collect();
        }

        $matches = Invoice::query()
            ->outstanding()
            ->forPayer(InvoicePayer::Customer)
            ->with('workOrder')
            ->get()
            ->map(function (Invoice $invoice) use ($content): ?array {
                $keys = array_filter([self::clean($invoice->workOrder?->code), self::clean($invoice->invoice_number)]);
                $hits = array_filter($keys, fn (string $key): bool => strlen($key) >= 6 && str_contains($content, $key));

                return $hits === [] ? null : ['invoice' => $invoice, 'length' => max(array_map('strlen', $hits))];
            })
            ->filter();

        if ($matches->isEmpty()) {
            return collect();
        }

        $longest = $matches->max('length');

        return $matches->where('length', $longest)->pluck('invoice')->values();
    }

    /**
     * Ghi nhận tiền vào hóa đơn; tiền dư (khách chuyển thừa) được ghi chú để kế toán xử lý.
     */
    public function matchToInvoice(BankTransaction $transaction, Invoice $invoice, ?int $userId = null): BankTransaction
    {
        $result = DB::transaction(function () use ($transaction, $invoice): array {
            $locked = BankTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BankTransactionStatus::Unmatched) {
                throw new DomainException('Giao dịch này đã được xử lý.');
            }

            $invoice->refresh();
            $outstanding = $invoice->outstandingAmount();

            if ($outstanding <= 0) {
                throw new DomainException("Hóa đơn {$invoice->invoice_number} đã thu đủ.");
            }

            $applied = min((float) $locked->amount, $outstanding);
            $this->workOrders->recordPayment(
                $invoice,
                $applied,
                'Chuyển khoản '.($locked->bank ?? '').' '.($locked->reference_code ?? $locked->provider_id),
            );

            $over = round((float) $locked->amount - $applied, 2);
            $locked->status = BankTransactionStatus::Matched;
            $locked->invoice_id = $invoice->id;
            $locked->matched_amount = $applied;
            $locked->note = $over > 0 ? 'Khách chuyển thừa '.VietnameseMoney::format($over).' — cần hoàn hoặc trừ lần sau.' : null;
            $locked->save();

            return ['transaction' => $locked, 'applied' => $applied];
        });

        $this->messaging->queuePaymentReceipt($invoice->refresh(), $result['applied']);

        return $result['transaction'];
    }

    public function ignore(BankTransaction $transaction, ?string $reason = null): void
    {
        if ($transaction->status !== BankTransactionStatus::Unmatched) {
            throw new DomainException('Chỉ bỏ qua giao dịch chưa gán.');
        }

        $transaction->status = BankTransactionStatus::Ignored;
        $transaction->note = filled($reason) ? $reason : 'Không phải tiền sửa xe.';
        $transaction->save();
    }

    /**
     * Payload giả lập đúng định dạng SePay, để chạy thử khi chưa nối ngân hàng.
     *
     * @return array<string, mixed>
     */
    public static function fakeSepayPayload(float $amount, string $content): array
    {
        return [
            'id' => 'sim-'.Str::lower(Str::random(10)),
            'gateway' => 'GiaLap',
            'transactionDate' => now((string) config('garage.timezone'))->format('Y-m-d H:i:s'),
            'accountNumber' => (string) config('garage.account_no'),
            'content' => $content,
            'transferType' => 'in',
            'transferAmount' => $amount,
            'referenceCode' => 'SIM'.Str::upper(Str::random(6)),
        ];
    }

    public static function clean(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $value)) ?? '';
    }

    private function parseTime(mixed $value): Carbon
    {
        try {
            return is_string($value) && $value !== ''
                ? Carbon::parse($value, (string) config('garage.timezone'))->utc()
                : now();
        } catch (Throwable) {
            return now();
        }
    }
}
