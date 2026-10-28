<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Province;
use App\Services\PsgcLocationImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PsgcLocationImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_load_every_psgc_province_city_and_municipality(): void
    {
        $data = json_decode((string) file_get_contents(base_path(PsgcLocationImporter::DEFAULT_PATH)), true);

        $this->assertSame(count($data['provinces']), Province::query()->count());
        $this->assertSame(84, Province::query()->count());
        $this->assertTrue(Province::query()->whereNull('psgc_code')->doesntExist());
        $this->assertSame(149, City::query()->where('kind', 'city')->count());
        $this->assertSame(1493, City::query()->where('kind', 'municipality')->count());

        // The provinces seeded before the import keep their names and gain their PSGC data.
        $metro = Province::query()->where('name', 'Metro Manila')->firstOrFail();
        $this->assertSame('1300000000', $metro->psgc_code);
        $this->assertSame('National Capital Region (NCR)', $metro->region);
        $this->assertSame(17, $metro->cities()->count());
        $this->assertTrue($metro->cities()->where('name', 'Quezon City')->where('kind', 'city')->exists());
        $this->assertTrue($metro->cities()->where('name', 'Pateros')->where('kind', 'municipality')->exists());

        $cebu = Province::query()->where('name', 'Cebu')->firstOrFail();
        $this->assertSame('0702200000', $cebu->psgc_code);
        $this->assertSame('Region VII (Central Visayas)', $cebu->region);
        // Highly urbanized cities are listed under the province they are in.
        $this->assertSame(53, $cebu->cities()->count());
        $this->assertTrue($cebu->cities()->where('name', 'Cebu City')->where('psgc_code', '0730600000')->exists());
        $this->assertTrue(Province::query()->where('name', 'Quezon')->firstOrFail()->cities()->where('name', 'Lucena City')->exists());
        $this->assertTrue(Province::query()->where('name', 'Benguet')->firstOrFail()->cities()->where('name', 'Baguio City')->exists());
    }

    public function test_reimport_adds_only_missing_entries_and_keeps_admin_changes(): void
    {
        $metro = Province::query()->where('name', 'Metro Manila')->firstOrFail();
        $metro->cities()->where('name', 'Makati City')->update(['name' => 'Makati', 'is_active' => false]);
        $metro->cities()->where('name', 'Pateros')->delete();
        Province::query()->where('name', 'Bohol')->update(['is_active' => false]);

        $result = $this->app->make(PsgcLocationImporter::class)->import();

        $this->assertSame(0, $result['provinces_added']);
        $this->assertSame(84, $result['provinces_matched']);
        $this->assertSame(1, $result['cities_added']);
        $this->assertSame(1641, $result['cities_matched']);
        $this->assertTrue(City::query()->where('name', 'Makati')->where('is_active', false)->exists());
        $this->assertFalse(City::query()->where('name', 'Makati City')->exists());
        $this->assertTrue($metro->cities()->where('name', 'Pateros')->exists());
        $this->assertFalse(Province::query()->where('name', 'Bohol')->value('is_active'));

        $this->artisan('locations:import-psgc')
            ->expectsOutputToContain('Provinces: 0 added, 84 already listed.')
            ->assertSuccessful();
    }
}
