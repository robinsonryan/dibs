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
        Schema::create(TablePrefixer::prefix('slots'), function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            // null = adhoc (a direct booking or an offer's own slot)
            $table->foreignUuid('availability_id')
                ->nullable()
                ->constrained(TablePrefixer::prefix('availabilities'))
                ->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            // overrides the availability's; the source of truth for adhoc slots
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('status')->default('open');
            $table->timestampsTz();

            $table->index(['availability_id', 'status']);
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('slots'));
    }
};
