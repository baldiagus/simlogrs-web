<?php

namespace Tests\Feature\Persediaan;

use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\LokasiGudang;
use App\Models\Persediaan\PengalihanBarang;
use App\Models\Persediaan\PergerakanStok;
use App\Models\Persediaan\StokBatch;
use App\Models\Distribusi\StokRuangan;
use App\Models\Master\Unit;
use App\Models\User;
use App\Services\Persediaan\PengalihanBarangService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PengalihanBarangServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected User $userPetugas;
    protected User $userKaru;
    protected User $userKepalaGudang;
    protected Gudang $gudangAsal;
    protected Gudang $gudangTujuan;
    protected Unit $unitTujuan;
    protected Unit $unitAsal;
    protected Barang $barangBhp;
    protected StokBatch $batchAsal;

    protected function setUp(): void
    {
        parent::setUp();

        $gudangs = Gudang::where('status', 'AKTIF')->take(2)->get();
        $this->gudangAsal = $gudangs[0];
        $this->gudangTujuan = $gudangs[1] ?? Gudang::create(['kode' => 'GDG-DST', 'nama' => 'Gudang Destinasi', 'status' => 'AKTIF']);

        $units = Unit::where('status', 'AKTIF')->take(2)->get();
        $this->unitAsal = $units[0];
        $this->unitTujuan = $units[1];

        $this->userPetugas = User::create([
            'nama' => 'Petugas Gudang TF',
            'username' => 'petugas_tf_brg',
            'email' => 'petugas_tf_brg@simlog.test',
            'password' => bcrypt('password'),
            'gudang_id' => $this->gudangAsal->id,
            'status' => 'AKTIF',
        ]);

        $this->userKaru = User::create([
            'nama' => 'Karu Penerima TF',
            'username' => 'karu_tf_brg',
            'email' => 'karu_tf_brg@simlog.test',
            'password' => bcrypt('password'),
            'unit_id' => $this->unitTujuan->id,
            'status' => 'AKTIF',
        ]);

        $this->userKepalaGudang = User::create([
            'nama' => 'Kepala Gudang TF',
            'username' => 'ka_gdg_tf',
            'email' => 'ka_gdg_tf@simlog.test',
            'password' => bcrypt('password'),
            'gudang_id' => $this->gudangAsal->id,
            'status' => 'AKTIF',
        ]);
        $satuanId = \App\Models\Master\SatuanBarang::firstOrCreate(['kode' => 'BOX-TF'], ['nama' => 'Box', 'status' => 'AKTIF'])->id;
        $kategoriId = \App\Models\Master\KategoriBarang::firstOrCreate(['kode' => 'KAT-BHP-TF'], ['nama' => 'Kategori BHP', 'status' => 'AKTIF'])->id;
        $this->barangBhp = Barang::firstOrCreate(
            ['kode' => 'BRG-BHP-TF-01'],
            [
                'nama' => 'Spuit 3cc Disposable TF',
                'tipe' => 'HABIS_PAKAI',
                'satuan_id' => $satuanId,
                'kategori_id' => $kategoriId,
                'status' => 'AKTIF',
            ]
        );

        $lokasiAsal = LokasiGudang::firstOrCreate(
            ['gudang_id' => $this->gudangAsal->id, 'kode' => 'RAK-BHP-01'],
            ['nama' => 'Rak BHP Asal', 'status' => 'AKTIF']
        );

        $this->batchAsal = StokBatch::create([
            'barang_id' => $this->barangBhp->id,
            'gudang_id' => $this->gudangAsal->id,
            'lokasi_gudang_id' => $lokasiAsal->id,
            'nomor_batch' => 'BATCH-TF-001',
            'tanggal_kedaluwarsa' => now()->addYear()->toDateString(),
            'jumlah' => 100,
            'status' => 'AKTIF',
        ]);
    }

    public function test_submitting_goods_transfer_from_warehouse_locks_batch_stock(): void
    {
        $service = app(PengalihanBarangService::class);

        $transfer = $service->ajukanPengalihan([
            'tipe_asal' => 'GUDANG',
            'gudang_asal_id' => $this->gudangAsal->id,
            'tipe_tujuan' => 'RUANGAN',
            'unit_tujuan_id' => $this->unitTujuan->id,
            'tanggal_pengalihan' => now()->toDateString(),
            'alasan' => 'Drop stok darurat ruang ICU',
            'diajukan_oleh' => $this->userPetugas->id,
            'items' => [
                [
                    'barang_id' => $this->barangBhp->id,
                    'stok_batch_id' => $this->batchAsal->id,
                    'jumlah' => 20,
                    'catatan' => '20 Box',
                ],
            ],
        ]);

        $this->assertInstanceOf(PengalihanBarang::class, $transfer);
        $this->assertEquals('DIAJUKAN', $transfer->status);
        $this->assertCount(1, $transfer->detail);

        $this->batchAsal->refresh();
        $this->assertEquals(80, $this->batchAsal->jumlah);
    }

    public function test_executing_receipt_to_room_increments_room_inventory_and_logs_stock_movement(): void
    {
        $service = app(PengalihanBarangService::class);

        $transfer = $service->ajukanPengalihan([
            'tipe_asal' => 'GUDANG',
            'gudang_asal_id' => $this->gudangAsal->id,
            'tipe_tujuan' => 'RUANGAN',
            'unit_tujuan_id' => $this->unitTujuan->id,
            'tanggal_pengalihan' => now()->toDateString(),
            'alasan' => 'Drop stok darurat ruang ICU',
            'diajukan_oleh' => $this->userPetugas->id,
            'items' => [
                [
                    'barang_id' => $this->barangBhp->id,
                    'stok_batch_id' => $this->batchAsal->id,
                    'jumlah' => 30,
                ],
            ],
        ]);
        $service->setujuiPengalihan($transfer->id, $this->userKepalaGudang->id);
        $service->eksekusiSerahTerima($transfer->id, $this->userKaru->id);

        $transfer->refresh();
        $this->assertEquals('SELESAI', $transfer->status);

        // 1. Verifikasi stok ruangan bertambah (Muncul di Persediaan Lokal Ruangan)
        $stokRuangan = StokRuangan::where('unit_id', $this->unitTujuan->id)
            ->where('barang_id', $this->barangBhp->id)
            ->first();

        $this->assertNotNull($stokRuangan);
        $this->assertEquals(30, $stokRuangan->jumlah);

        // 2. Verifikasi riwayat mutasi kartu stok tercatat
        $pergerakan = PergerakanStok::where('referensi_jenis', 'PENGALIHAN_BARANG')
            ->where('referensi_id', $transfer->id)
            ->first();

        $this->assertNotNull($pergerakan);
        $this->assertEquals(30, $pergerakan->jumlah);
        $this->assertEquals('TRANSFER', $pergerakan->jenis);
    }

    public function test_executing_receipt_between_warehouses_creates_or_increments_destination_batch(): void
    {
        $service = app(PengalihanBarangService::class);

        $transfer = $service->ajukanPengalihan([
            'tipe_asal' => 'GUDANG',
            'gudang_asal_id' => $this->gudangAsal->id,
            'tipe_tujuan' => 'GUDANG',
            'gudang_tujuan_id' => $this->gudangTujuan->id,
            'tanggal_pengalihan' => now()->toDateString(),
            'alasan' => 'Pemerataan persediaan logistik',
            'diajukan_oleh' => $this->userPetugas->id,
            'items' => [
                [
                    'barang_id' => $this->barangBhp->id,
                    'stok_batch_id' => $this->batchAsal->id,
                    'jumlah' => 25,
                ],
            ],
        ]);
        $service->setujuiPengalihan($transfer->id, $this->userKepalaGudang->id);
        $service->eksekusiSerahTerima($transfer->id, $this->userPetugas->id);
        $transfer->refresh();
        $this->assertEquals('SELESAI', $transfer->status);

        // Verifikasi batch bertambah di gudang tujuan
        $batchTujuan = StokBatch::where('gudang_id', $this->gudangTujuan->id)
            ->where('barang_id', $this->barangBhp->id)
            ->where('nomor_batch', $this->batchAsal->nomor_batch)
            ->first();

        $this->assertNotNull($batchTujuan);
        $this->assertEquals(25, $batchTujuan->jumlah);
    }

    public function test_transfer_to_or_restore_of_depleted_habis_batch_reactivates_status_to_aktif(): void
    {
        $service = app(PengalihanBarangService::class);

        $batchHabisTujuan = StokBatch::create([
            'barang_id' => $this->barangBhp->id,
            'gudang_id' => $this->gudangTujuan->id,
            'nomor_batch' => $this->batchAsal->nomor_batch,
            'tanggal_kedaluwarsa' => $this->batchAsal->tanggal_kedaluwarsa,
            'jumlah' => 0,
            'status' => 'HABIS',
        ]);

        $transfer = $service->ajukanPengalihan([
            'tipe_asal' => 'GUDANG',
            'gudang_asal_id' => $this->gudangAsal->id,
            'tipe_tujuan' => 'GUDANG',
            'gudang_tujuan_id' => $this->gudangTujuan->id,
            'tanggal_pengalihan' => now()->toDateString(),
            'alasan' => 'Reaktivasi batch habis',
            'diajukan_oleh' => $this->userPetugas->id,
            'items' => [
                [
                    'barang_id' => $this->barangBhp->id,
                    'stok_batch_id' => $this->batchAsal->id,
                    'jumlah' => 100,
                ],
            ],
        ]);

        $this->batchAsal->refresh();
        $this->assertEquals(0, $this->batchAsal->jumlah);
        $this->assertEquals('HABIS', $this->batchAsal->status);

        $service->setujuiPengalihan($transfer->id, $this->userKepalaGudang->id);
        $service->eksekusiSerahTerima($transfer->id, $this->userPetugas->id);

        $batchHabisTujuan->refresh();
        $this->assertEquals(100, $batchHabisTujuan->jumlah);
        $this->assertEquals('AKTIF', $batchHabisTujuan->status);
    }
}
