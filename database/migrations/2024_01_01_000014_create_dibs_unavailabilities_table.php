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
        $name = TablePrefixer::prefix('unavailabilities');

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            // Whose time this is: a host, or a context whose whole calendar the
            // away closes. Not nullable — an away nobody owns covers nothing.
            $table->string('scope_type');
            $table->string('scope_id');
            $table->string('kind');
            // A one-off away is a plain instant span (D10). Null on a standing one.
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // IANA zone: a standing away's windows are wall clock, so only the
            // away's own zone can say which instant "6 pm" is on a given date.
            $table->string('timezone');
            // The dates a standing away runs between, on that clock. Null on a
            // one-off, and a null `ends_on` means "until it is removed".
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('label')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['scope_type', 'scope_id', 'starts_at'], $name.'_scope_starts_at_index');
            $table->index(['scope_type', 'scope_id', 'kind'], $name.'_scope_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefixer::prefix('unavailabilities'));
    }
};
