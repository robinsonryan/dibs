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
        $name = TablePrefixer::prefix('offers');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            // the only lookup key a link carries
            $table->string('token')->unique();
            $table->string('offered_to_type');
            $table->string('offered_to_id');
            $table->string('created_by_type')->nullable();
            $table->string('created_by_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('status')->default('pending');
            $table->foreignUuid('accepted_booking_id')
                ->nullable()
                ->constrained(TablePrefixer::prefix('bookings'))
                ->nullOnDelete();
            $table->text('message')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['offered_to_type', 'offered_to_id'], $name.'_offered_to_index');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('offers'));
    }
};
