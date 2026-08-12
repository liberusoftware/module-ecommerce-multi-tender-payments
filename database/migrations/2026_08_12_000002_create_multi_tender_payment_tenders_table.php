<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only tender ledger, and the allocation record the host lacked.
 *
 * Every money column is an integer of minor units. No `decimal`, no `float`,
 * no `double` — the host's `orders.total_amount decimal(10,2)` is the shape
 * that drifts once a total is split across N tenders and reconciled.
 *
 * The table has no updated-in-place semantics. A reversal is a new row
 * pointing at the row it reverses, because the capture it undoes happened at
 * an institution and cannot be un-happened by editing a row here.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('multi_tender_payment_tenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('multi_tender_payment_plans')->cascadeOnDelete();

            // The order the caller declared. No tender kind has a priority of
            // its own, so the declared order is the only order there is, and it
            // is recorded rather than inferred.
            $table->unsignedInteger('position')->default(0);

            $table->string('kind');
            $table->string('effect')->default('captured');

            $table->integer('amount_minor')->default(0);
            $table->integer('requested_minor')->default(0);

            // Whatever the module that actually moved the money handed back.
            $table->string('external_reference')->nullable();

            // An instalment is a reference and nothing more. This module runs no
            // scheduler, holds no authoritative due date and chases nobody.
            $table->string('instalment_reference')->nullable();

            $table->foreignId('reverses_tender_id')->nullable()->constrained('multi_tender_payment_tenders')->cascadeOnDelete();
            $table->string('reason')->nullable();

            $table->string('idempotency_key')->nullable()->unique();
            $table->string('payload_hash')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_tender_payment_tenders');
    }
};
