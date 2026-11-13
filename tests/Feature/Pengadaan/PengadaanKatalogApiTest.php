<?php

namespace Tests\Feature\Pengadaan;

use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Persediaan\StokBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PengadaanKatalogApiTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'QzxKatalogRegresi';

    private array $barang = [];
    private Barang $inactive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('simlog_rs_test', DB::connection()->getDatabaseName(), 'Fixtures may only run on simlog_rs_test.');
        $this->actingAs(User::where('username', 'superadmin')->firstOrFail());
        $existing = Barang::where('status', 'AKTIF')->firstOrFail();

        // Reverse insertion and a name tie across page 1/2 exercise both sort keys.
        for ($i = 61; $i >= 1; $i--) {
            $this->barang[$i] = Barang::create([
                'kode' => sprintf('QZX-PR-%03d', $i),
                'nama' => self::PREFIX . sprintf(' %03d', $i === 51 ? 50 : $i),
                'tipe' => $i === 61 ? 'ASET' : ($i >= 56 ? 'SPAREPART' : 'HABIS_PAKAI'),
                'kategori_id' => $existing->kategori_id,
                'satuan_id' => $existing->satuan_id,
                'harga_estimasi' => 1234.5,
                'status' => 'AKTIF',
            ]);
        }

        $this->inactive = Barang::create([
            'kode' => 'QZX-PR-999',
            'nama' => self::PREFIX . ' Nonaktif',
            'tipe' => 'SPAREPART',
            'kategori_id' => $existing->kategori_id,
            'satuan_id' => $existing->satuan_id,
            'status' => 'NONAKTIF',
        ]);
    }

    private function katalog(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/master/barang' . ($query ? '?' . http_build_query($query) : ''));
    }

    private function allIds(array $query): array
    {
        $ids = [];
        $page = 1;
        do {
            $response = $this->katalog($query + ['page' => $page, 'per_halaman' => 100])->assertOk();
            $ids = array_merge($ids, array_column($response->json('data.items'), 'id'));
            $page++;
        } while ($page <= $response->json('data.paginasi.total_halaman'));

        return $ids;
    }

    public function test_all_active_fixtures_are_reachable_in_stable_pages_including_assets_after_fifty(): void
    {
        $first = $this->katalog(['cari' => self::PREFIX])->assertOk()
            ->assertJsonPath('sukses', true)
            ->assertJsonPath('meta.versi_api', 'v1.0')
            ->assertJsonCount(50, 'data.items')
            ->assertJsonPath('data.paginasi', [
                'total' => 61,
                'per_halaman' => 50,
                'halaman_ini' => 1,
                'total_halaman' => 2,
            ]);
        $second = $this->katalog(['cari' => self::PREFIX, 'page' => 2])->assertOk()
            ->assertJsonCount(11, 'data.items')
            ->assertJsonPath('data.paginasi.halaman_ini', 2)
            ->assertJsonPath('data.paginasi.total', 61);

        $expected = [];
        foreach (array_merge(range(1, 49), [51, 50], range(52, 61)) as $i) {
            $expected[] = $this->barang[$i]->id;
        }
        $firstIds = array_column($first->json('data.items'), 'id');
        $secondIds = array_column($second->json('data.items'), 'id');
        $combined = array_merge($firstIds, $secondIds);
        $this->assertSame(array_slice($expected, 0, 50), $firstIds);
        $this->assertSame($expected, $combined);
        $this->assertCount(61, array_unique($combined));
        $this->assertNotContains($this->inactive->id, $combined);
        $this->assertNotContains($this->barang[61]->id, $firstIds);
        $this->assertContains($this->barang[61]->id, $secondIds);
        $this->katalog(['cari' => ' qZx-Pr-061 '])->assertOk()
            ->assertJsonPath('data.items.0.id', $this->barang[61]->id)
            ->assertJsonPath('data.items.0.tipe', 'ASET')
            ->assertJsonPath('data.items.0.stok', 0)
            ->assertJsonPath('data.paginasi.total', 1);
    }

    public function test_name_prefixes_of_one_two_three_characters_and_full_name_ignore_case(): void
    {
        foreach (['q', 'qz', 'qzx', strtolower($this->barang[1]->nama)] as $query) {
            $lower = $this->allIds(['cari' => $query]);
            $this->assertContains($this->barang[1]->id, $lower, $query);
            $this->assertSame($lower, $this->allIds(['cari' => strtoupper($query)]), $query);
            $mixed = substr_replace(strtoupper($query), strtolower(substr($query, 1, 1)), 1, 1);
            $this->assertSame($lower, $this->allIds(['cari' => '  ' . $mixed . '  ']), $query);
        }
    }

    public function test_code_search_remains_grouped_with_status_and_type_filters(): void
    {
        foreach (['qzx-pr', 'QZX-PR', 'qZx-Pr'] as $query) {
            $response = $this->katalog(['cari' => $query, 'tipe' => 'SPAREPART', 'per_halaman' => 2])->assertOk()
                ->assertJsonCount(2, 'data.items')
                ->assertJsonPath('data.paginasi', [
                    'total' => 5,
                    'per_halaman' => 2,
                    'halaman_ini' => 1,
                    'total_halaman' => 3,
                ]);
            $this->assertSame(
                [$this->barang[56]->id, $this->barang[57]->id],
                array_column($response->json('data.items'), 'id')
            );
        }

        $this->katalog(['cari' => self::PREFIX, 'tipe' => 'HABIS_PAKAI', 'per_halaman' => 100])->assertOk()
            ->assertJsonCount(55, 'data.items')->assertJsonPath('data.paginasi.total', 55);
        $this->katalog(['cari' => self::PREFIX, 'tipe' => 'ASET'])->assertOk()
            ->assertJsonPath('data.items.0.id', $this->barang[61]->id)
            ->assertJsonPath('data.paginasi.total', 1);
        $this->katalog(['cari' => 'qzx-pr-999'])->assertOk()
            ->assertJsonPath('data.items', [])->assertJsonPath('data.paginasi.total', 0);
    }

    public function test_existing_item_fields_and_active_positive_warehouse_stock_are_preserved(): void
    {
        $barang = $this->barang[1];
        $gudang = Gudang::where('status', 'AKTIF')->firstOrFail();
        foreach ([[7, 'AKTIF'], [3, 'AKTIF'], [0, 'AKTIF'], [100, 'HABIS']] as $i => [$jumlah, $status]) {
            StokBatch::create([
                'barang_id' => $barang->id,
                'gudang_id' => $gudang->id,
                'nomor_batch' => 'QZX-PR-BATCH-' . $i,
                'jumlah' => $jumlah,
                'jumlah_dipesan' => 0,
                'harga_satuan' => 1234.5,
                'status' => $status,
            ]);
        }

        $this->katalog(['cari' => 'qzx-pr-001'])->assertOk()->assertJsonPath('data.items', [[
            'id' => $barang->id,
            'kode' => $barang->kode,
            'nama' => $barang->nama,
            'spesifikasi' => $barang->nama,
            'tipe' => 'HABIS_PAKAI',
            'tipe_label' => 'Habis Pakai (ATK/ART)',
            'kategori' => $barang->kategori->nama,
            'satuan' => $barang->satuan->nama,
            'merek' => '-',
            'harga_estimasi' => 1234.5,
            'stok' => 10,
        ]]);
    }

    public function test_missing_or_blank_query_returns_first_fifty_active_items(): void
    {
        $expected = Barang::where('status', 'AKTIF')->orderBy('nama')->orderBy('id')->limit(50)->pluck('id')->all();
        $total = Barang::where('status', 'AKTIF')->count();
        foreach ([[], ['cari' => '   ', 'tipe' => '']] as $query) {
            $response = $this->katalog($query)->assertOk()->assertJsonCount(50, 'data.items')
                ->assertJsonPath('data.paginasi.total', $total)
                ->assertJsonPath('data.paginasi.per_halaman', 50)
                ->assertJsonPath('data.paginasi.halaman_ini', 1)
                ->assertJsonPath('data.paginasi.total_halaman', (int) ceil($total / 50));
            $this->assertSame($expected, array_column($response->json('data.items'), 'id'));
        }
    }

    public function test_empty_results_and_out_of_range_pages_are_successful(): void
    {
        $this->katalog(['cari' => 'QZX-PR-NO-MATCH'])->assertOk()
            ->assertJsonPath('sukses', true)->assertJsonPath('data.items', [])
            ->assertJsonPath('data.paginasi', [
                'total' => 0, 'per_halaman' => 50, 'halaman_ini' => 1, 'total_halaman' => 1,
            ]);
        $this->katalog(['cari' => self::PREFIX, 'page' => 3])->assertOk()
            ->assertJsonPath('data.items', [])->assertJsonPath('data.paginasi.total', 61)
            ->assertJsonPath('data.paginasi.halaman_ini', 3)->assertJsonPath('data.paginasi.total_halaman', 2);
        $this->katalog(['cari' => self::PREFIX, 'per_halaman' => 100])->assertOk()
            ->assertJsonCount(61, 'data.items')->assertJsonPath('data.paginasi.per_halaman', 100);
        $this->katalog(['cari' => self::PREFIX, 'per_halaman' => 1])->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.paginasi.total_halaman', 61);
        $this->katalog(['cari' => str_repeat('x', 255)])->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_invalid_query_parameters_return_validation_errors(): void
    {
        $invalid = [
            ['cari', str_repeat('x', 256)], ['cari', ['invalid']],
            ['tipe', 'INVALID'], ['tipe', 'aset'], ['tipe', ['ASET']],
            ['page', 0], ['page', -1], ['page', '1.5'], ['page', 'abc'], ['page', ''], ['page', [1]],
            ['per_halaman', 0], ['per_halaman', -1], ['per_halaman', 101],
            ['per_halaman', '1.5'], ['per_halaman', 'abc'], ['per_halaman', ''], ['per_halaman', [50]],
        ];
        foreach ($invalid as [$field, $value]) {
            $this->katalog([$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }
}
