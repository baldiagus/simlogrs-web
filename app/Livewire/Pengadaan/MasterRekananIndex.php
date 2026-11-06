<?php

namespace App\Livewire\Pengadaan;

use App\Enums\Pengadaan\StatusPesananPembelian;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Models\Master\Pemasok;
use App\Models\Pengadaan\PesananPembelian;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class MasterRekananIndex extends Component
{
    use HasPortalLayout, HasToast, HasTableFilters, WithPagination;

    #[Url(history: true)]
    public string $filterKategori = ''; // 'Semua', 'Aktif', 'Nonaktif'

    public ?int $selectedPemasokId = null;
    public bool $showDetailModal = false;

    /**
     * Dijalankan pada setiap request (render awal maupun aksi). Data rekanan bersifat
     * katalog vendor global, sehingga tidak ada pembatasan scope unit/gudang.
     */
    public function boot(): void
    {
        $user = auth()->user();
        abort_unless(
            $user?->can('view_any_pengadaan::master::rekanan') || $user?->can('view_pengadaan::master::rekanan'),
            403,
            'Akses ditolak: Anda tidak memiliki wewenang melihat Evaluasi Rekanan Vendor.'
        );
    }

    /**
     * Buka modal dialog untuk menampilkan rincian data terpilih.
     */
    public function openDetail(int $id): void
    {
        $this->selectedPemasokId = $id;
        $this->showDetailModal = true;
    }

    /**
     * Computed property Livewire untuk mengakses data selected pemasok.
     */
    public function getSelectedPemasokProperty(): ?Pemasok
    {
        return $this->selectedPemasokId
            ? Pemasok::with(['pesananPembelian' => function ($q) {
                $q->latest('id')->take(10);
            }])->find($this->selectedPemasokId)
            : null;
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        $query = Pemasok::withCount(['pesananPembelian' => fn ($q) => $q->where('status', '!=', StatusPesananPembelian::Dibatalkan->value)])
            ->withSum(['pesananPembelian' => fn ($q) => $q->where('status', '!=', StatusPesananPembelian::Dibatalkan->value)], 'total_biaya');

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('nama', 'ilike', "%{$this->search}%")
                  ->orWhere('kode', 'ilike', "%{$this->search}%")
                  ->orWhere('kota', 'ilike', "%{$this->search}%");
            });
        }

        if (! empty($this->filterKategori)) {
            $query->where('status', $this->filterKategori);
        }

        $items = $query->orderBy('nama')->paginate($this->perPage);

        $totalRekanan = Pemasok::count();
        $rekananAktif = Pemasok::where('status', 'AKTIF')->count();
        $totalBelanjaVendor = (float) PesananPembelian::whereIn('status', StatusPesananPembelian::tidakDibatalkan())->sum('total_biaya');

        return view('livewire.pengadaan.master-rekanan-index', [
            'items' => $items,
            'totalRekanan' => $totalRekanan,
            'rekananAktif' => $rekananAktif,
            'totalBelanjaVendor' => $totalBelanjaVendor,
            'selectedPemasok' => $this->selectedPemasok,
        ])->layout('layouts.portal', [
            'title' => 'Evaluasi Kinerja Rekanan & Pemasok Vendor',
            'portal' => $this->getPortalName(),
        ]);
    }
}
