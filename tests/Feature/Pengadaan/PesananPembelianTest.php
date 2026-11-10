<?php

namespace Tests\Feature\Pengadaan;

use App\Enums\Pengadaan\StatusPermintaanPengadaan;
use App\Livewire\Pengadaan\PesananPembelianIndex;
use App\Models\Master\Barang;
use App\Models\Master\Pemasok;
use App\Models\Pengadaan\PermintaanPengadaan;
use App\Models\Pengadaan\PermintaanPengadaanDetail;
use App\Models\Pengadaan\PesananPembelian;
use App\Models\User;
use App\Support\Generator\CodeGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class PesananPembelianTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Pemasok $pemasok;
    protected Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', 'superadmin@simlogrs.local')->first();
        $this->pemasok = Pemasok::first();
        $this->barang = Barang::first();
    }

    public function test_creating_po_updates_pr_status_to_diproses_and_prevents_duplicate_po(): void
    {
        $this->actingAs($this->user);

        // 1. Buat PR dengan status DITERUSKAN_KE_PENGADAAN
        $pr = PermintaanPengadaan::create([
            'kode' => CodeGenerator::generatePr(),
            'unit_id' => $this->user->unit_id ?? 1,
            'diajukan_oleh' => $this->user->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => StatusPermintaanPengadaan::DiteruskanKePengadaan->value,
        ]);

        PermintaanPengadaanDetail::create([
            'permintaan_pengadaan_id' => $pr->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 10,
            'jumlah_disetujui' => 10,
        ]);

        // 2. Eksekusi konversi PR ke PO via Livewire
        $component = Livewire::test(PesananPembelianIndex::class)
            ->call('konversiPo', $pr->id)
            ->set('pemasok_id', $this->pemasok->id)
            ->set('syarat_pembayaran', 'TEMPO_30_HARI')
            ->set('itemRows', [
                [
                    'barang_id' => $this->barang->id,
                    'nama_barang' => $this->barang->nama,
                    'jumlah' => 10,
                    'harga_satuan' => 50000,
                    'subtotal' => 500000,
                ]
            ])
            ->call('savePo');

        // Assert PO pertama berhasil terbuat
        $this->assertDatabaseHas('pesanan_pembelian', [
            'permintaan_pengadaan_id' => $pr->id,
            'pemasok_id' => $this->pemasok->id,
        ]);

        // Assert status PR berubah menjadi DIPROSES
        $pr->refresh();
        $this->assertEquals(StatusPermintaanPengadaan::Diproses->value, $pr->status);

        // 3. Coba buat PO kedua untuk PR yang sama -> harus ditolak
        $component2 = Livewire::test(PesananPembelianIndex::class)
            ->call('konversiPo', $pr->id)
            ->set('pemasok_id', $this->pemasok->id)
            ->set('syarat_pembayaran', 'TEMPO_30_HARI')
            ->set('itemRows', [
                [
                    'barang_id' => $this->barang->id,
                    'nama_barang' => $this->barang->nama,
                    'jumlah' => 10,
                    'harga_satuan' => 50000,
                    'subtotal' => 500000,
                ]
            ])
            ->call('savePo');

        // Pastikan hanya ada tepat 1 PO yang mengacu pada PR tersebut di database
        $this->assertEquals(1, PesananPembelian::where('permintaan_pengadaan_id', $pr->id)->count());
    }
    public function test_po_item_quantity_must_be_positive_integer(): void
    {
        $this->actingAs($this->user);

        $pr = PermintaanPengadaan::create([
            'kode' => CodeGenerator::generatePr(),
            'unit_id' => $this->user->unit_id ?? 1,
            'diajukan_oleh' => $this->user->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => StatusPermintaanPengadaan::DiteruskanKePengadaan->value,
        ]);

        PermintaanPengadaanDetail::create([
            'permintaan_pengadaan_id' => $pr->id,
            'barang_id' => $this->barang->id,
            'jumlah' => 10,
            'jumlah_disetujui' => 10,
        ]);

        // Coba simpan PO dengan jumlah = 0 -> harus gagal validasi
        Livewire::test(PesananPembelianIndex::class)
            ->call('konversiPo', $pr->id)
            ->set('pemasok_id', $this->pemasok->id)
            ->set('syarat_pembayaran', 'TEMPO_30_HARI')
            ->set('itemRows', [
                [
                    'barang_id' => $this->barang->id,
                    'nama_barang' => $this->barang->nama,
                    'jumlah' => 0,
                    'harga_satuan' => 50000,
                    'subtotal' => 0,
                ]
            ])
            ->call('savePo')
            ->assertHasErrors(['itemRows.0.jumlah']);
    }

    public function test_save_po_rejects_unapproved_or_rejected_pr_and_non_numeric_custom_code_does_not_break_code_generator(): void
    {
        $this->actingAs($this->user);

        $pr = PermintaanPengadaan::create([
            'kode' => CodeGenerator::generatePr(),
            'unit_id' => $this->user->unit_id ?? 1,
            'diajukan_oleh' => $this->user->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => StatusPermintaanPengadaan::Diajukan->value,
        ]);

        Livewire::test(PesananPembelianIndex::class)
            ->set('prId', $pr->id)
            ->set('pemasok_id', $this->pemasok->id)
            ->set('syarat_pembayaran', 'TEMPO_30_HARI')
            ->set('itemRows', [
                [
                    'barang_id' => $this->barang->id,
                    'nama_barang' => $this->barang->nama,
                    'jumlah' => 10,
                    'harga_satuan' => 50000,
                    'subtotal' => 500000,
                ],
            ])
            ->call('savePo');

        $this->assertEquals(0, PesananPembelian::where('permintaan_pengadaan_id', $pr->id)->count());
        $pr->refresh();
        $this->assertEquals(StatusPermintaanPengadaan::Diajukan->value, $pr->status);

        // Pastikan akhiran non-numerik pada kode kustom tidak mereset urutan CodeGenerator ke 001
        $kodeStandard = CodeGenerator::generatePr();
        PermintaanPengadaan::create([
            'kode' => $kodeStandard,
            'unit_id' => $this->user->unit_id ?? 1,
            'diajukan_oleh' => $this->user->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => StatusPermintaanPengadaan::Diajukan->value,
        ]);
        PermintaanPengadaan::create([
            'kode' => 'PR-' . now()->format('Y') . '-CUSTOMLONG',
            'unit_id' => $this->user->unit_id ?? 1,
            'diajukan_oleh' => $this->user->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => StatusPermintaanPengadaan::Diajukan->value,
        ]);

        $nextKode = CodeGenerator::generatePr();
        $this->assertNotEquals('PR-' . now()->format('Y') . '-001', $nextKode);
        $this->assertNotEquals($kodeStandard, $nextKode);
    }
}
