<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Dibs\Support\TablePrefixer;

return new class extends Migration
{
    /**
     * A null `capacity` means "however many of the pool are free" (D18); a
     * number is the cap, whatever the pool says. The default stays 1, so every
     * path that does not ask for the pool rule keeps writing one appointment.
     */
    public function up(): void
    {
        Schema::table(TablePrefixer::prefix('slots'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('capacity')->nullable()->default(1)->change();
        });
    }

    public function down(): void
    {
        // Pool-derived rows have no number to go back to; one appointment is
        // what they were before the column could be null.
        DB::table(TablePrefixer::prefix('slots'))->whereNull('capacity')->update(['capacity' => 1]);

        Schema::table(TablePrefixer::prefix('slots'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('capacity')->default(1)->change();
        });
    }
};
