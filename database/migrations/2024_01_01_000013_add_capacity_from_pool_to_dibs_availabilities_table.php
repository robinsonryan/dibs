<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Dibs\Support\TablePrefixer;

return new class extends Migration
{
    /**
     * Whether the slots this availability materialises are measured by its
     * host pool (`capacity` null) rather than by a number (D18). It lives on
     * the availability because every path that lays a grid down — publishing,
     * a geometry edit, a series regeneration, a duplicate — reads the
     * availability row and nothing else, so the rule survives every remaking
     * of the grid without the caller restating it.
     */
    public function up(): void
    {
        Schema::table(TablePrefixer::prefix('availabilities'), function (Blueprint $table): void {
            $table->boolean('capacity_from_pool')->default(false)->after('slot_padding_minutes');
        });
    }

    public function down(): void
    {
        Schema::table(TablePrefixer::prefix('availabilities'), function (Blueprint $table): void {
            $table->dropColumn('capacity_from_pool');
        });
    }
};
