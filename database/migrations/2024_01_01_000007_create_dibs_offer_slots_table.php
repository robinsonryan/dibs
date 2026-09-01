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
        $name = TablePrefixer::prefix('offer_slots');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('offer_id')
                ->constrained(TablePrefixer::prefix('offers'))
                ->cascadeOnDelete();
            $table->foreignUuid('slot_id')
                ->constrained(TablePrefixer::prefix('slots'))
                ->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['offer_id', 'slot_id'], $name.'_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('offer_slots'));
    }
};
