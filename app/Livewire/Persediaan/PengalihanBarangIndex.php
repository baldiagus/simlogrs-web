<?php

namespace App\Livewire\Persediaan;

use App\Enums\Aset\StatusPengalihan;
use App\Livewire\Concerns\AuthorizesPengalihan;
use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Livewire\Persediaan\Concerns\HasFormPengalihanBarang;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\LokasiGudang;
use App\Models\Persediaan\PengalihanBarang;
use App\Models\Persediaan\StokBatch;
use App\Models\Master\Unit;
use App\Services\Persediaan\PengalihanBarangService;
use Livewire\Attributes\Url;
use Livewire\Component;

class PengalihanBarangIndex extends Component
{
    use HasTableFilters, HasToast, HasPortalLayout, HasConfirmationModal, AuthorizesPengalihan;
    use HasFormPengalihanBarang;

    /**
     * Dapatkan referensi entitas model pengalihan persediaan barang.
     */
    protected function entitasPengalihan(): string
    {
        return 'gudang_stok::pengalihan::barang';
    }

    #[Url(history: true)]
    public string $activeTab = 'aktif'; // 'aktif' | 'riwayat'

    #[Url(history: true)]
    public string $filterStatus = '';

    #[Url(history: true)]
    public ?int $filterGudangAsalId = null;

    // Modal Create State
    public bool $showCreateModal = false;
    public ?int $selected_barang_id = null;
    public string $tipe_asal = 'GUDANG';
    public ?int $gudang_asal_id = null;
    public ?int $unit_asal_id = null;
    public ?int $selected_stok_batch_id = null;
    public string $tipe_tujuan = 'GUDANG';
    public ?int $gudang_tujuan_id = null;
    public ?int $unit_tujuan_id = null;
    public ?int $lokasi_gudang_tujuan_id = null;
    public int $quantity = 1;
    public string $tanggal_pengalihan = '';
    public string $alasan = '';
    public string $catatan = '';

    // Modal Detail / BAST
    public bool $showDetailModal = false;
    public ?PengalihanBarang $selectedTransfer = null;

    // Modal Otorisasi
    public bool $showApprovalModal = false;
    public ?int $actionTransferId = null;
    public string $actionType = ''; // 'APPROVE' | 'REJECT'
    public string $catatanApproval = '';

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        $this->tanggal_pengalihan = now()->toDateString();
        $user = auth()->user();
        $isGlobalAdmin = (bool) ($user && $user->hasRole(['super_admin', 'admin_logistik']));

