<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Enums\Audit\StatusCapa;
use App\Http\Controllers\Api\ApiController;
use App\Models\Pengadaan\PesananPembelian;
use App\Models\Audit\TemuanAudit;
use App\Support\File\FileOptimizer;
use App\Support\Generator\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuditTemuanApiController extends ApiController
{
    /**
     * Terbitkan temuan CAPA langsung dari anomali PO Pengadaan.
     * POST /api/v1/audit/kepatuhan-pengadaan/{po_id}/capa
     */
    public function buatTemuanKepatuhan(Request $request, int $po_id): JsonResponse
    {
        $user = $request->user();
        $canCreateTemuan = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('create_audit_manajemen::temuan::capa')
        );
        if (! $canCreateTemuan) {
            return $this->jsonError('Hanya Auditor SPI yang berwenang menerbitkan temuan audit kepatuhan.', 403);
        }

        $po = PesananPembelian::with(['pemasok', 'permintaanPengadaan'])->find($po_id);
        if (! $po) {
            return $this->jsonError('Dokumen PO tidak ditemukan.', 404);
        }

        $validator = Validator::make($request->all(), [
            'tingkat_risiko' => 'required|in:Mayor,Moderat,Minor',
            'due_date_capa'  => 'required|date|after_or_equal:today',
            'ringkasan'      => 'nullable|string|min:10',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi gagal.', 422, $validator->errors());
        }

        $tipe = $request->input('tipe_anomali', 'TANPA_PR');
        $vendor = $po->pemasok?->nama ?? 'Vendor';
        $objekAudit = "Pesanan Pembelian (PO) {$po->kode} - Rekanan: {$vendor}";

        if ($request->filled('ringkasan')) {
            $ringkasan = $request->input('ringkasan');
        } elseif ($tipe === 'TANPA_PR') {
            $ringkasan = "Ditemukan penerbitan Pesanan Pembelian (PO) nomor {$po->kode} senilai Rp " . number_format($po->total_biaya, 0, ',', '.') . " tanpa melalui dokumen Permintaan Pengadaan (PR) resmi dari unit pemohon.";
        } elseif ($tipe === 'TERLAMBAT') {
            $ringkasan = "Pengiriman barang PO nomor {$po->kode} dari vendor {$vendor} mengalami keterlambatan melebihi estimasi kirim ({$po->estimasi_tanggal_kirim?->format('d/m/Y')}).";
        } else {
            $ringkasan = "Terdapat selisih kuantitas barang yang diterima di gudang terhadap kuantitas yang dipesan pada dokumen PO {$po->kode}.";
        }

        $kodeAud = CodeGenerator::generateAud();

        $temuan = TemuanAudit::create([
            'kode'           => $kodeAud,
            'tanggal_audit'  => now()->toDateString(),
            'objek_audit'    => $objekAudit,
            'ringkasan'      => $ringkasan,
            'tingkat_risiko' => $request->input('tingkat_risiko', 'Mayor'),
            'due_date_capa'  => $request->input('due_date_capa'),
            'status_capa'    => StatusCapa::InProgress->value,
            'auditor_id'     => $user->id,
            'unit_id'        => $po->permintaanPengadaan?->unit_id,
        ]);

        return $this->jsonSuccess([
            'id'             => $temuan->id,
            'kode'           => $temuan->kode,
            'status_capa'    => $temuan->status_capa,
            'tingkat_risiko' => $temuan->tingkat_risiko,
        ], "Temuan audit kepatuhan '{$temuan->kode}' berhasil diterbitkan ke register CAPA.", 201);
    }

    /**
     * Register Temuan Audit & CAPA.
     * GET /api/v1/audit/temuan-capa
     */
    public function registerTemuan(Request $request): JsonResponse
    {
        $status = $request->query('status'); // In Progress, In Review, Closed
        $risiko = $request->query('risiko'); // Mayor, Moderat, Minor
        $search = $request->query('search');

        $query = TemuanAudit::with([
            'auditor:id,nama',
            'evaluator:id,nama',
            'unit:id,nama',
            'gudang:id,nama',
        ])
        ->when($status, fn ($q) => $q->where('status_capa', $status))
        ->when($risiko, fn ($q) => $q->where('tingkat_risiko', $risiko))
        ->when($search, function ($q) use ($search) {
            $q->where(function ($sub) use ($search) {
                $sub->where('kode', 'like', "%{$search}%")
                    ->orWhere('objek_audit', 'like', "%{$search}%")
                    ->orWhere('ringkasan', 'like', "%{$search}%");
            });
        })
        ->latest('id');
        $user = $request->user();
        $canViewTemuan = $user && (
            $user->hasRole(['super_admin', 'admin_logistik', 'auditor', 'manajemen']) ||
            $user->can('view_any_audit_manajemen::temuan::capa') ||
            $user->can('view_audit_manajemen::temuan::capa')
        );
        if (! $canViewTemuan) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk mengakses data temuan audit.', 403);
        }
        // Pembaca global hanya super_admin, admin_logistik, auditor, manajemen (ADR 0001);
        // view_any_* saja tidak membuka scope lintas unit/gudang.
        $isGlobalAuditor = $user && $user->hasRole(['super_admin', 'admin_logistik', 'auditor', 'manajemen']);

        if (! $isGlobalAuditor) {
            if ($user->unit_id) {
                $query->where('unit_id', $user->unit_id);
            } elseif ($user->gudang_id) {
                $query->where('gudang_id', $user->gudang_id);
            } else {
                return $this->jsonError('Anda tidak memiliki wewenang untuk mengakses data temuan audit.', 403);
            }
        }

        $paginated = $query->paginate(15);

        $today = now()->toDateString();

        $items = $paginated->getCollection()->map(fn ($t) => $this->formatTemuan($t, $today));

        return $this->jsonSuccess([
            'items'    => $items,
            'paginasi' => [
                'total'        => $paginated->total(),
                'per_halaman'  => $paginated->perPage(),
                'halaman_ini'  => $paginated->currentPage(),
                'total_halaman'=> $paginated->lastPage(),
            ],
        ], 'Daftar temuan audit & CAPA berhasil dimuat.');
    }

    private function formatTemuan($t, string $today): array
    {
        $isOverdue = $t->status_capa !== StatusCapa::Closed->value && $t->due_date_capa && $t->due_date_capa->format('Y-m-d') < $today;

        return [
            'id'                 => $t->id,
            'kode'               => $t->kode,
            'tanggal_audit'      => $t->tanggal_audit?->format('Y-m-d') ?? '-',
            'objek_audit'        => $t->objek_audit,
            'ringkasan'          => $t->ringkasan,
            'tingkat_risiko'     => $t->tingkat_risiko,
            'due_date_capa'      => $t->due_date_capa?->format('Y-m-d') ?? '-',
            'status_capa'        => $t->status_capa,
            'is_overdue'         => $isOverdue,
            'auditor'            => $t->auditor?->nama ?? 'Auditor SPI',
            'evaluator'          => $t->evaluator?->nama ?? '-',
            'unit'               => $t->unit?->nama ?? '-',
            'gudang'             => $t->gudang?->nama ?? '-',
            'unit_id'            => $t->unit_id ? (int) $t->unit_id : null,
            'gudang_id'          => $t->gudang_id ? (int) $t->gudang_id : null,
            'lokasi_terperiksa'  => $t->unit?->nama ?? ($t->gudang?->nama ?? 'Sentral'),
            'analisis_penyebab'  => $t->analisis_penyebab,
            'tindakan_perbaikan' => $t->tindakan_perbaikan,
            'catatan_evaluasi'   => $t->catatan_evaluasi,
            'bukti_perbaikan'    => $t->bukti_perbaikan ? (is_array($t->bukti_perbaikan) ? $t->bukti_perbaikan : [$t->bukti_perbaikan]) : [],
        ];
    }

    /**
     * Penerbitan temuan audit baru secara mandiri oleh Auditor.
     * POST /api/v1/audit/temuan-capa
     */
    public function simpanTemuan(Request $request): JsonResponse
    {
        $user = $request->user();
        $canCreateTemuan = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('create_audit_manajemen::temuan::capa')
        );
        if (! $canCreateTemuan) {
            return $this->jsonError('Hanya Auditor SPI yang berwenang menerbitkan temuan audit.', 403);
        }

        $validator = Validator::make($request->all(), [
            'objek_audit'    => 'required|string|min:5|max:150',
            'ringkasan'      => 'required|string|min:10',
            'tingkat_risiko' => 'required|in:Mayor,Moderat,Minor',
            'tanggal_audit'  => 'required|date',
            'due_date_capa'  => 'required|date|after_or_equal:tanggal_audit',
            'unit_id'        => 'nullable|exists:unit,id',
            'gudang_id'      => 'nullable|exists:gudang,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi gagal.', 422, $validator->errors());
        }

        $kode = CodeGenerator::generateAud();

        $temuan = TemuanAudit::create([
            'kode'           => $kode,
            'tanggal_audit'  => $request->input('tanggal_audit'),
            'objek_audit'    => $request->input('objek_audit'),
            'ringkasan'      => $request->input('ringkasan'),
            'tingkat_risiko' => $request->input('tingkat_risiko'),
            'due_date_capa'  => $request->input('due_date_capa'),
            'status_capa'    => StatusCapa::InProgress->value,
            'auditor_id'     => $user->id,
            'unit_id'        => $request->input('unit_id'),
            'gudang_id'      => $request->input('gudang_id'),
        ]);

        return $this->jsonSuccess([
            'id'             => $temuan->id,
            'kode'           => $temuan->kode,
            'status_capa'    => $temuan->status_capa,
            'tingkat_risiko' => $temuan->tingkat_risiko,
        ], "Temuan audit '{$temuan->kode}' berhasil didaftarkan.", 201);
    }

    /**
     * Input tindakan perbaikan oleh Auditee Unit.
     * POST /api/v1/audit/temuan-capa/{id}/tindak-lanjut
     */
    public function tindakLanjutTemuan(Request $request, int $id): JsonResponse
    {
        $temuan = TemuanAudit::find($id);

        if (! $temuan) {
            return $this->jsonError('Temuan audit tidak ditemukan.', 404);
        }
        $user = $request->user();
        $isTargetAuditee = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            ($temuan->unit_id && (int) $user->unit_id === (int) $temuan->unit_id) ||
            ($temuan->gudang_id && (int) $user->gudang_id === (int) $temuan->gudang_id)
        );
        if (! $isTargetAuditee) {
            return $this->jsonError('Anda tidak memiliki wewenang mengirimkan tindak lanjut untuk temuan audit fasilitas/unit lain.', 403);
        }
        if ($temuan->status_capa === StatusCapa::Closed->value) {
            return $this->jsonError('Temuan audit sudah ditutup (Closed), tindak lanjut tidak dapat diubah.', 403);
        }
        $validator = Validator::make($request->all(), [
            'analisis_penyebab'  => 'required|string|min:10',
            'tindakan_perbaikan' => 'required|string|min:10',
            'bukti_perbaikan'    => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi gagal.', 422, $validator->errors());
        }

        $temuan->update([
            'analisis_penyebab'  => $request->input('analisis_penyebab'),
            'tindakan_perbaikan' => $request->input('tindakan_perbaikan'),
            'bukti_perbaikan'    => $request->input('bukti_perbaikan') ? [$request->input('bukti_perbaikan')] : $temuan->bukti_perbaikan,
            'status_capa'        => StatusCapa::InReview->value,
        ]);

        return $this->jsonSuccess([
            'id'          => $temuan->id,
            'kode'        => $temuan->kode,
            'status_capa' => $temuan->status_capa,
        ], "Tindak lanjut CAPA berhasil dikirimkan untuk evaluasi auditor.");
    }

    /**
     * Unggah berkas bukti perbaikan CAPA oleh Auditee Unit/Gudang.
     * POST /api/v1/audit/temuan-capa/{id}/bukti
     */
    public function uploadBukti(Request $request, int $id): JsonResponse
    {
        $temuan = TemuanAudit::find($id);

        if (! $temuan) {
            return $this->jsonError('Temuan audit tidak ditemukan.', 404);
        }
        $user = $request->user();
        $isTargetAuditee = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            ($temuan->unit_id && (int) $user->unit_id === (int) $temuan->unit_id) ||
            ($temuan->gudang_id && (int) $user->gudang_id === (int) $temuan->gudang_id)
        );
        if (! $isTargetAuditee) {
            return $this->jsonError('Anda tidak memiliki wewenang mengunggah bukti untuk temuan audit fasilitas/unit lain.', 403);
        }
        if ($temuan->status_capa === StatusCapa::Closed->value) {
            return $this->jsonError('Temuan audit sudah ditutup (Closed), bukti tidak dapat ditambahkan.', 403);
        }

        $validator = Validator::make($request->all(), [
            'berkas'   => 'required|array',
            'berkas.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:' . config('simlog.upload.max_kb'),
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi gagal.', 422, $validator->errors());
        }

        $bukti = is_array($temuan->bukti_perbaikan) ? $temuan->bukti_perbaikan : [];
        foreach ($request->file('berkas') as $file) {
            $bukti[] = FileOptimizer::optimizeAndStore($file, 'audit/capa');
        }
        $temuan->update(['bukti_perbaikan' => $bukti]);

        return $this->jsonSuccess(['bukti_perbaikan' => $bukti], 'Bukti perbaikan berhasil diunggah.');
    }

    /**
     * Evaluasi penutupan (Closing) oleh Auditor SPI.
     * POST /api/v1/audit/temuan-capa/{id}/evaluasi
     */
    public function evaluasiTemuan(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $temuan = TemuanAudit::find($id);

        if (! $temuan) {
            return $this->jsonError('Temuan audit tidak ditemukan.', 404);
        }
        $canEvaluate = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('update_audit_manajemen::temuan::capa')
        );
        if (! $canEvaluate) {
            return $this->jsonError('Hanya Auditor Internal SPI yang berwenang mengevaluasi dan menutup temuan CAPA.', 403);
        }

        $validator = Validator::make($request->all(), [
            'hasil_evaluasi'   => ['required', \Illuminate\Validation\Rule::in(StatusCapa::hasilEvaluasi())],
            'catatan_evaluasi' => 'required|string|min:5',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi gagal.', 422, $validator->errors());
        }

        $temuan->update([
            'status_capa'      => $request->input('hasil_evaluasi'),
            'catatan_evaluasi' => $request->input('catatan_evaluasi'),
            'dievaluasi_oleh'  => $user->id,
        ]);

        return $this->jsonSuccess([
            'id'          => $temuan->id,
            'kode'        => $temuan->kode,
            'status_capa' => $temuan->status_capa,
        ], "Evaluasi temuan CAPA '{$temuan->kode}' berhasil disimpan sebagai {$temuan->status_capa}.");
    }
}
