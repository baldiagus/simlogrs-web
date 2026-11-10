<?php

namespace App\Livewire\Audit;

use App\Enums\Audit\StatusCapa;
use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Models\Master\Gudang;
use App\Models\Audit\TemuanAudit;
use App\Models\Master\Unit;
use App\Models\User;
use App\Support\File\FileOptimizer;
use App\Support\Generator\CodeGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class RegisterTemuanCapaIndex extends Component
{
    use HasTableFilters, HasToast, HasPortalLayout, HasConfirmationModal, WithFileUploads;

    #[Url(history: true)]
    public string $filterStatus = ''; // 'In Progress', 'In Review', 'Closed'

    #[Url(history: true)]
    public string $filterRisiko = ''; // 'Mayor', 'Moderat', 'Minor'

    // Modal Buat Temuan Baru
    public bool $showCreateModal = false;

    #[Validate('required|string|min:5|max:150')]
    public string $objek_audit = '';

    #[Validate('required|string|min:10')]
    public string $ringkasan = '';

    #[Validate('required|in:Mayor,Moderat,Minor')]
    public string $tingkat_risiko = 'Moderat';

    #[Validate('required|date')]
    public string $tanggal_audit = '';

    #[Validate('required|date|after_or_equal:tanggal_audit')]
    public string $due_date_capa = '';

    public ?int $unit_id = null;
    public ?int $gudang_id = null;

    public function updatedUnitId($val): void
    {
        if ($val) {
            $this->gudang_id = null;
        }
    }

    public function updatedGudangId($val): void
    {
        if ($val) {
            $this->unit_id = null;
        }
    }

    public function resetAuditee(): void
    {
        $this->unit_id = null;
        $this->gudang_id = null;
    }

    // Modal Tindak Lanjut Unit (Auditee)
    public bool $showTindakLanjutModal = false;
    public ?int $selectedTemuanId = null;

    #[Validate('required|string|min:10')]
    public string $analisis_penyebab = '';

    #[Validate('required|string|min:10')]
    public string $tindakan_perbaikan = '';

    public $fileBuktiList = [];

    // Modal Evaluasi Closing (Auditor)
    public bool $showEvaluasiModal = false;

    #[Validate('required|in:Closed,In Progress')]
    public string $hasil_evaluasi = StatusCapa::Closed->value;

    #[Validate('required|string|min:5')]
    public string $catatan_evaluasi = '';

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        $this->tanggal_audit = now()->toDateString();
        $this->due_date_capa = now()->addDays(30)->toDateString();
    }

    /**
     * Buka modal formulir penambahan data baru setelah validasi wewenang.
     */
    public function openCreate(): void
    {
        abort_unless(auth()->user()?->can('create_audit_manajemen::temuan::capa'), 403);

        $this->reset(['objek_audit', 'ringkasan', 'unit_id', 'gudang_id']);
        $this->tingkat_risiko = 'Moderat';
        $this->tanggal_audit = now()->toDateString();
        $this->due_date_capa = now()->addDays(30)->toDateString();
        $this->showCreateModal = true;
    }

    /**
     * Validasi dan simpan penerbitan temuan audit baru ke database.
     */
    public function saveCreate(): void
    {
        abort_unless(auth()->user()?->can('create_audit_manajemen::temuan::capa'), 403);

        $this->validate([
            'objek_audit'    => 'required|string|min:5|max:150',
            'ringkasan'      => 'required|string|min:10',
            'tingkat_risiko' => 'required|in:Mayor,Moderat,Minor',
            'tanggal_audit'  => 'required|date',
            'due_date_capa'  => 'required|date|after_or_equal:tanggal_audit',
        ], [
            'objek_audit.required'    => 'Objek pemeriksaan audit wajib diisi.',
            'objek_audit.min'         => 'Objek audit minimal 5 karakter.',
            'ringkasan.required'      => 'Uraian ringkasan temuan wajib diisi.',
            'ringkasan.min'           => 'Uraian ringkasan temuan minimal 10 karakter.',
            'tingkat_risiko.required' => 'Tingkat klasifikasi risiko temuan wajib ditentukan.',
            'due_date_capa.after_or_equal' => 'Batas waktu CAPA tidak boleh mendahului tanggal audit.',
        ]);
        if (empty($this->unit_id) && empty($this->gudang_id)) {
            $this->addError('auditee', 'Wajib memilih salah satu entitas terperiksa: Unit Ruangan atau Fasilitas Gudang.');
            return;
        }

        if (!empty($this->unit_id) && !empty($this->gudang_id)) {
            $this->addError('auditee', 'Pilih salah satu entitas saja: Unit Ruangan atau Fasilitas Gudang, tidak boleh keduanya.');
            return;
        }


        $user = auth()->user();
        $kode = CodeGenerator::generateAud();

        TemuanAudit::create([
            'kode'           => $kode,
            'tanggal_audit'  => $this->tanggal_audit,
            'objek_audit'    => $this->objek_audit,
            'ringkasan'      => $this->ringkasan,
            'tingkat_risiko' => $this->tingkat_risiko,
            'due_date_capa'  => $this->due_date_capa,
            'status_capa'    => StatusCapa::InProgress->value,
            'auditor_id'     => $user->id,
            'unit_id'        => $this->unit_id ?: null,
            'gudang_id'      => $this->gudang_id ?: null,
        ]);

        $this->showCreateModal = false;
        $this->reset(['objek_audit', 'ringkasan', 'unit_id', 'gudang_id']);
        $this->toast("Temuan audit '{$kode}' berhasil didaftarkan.", 'success');
    }
    /**
     * Computed property untuk mendapatkan data temuan audit yang sedang dipilih.
     */
    public function getSelectedTemuanProperty(): ?TemuanAudit
    {
        return $this->selectedTemuanId
            ? TemuanAudit::with(['auditor', 'unit', 'gudang', 'evaluator'])->find($this->selectedTemuanId)
            : null;
    }

    /**
     * Periksa apakah pengguna berhak mengelola tindakan perbaikan CAPA (Auditee / Super Admin).
     */
    public function canUserManageTindakLanjut(?User $user, TemuanAudit $temuan): bool
    {
        if (! $user) {
            return false;
        }

        // Sama dengan API tindakLanjutTemuan: admin global ATAU auditee pemilik unit/gudang.
        // Sengaja tanpa syarat permission update (itu membuka Evaluasi & Closing).
        return $user->hasRole(['super_admin', 'admin_logistik'])
            || ($temuan->unit_id && (int) $user->unit_id === (int) $temuan->unit_id)
            || ($temuan->gudang_id && (int) $user->gudang_id === (int) $temuan->gudang_id);
    }

    /**
     * Verifikasi wewenang auditee untuk melihat dan menindaklanjuti temuan audit.
     */
    protected function authorizeAuditeeAccess(TemuanAudit $temuan): void
    {
        $user = auth()->user();
        abort_unless(
            $this->canUserManageTindakLanjut($user, $temuan),
            403,
            'Anda tidak memiliki wewenang mengelola tindak lanjut temuan audit unit ini.'
        );
        abort_if(
            $temuan->status_capa === StatusCapa::Closed->value,
            403,
            'Temuan audit telah ditutup resmi (Closed) dan tidak dapat dimodifikasi.'
        );
    }

    /**
     * Buka modal dialog pencatatan tindak lanjut temuan audit.
     */
    public function openTindakLanjut(int $id): void
    {
        $this->selectedTemuanId = $id;
        $temuan = TemuanAudit::findOrFail($id);
        $this->authorizeAuditeeAccess($temuan);
        $this->analisis_penyebab = $temuan->analisis_penyebab ?? '';
        $this->tindakan_perbaikan = $temuan->tindakan_perbaikan ?? '';
        $this->fileBuktiList = [];
        $this->showTindakLanjutModal = true;
    }

    /**
     * Validasi dan simpan laporan tindak lanjut perbaikan beserta berkas bukti pendukung.
     */
    public function saveTindakLanjut(): void
    {
        if (! $this->selectedTemuanId) {
            return;
        }

        $this->validate([
            'analisis_penyebab'  => 'required|string|min:10',
            'tindakan_perbaikan' => 'required|string|min:10',
            'fileBuktiList.*'    => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,xlsx,docx|max:' . config('simlog.upload.max_kb'),
        ], [
            'analisis_penyebab.required'  => 'Analisis akar penyebab masalah wajib diisi.',
            'analisis_penyebab.min'       => 'Analisis penyebab minimal 10 karakter.',
            'tindakan_perbaikan.required' => 'Uraian tindakan perbaikan (CAPA) wajib diisi.',
            'tindakan_perbaikan.min'      => 'Tindakan perbaikan minimal 10 karakter.',
        ]);

        $temuan = TemuanAudit::findOrFail($this->selectedTemuanId);
        $this->authorizeAuditeeAccess($temuan);
        $existingBukti = is_array($temuan->bukti_perbaikan) ? $temuan->bukti_perbaikan : [];

        if (! empty($this->fileBuktiList)) {
            $files = is_array($this->fileBuktiList) ? $this->fileBuktiList : [$this->fileBuktiList];
            foreach ($files as $file) {
                if ($file) {
                    $existingBukti[] = FileOptimizer::optimizeAndStore($file, 'audit/capa');
                }
            }
        }

        $temuan->update([
            'analisis_penyebab'  => $this->analisis_penyebab,
            'tindakan_perbaikan' => $this->tindakan_perbaikan,
            'bukti_perbaikan'    => $existingBukti,
            'status_capa'        => StatusCapa::InReview->value,
        ]);

        $this->fileBuktiList = [];
        $this->showTindakLanjutModal = false;
        $this->toast("Tindak lanjut perbaikan untuk {$temuan->kode} berhasil dikirim (Status: In Review).", 'success');
    }

    /**
     * Hapus berkas bukti sementara yang baru diunggah pada formulir.
     */
    public function removeTempFile(int $index): void
    {
        if (! $this->selectedTemuanId) {
            return;
        }

        $this->authorizeAuditeeAccess(TemuanAudit::findOrFail($this->selectedTemuanId));
        unset($this->fileBuktiList[$index]);
        $this->fileBuktiList = array_values($this->fileBuktiList);
    }

    /**
     * Hapus berkas bukti yang sebelumnya telah tersimpan pada database.
     */
    public function deleteExistingBukti(int $index): void
    {
        if (! $this->selectedTemuanId) {
            return;
        }

        $temuan = TemuanAudit::findOrFail($this->selectedTemuanId);
        $this->authorizeAuditeeAccess($temuan);
        $bukti = is_array($temuan->bukti_perbaikan) ? $temuan->bukti_perbaikan : [];
        if (isset($bukti[$index])) {
            $path = $bukti[$index]['path'] ?? null;
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            unset($bukti[$index]);
            $temuan->update(['bukti_perbaikan' => array_values($bukti)]);
            $this->toast("Lampiran berkas bukti berhasil dihapus.", 'info');
        }
    }

    /**
     * Buka modal formulir evaluasi verifikasi penutupan (closing) temuan audit.
     */
    public function openEvaluasiClosing(int $id): void
    {
        abort_unless(auth()->user()?->can('update_audit_manajemen::temuan::capa'), 403);

        $this->selectedTemuanId = $id;
        $temuan = TemuanAudit::findOrFail($id);
        $this->hasil_evaluasi = StatusCapa::Closed->value;
        $this->catatan_evaluasi = $temuan->catatan_evaluasi ?? '';
        $this->showEvaluasiModal = true;
    }

    /**
     * Validasi dan simpan keputusan penutupan status temuan audit oleh auditor.
     */
    public function saveEvaluasiClosing(): void
    {
        abort_unless(auth()->user()?->can('update_audit_manajemen::temuan::capa'), 403);

        if (! $this->selectedTemuanId) {
            return;
        }

        $this->validate([
            'hasil_evaluasi'   => ['required', Rule::in(StatusCapa::hasilEvaluasi())],
            'catatan_evaluasi' => 'required|string|min:5',
        ], [
            'hasil_evaluasi.required'   => 'Hasil evaluasi status CAPA wajib dipilih.',
            'catatan_evaluasi.required' => 'Catatan telaah verifikasi auditor wajib diisi.',
            'catatan_evaluasi.min'      => 'Catatan evaluasi minimal 5 karakter.',
        ]);

        $user = auth()->user();
        $temuan = TemuanAudit::findOrFail($this->selectedTemuanId);

        $temuan->update([
            'status_capa'      => $this->hasil_evaluasi,
            'catatan_evaluasi' => $this->catatan_evaluasi,
            'dievaluasi_oleh'  => $user->id,
        ]);

        $this->showEvaluasiModal = false;
        $toastMsg = $this->hasil_evaluasi === StatusCapa::Closed->value
            ? "Temuan audit {$temuan->kode} berhasil ditutup resmi (Closed)."
            : "Tindak lanjut {$temuan->kode} dikembalikan untuk revisi (In Progress).";

        $this->toast($toastMsg, $this->hasil_evaluasi === StatusCapa::Closed->value ? 'success' : 'info');
    }
    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        $user = auth()->user();
        abort_unless(
            $user && ($user->can('view_any_audit_manajemen::temuan::capa') || $user->can('view_audit_manajemen::temuan::capa')),
            403
        );

        $today = now()->toDateString();

        // Query dasar ber-scope: non-global hanya melihat temuan unit/gudangnya sendiri.
        // view_any_* saja tidak membuka scope lintas unit/gudang (ADR 0001).
        $isGlobal = $user->hasRole(['super_admin', 'admin_logistik', 'auditor', 'manajemen']);

        $scoped = TemuanAudit::query();
        if (! $isGlobal) {
            $scoped->where(function ($q) use ($user) {
                if ($user->unit_id) {
                    $q->orWhere('unit_id', $user->unit_id);
                }
                if ($user->gudang_id) {
                    $q->orWhere('gudang_id', $user->gudang_id);
                }
                if (! $user->unit_id && ! $user->gudang_id) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        // Metrik Ringkasan CAPA (diturunkan dari query ber-scope)
        $closed = StatusCapa::Closed->value;
        $totalTemuan = (clone $scoped)->count();
        $totalClosed = (clone $scoped)->where('status_capa', $closed)->count();
        $totalMayorOpen = (clone $scoped)->where('tingkat_risiko', 'Mayor')->where('status_capa', '!=', $closed)->count();
        $totalOverdue = (clone $scoped)->where('status_capa', '!=', $closed)->where('due_date_capa', '<', $today)->count();
        $closingRate = $totalTemuan > 0 ? round(($totalClosed / $totalTemuan) * 100, 1) : 100;

        $query = (clone $scoped)->with(['auditor', 'evaluator', 'unit', 'gudang'])
            ->when($this->filterStatus, fn ($q) => $q->where('status_capa', $this->filterStatus))
            ->when($this->filterRisiko, fn ($q) => $q->where('tingkat_risiko', $this->filterRisiko))
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('kode', 'ilike', "%{$this->search}%")
                        ->orWhere('objek_audit', 'ilike', "%{$this->search}%")
                        ->orWhere('ringkasan', 'ilike', "%{$this->search}%");
                });
            });

        $items = $query->orderByRaw('CASE WHEN status_capa = ? THEN 1 WHEN status_capa = ? THEN 2 ELSE 3 END', [StatusCapa::InProgress->value, StatusCapa::InReview->value])
            ->orderBy('due_date_capa', 'asc')
            ->paginate($this->perPage);

        $daftarUnit = Cache::remember('dropdown_unit_aktif', config('simlog.cache.dropdown_ttl'), fn () => Unit::where('status', 'AKTIF')->orderBy('nama')->get());
        $daftarGudang = Cache::remember('dropdown_gudang_aktif', config('simlog.cache.dropdown_ttl'), fn () => Gudang::where('status', 'AKTIF')->orderBy('nama')->get());

        return view('livewire.audit.register-temuan-capa-index', [
            'items'          => $items,
            'selectedTemuan' => $this->selectedTemuan,
            'totalTemuan'    => $totalTemuan,
            'totalClosed'    => $totalClosed,
            'totalMayorOpen' => $totalMayorOpen,
            'totalOverdue'   => $totalOverdue,
            'closingRate'    => $closingRate,
            'daftarUnit'     => $daftarUnit,
            'daftarGudang'   => $daftarGudang,
            'canCreate'      => $user?->can('create_audit_manajemen::temuan::capa'),
            'canEvaluate'    => $user?->can('update_audit_manajemen::temuan::capa'),
        ])->layout('layouts.portal', ['portal' => $this->getPortalName(), 'title' => 'Register Temuan Audit & CAPA']);
    }
}