        if (! $isGlobalAdmin) {
            if ($user?->gudang_id) {
                $this->tipe_tujuan = 'GUDANG';
                $this->gudang_tujuan_id = $user->gudang_id;
                $this->unit_tujuan_id = null;
            } elseif ($user?->unit_id) {
                $this->tipe_tujuan = 'RUANGAN';
                $this->unit_tujuan_id = $user->unit_id;
                $this->gudang_tujuan_id = null;
            }

            $this->tipe_asal = 'GUDANG';
            $this->gudang_asal_id = Gudang::where('status', 'AKTIF')
                ->where('id', '!=', $this->gudang_tujuan_id)
                ->value('id');
        } else {
            $this->gudang_asal_id = Gudang::where('status', 'AKTIF')->value('id');
            $this->tipe_tujuan = 'GUDANG';
            $this->gudang_tujuan_id = Gudang::where('status', 'AKTIF')
                ->where('id', '!=', $this->gudang_asal_id)
                ->value('id');
        }
    }

    /**
     * Ubah tab navigasi yang sedang aktif.
     */
    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['aktif', 'otorisasi', 'riwayat'], true) ? $tab : 'aktif';
        $this->resetPage();
    }

    /**
     * Buka modal formulir permohonan pengalihan barang antar-gudang.
     */
    public function openCreateModal(): void
    {
        $user = auth()->user();
        $canCreate = $user && $user->can('create_gudang_stok::pengalihan::barang');

        abort_unless($canCreate, 403, 'Akses ditolak: Hanya fasilitas gudang logistik yang berwenang mengajukan pengalihan barang.');

        $this->resetValidation();
        $this->tanggal_pengalihan = now()->toDateString();
        $this->selected_barang_id = null;
        $this->selected_stok_batch_id = null;
        $this->quantity = 1;
        $this->alasan = '';
        $this->catatan = '';

        $isGlobalAdmin = (bool) ($user && $user->hasRole(['super_admin', 'admin_logistik']));
        if (! $isGlobalAdmin) {
            if ($user?->gudang_id) {
                $this->tipe_tujuan = 'GUDANG';
                $this->gudang_tujuan_id = $user->gudang_id;
                $this->unit_tujuan_id = null;
            } elseif ($user?->unit_id) {
                $this->tipe_tujuan = 'RUANGAN';
                $this->unit_tujuan_id = $user->unit_id;
                $this->gudang_tujuan_id = null;
            }

            $this->tipe_asal = 'GUDANG';
            $this->gudang_asal_id = Gudang::where('status', 'AKTIF')
                ->where('id', '!=', $this->gudang_tujuan_id)
                ->value('id');
        }

        $this->showCreateModal = true;
    }

    /**
     * Validasi dan ajukan permohonan pengalihan stok barang antar-gudang.
     */
    public function submitPengalihan(): void
    {
        $user = auth()->user();
        $canCreate = $user && $user->can('create_gudang_stok::pengalihan::barang');

        abort_unless($canCreate, 403, 'Akses ditolak.');

        $isGlobalAdmin = (bool) ($user && $user->hasRole(['super_admin', 'admin_logistik']));
        if (! $isGlobalAdmin) {
            if ($user?->gudang_id) {
                $this->tipe_tujuan = 'GUDANG';
                $this->gudang_tujuan_id = $user->gudang_id;
                $this->unit_tujuan_id = null;
            } elseif ($user?->unit_id) {
                $this->tipe_tujuan = 'RUANGAN';
                $this->unit_tujuan_id = $user->unit_id;
                $this->gudang_tujuan_id = null;
            }
        }

        $this->validate([
            'selected_barang_id' => 'required|exists:barang,id',
            'tipe_asal' => 'required|in:GUDANG,RUANGAN',
            'gudang_asal_id' => 'required_if:tipe_asal,GUDANG|nullable|exists:gudang,id',
            'selected_stok_batch_id' => 'required_if:tipe_asal,GUDANG|nullable|exists:stok_batch,id',
            'tipe_tujuan' => 'required|in:GUDANG,RUANGAN',
            'gudang_tujuan_id' => 'required_if:tipe_tujuan,GUDANG|nullable|exists:gudang,id',
            'unit_tujuan_id' => 'required_if:tipe_tujuan,RUANGAN|nullable|exists:unit,id',
            'quantity' => 'required|integer|min:1',
            'tanggal_pengalihan' => 'required|date',
            'alasan' => 'required|string|min:5|max:1000',
        ], [
            'selected_barang_id.required' => 'Komoditas barang wajib dipilih.',
            'selected_stok_batch_id.required_if' => 'Batch stok fisik asal wajib dipilih.',
            'alasan.required' => 'Alasan / dasar pengalihan wajib diisi.',
            'alasan.min' => 'Alasan minimal 5 karakter.',
        ]);

        if ($this->tipe_asal === 'GUDANG' && $this->tipe_tujuan === 'GUDANG' && (int) $this->gudang_asal_id === (int) $this->gudang_tujuan_id) {
            $this->addError('gudang_asal_id', 'Gudang asal dan gudang tujuan pengalihan tidak boleh sama.');
            return;
        }

        try {
            $service = app(PengalihanBarangService::class);
            $transfer = $service->ajukanPengalihan([
                'tipe_asal' => $this->tipe_asal,
                'gudang_asal_id' => $this->gudang_asal_id,
                'unit_asal_id' => $this->unit_asal_id,
                'tipe_tujuan' => $this->tipe_tujuan,
                'gudang_tujuan_id' => $this->gudang_tujuan_id,
                'unit_tujuan_id' => $this->unit_tujuan_id,
                'lokasi_gudang_tujuan_id' => $this->lokasi_gudang_tujuan_id,
                'tanggal_pengalihan' => $this->tanggal_pengalihan,
                'alasan' => $this->alasan,
                'catatan' => $this->catatan,
                'diajukan_oleh' => $user->id,
                'items' => [
                    [
                        'barang_id' => $this->selected_barang_id,
                        'stok_batch_id' => $this->selected_stok_batch_id,
                        'jumlah' => $this->quantity,
                    ],
                ],
            ]);

            $this->showCreateModal = false;
            $this->toast("Pengalihan barang {$transfer->kode} berhasil diajukan.", 'success');
            $this->reset(['selected_barang_id', 'selected_stok_batch_id', 'quantity', 'alasan', 'catatan']);
            $this->resetPage();
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), 'danger');
        }
    }

    /**
     * Buka modal dialog dan muat data rincian record terpilih.
     */
    public function showDetail(int $id): void
    {
        $transfer = PengalihanBarang::with([
            'gudangAsal', 'unitAsal', 'gudangTujuan', 'unitTujuan', 'lokasiGudangTujuan',
            'pengaju', 'penyetuju', 'penerima',
            'detail.barang.satuan', 'detail.stokBatch',
        ])->findOrFail($id);

        $user = auth()->user();
        $isGlobalAdmin = (bool) ($user && $user->hasRole(['super_admin', 'admin_logistik', 'auditor', 'manajemen']));
        if (! $isGlobalAdmin) {
            $inOrigin = ($transfer->tipe_asal === 'GUDANG' && $user?->gudang_id && (int) $user->gudang_id === (int) $transfer->gudang_asal_id)
                || ($transfer->tipe_asal === 'RUANGAN' && $user?->unit_id && (int) $user->unit_id === (int) $transfer->unit_asal_id);
            $inDestination = ($transfer->tipe_tujuan === 'GUDANG' && $user?->gudang_id && (int) $user->gudang_id === (int) $transfer->gudang_tujuan_id)
                || ($transfer->tipe_tujuan === 'RUANGAN' && $user?->unit_id && (int) $user->unit_id === (int) $transfer->unit_tujuan_id);
            abort_unless($inOrigin || $inDestination, 403, 'Anda tidak memiliki wewenang melihat detail pengalihan barang ini.');
        }

        $this->selectedTransfer = $transfer;
        $this->showDetailModal = true;
    }

    /**
     * Buka modal dialog konfirmasi persetujuan pengalihan barang.
     */
    public function openApprovalModal(int $id, string $type): void
    {
        $this->actionTransferId = $id;
        $this->actionType = $type;
        $this->catatanApproval = '';
        $this->showApprovalModal = true;
    }

    /**
     * Eksekusi persetujuan atau penolakan pengalihan barang di database.
     */
    public function executeApproval(): void
    {
        $user = auth()->user();
        $transfer = PengalihanBarang::findOrFail($this->actionTransferId);

        // 1. Anti Self-Approval Guard
        abort_if(
            (int) $transfer->diajukan_oleh === (int) $user?->id,
            403,
            'Konflik Kepentingan: Anda tidak dapat menyetujui atau menolak permohonan pengalihan yang Anda buat sendiri.'
        );

        // 2. Wewenang Pimpinan Fasilitas Asal Pemilik Barang
        $isGlobalAdmin = (bool) ($user && $user->hasRole(['super_admin', 'admin_logistik']));
        $isOriginOwner = ($transfer->tipe_asal === 'GUDANG' && $user?->gudang_id && (int) $user->gudang_id === (int) $transfer->gudang_asal_id && $user->hasRole('kepala_gudang') && $user->can('update_gudang_stok::pengalihan::barang'))
            || ($transfer->tipe_asal === 'RUANGAN' && $user?->unit_id && (int) $user->unit_id === (int) $transfer->unit_asal_id && $user->hasRole('kepala_unit') && $user->can('update_gudang_stok::pengalihan::barang'));

        abort_unless(
            $isGlobalAdmin || $isOriginOwner,
            403,
            'Hanya pimpinan fasilitas asal pemilik barang yang berhak melakukan otorisasi pelepasan barang persediaan.'
        );
        $service = app(PengalihanBarangService::class);
        try {
            if ($this->actionType === 'APPROVE') {
                $transfer = $service->setujuiPengalihan($this->actionTransferId, $user->id, $this->catatanApproval);
                $this->toast("Pengalihan {$transfer->kode} disetujui untuk serah terima.", 'success');
            } else {
                $transfer = $service->tolakPengalihan($this->actionTransferId, $user->id, $this->catatanApproval);
                $this->toast("Pengalihan {$transfer->kode} ditolak dan stok dikembalikan.", 'warning');
            }

            $this->showApprovalModal = false;
            $this->actionTransferId = null;
            $this->resetPage();
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), 'danger');
        }
    }

    /**
     * Eksekusi pencatatan penerimaan barang di ruangan atau gudang tujuan.
     */
    public function executeTerima(int $id): void
    {
        $user = auth()->user();
        $this->authorizePengalihan(PengalihanBarang::findOrFail($id), 'serah_terima', 'Hanya fasilitas tujuan yang berhak mengonfirmasi serah terima fisik barang.');

        try {
            $service = app(PengalihanBarangService::class);
            $transfer = $service->eksekusiSerahTerima($id, $user->id);
            $this->toast("Serah terima {$transfer->kode} selesai! Stok resmi mengalir ke inventaris tujuan.", 'success');
            $this->resetPage();
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), 'danger');
        }
    }

    /**
     * Batalkan transaksi pengalihan sebelum disetujui.
     */
    public function cancelPengalihan(int $id): void
    {
        $user = auth()->user();
        $this->authorizePengalihan(PengalihanBarang::findOrFail($id), 'batal', 'Hanya pengaju dengan izin pembatalan yang berhak membatalkan pengalihan barang ini.');
        try {
            $service = app(PengalihanBarangService::class);
            $transfer = $service->batalkanPengalihan($id, $user->id, 'Dibatalkan oleh pengaju');
            $this->toast("Pengalihan {$transfer->kode} dibatalkan dan stok dikembalikan.", 'info');
            $this->resetPage();
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), 'danger');
        }
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        $user = auth()->user();
        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik']);

        // Unit Ruangan remains out of scope for warehouse goods transfers.
        $isWarehouseOrAdmin = $isGlobalAdmin || $user?->gudang_id;
        abort_unless($isWarehouseOrAdmin && ($user->can('view_any_gudang_stok::pengalihan::barang') || $user->can('view_gudang_stok::pengalihan::barang')), 403, 'Akses ditolak: Hanya fasilitas gudang logistik yang berwenang mengakses modul Pengalihan Barang.');

        $query = PengalihanBarang::with([
            'gudangAsal', 'unitAsal', 'gudangTujuan', 'unitTujuan',
            'pengaju', 'penyetuju', 'penerima',
            'detail.barang.satuan',
        ]);

        // 1. Tab Aktif: Pengajuan yang diterbitkan oleh / ditujukan ke gudang pengguna
        if ($this->activeTab === 'aktif') {
            if (! $isGlobalAdmin && $user?->gudang_id) {
                $query->where(function ($q) use ($user) {
                    $q->where(fn ($sub) => $sub->where('tipe_tujuan', 'GUDANG')->where('gudang_tujuan_id', $user->gudang_id))
                      ->orWhere('diajukan_oleh', $user->id);
                });
            }
            $query->whereIn('status', StatusPengalihan::aktif());
        }
        // 2. Tab Otorisasi: Pengajuan dari gudang/unit LAIN yang meminta stok dari gudang pengguna
        elseif ($this->activeTab === 'otorisasi') {
            if (! $isGlobalAdmin && $user?->gudang_id) {
                $query->where('tipe_asal', 'GUDANG')
                      ->where('gudang_asal_id', $user->gudang_id)
                      ->where(function ($sub) use ($user) {
                          $sub->where('tipe_tujuan', '!=', 'GUDANG')
                              ->orWhere('gudang_tujuan_id', '!=', $user->gudang_id);
                      });
            }
            $query->where('status', StatusPengalihan::Diajukan->value);
        }
        // 3. Tab Riwayat: Selesai, Ditolak, Dibatalkan
        else {
            if (! $isGlobalAdmin && $user?->gudang_id) {
                $query->where(function ($q) use ($user) {
                    $q->where(fn ($sub) => $sub->where('tipe_asal', 'GUDANG')->where('gudang_asal_id', $user->gudang_id))
                      ->orWhere(fn ($sub) => $sub->where('tipe_tujuan', 'GUDANG')->where('gudang_tujuan_id', $user->gudang_id));
                });
            }
            $query->whereIn('status', StatusPengalihan::riwayat());
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('kode', 'ilike', "%{$this->search}%")
                  ->orWhere('alasan', 'ilike', "%{$this->search}%")
                  ->orWhereHas('gudangAsal', fn ($g) => $g->where('nama', 'ilike', "%{$this->search}%"))
                  ->orWhereHas('gudangTujuan', fn ($g) => $g->where('nama', 'ilike', "%{$this->search}%"))
                  ->orWhereHas('unitTujuan', fn ($u) => $u->where('nama', 'ilike', "%{$this->search}%"))
                  ->orWhereHas('detail.barang', fn ($b) => $b->where('nama', 'ilike', "%{$this->search}%"));
            });
        }

        $items = $query->orderBy($this->getValidatedSortColumn(['kode', 'status', 'tanggal_pengalihan']), $this->getValidatedSortDirection())->paginate($this->perPage);

        // Hitungan Tab & KPI yang disinkronkan ke gudang pengguna (mencegah kebocoran angka global)
        $countAktifQuery = PengalihanBarang::whereIn('status', StatusPengalihan::aktif());
        if (! $isGlobalAdmin && $user?->gudang_id) {
            $countAktifQuery->where(function ($q) use ($user) {
                $q->where(fn ($sub) => $sub->where('tipe_tujuan', 'GUDANG')->where('gudang_tujuan_id', $user->gudang_id))
                  ->orWhere('diajukan_oleh', $user->id);
            });
        }
        $countAktif = $countAktifQuery->count();

        $countOtorisasiQuery = PengalihanBarang::where('status', StatusPengalihan::Diajukan->value);
        if (! $isGlobalAdmin && $user?->gudang_id) {
            $countOtorisasiQuery->where('tipe_asal', 'GUDANG')
                ->where('gudang_asal_id', $user->gudang_id)
                ->where(function ($sub) use ($user) {
                    $sub->where('tipe_tujuan', '!=', 'GUDANG')
                        ->orWhere('gudang_tujuan_id', '!=', $user->gudang_id);
                });
        }
        $countOtorisasi = $countOtorisasiQuery->count();

        $kpiBerjalan = $countAktif;
        $kpiMenungguTerima = (clone $countAktifQuery)->where('status', StatusPengalihan::Disetujui->value)->count();

        $riwayatQuery = PengalihanBarang::where('status', StatusPengalihan::Selesai->value);
        if (! $isGlobalAdmin && $user?->gudang_id) {
            $riwayatQuery->where(function ($q) use ($user) {
                $q->where(fn ($sub) => $sub->where('tipe_asal', 'GUDANG')->where('gudang_asal_id', $user->gudang_id))
                  ->orWhere(fn ($sub) => $sub->where('tipe_tujuan', 'GUDANG')->where('gudang_tujuan_id', $user->gudang_id));
            });
        }
        $kpiSelesaiBulanIni = (clone $riwayatQuery)
            ->whereMonth('tanggal_serah_terima', now()->month)
            ->whereYear('tanggal_serah_terima', now()->year)
            ->count();

        $kpiTotalItem = \App\Models\Persediaan\PengalihanBarangDetail::whereHas('pengalihanBarang', function ($q) use ($isGlobalAdmin, $user) {
            $q->where('status', StatusPengalihan::Selesai->value);
            if (! $isGlobalAdmin && $user?->gudang_id) {
                $q->where(function ($sub) use ($user) {
                    $sub->where(fn ($s) => $s->where('tipe_asal', 'GUDANG')->where('gudang_asal_id', $user->gudang_id))
                        ->orWhere(fn ($s) => $s->where('tipe_tujuan', 'GUDANG')->where('gudang_tujuan_id', $user->gudang_id));
                });
            }
        })->sum('jumlah');
        $daftarBarang = Barang::where('status', 'AKTIF')
            ->where('tipe', '!=', 'ASET')
            ->orderBy('nama')
            ->get();

        $batchesAsal = collect();
        if ($this->selected_barang_id && $this->gudang_asal_id) {
            $batchesAsal = StokBatch::where('barang_id', $this->selected_barang_id)
                ->where('gudang_id', $this->gudang_asal_id)
                ->where('jumlah', '>', 0)
                ->where('status', 'AKTIF')
                ->orderBy('tanggal_kedaluwarsa', 'asc')
                ->get();
        }

        $gudangs = Gudang::where('status', 'AKTIF')->orderBy('nama')->get();
        $units = Unit::where('status', 'AKTIF')->orderBy('nama')->get();
        $raks = $this->gudang_tujuan_id ? LokasiGudang::where('gudang_id', $this->gudang_tujuan_id)->where('status', 'AKTIF')->orderBy('kode')->get() : collect();

        return view('livewire.persediaan.pengalihan-barang-index', [
            'items' => $items,
            'countAktif' => $countAktif,
            'countOtorisasi' => $countOtorisasi,
            'kpiBerjalan' => $kpiBerjalan,
            'kpiMenungguTerima' => $kpiMenungguTerima,
            'kpiSelesaiBulanIni' => $kpiSelesaiBulanIni,
            'kpiTotalItem' => $kpiTotalItem,
            'daftarBarang' => $daftarBarang,
            'batchesAsal' => $batchesAsal,
            'gudangs' => $gudangs,
            'units' => $units,
            'raks' => $raks,
            'isGlobalAdmin' => $isGlobalAdmin,
            'izin' => $this->izinPengalihan($items),
            'isDestinationLocked' => ! $isGlobalAdmin && ($user?->gudang_id || $user?->unit_id),
            'namaLokasiTujuanUser' => $user?->gudang_id
                ? ($user->gudang?->nama ?? 'Gudang Anda')
                : ($user?->unit_id ? ($user->unit?->nama ?? 'Ruangan Anda') : 'Lokasi Terdaftar'),
        ])->layout('layouts.portal', [
            'portal' => $this->getPortalName(),
            'title' => 'Pengalihan & Mutasi Barang',
        ]);
    }
}
