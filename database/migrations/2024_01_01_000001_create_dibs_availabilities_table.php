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
        Schema::create(TablePrefixer::prefix('availabilities'), function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('context_type')->nullable();
            $table->string('context_id')->nullable();
            $table->string('type')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedSmallInteger('slot_duration_minutes');
            $table->unsignedSmallInteger('slot_padding_minutes')->default(0);
            $table->unsignedInteger('min_notice_minutes')->nullable();
            $table->unsignedSmallInteger('max_horizon_days')->nullable();
            $table->string('status')->default('draft');
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['context_type', 'context_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('availabilities'));
    }
};
