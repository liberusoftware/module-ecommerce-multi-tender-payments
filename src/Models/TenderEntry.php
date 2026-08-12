<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderEffect;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderLedgerIsAppendOnly;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * One entry in the append-only tender ledger.
 *
 * This is also the allocation record the host never had: it says which tender
 * covered which portion of which plan, in which declared position, with which
 * external reference. An outstanding balance can therefore be computed rather
 * than asserted by a status string.
 *
 * There is no update path and no delete path. The hooks below refuse both, and
 * because model events never fire for a mass query-builder write, an
 * architecture test also proves this package's own source issues none.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $position
 * @property TenderKind $kind
 * @property TenderEffect $effect
 * @property int $amount_minor
 * @property int $requested_minor
 * @property string|null $external_reference
 * @property string|null $instalment_reference
 * @property int|null $reverses_tender_id
 * @property string|null $reason
 * @property string|null $idempotency_key
 * @property string|null $payload_hash
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentPlan $plan
 * @property-read TenderEntry|null $reversal
 * @property-read TenderEntry|null $reverses
 */
final class TenderEntry extends Model
{
    protected $table = 'multi_tender_payment_tenders';

    protected $guarded = [];

    /**
     * Mirrors of the column defaults, because `create()` does not read a column
     * default back onto the instance it returns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'effect' => 'captured',
        'position' => 0,
        'amount_minor' => 0,
        'requested_minor' => 0,
    ];

    /** @return BelongsTo<PaymentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'plan_id');
    }

    /**
     * The entry that reversed this one, if any.
     *
     * @return HasOne<TenderEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_tender_id');
    }

    /**
     * The entry this one reverses, if it is a reversal.
     *
     * @return BelongsTo<TenderEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_tender_id');
    }

    /** What this entry contributed, in the plan's currency. */
    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->plan->currency, $this->plan->currency_exponent);
    }

    /** Whether the tender was spent short of what the caller asked of it. */
    public function isPartlySpent(): bool
    {
        return $this->amount_minor < $this->requested_minor;
    }

    protected static function booted(): void
    {
        self::updating(static function (self $entry): void {
            throw new TenderLedgerIsAppendOnly(
                "Tender [{$entry->id}] cannot be updated: the ledger is append-only. Record a reversal instead."
            );
        });

        self::deleting(static function (self $entry): void {
            throw new TenderLedgerIsAppendOnly(
                "Tender [{$entry->id}] cannot be deleted: the ledger is append-only. Record a reversal instead."
            );
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TenderKind::class,
            'effect' => TenderEffect::class,
            'plan_id' => 'integer',
            'position' => 'integer',
            'amount_minor' => 'integer',
            'requested_minor' => 'integer',
            'reverses_tender_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
