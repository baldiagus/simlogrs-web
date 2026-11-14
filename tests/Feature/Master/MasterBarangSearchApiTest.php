<?php

namespace Tests\Feature\Master;

use App\Models\Master\Barang;
use App\Models\Master\KategoriBarang;
use App\Models\Master\SatuanBarang;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MasterBarangSearchApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_search_by_name_and_code_preserves_type_filter_and_handles_no_matches(): void
    {
        $this->actingAs(User::where('username', 'superadmin')->firstOrFail());
        $kategori = KategoriBarang::firstOrCreate(
            ['kode' => 'KAT-SEARCH-TEST'],
            ['nama' => 'Kategori Search Test', 'status' => 'AKTIF']
        );
        $satuan = SatuanBarang::firstOrCreate(
            ['kode' => 'SAT-SEARCH-TEST'],
            ['nama' => 'Unit Search Test', 'status' => 'AKTIF']
        );
        $attributes = ['kategori_id' => $kategori->id, 'satuan_id' => $satuan->id, 'status' => 'AKTIF'];
        $barang = Barang::create($attributes + [
            'kode' => 'BRG-REGSEARCH-001',
            'nama' => 'Jarum KatalogRegresi',
            'tipe' => 'HABIS_PAKAI',
        ]);
        $sparepart = Barang::create($attributes + [
            'kode' => 'BRG-REGSEARCH-002',
            'nama' => 'Motor KatalogRegresi',
            'tipe' => 'SPAREPART',
        ]);

        $this->getJson('/api/v1/master/barang-lengkap?cari=a')->assertOk();
        $this->getJson('/api/v1/master/barang-lengkap?cari=jArUm%20kAtAlOgReGrEsI')
            ->assertOk()->assertJsonPath('data.*.id', [$barang->id]);
        $this->getJson('/api/v1/master/barang-lengkap?cari=regsearch-001')
            ->assertOk()->assertJsonPath('data.*.id', [$barang->id]);
        $this->getJson('/api/v1/master/barang-lengkap?cari=REGSEARCH&tipe=HABIS_PAKAI')
            ->assertOk()->assertJsonPath('data.*.id', [$barang->id]);
        $this->getJson('/api/v1/master/barang-lengkap?cari=katalogregresi&tipe=SPAREPART')
            ->assertOk()->assertJsonPath('data.*.id', [$sparepart->id]);
        $this->getJson('/api/v1/master/barang-lengkap?cari=ZZZ-NO-MATCH-REGSEARCH')
            ->assertOk()->assertJsonPath('data', []);
    }
}
