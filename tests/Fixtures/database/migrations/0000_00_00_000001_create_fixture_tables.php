<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['fixture_users', 'fixture_rooms', 'fixture_organizations'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
                $table->string('name');
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_organizations');
        Schema::dropIfExists('fixture_rooms');
        Schema::dropIfExists('fixture_users');
    }
};
