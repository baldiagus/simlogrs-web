<?php

namespace App\Livewire\Ipsrs;

use App\Enums\Ipsrs\StatusSpk;
use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Livewire\Ipsrs\Concerns\HasSparepartLembarKerja;
use App\Models\Master\Barang;
use App\Models\Ipsrs\PenugasanMaintenance;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

class LembarKerjaPengerjaanIndex extends Component
{
    use HasTableFilters, HasToast, HasPortalLayout, HasConfirmationModal;
    use HasSparepartLembarKerja;

    #[Url(history: true)]
    public string $filterStatus = 'AKTIF'; // 'AKTIF' atau 'SELESAI' atau 'SEMUA'

    public bool $showModal = false;
    public bool $showDetailModal = false;
    public ?PenugasanMaintenance $selectedSpk = null;

    // Form Lembar Kerja
    public ?int $penugasan_id = null;
    public ?PenugasanMaintenance $formSpk = null;

    #[Validate('required|date', message: [
        'required' => 'Tanggal pengerjaan fisik wajib diisi.',
    ])]
    public string $tanggal_pengerjaan = '';

    #[Validate('nullable|string')]
    public ?string $jam_mulai = '08:00';

    #[Validate('nullable|string')]
    public ?string $jam_selesai = '10:00';

    #[Validate('required|string|min:5', message: [
        'required' => 'Diagnosa teknis penyebab kerusakan wajib diisi.',
        'min'      => 'Diagnosa teknis minimal 5 karakter.',
    ])]
    public string $diagnosa = '';

    #[Validate('required|string|min:5', message: [
        'required' => 'Uraian tindakan perbaikan fisik wajib diisi.',
        'min'      => 'Uraian tindakan minimal 5 karakter.',
    ])]
    public string $tindakan = '';

    #[Validate('required|in:BERHASIL,PENDING,GAGAL', message: [
        'required' => 'Hasil akhir pengerjaan servis wajib dipilih.',
    ])]
    public string $hasil = 'BERHASIL';
    #[Validate('nullable|string')]
    public ?string $catatan = null;

    public bool $uji_fungsi_kelaikan = true;

    // Suku Cadang yang Digunakan
    public array $sparepartItems = [];

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        $this->tanggal_pengerjaan = now()->format('Y-m-d\TH:i');
    }

    /**
     * Buka formulir modal pengisian lembar kerja pengerjaan teknisi.
     */
    public function openLembarKerja(int $spkId): void
    {
        abort_unless(auth()->user()?->can('create_pemeliharaan::lembar::kerja'), 403);

        $this->penugasan_id = $spkId;
        $this->formSpk = PenugasanMaintenance::with(['permintaanMaintenance.aset.barang', 'permintaanMaintenance.unit'])->findOrFail($spkId);
        $user = auth()->user();
        $isOwner = (int) $this->formSpk->teknisi_id === (int) $user?->id;
        $isSupervisor = (bool) $user?->hasRole(['super_admin', 'admin_logistik']);
        abort_unless($isOwner || $isSupervisor, 403, 'Anda hanya berhak mengisi lembar kerja untuk SPK yang ditugaskan kepada Anda.');
        abort_if(in_array($this->formSpk->status, StatusSpk::riwayat(), true), 422, 'SPK berstatus ' . $this->formSpk->status . ' tidak dapat ditambahkan catatan pengerjaan.');
        $this->tanggal_pengerjaan = now()->format('Y-m-d\TH:i');
        $this->jam_mulai = now()->subHours(2)->format('H:i');
        $this->jam_selesai = now()->format('H:i');
        $this->diagnosa = '';
        $this->tindakan = '';
        $this->hasil = 'BERHASIL';
        $this->catatan = null;
        $this->uji_fungsi_kelaikan = true;
        $this->sparepartItems = [];

        $this->showModal = true;
    }

    /**
     * Validasi dan simpan hasil pengerjaan servis serta pemakaian suku cadang.
     */
    public function saveLembarKerja(): void
    {
        abort_unless(auth()->user()?->can('create_pemeliharaan::lembar::kerja'), 403);
        $this->validate();

        if (! $this->validasiKetersediaanSparepart()) {
            return;
        }

        $user = auth()->user();
        $spk = PenugasanMaintenance::with(['permintaanMaintenance.aset'])->findOrFail($this->penugasan_id);
        $isOwner = (int) $spk->teknisi_id === (int) $user?->id;
        $isSupervisor = (bool) $user?->hasRole(['super_admin', 'admin_logistik']);
        abort_unless($isOwner || $isSupervisor, 403, 'Anda hanya berhak mengisi lembar kerja untuk SPK yang ditugaskan kepada Anda.');
        abort_if(in_array($spk->status, StatusSpk::riwayat(), true), 422, 'SPK berstatus ' . $spk->status . ' tidak dapat ditambahkan catatan pengerjaan.');
        $tiket = $spk->permintaanMaintenance;

        try {
            $this->eksekusiSimpanLembarKerja($user, $spk, $tiket);

            $this->showModal = false;
            $this->reset(['penugasan_id', 'formSpk', 'diagnosa', 'tindakan', 'catatan', 'sparepartItems']);
            $this->toast("Lembar kerja servis SPK {$spk->kode} berhasil disimpan.", 'success');
        } catch (Exception $e) {
            $this->addError('general', $e->getMessage());
            $this->toast($e->getMessage(), 'danger');
        }
    }

    /**
     * Buka modal dialog dan muat data rincian record terpilih.
     */
    public function showDetail(int $spkId): void
    {
        $this->authorizeView();

        $this->selectedSpk = PenugasanMaintenance::with([
            'permintaanMaintenance.aset.barang',
            'permintaanMaintenance.unit',
            'permintaanMaintenance.pelapor',
            'teknisi',
            'penugast',
            'logMaintenance.teknisi',
            'logMaintenance.sukuCadang.barang',
            'permintaanMaintenance.sukuCadang.barang',
        ])->findOrFail($spkId);
        abort_unless(! $this->isOwnSpkOnly() || (int) $this->selectedSpk->teknisi_id === (int) auth()->id(), 403);

        $this->showDetailModal = true;
    }

    /**
     * Teknisi hanya melihat lembar kerja SPK miliknya; koordinator, role global,
     * dan auditor/manajemen membaca seluruh departemen.
     */
    private function isOwnSpkOnly(): bool
    {
        return ! auth()->user()?->hasRole(['super_admin', 'admin_logistik', 'kepala_teknisi', 'auditor', 'manajemen']);
    }

    /**
     * Verifikasi wewenang pengguna untuk melihat data pada halaman ini.
     */
    private function authorizeView(): void
    {
        abort_unless(
            auth()->user()?->can('view_any_pemeliharaan::lembar::kerja') ||
            auth()->user()?->can('view_pemeliharaan::lembar::kerja'),
            403
        );
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        $this->authorizeView();

        $query = PenugasanMaintenance::with([
            'permintaanMaintenance.aset.barang',
            'permintaanMaintenance.unit',
            'teknisi',
            'logMaintenance',
        ])
        ->when($this->isOwnSpkOnly(), fn ($q) => $q->where('teknisi_id', auth()->id()))
        ->when($this->filterStatus === 'AKTIF', fn ($q) => $q->whereIn('status', StatusSpk::aktif()))
        ->when($this->filterStatus === StatusSpk::Selesai->value, fn ($q) => $q->where('status', StatusSpk::Selesai->value))
        ->when($this->search, function ($q) {
            $q->where(function ($sub) {
                $sub->where('kode', 'ilike', "%{$this->search}%")
                    ->orWhereHas('teknisi', fn ($t) => $t->where('nama', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('permintaanMaintenance', function ($m) {
                        $m->where('kode', 'ilike', "%{$this->search}%")
                            ->orWhereHas('aset', fn ($a) => $a->where('kode_aset', 'ilike', "%{$this->search}%"));
                    });
            });
        });

        $daftarSpk = $query
            ->orderBy($this->sortBy ?: 'id', $this->sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate($this->perPage);

        // Komoditas suku cadang yang aktif untuk dropdown beserta stok fisik tersedia
        $daftarSparepart = Barang::query()
            ->where('status', 'AKTIF')
            ->where(function ($q) {
                $q->where('tipe', 'SPAREPART')
                    ->orWhereHas('kategori', fn ($k) => $k->where('nama', 'ilike', '%sparepart%')->orWhere('kode', 'ilike', '%sparepart%'));
            })
            ->with(['satuan', 'kategori'])
            ->withSum(['stokBatch as stok_tersedia' => function ($q) {
                $q->where('status', 'AKTIF')
                    ->where('jumlah', '>', 0)
                    ->where(function ($sq) {
                        $sq->whereNull('tanggal_kedaluwarsa')
                            ->orWhere('tanggal_kedaluwarsa', '>=', now()->toDateString());
                    });
            }], 'jumlah')
            ->orderBy('nama')
            ->get()
            ->map(function ($b) {
                $stok = (int) ($b->stok_tersedia ?? 0);
                $satuan = $b->satuan?->simbol ?? 'Unit';
                $kategori = $b->kategori?->nama ?? 'Suku Cadang';

                return [
                    'id'            => $b->id,
                    'nama'          => "[{$b->kode}] {$b->nama}",
                    'sub'           => "Kategori: {$kategori} • Sisa: {$stok} {$satuan}" . ($stok <= 0 ? ' (HABIS)' : ''),
                    'stok_tersedia' => $stok,
                    'satuan'        => $satuan,
                ];
            });

        return view('livewire.ipsrs.lembar-kerja-pengerjaan-index', [
            'daftarSpk'       => $daftarSpk,
            'daftarSparepart' => $daftarSparepart,
        ])->layout('layouts.portal', ['portal' => $this->getPortalName(), 'title' => 'Lembar Kerja Pengerjaan IPSRS']);
    }
}
