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
        $name = TablePrefixer::prefix('series_hosts');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('series_id')
                ->constrained(TablePrefixer::prefix('series'))
                ->cascadeOnDelete();
            $table->string('host_type');
            $table->string('host_id');
            $table->string('role')->default('host');
            $table->timestampsTz();

            $table->unique(['series_id', 'host_type', 'host_id', 'role'], $name.'_pool_unique');
            $table->index(['host_type', 'host_id'], $name.'_host_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('series_hosts'));
    }
};
