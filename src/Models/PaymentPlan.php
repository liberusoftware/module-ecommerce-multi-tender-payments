<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * The plan an order's tenders hang off.
 *
 * Deliberately almost empty. There is no status column, no `amount_paid`, and
 * no cached total: a mutable balance is a defect, and everything anyone wants
 * to know about this plan is a fold over its tenders. The currency lives here
 * and only here, so a tender cannot disagree with the order it belongs to.
 *
 * @property int $id
 * @property string $order_reference
 * @property string $currency
 * @property int $currency_exponent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TenderEntry> $tenders
 */
final class PaymentPlan extends Model
{
    protected $table = 'multi_tender_payment_plans';

    protected $guarded = [];

    /**
     * A column default is not read back by `create()` — the model returned
     * carries null until it is refreshed from the database. Declaring it here
     * as well is what makes the freshly created instance honest.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency_exponent' => 2,
    ];

    /** @return HasMany<TenderEntry, $this> */
    public function tenders(): HasMany
    {
        return $this->hasMany(TenderEntry::class, 'plan_id');
    }

    /** A zero amount in this plan's currency, to build others from. */
    public function zero(): Money
    {
        return new Money(0, $this->currency, $this->currency_exponent);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency_exponent' => 'integer'];
    }
}
