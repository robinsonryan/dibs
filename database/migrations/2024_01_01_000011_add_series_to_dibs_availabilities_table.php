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
        $name = TablePrefixer::prefix('availabilities');

        Schema::table($name, function (Blueprint $table) use ($name): void {
            // Nulled rather than cascaded when the series goes: an occurrence
            // that carried bookings is history and outlives its rule.
            $table->foreignUuid('series_id')
                ->nullable()
                ->constrained(TablePrefixer::prefix('series'))
                ->nullOnDelete();
            $table->date('occurs_on')->nullable();
            // Which block of that weekday this occurrence is, 0-based: two
            // blocks on one day are two rows on one date.
            $table->unsignedSmallInteger('window_index')->nullable();
            $table->unsignedInteger('rule_version')->nullable();
            $table->timestampTz('detached_at')->nullable();

            $table->index(['series_id', 'occurs_on'], $name.'_series_date_index');
        });

        DB::statement(sprintf(
            'create unique index %s_series_occurrence_unique on %s (series_id, occurs_on, window_index) where series_id is not null',
            $name,
            $name,
        ));
    }

    public function down(): void
    {
        $name = TablePrefixer::prefix('availabilities');

        DB::statement(sprintf('drop index if exists %s_series_occurrence_unique', $name));

        Schema::table($name, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name.'_series_date_index');
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn(['occurs_on', 'window_index', 'rule_version', 'detached_at']);
        });
    }
};
