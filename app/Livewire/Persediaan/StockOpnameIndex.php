<?php

namespace App\Livewire\Persediaan;

use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Livewire\Persediaan\Concerns\HasFormOpname;
use App\Models\Master\Gudang;
use App\Models\Persediaan\StokBatch;
use App\Models\Persediaan\StokOpname;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Rap2hpoutre\FastExcel\FastExcel;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class StockOpnameIndex extends Component
{
    use HasTableFilters, HasToast, HasPortalLayout, HasConfirmationModal, WithFileUploads;
    use HasFormOpname;

    #[Url(history: true)]
    public string $filterStatus = '';

    #[Url(history: true)]
    public ?int $filterGudangId = null;

    // Modal Inisiasi Opname
    public bool $showInisiasiModal = false;

    #[Validate('required|exists:gudang,id')]
    public ?int $gudang_id = null;

    #[Validate('required|date')]
    public string $tanggal_opname = '';

    #[Validate('nullable|string')]
    public ?string $catatan = null;

    // Modal Input Blind Count
    public bool $showCountModal = false;
    public ?StokOpname $activeOpname = null;
    public array $countRows = [];

    // Modal QR Code Batch
    public bool $showQrModal = false;
    public ?StokBatch $selectedBatchQr = null;
    public ?string $qrCodeSvg = null;

    // Modal Detail Opname
    public bool $showDetailModal = false;
    public ?StokOpname $selectedOpname = null;

    // Excel Upload
    public $fileExcel = null;

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        $this->tanggal_opname = now()->toDateString();
    }

    /**
     * Ekspor data tabel ke format spreadsheet Excel.
     */
    public function exportExcel(int $opnameId)
    {
        abort_unless(
            auth()->user()?->can('view_any_gudang_stok::stok::opname') ||
            auth()->user()?->can('view_gudang_stok::stok::opname'),
            403
        );

        $opname = StokOpname::with(['gudang', 'detail.barang.satuan', 'detail.stokBatch.lokasiGudang'])->findOrFail($opnameId);

        $user = auth()->user();
        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik']);
        abort_unless(
            $isGlobalAdmin || ($user?->gudang_id && (int) $user->gudang_id === (int) $opname->gudang_id),
            403,
            'Anda tidak memiliki wewenang mengunduh data opname gudang lain.'
        );

        $rows = $opname->detail->map(fn ($d) => [
            'ID_Detail'     => $d->id,
            'Kode_Barang'   => $d->barang?->kode,
            'Nama_Barang'   => $d->barang?->nama,
            'Nomor_Batch'   => $d->stokBatch?->nomor_batch,
            'Lokasi_Rak'    => $d->stokBatch?->lokasiGudang?->nama ?? 'Tanpa Rak',
            'Satuan'        => $d->barang?->satuan?->simbol ?? 'Unit',
            'Jumlah_Fisik'  => $d->jumlah_fisik,
            'Catatan'       => $d->catatan ?? '',
        ]);

        $fileName = "Lembar_Opname_{$opname->kode}.xlsx";
        return (new FastExcel($rows))->download($fileName);
    }

    /**
     * Import data hasil perhitungan fisik stock opname dari file Excel.
     */
    public function importExcel(): void
    {
        $this->validate([
            'fileExcel' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ], [
            'fileExcel.required' => 'Berkas Excel hasil opname fisik wajib diunggah.',
            'fileExcel.mimes'    => 'Format berkas harus berekstensi .xlsx, .xls, atau .csv.',
            'fileExcel.max'      => 'Ukuran berkas Excel maksimal 5 MB.',
        ]);

        if (! $this->activeOpname) {
            return;
        }
        abort_unless(auth()->user()?->can('update_gudang_stok::stok::opname') || auth()->user()?->can('create_gudang_stok::stok::opname'), 403, 'Anda tidak memiliki wewenang mengimpor hasil stock opname.');

        $user = auth()->user();
        $isGlobalAdmin = $user?->hasRole(['super_admin', 'admin_logistik']);
        abort_unless($isGlobalAdmin || ($user?->gudang_id && (int) $user->gudang_id === (int) $this->activeOpname->gudang_id), 403, 'Anda tidak memiliki wewenang memproses opname di fasilitas gudang ini.');

        try {
            $path = $this->fileExcel->getRealPath();
            $collection = (new FastExcel)->import($path);

            $updatedCount = 0;
            foreach ($collection as $row) {
                $detailId = $row['ID_Detail'] ?? null;
                $fisik = isset($row['Jumlah_Fisik']) ? (int) $row['Jumlah_Fisik'] : null;

                if ($detailId && $fisik !== null && isset($this->countRows[$detailId])) {
                    $this->countRows[$detailId]['jumlah_fisik'] = max(0, $fisik);
                    if (! empty($row['Catatan'])) {
                        $this->countRows[$detailId]['catatan'] = trim($row['Catatan']);
                    }
                    $updatedCount++;
                }
            }

            $this->reset('fileExcel');
            $this->toast("Berhasil mengimpor {$updatedCount} baris perhitungan fisik dari Excel.", 'success');
        } catch (Exception $e) {
            $this->toast("Gagal mengimpor file Excel: {$e->getMessage()}", 'danger');
        }
    }

    /**
     * Tampilkan kode QR untuk identifikasi batch persediaan barang.
     */
    public function showQrBatch(int $batchId): void
    {
        $this->selectedBatchQr = StokBatch::with(['barang', 'gudang', 'lokasiGudang'])->findOrFail($batchId);
        $this->qrCodeSvg = QrCode::size(160)
            ->margin(1)
            ->errorCorrection('H')
            ->generate($this->selectedBatchQr->nomor_batch);

        $this->showQrModal = true;
    }

    /**
     * Buka modal dialog dan muat data rincian record terpilih.
     */
    public function showDetail(int $opnameId): void
    {
        abort_unless(
            auth()->user()?->can('view_any_gudang_stok::stok::opname') ||
            auth()->user()?->can('view_gudang_stok::stok::opname'),
            403
        );

        $opname = StokOpname::with([
            'gudang',
            'pembuat',
            'penyetuju',
            'detail.barang.satuan',
            'detail.stokBatch.lokasiGudang',
            'penyesuaian',
        ])->findOrFail($opnameId);

        $user = auth()->user();
        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik', 'auditor', 'manajemen']);
        abort_unless(
            $isGlobalAdmin || ($user?->gudang_id && (int) $user->gudang_id === (int) $opname->gudang_id),
            403,
            'Anda tidak memiliki wewenang melihat detail opname gudang lain.'
        );

        $this->selectedOpname = $opname;
        $this->showDetailModal = true;
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        abort_unless(
            auth()->user()?->can('view_any_gudang_stok::stok::opname') ||
            auth()->user()?->can('view_gudang_stok::stok::opname'),
            403
        );

        $user = auth()->user();
        $isGlobalAdmin = $user->hasRole(['super_admin', 'admin_logistik']);

        $query = StokOpname::with(['gudang', 'pembuat', 'penyetuju', 'penyesuaian'])
            ->withCount('detail')
            ->when(! $isGlobalAdmin && $user?->gudang_id, fn ($q) => $q->where('gudang_id', $user->gudang_id))
            ->when($this->filterGudangId, fn ($q) => $q->where('gudang_id', $this->filterGudangId))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('kode', 'ilike', "%{$this->search}%")
                        ->orWhereHas('gudang', fn ($g) => $g->where('nama', 'ilike', "%{$this->search}%"));
                });
            });

        $items = $query->orderBy('id', 'desc')->paginate($this->perPage);

        $daftarGudang = Cache::remember('dropdown_gudang_aktif', config('simlog.cache.dropdown_ttl'), fn () => Gudang::where('status', 'AKTIF')->orderBy('nama')->get());

        return view('livewire.persediaan.stock-opname-index', [
            'items'        => $items,
            'daftarGudang' => $daftarGudang,
            'canManage'    => $isGlobalAdmin || $user?->can('create_gudang_stok::stok::opname'),
        ])->layout('layouts.portal', ['portal' => $this->getPortalName(), 'title' => 'Stock Opname Fisik (Blind Count)']);
    }
}
