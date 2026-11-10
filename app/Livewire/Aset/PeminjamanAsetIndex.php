<?php

namespace App\Livewire\Aset;

use App\Enums\Aset\StatusAset;
use App\Enums\Aset\StatusPeminjaman;
use App\Livewire\Aset\Concerns\HasAksiPeminjaman;
use App\Livewire\Aset\Concerns\HasPenyediaPeminjaman;
use App\Livewire\Aset\Concerns\HasFilterPenyediaPeminjaman;
use App\Livewire\Concerns\AuthorizesPeminjaman;
use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Models\Aset\Aset;
use App\Models\Master\Gudang;
use App\Models\Aset\PermintaanPeminjaman;
use App\Models\Master\Unit;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class PeminjamanAsetIndex extends Component
{
    use HasTableFilters, HasToast, HasPortalLayout, HasConfirmationModal, AuthorizesPeminjaman, HasPenyediaPeminjaman, HasFilterPenyediaPeminjaman;
    use HasAksiPeminjaman;

    #[Url(history: true)]
    public string $activeTab = 'aktif';

    #[Url(history: true)]
    public string $filterStatus = '';

    #[Url(history: true)]
    public string $filterPerspektif = 'semua';
    public string $filterKatalogLokasi = '';
    public ?int $filterKatalogUnitId = null;
    public ?int $filterKatalogGudangId = null;

    public bool $showPinjamModal = false;
    public bool $showDetailModal = false;
    public ?PermintaanPeminjaman $selectedPinjam = null;
    public ?int $selected_barang_id = null;
    public ?string $selected_penyedia_key = null;
    public string $searchPenyedia = '';
    public string $tipe_penyedia = 'RUANGAN';
    public ?int $unit_penyedia_id = null;
    public ?int $gudang_penyedia_id = null;
    public ?int $selected_aset_id = null;
    public int $quantity = 1;
    public string $tanggal_pinjam = '';
    public string $tanggal_kembali_rencana = '';
    public string $keperluan = '';
    public string $catatan = '';

    public bool $showPerpanjangModal = false;
    public ?int $perpanjangId = null;
    public string $tanggal_kembali_baru = '';
    public string $alasan_perpanjang = '';

    public bool $showQrModal = false;
    public ?Aset $selectedAsetQr = null;
    public string $qrCodeSvg = '';

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        $this->tanggal_pinjam = date('Y-m-d');
        $this->tanggal_kembali_rencana = date('Y-m-d', strtotime('+7 days'));

        if ($this->tipe_penyedia === 'RUANGAN' && ! $this->unit_penyedia_id) {
            $this->unit_penyedia_id = Unit::where('status', 'AKTIF')->orderBy('nama')->value('id');
        }
    }

    /**
     * Ubah tab navigasi yang sedang aktif.
     */
    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['aktif', 'katalog'], true) ? $tab : 'aktif';
        $this->resetPage();
    }

    /**
     * Hook Livewire saat properti active tab diperbarui.
     */
    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }
    /**
     * Hook Livewire saat filter perspektif diubah untuk memperbarui data dan mereset paginasi.
     */
    public function updatedFilterPerspektif(): void
    {
        $this->resetPage();
    }

    /**
     * Dapatkan judul halaman sesuai dengan konteks komponen.
     */
    public function getPageTitle(): string
    {
        $user = auth()->user();
        $portal = $this->getPortalName();

        if ($user?->unit_id && $user->unit) {
            return "Peminjaman Aset Ruangan ({$user->unit->nama})";
        }

        if ($user?->gudang_id && $user->gudang) {
            return "Peminjaman Aset Gudang ({$user->gudang->nama})";
        }

        if ($portal === 'kepala-gudang' || $portal === 'petugas-gudang') {
            return 'Peminjaman Aset Gudang';
        }

        if ($portal === 'kepala-unit' || $portal === 'petugas-unit') {
            return 'Peminjaman Aset Ruangan';
        }

        return 'Sirkulasi Aset Global (Logistik Rumah Sakit)';
    }

    /**
     * Dapatkan subjudul penjelasan konteks halaman peminjaman aset.
     */
    public function getPageSubtitle(): string
    {
        $user = auth()->user();

        if ($user?->unit_id) {
            return 'Pengelolaan inventaris ruangan dan sirkulasi peminjaman aset antar-ruangan.';
        }

        if ($user?->gudang_id) {
            return 'Pengelolaan sirkulasi peminjaman aset inventaris fasilitas gudang logistik.';
        }

        return 'Pengawasan perputaran seluruh inventaris dan deteksi keterlambatan se-rumah sakit.';
    }

    /**
     * Hook Livewire saat filter status diubah untuk memperbarui data dan mereset paginasi.
     */
    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Buka formulir pembuatan permohonan peminjaman aset baru.
     */
    public function createPinjam(): void
    {
        abort_unless(auth()->user()?->can('create_layanan_unit::peminjaman::aset'), 403);

        $this->resetPinjamForm();
        $this->tanggal_pinjam = date('Y-m-d');
        $this->tanggal_kembali_rencana = date('Y-m-d', strtotime('+7 days'));
        $this->showPinjamModal = true;
    }

    /**
     * Inisialisasi peminjaman dengan aset terpilih dari katalog.
     */
    public function pinjamAset(int $asetId): void
    {
        abort_unless(auth()->user()?->can('create_layanan_unit::peminjaman::aset'), 403);

        $aset = Aset::with('barang')->findOrFail($asetId);
        $isMaintenance = \App\Models\Ipsrs\PermintaanMaintenance::where('aset_id', $aset->id)
            ->whereNotIn('status', ['SELESAI', 'DIBATALKAN'])
            ->exists();
        if ($isMaintenance || $aset->status === StatusAset::DalamMaintenance->value || $aset->kondisi !== 'BAIK') {
            $this->toast("Aset '{$aset->kode_aset}' sedang dalam laporan pemeliharaan/perbaikan IPSRS dan tidak dapat dipinjam.", 'warning');
            return;
        }
        $this->resetPinjamForm();
        $this->selected_barang_id = $aset->barang_id;
        $key = $aset->unit_id ? "RUANGAN_{$aset->unit_id}" : "GUDANG_{$aset->gudang_id}";
        $this->selectPenyediaKey($key);
        $this->selected_aset_id = $aset->id;

        $this->tanggal_pinjam = date('Y-m-d');
        $this->tanggal_kembali_rencana = date('Y-m-d', strtotime('+7 days'));
        $this->showPinjamModal = true;
    }

    /**
     * Kosongkan seluruh isian formulir peminjaman aset.
     */
    public function resetPinjamForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'selected_barang_id',
            'selected_penyedia_key',
            'selected_aset_id',
            'unit_penyedia_id',
            'gudang_penyedia_id',
            'keperluan',
            'catatan',
        ]);
        $this->searchPenyedia = '';
        $this->quantity = 1;
        $this->tipe_penyedia = 'RUANGAN';
    }

    /**
     * Validasi isian formulir dan ajukan permohonan peminjaman aset ke database.
     */
    public function savePinjam(): void
    {
        abort_unless(auth()->user()?->can('create_layanan_unit::peminjaman::aset'), 403);

        $user = auth()->user();
        $unitPeminjamId = $user->unit_id;
        if (! $unitPeminjamId) {
            $this->toast('Anda harus terdaftar pada unit/ruangan untuk mengajukan peminjaman aset.', 'danger');
            return;
        }

        $rules = [
            'selected_barang_id'      => ['required', Rule::exists('barang', 'id')->where('status', 'AKTIF')],
            'selected_penyedia_key'   => 'required|string',
            'tanggal_pinjam'          => 'required|date',
            'tanggal_kembali_rencana' => 'required|date|after_or_equal:tanggal_pinjam',
            'keperluan'               => 'required|string|max:1000',
            'catatan'                 => 'nullable|string|max:1000',
        ];

        $messages = [
            'selected_barang_id.required'            => 'Aset inventaris wajib dipilih.',
            'selected_penyedia_key.required'         => 'Lokasi unit atau gudang penyedia wajib dipilih.',
            'tanggal_pinjam.required'                => 'Tanggal pinjam wajib diisi.',
            'tanggal_kembali_rencana.required'       => 'Tanggal rencana pengembalian wajib diisi.',
            'tanggal_kembali_rencana.after_or_equal' => 'Tanggal rencana kembali tidak boleh sebelum tanggal pinjam.',
            'keperluan.required'                     => 'Keperluan peminjaman wajib dicantumkan.',
        ];

        $this->validate($rules, $messages);

        // Query seluruh aset yang tersedia di lokasi penyedia
        $assetsQuery = Aset::where('barang_id', $this->selected_barang_id)
            ->where('status', StatusAset::Tersedia->value)
            ->where('kondisi', 'BAIK')
            ->whereHas('barang', fn ($b) => $b->where('status', 'AKTIF'))
            ->whereDoesntHave('peminjamanDetail.permintaanPeminjaman', function ($sub) {
                $sub->whereIn('status', StatusPeminjaman::aktif());
            })
            ->whereDoesntHave('permintaanMaintenance', function ($sub) {
                $sub->whereNotIn('status', ['SELESAI', 'DIBATALKAN']);
            });

        if ($this->tipe_penyedia === 'RUANGAN') {
            $assetsQuery->where('unit_id', $this->unit_penyedia_id)
                ->whereHas('unit', fn ($u) => $u->where('status', 'AKTIF'));
        } else {
            $assetsQuery->where('gudang_id', $this->gudang_penyedia_id)
                ->whereHas('gudang', fn ($g) => $g->where('status', 'AKTIF'));
        }

        $availableCount = $assetsQuery->count();
        if ($availableCount === 0) {
            $this->addError('selected_penyedia_key', 'Tidak ada aset yang tersedia pada lokasi penyedia.');
            $this->addError('selected_aset_id', 'Aset tidak tersedia atau sedang dalam perbaikan.');
            return;
        }

        $this->validate([
            'quantity' => "required|integer|min:1|max:{$availableCount}",
        ], [
            'quantity.required' => 'Jumlah unit peminjaman wajib diisi.',
            'quantity.min'      => 'Jumlah unit minimal 1 unit.',
            'quantity.max'      => "Jumlah unit melebihi stok tersedia (maksimal {$availableCount} unit).",
        ]);

        $allocatedAssets = $assetsQuery->take($this->quantity)->get();
        if ($allocatedAssets->isEmpty()) {
            $this->addError('selected_aset_id', 'Aset yang dipilih tidak tersedia atau tidak berada pada lokasi penyedia.');
            return;
        }

        // Kunci integritas: periksa apakah aset sedang dalam transaksi peminjaman aktif lain
        $isSedangDipinjam = PermintaanPeminjaman::whereIn('status', StatusPeminjaman::aktif())
            ->whereHas('detail', fn ($d) => $d->where('aset_id', $aset->id))
            ->exists();

        if ($isSedangDipinjam) {
            $this->addError('selected_aset_id', "Aset '{$aset->kode_aset}' ({$aset->barang?->nama}) sedang dalam proses pengajuan atau sedang aktif dipinjam oleh unit lain.");
            return;
        }

        $isMaintenance = \App\Models\Ipsrs\PermintaanMaintenance::where('aset_id', $aset->id)
            ->whereNotIn('status', ['SELESAI', 'DIBATALKAN'])
            ->exists();
        if ($isMaintenance || $aset->status === StatusAset::DalamMaintenance->value) {
            $this->addError('selected_aset_id', "Aset '{$aset->kode_aset}' ({$aset->barang?->nama}) sedang dalam penanganan perbaikan/pemeliharaan IPSRS dan tidak dapat dipinjam.");
            return;
        }
        if ($this->tipe_penyedia === 'RUANGAN' && $this->unit_penyedia_id === $unitPeminjamId) {
            $this->addError('selected_penyedia_key', 'Unit penyedia tidak boleh sama dengan unit peminjam.');
            return;
        }

        $kode = app(\App\Services\Aset\PeminjamanAsetService::class)->ajukanPeminjaman([
            'tipe_penyedia'           => $this->tipe_penyedia,
            'unit_penyedia_id'        => $this->unit_penyedia_id,
            'gudang_penyedia_id'      => $this->gudang_penyedia_id,
            'unit_id'                 => $unitPeminjamId,
            'tanggal_pinjam'          => $this->tanggal_pinjam,
            'tanggal_kembali_rencana' => $this->tanggal_kembali_rencana,
            'keperluan'               => $this->keperluan,
            'catatan'                 => $this->catatan ?: null,
        ], $user, $allocatedAssets);

        $this->showPinjamModal = false;
        $this->resetPinjamForm();
        $this->toast("Permintaan peminjaman '{$kode}' berhasil diajukan.", 'success');
    }

    /**
     * Buka modal dialog untuk menampilkan rincian data terpilih.
     */
    public function openDetail(int $id): void
    {
        $pinjam = PermintaanPeminjaman::with([
            'unitPenyedia',
            'gudangPenyedia',
            'unitPeminjam',
            'pengaju',
            'penyetuju',
            'detail.aset.barang.satuan',
            'pengembalian.detail.aset',
            'pengembalian.pemeriksa',
        ])->findOrFail($id);

        $user = auth()->user();
        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik']);
        if (! $isGlobalAdmin && ! $user?->hasRole(['auditor', 'manajemen'])) {
            $isBorrower = $user?->unit_id && (int) $user->unit_id === (int) $pinjam->unit_id;
            abort_unless($isBorrower || $this->dalamScopePenyedia($user, $pinjam), 403, 'Anda tidak memiliki wewenang untuk melihat peminjaman ini.');
        }

        $this->selectedPinjam = $pinjam;
        $this->showDetailModal = true;
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        $user = auth()->user();
        abort_unless($user && ($user->can('view_any_layanan_unit::peminjaman::aset') || $user->can('view_layanan_unit::peminjaman::aset')), 403);

        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik']);

        [$countAktif, $countKatalog] = $this->hitungStatistikPeminjaman($user, $isGlobalAdmin);
        $daftarPeminjaman = $this->activeTab === 'aktif' ? $this->queryDaftarPeminjaman($user, $isGlobalAdmin) : collect();
        $daftarKatalogAset = $this->activeTab === 'katalog' ? $this->queryDaftarKatalogAset($user, $isGlobalAdmin) : collect();

        $daftarUnit = Unit::where('status', 'AKTIF')->orderBy('nama')->get();
        $daftarGudang = Gudang::where('status', 'AKTIF')->orderBy('nama')->get();
        $daftarBarangAsetTersedia = $this->getDaftarBarangAsetTersedia();
        $daftarPenyediaAset = $this->getPenyediaAsetList();

        return view('livewire.aset.peminjaman-aset-index', [
            'activeTab'               => $this->activeTab,
            'filterStatus'            => $this->filterStatus,
            'filterKatalogLokasi'     => $this->filterKatalogLokasi,
            'filterKatalogUnitId'     => $this->filterKatalogUnitId,
            'filterKatalogGudangId'   => $this->filterKatalogGudangId,
            'showPinjamModal'         => $this->showPinjamModal,
            'showDetailModal'         => $this->showDetailModal,
            'selectedPinjam'          => $this->selectedPinjam,
            'tipe_penyedia'           => $this->tipe_penyedia,
            'unit_penyedia_id'        => $this->unit_penyedia_id,
            'gudang_penyedia_id'      => $this->gudang_penyedia_id,
            'selected_aset_id'        => $this->selected_aset_id,
            'tanggal_pinjam'          => $this->tanggal_pinjam,
            'tanggal_kembali_rencana' => $this->tanggal_kembali_rencana,
            'keperluan'               => $this->keperluan,
            'catatan'                 => $this->catatan,
            'showPerpanjangModal'     => $this->showPerpanjangModal,
            'perpanjangId'            => $this->perpanjangId,
            'tanggal_kembali_baru'    => $this->tanggal_kembali_baru,
            'alasan_perpanjang'       => $this->alasan_perpanjang,
            'showQrModal'             => $this->showQrModal,
            'selectedAsetQr'          => $this->selectedAsetQr,
            'qrCodeSvg'               => $this->qrCodeSvg,
            'confirmTitle'            => $this->confirmTitle,
            'confirmDescription'      => $this->confirmDescription,
            'confirmButtonText'       => $this->confirmButtonText,
            'confirmButtonVariant'    => $this->confirmButtonVariant,
            'showConfirmModal'        => $this->showConfirmModal,
            'daftarPeminjaman'        => $daftarPeminjaman,
            'daftarKatalogAset'       => $daftarKatalogAset,
            'daftarUnit'              => $daftarUnit,
            'daftarGudang'            => $daftarGudang,
            'daftarBarangAsetTersedia' => $daftarBarangAsetTersedia,
            'daftarPenyediaAset'       => $daftarPenyediaAset,
            'selected_barang_id'       => $this->selected_barang_id,
            'selected_penyedia_key'    => $this->selected_penyedia_key,
            'isGlobalAdmin'           => $isGlobalAdmin,
            'countAktif'              => $countAktif,
            'countKatalog'            => $countKatalog,
            'userUnitId'              => $user?->unit_id,
            'userGudangId'            => $user?->gudang_id,
            'izin'                    => $this->izinPeminjaman($daftarPeminjaman),
            'izinSelected'            => $this->selectedPinjam ? $this->izinPeminjaman([$this->selectedPinjam])[$this->selectedPinjam->id] : null,
            'pageTitle'               => $this->getPageTitle(),
            'pageSubtitle'            => $this->getPageSubtitle(),
        ])->layout('layouts.portal', [
            'portal' => $this->getPortalName(),
            'title'  => $this->getPageTitle(),
        ]);
    }
}
