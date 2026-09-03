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
        $name = TablePrefixer::prefix('series_windows');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('series_id')
                ->constrained(TablePrefixer::prefix('series'))
                ->cascadeOnDelete();
            // 0 = Sunday … 6 = Saturday, matching Carbon's dayOfWeek.
            $table->unsignedSmallInteger('weekday');
            // Minutes from local midnight, in the series' timezone.
            $table->unsignedSmallInteger('starts_at_minutes');
            $table->unsignedSmallInteger('ends_at_minutes');
            $table->timestampsTz();

            $table->index(['series_id', 'weekday'], $name.'_series_weekday_index');
        });

        DB::statement(sprintf(
            'alter table %s add constraint %s_bounds_check check (ends_at_minutes > starts_at_minutes)',
            $name,
            $name,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('series_windows'));
    }
};
