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
        $name = TablePrefixer::prefix('series');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('context_type')->nullable();
            $table->string('context_id')->nullable();
            $table->string('title');
            // IANA zone: windows are wall-clock, so only the series knows what
            // "6 pm" means on a given date (the D10 exception, ruled 2026-09-03).
            $table->string('timezone');
            $table->string('cadence');
            $table->jsonb('ordinals')->default(DB::raw("'[]'::jsonb"));
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('slot_duration_minutes');
            $table->unsignedSmallInteger('slot_padding_minutes')->default(0);
            $table->unsignedInteger('min_notice_minutes')->nullable();
            $table->unsignedSmallInteger('max_horizon_days')->nullable();
            $table->string('location')->nullable();
            $table->string('status');
            $table->unsignedInteger('rule_version')->default(1);
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['context_type', 'context_id', 'status'], $name.'_context_status_index');
        });

        // Case-insensitive uniqueness of the title within one context: an
        // expression index, which the schema builder cannot express.
        DB::statement(sprintf(
            'create unique index %s_context_title_unique on %s (context_type, context_id, lower(title))',
            $name,
            $name,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('series'));
    }
};
