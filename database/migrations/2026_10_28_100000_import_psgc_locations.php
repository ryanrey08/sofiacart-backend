<?php

use App\Services\PsgcLocationImporter;
use Illuminate\Database\Migrations\Migration;

/**
 * Loads the official PSGC provinces (and Metro Manila's cities) into the registration lists, so a
 * deploy's `migrate` fills them. Later data files are loaded with `php artisan locations:import-psgc`.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PsgcLocationImporter::class)->import();
    }

    public function down(): void
    {
        // Imported entries may already be used by stores; they are left in place.
    }
};
