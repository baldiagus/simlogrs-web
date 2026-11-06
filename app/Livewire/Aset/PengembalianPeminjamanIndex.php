<?php

namespace App\Livewire\Aset;

use App\Enums\Aset\StatusPeminjaman;
use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Models\Aset\PengembalianPeminjaman;
use App\Models\Aset\PermintaanPeminjaman;
use App\Services\Aset\PeminjamanAsetService;
use Livewire\Attributes\Url;
use Livewire\Component;

class PengembalianPeminjamanIndex extends Component
{
    use HasTableFilters, HasToast, HasPortalLayout, HasConfirmationModal;

    public string $pageTitle = 'Pengembalian & Inspeksi Fisik Aset';
    #[Url(history: true)]
    public string $activeTab = 'menunggu'; // 'menunggu' | 'riwayat'


    public ?int $selectedPinjamId = null;
    public ?PermintaanPeminjaman $selectedPinjam = null;
    public array $inspeksiRows = [];
    public string $catatanUmum = '';
    public bool $showPengembalianModal = false;

    public ?PengembalianPeminjaman $selectedPengembalian = null;
    public bool $showDetailPengembalianModal = false;

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage('pinjam_page');
        $this->resetPage('kembali_page');
    }

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        $this->checkAccess();
    }

    /**
     * Pastikan hak akses modul berbasis izin dinamis Spatie.
     */
    protected function checkAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (
                $user->can('view_any_layanan_unit::peminjaman::aset') ||
                $user->can('view_layanan_unit::peminjaman::aset') ||
                $user->can('view_any_layanan_unit::pengembalian::aset') ||
                $user->can('view_layanan_unit::pengembalian::aset')
            ),
            403,
            'Anda tidak memiliki wewenang untuk mengakses halaman Pengembalian & Inspeksi Fisik Aset.'
        );
    }

    /**
     * Buka modal inspeksi fisik dan siapkan baris kondisi aset.
     */
    protected function authorizeProviderAccess(PermintaanPeminjaman $pinjam): void
    {
        $user = auth()->user();
        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik']);

        if (! $isGlobalAdmin) {
            abort_unless($user?->canAny(['create_layanan_unit::pengembalian::aset', 'update_layanan_unit::pengembalian::aset']), 403, 'Anda tidak memiliki izin memproses pengembalian & inspeksi fisik aset.');
            if ($pinjam->tipe_penyedia === 'RUANGAN' && (! $user?->unit_id || (int) $user->unit_id !== (int) $pinjam->unit_penyedia_id)) {
                abort(403, 'Hanya ruangan penyedia yang berhak memproses inspeksi & pengembalian fisik aset.');
            }
            if ($pinjam->tipe_penyedia === 'GUDANG' && (! $user?->gudang_id || (int) $user->gudang_id !== (int) $pinjam->gudang_penyedia_id)) {
                abort(403, 'Hanya fasilitas gudang penyedia yang berhak memproses inspeksi & pengembalian fisik aset.');
            }
        }
    }

    /**
     * Buka modal formulir pencatatan pengembalian aset pinjaman.
     */
    public function openPengembalianModal(int $id): void
    {
        $this->checkAccess();

        $pinjam = PermintaanPeminjaman::with([
            'unitPeminjam',
            'unitPenyedia',
            'gudangPenyedia',
            'pengaju',
            'detail.aset.barang',
        ])->findOrFail($id);
        $this->authorizeProviderAccess($pinjam);

        if ($pinjam->status !== StatusPeminjaman::Dipinjam->value) {
            $this->toast('Hanya peminjaman berstatus DIPINJAM yang dapat diproses pengembalian.', 'warning');
            return;
        }

        $this->selectedPinjamId = $pinjam->id;
        $this->selectedPinjam = $pinjam;
        $this->catatanUmum = '';
        $this->inspeksiRows = [];

        foreach ($pinjam->detail as $detail) {
            $this->inspeksiRows[$detail->id] = [
                'permintaan_peminjaman_detail_id' => $detail->id,
                'aset_id'                         => $detail->aset_id,
                'nama_aset'                       => $detail->aset?->barang?->nama ?? ('Aset #' . $detail->aset_id),
                'kode_aset'                       => $detail->aset?->kode_aset ?? '-',
                'kondisi_saat_pinjam'             => $detail->kondisi_saat_pinjam ?? 'BAIK',
                'kondisi_saat_kembali'            => 'BAIK',
                'catatan'                         => '',
            ];
        }

        $this->showPengembalianModal = true;
    }

    /**
     * Tutup modal inspeksi pengembalian fisik aset.
     */
    public function closePengembalianModal(): void
    {
        $this->showPengembalianModal = false;
        $this->reset(['selectedPinjamId', 'selectedPinjam', 'inspeksiRows', 'catatanUmum']);
    }

    /**
     * Minta konfirmasi sebelum mengeksekusi pengembalian aset.
     */
    public function confirmSubmitPengembalian(): void
    {
        $this->validate([
            'inspeksiRows'                        => 'required|array|min:1',
            'inspeksiRows.*.kondisi_saat_kembali' => 'required|in:BAIK,RUSAK_RINGAN,RUSAK_BERAT',
            'inspeksiRows.*.catatan'              => 'nullable|string|max:500',
            'catatanUmum'                         => 'nullable|string|max:1000',
        ], [
            'inspeksiRows.*.kondisi_saat_kembali.required' => 'Kondisi fisik aset saat serah terima kembali wajib dinilai.',
        ]);

        $this->askConfirmation(
            target: null,
            action: 'submitPengembalian',
            title: 'Konfirmasi Pengembalian Aset',
            description: "Apakah Anda yakin ingin menyelesaikan proses pengembalian dan inspeksi fisik untuk transaksi {$this->selectedPinjam?->kode}?",
            buttonText: 'Ya, Terima Pengembalian',
            variant: 'primary'
        );
    }

    /**
     * Eksekusi transaksi pengembalian via service.
     */
    public function submitPengembalian(): void
    {
        $this->checkAccess();

        if (! $this->selectedPinjamId || empty($this->inspeksiRows)) {
            return;
        }

        $pinjam = PermintaanPeminjaman::findOrFail($this->selectedPinjamId);
        $this->authorizeProviderAccess($pinjam);

        if ($pinjam->status !== StatusPeminjaman::Dipinjam->value) {
            $this->toast('Status transaksi peminjaman sudah tidak aktif dipinjam.', 'warning');
            $this->closePengembalianModal();
            return;
        }

        $this->validate([
            'inspeksiRows'                        => 'required|array|min:1',
            'inspeksiRows.*.kondisi_saat_kembali' => 'required|in:BAIK,RUSAK_RINGAN,RUSAK_BERAT',
            'inspeksiRows.*.catatan'              => 'nullable|string|max:500',
            'catatanUmum'                         => 'nullable|string|max:1000',
        ], [
            'inspeksiRows.*.kondisi_saat_kembali.required' => 'Kondisi fisik aset saat serah terima kembali wajib dinilai.',
        ]);

        $hasDamaged = collect($this->inspeksiRows)->contains(
            fn ($row) => in_array($row['kondisi_saat_kembali'] ?? 'BAIK', ['RUSAK_RINGAN', 'RUSAK_BERAT'])
        );

        try {
            app(PeminjamanAsetService::class)->prosesPengembalian(
                $pinjam,
                $this->inspeksiRows,
                auth()->user(),
                $this->catatanUmum ?: null
            );

            $this->showPengembalianModal = false;
            $this->reset(['selectedPinjamId', 'selectedPinjam', 'inspeksiRows', 'catatanUmum']);

            if ($hasDamaged) {
                $this->toast('Aset berhasil dikembalikan. Tiket perbaikan otomatis diterbitkan ke teknisi IPSRS.', 'warning');
            } else {
                $this->toast('Aset berhasil dikembalikan dalam kondisi baik dan siap digunakan kembali.', 'success');
            }
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), 'danger');
        }
    }

    /**
     * Buka rincian riwayat pengembalian yang sudah selesai.
     */
    public function openDetailPengembalianModal(int $id): void
    {
        $this->checkAccess();

        $this->selectedPengembalian = PengembalianPeminjaman::with([
            'permintaanPeminjaman.unitPeminjam',
            'permintaanPeminjaman.unitPenyedia',
            'permintaanPeminjaman.gudangPenyedia',
            'pemeriksa',
            'detail.aset.barang',
            'detail.maintenance',
        ])->findOrFail($id);

        $scoped = $this->applyScope(PengembalianPeminjaman::query()->whereKey($id), auth()->user(), 'permintaanPeminjaman');
        abort_unless($scoped->exists(), 403, 'Anda tidak memiliki wewenang untuk melihat riwayat pengembalian ini.');

        $this->showDetailPengembalianModal = true;
    }

    /**
     * Filter data peminjaman sesuai wewenang unit dan gudang pengguna.
     */
    protected function applyScope($query, $user, string $peminjamanRelation = '')
    {
        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik']);

        if ($isGlobalAdmin) {
            return $query;
        }

        $scopeCallback = function ($q) use ($user) {
            $scoped = false;

            if ($user?->unit_id) {
                $scoped = true;
                $q->where('unit_id', $user->unit_id)
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('tipe_penyedia', 'RUANGAN')
                          ->where('unit_penyedia_id', $user->unit_id);
                  });
            }

            if ($user?->gudang_id) {
                $scoped = true;
                $q->orWhere(function ($sub) use ($user) {
                    $sub->where('tipe_penyedia', 'GUDANG')
                        ->where('gudang_penyedia_id', $user->gudang_id);
                });
            }

            if (! $scoped) {
                // Pembaca global hanya auditor/manajemen (ADR 0001); selain itu fail-closed.
                $q->whereRaw($user?->hasRole(['auditor', 'manajemen']) ? '1=1' : '1=0');
            }
        };

        if ($peminjamanRelation !== '') {
            return $query->whereHas($peminjamanRelation, $scopeCallback);
        }

        return $query->where($scopeCallback);
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        $this->checkAccess();

        $user = auth()->user();
        $isGlobalAdmin = (bool) ($user && $user->hasRole(['super_admin', 'admin_logistik']));
        // Kueri peminjaman aktif berstatus DIPINJAM
        $activeLoansQuery = PermintaanPeminjaman::query()
            ->with([
                'unitPeminjam',
                'unitPenyedia',
                'gudangPenyedia',
                'pengaju',
                'detail.aset.barang',
            ])
            ->withCount('detail')
            ->where('status', StatusPeminjaman::Dipinjam->value);

        $this->applyScope($activeLoansQuery, $user);

        if ($this->search) {
            $term = trim($this->search);
            $activeLoansQuery->where(function ($q) use ($term) {
                $q->where('kode', 'ilike', "%{$term}%")
                  ->orWhereHas('unitPeminjam', fn ($u) => $u->where('nama', 'ilike', "%{$term}%"))
                  ->orWhereHas('unitPenyedia', fn ($u) => $u->where('nama', 'ilike', "%{$term}%"))
                  ->orWhereHas('gudangPenyedia', fn ($g) => $g->where('nama', 'ilike', "%{$term}%"))
                  ->orWhereHas('detail.aset', function ($a) use ($term) {
                      $a->where('kode_aset', 'ilike', "%{$term}%")
                        ->orWhereHas('barang', fn ($b) => $b->where('nama', 'ilike', "%{$term}%"));
                  });
            });
        }

        $sortCol = in_array($this->sortBy, ['kode', 'tanggal_pinjam', 'tanggal_kembali_rencana', 'id']) ? $this->sortBy : 'id';
        $daftarPeminjaman = $activeLoansQuery
            ->orderBy($sortCol, $this->sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate($this->perPage, ['*'], 'pinjam_page');

        // Kueri riwayat pengembalian yang telah selesai
        $historyQuery = PengembalianPeminjaman::query()
            ->with([
                'permintaanPeminjaman.unitPeminjam',
                'permintaanPeminjaman.unitPenyedia',
                'permintaanPeminjaman.gudangPenyedia',
                'pemeriksa',
                'detail.aset.barang',
                'detail.maintenance',
            ])
            ->withCount('detail');

        $this->applyScope($historyQuery, $user, 'permintaanPeminjaman');

        if ($this->search) {
            $term = trim($this->search);
            $historyQuery->where(function ($q) use ($term) {
                $q->where('kode', 'ilike', "%{$term}%")
                  ->orWhereHas('permintaanPeminjaman', fn ($p) => $p->where('kode', 'ilike', "%{$term}%"))
                  ->orWhereHas('permintaanPeminjaman.unitPeminjam', fn ($u) => $u->where('nama', 'ilike', "%{$term}%"))
                  ->orWhereHas('pemeriksa', fn ($pm) => $pm->where('nama', 'ilike', "%{$term}%"))
                  ->orWhereHas('detail.aset', function ($a) use ($term) {
                      $a->where('kode_aset', 'ilike', "%{$term}%")
                        ->orWhereHas('barang', fn ($b) => $b->where('nama', 'ilike', "%{$term}%"));
                  });
            });
        }

        $riwayatPengembalian = $historyQuery
            ->orderBy('id', 'desc')
            ->paginate($this->perPage, ['*'], 'kembali_page');

        return view('livewire.aset.pengembalian-peminjaman-index', [
            'daftarPeminjaman'    => $daftarPeminjaman,
            'riwayatPengembalian' => $riwayatPengembalian,
            'pageTitle'           => $this->pageTitle,
            'sortBy'              => $this->sortBy,
            'sortDirection'       => $this->sortDirection,
            'isGlobalAdmin'       => $isGlobalAdmin,
        ])->layout('layouts.portal', [
            'portal' => $this->getPortalName(),
            'title'  => $this->pageTitle,
        ]);
    }
}
