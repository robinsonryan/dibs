<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Dibs\Support\TablePrefixer;

return new class extends Migration
{
    public function up(): void
    {
        $name = TablePrefixer::prefix('bookings');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            // restrictOnDelete is what enforces D3: a slot with any booking row,
            // even a cancelled one, can never be deleted — bookings are history.
            $table->foreignUuid('slot_id')
                ->constrained(TablePrefixer::prefix('slots'))
                ->restrictOnDelete();
            // The owning scope, copied from the availability at creation (or
            // supplied for a direct booking) so every booking answers "whose is
            // this?" without a join — the D13 rule applied to tenancy.
            $table->string('context_type')->nullable();
            $table->string('context_id')->nullable();
            $table->string('booked_for_type');
            $table->string('booked_for_id');
            $table->string('booked_by_type');
            $table->string('booked_by_id');
            // consumer vocabulary, denormalised at creation (D13)
            $table->string('type')->nullable()->index();
            $table->string('status')->default('booked');
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_by_type')->nullable();
            $table->string('cancelled_by_id')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['booked_for_type', 'booked_for_id'], $name.'_booked_for_index');
            $table->index(['booked_by_type', 'booked_by_id'], $name.'_booked_by_index');
            $table->index(['slot_id', 'status']);
            $table->index(['context_type', 'context_id'], $name.'_context_index');
        });

        // The same person cannot hold two live claims on one slot.
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s_live_claim_unique ON %s (slot_id, booked_for_type, booked_for_id) WHERE status = %s',
            $name,
            $name,
            "'booked'",
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('bookings'));
    }
};
