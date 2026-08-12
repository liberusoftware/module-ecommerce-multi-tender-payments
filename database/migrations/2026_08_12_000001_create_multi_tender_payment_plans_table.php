<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plan an order's tenders hang off.
 *
 * A package-invented table, so it carries the module prefix. It adopts nothing
 * from the host: the host's `orders.payment_method`, `orders.transaction_id`
 * and the two `payment_status` string columns are exactly the shapes this
 * module exists to replace, and adopting a wrong table would carry the fault
 * forward.
 *
 * There is no status column here, and there is no `amount_paid`. Both would be
 * a second copy of something the ledger already knows.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('multi_tender_payment_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('order_reference')->unique();

            // The currency has one origin: the payable total the host resolved.
            // Never a caller's, never a default, and never converted.
            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_exponent')->default(2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_tender_payment_plans');
    }
};
