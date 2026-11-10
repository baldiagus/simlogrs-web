<?php

namespace App\Livewire\Pengadaan;

use App\Enums\Pengadaan\StatusPermintaanPengadaan;
use App\Enums\Pengadaan\StatusPesananPembelian;
use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Models\Master\Pemasok;
use App\Models\Pengadaan\PermintaanPengadaan;
use App\Models\Pengadaan\PesananPembelian;
use App\Models\Pengadaan\PesananPembelianDetail;
use App\Support\Generator\CodeGenerator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class PesananPembelianIndex extends Component
{
    use HasTableFilters, HasToast, HasPortalLayout, HasConfirmationModal;

    public bool $showModal = false;
    public bool $showDetailModal = false;
    public ?object $selectedPo = null;

    #[Url(history: true)]
    public string $filterStatus = '';

    // Konversi PO form fields
    public ?int $prId = null;
    public ?int $pemasok_id = null;
    public ?string $estimasi_tanggal_kirim = null;
    public string $syarat_pembayaran = 'TEMPO_30_HARI';
    public string $catatan = '';
    public string $kode = '';
    public array $itemRows = [];

    /**
     * Hook Livewire saat filter status diubah untuk memperbarui data dan mereset paginasi.
     */
    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }
    /**
     * Reset seluruh parameter filter dan pencarian ke kondisi default.
     */
    public function resetFilters(): void
    {
        $this->search = '';
        $this->filterStatus = '';
        $this->resetPage();
    }

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        if (request()->has('konversi_pr')) {
            $prId = (int) request('konversi_pr');
            if ($prId > 0) {
                $this->konversiPo($prId);
            }
        }
    }

    /**
     * Buka formulir penerbitan PO hasil konversi dari PR yang telah disetujui.
     */
    public function konversiPo(int $prId): void
    {
        abort_unless(auth()->user()?->can('create_pengadaan::pesanan::pembelian'), 403);

        $pr = PermintaanPengadaan::with('detail.barang')->findOrFail($prId);

        if ($pr->status !== StatusPermintaanPengadaan::DiteruskanKePengadaan->value) {
            $this->toast("Hanya PR berstatus DITERUSKAN KE PENGADAAN yang dapat dikonversi (saat ini: {$pr->status}).", 'danger');
            return;
        }

        $alreadyHasPo = PesananPembelian::where('permintaan_pengadaan_id', $prId)
            ->where('status', '!=', StatusPesananPembelian::Dibatalkan->value)
            ->exists();
        if ($alreadyHasPo) {
            $this->toast('Permintaan Pengadaan ini sudah memiliki PO aktif.', 'danger');
            return;
        }
        $this->reset(['pemasok_id', 'estimasi_tanggal_kirim', 'catatan', 'itemRows']);
        $this->prId = $pr->id;
        $this->syarat_pembayaran = 'TEMPO_30_HARI';
        $this->kode = CodeGenerator::generatePo();

        $this->itemRows = $pr->detail->map(function ($d) {
            $jumlah = (int) ($d->jumlah_disetujui ?: $d->jumlah);
            $harga = (float) ($d->barang && $d->barang->harga_beli_terakhir > 0
                ? $d->barang->harga_beli_terakhir
                : ($d->barang->harga_estimasi ?? 0));

            return [
                'barang_id'    => $d->barang_id,
                'nama_barang'  => $d->barang->nama ?? '-',
                'jumlah'       => $jumlah,
                'harga_satuan' => $harga,
                'subtotal'     => $jumlah * $harga,
            ];
        })->toArray();

        $this->showModal = true;
    }

    /**
     * Kalkulasi ulang subtotal dan total biaya saat baris item PO berubah.
     */
    public function updatedItemRows(): void
    {
        foreach ($this->itemRows as $i => $row) {
            $harga = (float) ($row['harga_satuan'] ?? 0);
            $jumlah = (int) ($row['jumlah'] ?? 0);
            $this->itemRows[$i]['subtotal'] = $harga * $jumlah;
        }
    }

    /**
     * Validasi dan terbitkan dokumen Pesanan Pembelian (PO) ke database.
     */
    public function savePo(): void
    {
        abort_unless(auth()->user()?->can('create_pengadaan::pesanan::pembelian'), 403);
        if ($this->prId) {
            $alreadyHasPo = PesananPembelian::where('permintaan_pengadaan_id', $this->prId)
                ->where('status', '!=', StatusPesananPembelian::Dibatalkan->value)
                ->exists();
            if ($alreadyHasPo) {
                $this->toast('Permintaan Pengadaan ini sudah pernah diterbitkan menjadi Pesanan Pembelian (PO).', 'danger');
                return;
            }
        }

        $this->validate([
            'pemasok_id'            => 'required|exists:pemasok,id',
            'estimasi_tanggal_kirim' => 'nullable|date',
            'syarat_pembayaran'     => 'required|in:TEMPO_30_HARI,TEMPO_60_HARI,TUNAI',
            'catatan'               => 'nullable|string|max:1000',
            'itemRows'              => 'required|array|min:1',
            'itemRows.*.barang_id'  => 'required|exists:barang,id',
            'itemRows.*.jumlah'     => 'required|integer|min:1',
            'itemRows.*.harga_satuan' => 'required|numeric|min:0',
        ], [
            'pemasok_id.required'        => 'Pemasok / vendor rekanan wajib dipilih.',
            'syarat_pembayaran.required' => 'Syarat termin pembayaran wajib ditentukan.',
            'itemRows.*.jumlah.required' => 'Kuantitas barang wajib diisi.',
            'itemRows.*.jumlah.min'      => 'Kuantitas barang minimal 1 unit.',
            'itemRows.*.harga_satuan.required' => 'Harga satuan barang wajib diisi.',
            'itemRows.*.harga_satuan.min'      => 'Harga satuan tidak boleh bernilai negatif.',
        ]);

        $sudahAdaPo = false;
        $statusPrTidakValid = false;
        DB::transaction(function () use (&$sudahAdaPo, &$statusPrTidakValid) {
            // Kunci baris PR lalu cek ulang di dalam transaksi: dua penerbitan bersamaan tidak boleh sama-sama lolos.
            if ($this->prId) {
                $pr = PermintaanPengadaan::lockForUpdate()->find($this->prId);
                if (! $pr || $pr->status !== StatusPermintaanPengadaan::DiteruskanKePengadaan->value) {
                    $statusPrTidakValid = true;
                    return;
                }
                $sudahAdaPo = PesananPembelian::where('permintaan_pengadaan_id', $this->prId)
                    ->where('status', '!=', StatusPesananPembelian::Dibatalkan->value)
                    ->exists();
                if ($sudahAdaPo) {
                    return;
                }
            }
            $totalBiaya = collect($this->itemRows)->sum(fn ($r) => (int) $r['jumlah'] * (float) $r['harga_satuan']);

            $po = PesananPembelian::create([
                'kode'                    => $this->kode ?: CodeGenerator::generatePo(),
                'pemasok_id'              => $this->pemasok_id,
                'permintaan_pengadaan_id' => $this->prId,
                'tanggal_po'              => now()->toDateString(),
                'estimasi_tanggal_kirim'  => $this->estimasi_tanggal_kirim,
                'total_biaya'             => $totalBiaya,
                'syarat_pembayaran'       => $this->syarat_pembayaran,
                'status'                  => StatusPesananPembelian::Diterbitkan->value,
                'catatan'                 => $this->catatan,
                'dibuat_oleh'             => auth()->id(),
            ]);

            foreach ($this->itemRows as $row) {
                PesananPembelianDetail::create([
                    'pesanan_pembelian_id' => $po->id,
                    'barang_id'            => $row['barang_id'],
                    'jumlah_dipesan'       => $row['jumlah'],
                    'harga_satuan'         => $row['harga_satuan'],
                    'subtotal'             => $row['subtotal'],
                ]);
            }

            if ($this->prId) {
                PermintaanPengadaan::where('id', $this->prId)->update([
                    'status' => StatusPermintaanPengadaan::Diproses->value,
                ]);
            }
        });

        if ($statusPrTidakValid) {
            $this->toast('Hanya Permintaan Pengadaan berstatus DITERUSKAN KE PENGADAAN yang dapat diterbitkan menjadi PO.', 'danger');
            return;
        }

        if ($sudahAdaPo) {
            $this->toast('Permintaan Pengadaan ini sudah pernah diterbitkan menjadi Pesanan Pembelian (PO).', 'danger');
            return;
        }

        $this->showModal = false;
        $this->toast('Pesanan Pembelian berhasil dibuat.', 'success');
    }

    /**
     * Buka modal dialog dan muat data rincian record terpilih.
     */
    public function showDetail(int $id): void
    {
        abort_unless(auth()->user()?->can('view_any_pengadaan::pesanan::pembelian') || auth()->user()?->can('view_pengadaan::pesanan::pembelian'), 403);

        $this->selectedPo = PesananPembelian::with(['pemasok', 'permintaanPengadaan', 'detail.barang', 'pembuat'])
            ->findOrFail($id);
        $this->showDetailModal = true;
    }
    /**
     * Tampilkan modal konfirmasi pembaruan status pengiriman barang.
     */
    public function confirmUpdateStatusKirim(int $id): void
    {
        abort_unless(auth()->user()?->can('update_pengadaan::pesanan::pembelian'), 403);

        $po = PesananPembelian::with('pemasok')->findOrFail($id);
        $this->askConfirmation(
            target: $id,
            action: 'updateStatusKirim',
            title: 'Konfirmasi Pengiriman Vendor',
            description: "Ubah status pesanan {$po->kode} (Vendor: {$po->pemasok?->nama}) menjadi DIKIRIM_VENDOR? Menandakan barang dalam perjalanan.",
            buttonText: 'Ya, Tandai Sedang Dikirim',
            variant: 'primary'
        );
    }


    /**
     * Perbarui status perjalanan pengiriman distribusi barang.
     */
    public function updateStatusKirim(int $id): void
    {
        abort_unless(auth()->user()?->can('update_pengadaan::pesanan::pembelian'), 403);

        $po = PesananPembelian::findOrFail($id);

        if ($po->status !== StatusPesananPembelian::Diterbitkan->value) {
            $this->toast('Status PO tidak dapat diubah.', 'danger');
            return;
        }

        $po->update(['status' => StatusPesananPembelian::DikirimVendor->value]);
        $this->toast("PO {$po->kode} telah diperbarui ke status DIKIRIM VENDOR.", 'success');
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        abort_unless(auth()->user()?->can('view_any_pengadaan::pesanan::pembelian') || auth()->user()?->can('view_pengadaan::pesanan::pembelian'), 403);

        $daftarPemasok = Pemasok::where('status', 'AKTIF')->get();

        $daftarPrSiapKonversi = PermintaanPengadaan::where('status', StatusPermintaanPengadaan::DiteruskanKePengadaan->value)
            ->whereDoesntHave('pesananPembelian')
            ->get();

        $items = PesananPembelian::with(['pemasok', 'permintaanPengadaan', 'detail', 'pembuat'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('kode', 'ilike', "%{$this->search}%")
                        ->orWhereHas('pemasok', fn ($p) => $p->where('nama', 'ilike', "%{$this->search}%"));
                });
            })
            ->orderBy($this->sortBy ?: 'id', $this->sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate($this->perPage);

        return view('livewire.pengadaan.pesanan-pembelian-index', [
            'items'                 => $items,
            'daftarPemasok'         => $daftarPemasok,
            'daftarPrSiapKonversi'  => $daftarPrSiapKonversi,
        ])->layout('layouts.portal', ['portal' => $this->getPortalName(), 'title' => 'Pesanan Pembelian (PO)']);
    }
}
