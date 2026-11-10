<?php

namespace Tests\Feature\Distribusi;

use App\Livewire\Distribusi\DistribusiApprovalIndex;
use App\Livewire\Distribusi\PenerimaanBarangIndex;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\KategoriBarang;
use App\Models\Master\LokasiGudang;
use App\Models\Distribusi\PengirimanBarang;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Distribusi\PermintaanDistribusiDetail;
use App\Models\Master\SatuanBarang;
use App\Models\Persediaan\StokBatch;
use App\Models\Distribusi\StokRuangan;
use App\Models\Master\Unit;
use App\Models\User;
use App\Services\Distribusi\DistribusiService;
use App\Support\Generator\CodeGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DistribusiBackorderTest extends TestCase
{
    use DatabaseTransactions;

    protected User $userAdmin;
    protected Gudang $gudang;
    protected Unit $unit;
    protected Barang $barang;
    protected LokasiGudang $lokasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userAdmin = User::where('email', 'superadmin@simlogrs.local')->first();
        $this->gudang = Gudang::where('status', 'AKTIF')->first();
        $this->unit = Unit::where('status', 'AKTIF')->first();

        $this->lokasi = LokasiGudang::firstOrCreate(
            ['kode' => 'RAK-BO-TEST', 'gudang_id' => $this->gudang->id],
            ['nama' => 'Rak Backorder Test', 'status' => 'AKTIF']
        );

        $kategori = KategoriBarang::firstOrCreate(['kode' => 'KAT-BO'], ['nama' => 'Kategori BO', 'status' => 'AKTIF']);
        $satuan = SatuanBarang::firstOrCreate(['kode' => 'PCS-BO'], ['nama' => 'Pcs', 'status' => 'AKTIF']);

        $this->barang = Barang::firstOrCreate(
            ['kode' => 'BRG-BO-01'],
            [
                'nama' => 'Kasa Medis Backorder Test',
                'kategori_id' => $kategori->id,
                'satuan_id' => $satuan->id,
                'tipe' => 'HABIS_PAKAI',
                'status' => 'AKTIF',
            ]
        );
    }

    public function test_approving_requisition_approves_full_requested_quantity_even_if_stock_is_insufficient(): void
    {
        $this->actingAs($this->userAdmin);

        // Stok di gudang hanya ada 2 unit
        StokBatch::where('barang_id', $this->barang->id)->delete();
        StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-BO-02',
            'tanggal_kedaluwarsa' => now()->addYear()->toDateString(),
            'jumlah' => 2,
            'status' => 'AKTIF',
        ]);

        // Ruangan meminta 5 unit
        $req = PermintaanDistribusi::create([
            'kode' => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id' => $this->unit->id,
            'gudang_id' => $this->gudang->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => 'DISETUJUI_KARU',
            'diajukan_oleh' => $this->userAdmin->id,
        ]);
        $detail = PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $req->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 5,
        ]);

        // Kepala Gudang membuka otorisasi
        $component = Livewire::test(DistribusiApprovalIndex::class)
            ->call('openReview', $req->id);

        // Jumlah yang disetujui HARUS TETAP 5 (tidak boleh dipotong sepihak menjadi 2)
        $this->assertEquals(5, $component->get("itemQtys.{$detail->id}.jumlah_disetujui"));

        // Konfirmasi persetujuan HARUS berhasil (tidak boleh ditolak error "melebihi stok gudang")
        $component->call('confirmApproveGudang')
            ->assertHasNoErrors()
            ->call('submitApproveGudang');

        $req->refresh();
        $detail->refresh();

        $this->assertEquals('DISETUJUI_GUDANG', $req->status);
        $this->assertEquals(5, $detail->jumlah_disetujui);
    }

    public function test_picking_partial_stock_issues_first_sppb_and_marks_request_as_partially_sent(): void
    {
        // 1. Stok gudang hanya ada 2 unit
        StokBatch::where('barang_id', $this->barang->id)->delete();
        $batch = StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-BO-PARTIAL-1',
            'tanggal_kedaluwarsa' => now()->addYear()->toDateString(),
            'jumlah' => 2,
            'status' => 'AKTIF',
        ]);

        // 2. Permintaan disetujui 5 unit
        $req = PermintaanDistribusi::create([
            'kode' => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id' => $this->unit->id,
            'gudang_id' => $this->gudang->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => 'DISETUJUI_GUDANG',
            'diajukan_oleh' => $this->userAdmin->id,
            'disetujui_oleh' => $this->userAdmin->id,
        ]);
        $detail = PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $req->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 5,
            'jumlah_disetujui' => 5,
        ]);

        // 3. Eksekusi picking pertama
        $service = app(DistribusiService::class);
        $sppb1 = $service->eksekusiPickingFefo($req, $this->userAdmin, $this->gudang->id);

        $this->assertNotNull($sppb1);
        $this->assertEquals(2, $sppb1->detail->sum('jumlah_dikirim'));

        $req->refresh();
        // Permintaan TIDAK BOLEH ditutup atau dianggap selesai 100%, melainkan DIKIRIM_SEBAGIAN (terhutang 3)
        $this->assertEquals('DIKIRIM_SEBAGIAN', $req->status);

        $batch->refresh();
        $this->assertEquals(0, $batch->jumlah);
        $this->assertEquals('HABIS', $batch->status);
    }

    public function test_subsequent_stock_restock_enables_picking_remaining_backorder_as_second_sppb(): void
    {
        // 1. Setup permintaan 5 unit dengan SPPB 1 sudah terbit 2 unit
        $req = PermintaanDistribusi::create([
            'kode' => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id' => $this->unit->id,
            'gudang_id' => $this->gudang->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => 'DIKIRIM_SEBAGIAN',
            'diajukan_oleh' => $this->userAdmin->id,
            'disetujui_oleh' => $this->userAdmin->id,
        ]);
        $detail = PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $req->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 5,
            'jumlah_disetujui' => 5,
        ]);

        // SPPB 1 (2 unit)
        $sppb1 = PengirimanBarang::create([
            'kode' => CodeGenerator::generate('SPPB', PengirimanBarang::class, 'kode', 'Ymd', 4),
            'permintaan_distribusi_id' => $req->id,
            'gudang_id' => $this->gudang->id,
            'unit_id' => $this->unit->id,
            'tanggal_kirim' => now()->toDateString(),
            'status' => 'DITERIMA',
            'dikirim_oleh' => $this->userAdmin->id,
        ]);
        $batchLama = StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-LAMA',
            'tanggal_kedaluwarsa' => now()->addMonths(6)->toDateString(),
            'jumlah' => 0,
            'status' => 'HABIS',
        ]);
        \App\Models\Distribusi\PengirimanBarangDetail::create([
            'pengiriman_barang_id' => $sppb1->id,
            'barang_id' => $this->barang->id,
            'stok_batch_id' => $batchLama->id,
            'jumlah_dikirim' => 2,
        ]);

        // 2. Gudang kedatangan restok baru (misal 10 unit dari vendor)
        $batchBaru = StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-RESTOK-VENDOR',
            'tanggal_kedaluwarsa' => now()->addYear()->toDateString(),
            'jumlah' => 10,
            'status' => 'AKTIF',
        ]);

        // 3. Eksekusi picking kedua (untuk sisa 3 unit terhutang)
        $service = app(DistribusiService::class);
        $sppb2 = $service->eksekusiPickingFefo($req, $this->userAdmin, $this->gudang->id);

        $this->assertNotNull($sppb2);
        // SPPB 2 HANYA boleh mengambil sisa terhutang yaitu 3 unit (bukan 5)!
        $this->assertEquals(3, $sppb2->detail->sum('jumlah_dikirim'));

        $req->refresh();
        // Seluruh 5 unit telah diterbitkan SPPB (2 di SPPB-1 + 3 di SPPB-2)
        $this->assertEquals('SEDANG_DIKIRIM', $req->status);

        $batchBaru->refresh();
        $this->assertEquals(7, $batchBaru->jumlah);
    }

    public function test_room_receiving_partial_sppb_keeps_request_partially_sent_until_all_received(): void
    {
        $this->actingAs($this->userAdmin);

        $req = PermintaanDistribusi::create([
            'kode' => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id' => $this->unit->id,
            'gudang_id' => $this->gudang->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => 'DIKIRIM_SEBAGIAN',
            'diajukan_oleh' => $this->userAdmin->id,
            'disetujui_oleh' => $this->userAdmin->id,
        ]);
        PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $req->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 5,
            'jumlah_disetujui' => 5,
        ]);

        $batch = StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-TEST-RCV-PARTIAL',
            'jumlah' => 0,
            'status' => 'HABIS',
        ]);

        $sppb1 = PengirimanBarang::create([
            'kode' => CodeGenerator::generate('SPPB', PengirimanBarang::class, 'kode', 'Ymd', 4),
            'permintaan_distribusi_id' => $req->id,
            'gudang_id' => $this->gudang->id,
            'unit_id' => $this->unit->id,
            'tanggal_kirim' => now()->toDateString(),
            'status' => 'DIKIRIM',
            'dikirim_oleh' => $this->userAdmin->id,
        ]);
        \App\Models\Distribusi\PengirimanBarangDetail::create([
            'pengiriman_barang_id' => $sppb1->id,
            'barang_id' => $this->barang->id,
            'stok_batch_id' => $batch->id,
            'jumlah_dikirim' => 2,
        ]);

        // Ruangan menerima SPPB 1 (2 unit)
        StokRuangan::where('unit_id', $this->unit->id)->where('barang_id', $this->barang->id)->delete();

        Livewire::test(PenerimaanBarangIndex::class)
            ->call('terima', $sppb1->id);

        $req->refresh();
        // Permintaan TIDAK BOLEH menjadi SELESAI karena baru 2 dari 5 unit yang diterima
        $this->assertEquals('DIKIRIM_SEBAGIAN', $req->status);

        $stokRuangan = StokRuangan::where('unit_id', $this->unit->id)->where('barang_id', $this->barang->id)->first();
        $this->assertEquals(2, $stokRuangan->jumlah);
    }

    public function test_executing_picking_via_livewire_component_succeeds_for_partially_sent_requests(): void
    {
        $this->actingAs($this->userAdmin);

        // 1. Setup permintaan 5 unit dengan SPPB 1 sudah terbit 2 unit (status DIKIRIM_SEBAGIAN)
        $req = PermintaanDistribusi::create([
            'kode' => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id' => $this->unit->id,
            'gudang_id' => $this->gudang->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => 'DIKIRIM_SEBAGIAN',
            'diajukan_oleh' => $this->userAdmin->id,
            'disetujui_oleh' => $this->userAdmin->id,
        ]);
        $detail = PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $req->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 5,
            'jumlah_disetujui' => 5,
        ]);

        $sppb1 = PengirimanBarang::create([
            'kode' => CodeGenerator::generate('SPPB', PengirimanBarang::class, 'kode', 'Ymd', 4),
            'permintaan_distribusi_id' => $req->id,
            'gudang_id' => $this->gudang->id,
            'unit_id' => $this->unit->id,
            'tanggal_kirim' => now()->toDateString(),
            'status' => 'DITERIMA',
            'dikirim_oleh' => $this->userAdmin->id,
        ]);
        $batchLama = StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-OLD-SPPB1',
            'jumlah' => 0,
            'status' => 'HABIS',
        ]);
        \App\Models\Distribusi\PengirimanBarangDetail::create([
            'pengiriman_barang_id' => $sppb1->id,
            'barang_id' => $this->barang->id,
            'stok_batch_id' => $batchLama->id,
            'jumlah_dikirim' => 2,
        ]);

        // 2. Beri stok restok baru 10 unit di gudang
        StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-NEW-RESTOCK',
            'tanggal_kedaluwarsa' => now()->addYear()->toDateString(),
            'jumlah' => 10,
            'status' => 'AKTIF',
        ]);

        // 3. Panggil executePicking langsung melalui komponen Livewire PickingDistribusiIndex
        \Livewire\Livewire::test(\App\Livewire\Distribusi\PickingDistribusiIndex::class)
            ->call('executePicking', $req->id)
            ->assertHasNoErrors();
        // Verifikasi SPPB kedua terbit
        $sppb2 = PengirimanBarang::where('permintaan_distribusi_id', $req->id)->latest('id')->first();
        $this->assertNotNull($sppb2);
        $this->assertNotEquals($sppb1->id, $sppb2->id);
        $this->assertEquals(3, $sppb2->detail->sum('jumlah_dikirim'));

        $req->refresh();
        $this->assertEquals('SEDANG_DIKIRIM', $req->status);
    }

    public function test_duplicate_barang_lines_in_permintaan_supports_partial_backorder_and_single_batch_allocation(): void
    {
        $req = PermintaanDistribusi::create([
            'kode' => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id' => $this->unit->id,
            'gudang_id' => $this->gudang->id,
            'diajukan_oleh' => $this->userAdmin->id,
            'disetujui_oleh' => $this->userAdmin->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => 'DISETUJUI_GUDANG',
        ]);

        PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $req->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 5,
            'jumlah_disetujui' => 5,
        ]);
        PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $req->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 5,
            'jumlah_disetujui' => 5,
        ]);

        StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-DUP-01',
            'tanggal_kedaluwarsa' => now()->addYear()->toDateString(),
            'jumlah' => 5,
            'status' => 'AKTIF',
        ]);

        $service = app(DistribusiService::class);
        $sppb1 = $service->eksekusiPickingFefo($req, $this->userAdmin, $this->gudang->id);

        $req->refresh();
        $this->assertEquals('DIKIRIM_SEBAGIAN', $req->status);
        $this->assertEquals(5, $sppb1->detail->sum('jumlah_dikirim'));

        StokBatch::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudang->id,
            'lokasi_gudang_id' => $this->lokasi->id,
            'nomor_batch' => 'BATCH-DUP-02',
            'tanggal_kedaluwarsa' => now()->addYears(2)->toDateString(),
            'jumlah' => 10,
            'status' => 'AKTIF',
        ]);

        $sppb2 = $service->eksekusiPickingFefo($req, $this->userAdmin, $this->gudang->id);

        $req->refresh();
        $this->assertEquals('SEDANG_DIKIRIM', $req->status);
        $this->assertEquals(5, $sppb2->detail->sum('jumlah_dikirim'));
    }
}
