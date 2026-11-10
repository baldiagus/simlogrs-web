<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Enums\Ipsrs\StatusMaintenance;
use App\Enums\Ipsrs\StatusSpk;
use App\Http\Controllers\Api\ApiController;
use App\Models\Ipsrs\PenugasanMaintenance;
use App\Models\Ipsrs\PermintaanMaintenance;
use App\Models\User;
use App\Support\Generator\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Disposisi tiket, daftar & detail SPK teknisi IPSRS.
 */
class MaintenanceSpkApiController extends ApiController
{
    /**
     * Daftar teknisi resmi IPSRS untuk opsi penugasan SPK.
     * GET /v1/maintenance/teknisi
     */
    public function daftarTeknisi(Request $request): JsonResponse
    {
        $teknisi = User::where('status', 'AKTIF')
            ->whereHas('roles', fn ($r) => $r->where('name', 'teknisi'))
            ->orderBy('nama')
            ->get(['id', 'nama', 'email', 'nip']);

        return $this->jsonSuccess($teknisi, 'Daftar teknisi IPSRS berhasil dimuat.');
    }

    /**
     * Disposisi tiket kerusakan dan penerbitan SPK penugasan teknisi.
     * POST /v1/maintenance/tiket/{id}/disposisi
     */
    public function disposisiTiket(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $canDisposisi = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            ($user->can('create_pemeliharaan::spk::perbaikan') && $user->hasRole('kepala_teknisi'))
        );

        if (! $canDisposisi) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk menerbitkan SPK penugasan teknisi.', 403);
        }

        $tiket = PermintaanMaintenance::with('aset')->find($id);

        if (! $tiket) {
            return $this->jsonError('Tiket kerusakan tidak ditemukan.', 404);
        }

        if (! in_array($tiket->status, StatusMaintenance::menungguPenugasan(), true)) {
            return $this->jsonError("Tiket {$tiket->kode} sudah diproses sebelumnya (saat ini: {$tiket->status}).", 422);
        }

        $validator = $this->validatorDisposisi($request);

        if ($validator->fails()) {
            return $this->jsonError('Validasi disposisi gagal.', 422, $validator->errors());
        }

        $teknisiId = (int) $request->input('teknisi_id');
        $tglPenugasan = $request->input('tanggal_penugasan') ?: now()->toDateString();
        $targetSelesai = $request->input('target_selesai') ?: now()->addDays(2)->toDateString();
        $instruksiKhusus = $request->input('instruksi_khusus');

        $kodeSpk = CodeGenerator::generateSpk();

        try {
            $spk = $this->terbitkanSpk($kodeSpk, $tiket, $teknisiId, $user, $tglPenugasan, $targetSelesai, $instruksiKhusus);

            $teknisi = User::find($teknisiId);

            return $this->jsonSuccess([
                'id'       => $spk->id,
                'kode_spk' => $spk->kode,
                'teknisi'  => $teknisi?->nama ?? 'Teknisi',
                'status'   => StatusSpk::Ditugaskan->value,
            ], "Surat Perintah Kerja {$spk->kode} berhasil diterbitkan dan ditugaskan ke {$teknisi?->nama}.", 201);
        } catch (\Throwable $e) {
            return $this->jsonError('Gagal menerbitkan SPK: ' . $e->getMessage(), 500);
        }
    }

    private function validatorDisposisi(Request $request)
    {
        return Validator::make($request->all(), [
            'teknisi_id'        => 'required|exists:pengguna,id',
            'tanggal_penugasan' => 'nullable|date',
            'target_selesai'    => 'nullable|date',
            'instruksi_khusus'  => 'nullable|string|max:500',
        ], [
            'teknisi_id.required' => 'Teknisi penanggung jawab wajib dipilih.',
            'teknisi_id.exists'   => 'Teknisi yang dipilih tidak valid di sistem.',
        ]);
    }

    private function terbitkanSpk(string $kodeSpk, PermintaanMaintenance $tiket, int $teknisiId, $user, $tglPenugasan, $targetSelesai, $instruksiKhusus): PenugasanMaintenance
    {
        return DB::transaction(function () use ($kodeSpk, $tiket, $teknisiId, $user, $tglPenugasan, $targetSelesai, $instruksiKhusus) {
            $spkRecord = PenugasanMaintenance::create([
                'kode'                      => $kodeSpk,
                'permintaan_maintenance_id' => $tiket->id,
                'teknisi_id'                => $teknisiId,
                'ditugaskan_oleh'           => $user->id,
                'tanggal_penugasan'         => $tglPenugasan,
                'target_selesai'            => $targetSelesai,
                'instruksi_khusus'          => $instruksiKhusus,
                'status'                    => StatusSpk::Ditugaskan->value,
            ]);

            $tiket->update([
                'status' => StatusMaintenance::Ditugaskan->value,
            ]);

            return $spkRecord;
        });
    }

    /**
     * Daftar Surat Perintah Kerja (SPK) untuk mobile teknisi.
     * GET /v1/maintenance/spk
     */
    public function spkList(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->jsonError('Unauthenticated.', 401);
        }

        $canViewAll = $user->hasRole(['super_admin', 'admin_logistik', 'kepala_teknisi', 'auditor', 'manajemen']);
        if (! $canViewAll && ! $user->can('view_any_pemeliharaan::spk::perbaikan') && ! $user->can('view_pemeliharaan::spk::perbaikan') && ! $user->hasRole(['teknisi', 'kepala_teknisi'])) {
            return $this->jsonError('Anda tidak memiliki wewenang melihat daftar SPK teknisi.', 403);
        }

        $filterStatus = $request->query('status'); // DITUGASKAN, DITERIMA, SELESAI
        $spks = PenugasanMaintenance::with([
            'permintaanMaintenance.aset.barang',
            'permintaanMaintenance.unit',
            'teknisi',
            'penugast',
        ])
        ->when(! $canViewAll, fn ($q) => $q->where('teknisi_id', $user->id))
        ->when($filterStatus, fn ($q) => $q->where('status', $filterStatus))
        ->orderByRaw('CASE WHEN status = ? THEN 1 WHEN status = ? THEN 2 ELSE 3 END', [StatusSpk::Ditugaskan->value, StatusSpk::Diterima->value])
        ->orderBy('id', 'desc')
        ->paginate(15);

        $items = $spks->getCollection()->map(fn ($spk) => [
            'id'                => $spk->id,
            'kode_spk'          => $spk->kode,
            'status'            => $spk->status,
            'tanggal_penugasan' => $spk->tanggal_penugasan?->format('Y-m-d'),
            'target_selesai'    => $spk->target_selesai?->format('Y-m-d'),
            'instruksi_khusus'  => $spk->instruksi_khusus,
            'teknisi'           => $spk->teknisi?->nama ?? '-',
            'tiket'             => [
                'kode'              => $spk->permintaanMaintenance?->kode,
                'prioritas'         => $spk->permintaanMaintenance?->prioritas,
                'deskripsi_masalah' => $spk->permintaanMaintenance?->deskripsi_masalah,
                'foto_url'          => $spk->permintaanMaintenance?->foto_kerusakan
                    ? asset('storage/' . ltrim($spk->permintaanMaintenance->foto_kerusakan, '/'))
                    : null,
            ],
            'aset'              => [
                'kode_aset'  => $spk->permintaanMaintenance?->aset?->kode_aset ?? '-',
                'nama'       => $spk->permintaanMaintenance?->aset?->barang?->nama ?? '-',
                'nomor_seri' => $spk->permintaanMaintenance?->aset?->nomor_seri ?? '-',
                'lokasi'     => $spk->permintaanMaintenance?->unit?->nama ?? '-',
            ],
        ]);

        return $this->jsonSuccess([
            'spk' => $items,
            'paginasi' => [
                'total'        => $spks->total(),
                'per_halaman'  => $spks->perPage(),
                'halaman_ini'  => $spks->currentPage(),
                'total_halaman'=> $spks->lastPage(),
            ],
        ], 'Daftar penugasan SPK teknisi berhasil dimuat.');
    }

    /**
     * Rincian lengkap dokumen SPK teknisi.
     * GET /v1/maintenance/spk/{id}
     */
    public function spkDetail(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $isGlobalAdmin = $user?->hasRole(['super_admin', 'admin_logistik']);
        if (! $user || (! $isGlobalAdmin && ! $user->can('view_any_pemeliharaan::spk::perbaikan') && ! $user->can('view_pemeliharaan::spk::perbaikan'))) {
            return $this->jsonError('Anda tidak memiliki wewenang melihat detail SPK.', 403);
        }

        $spk = PenugasanMaintenance::with([
            'permintaanMaintenance.aset.barang',
            'permintaanMaintenance.unit',
            'permintaanMaintenance.pelapor',
            'teknisi',
            'penugast',
            'logMaintenance.teknisi',
            'logMaintenance.sukuCadang.barang',
        ])->find($id);

        if (! $spk) {
            return $this->jsonError('Dokumen SPK tidak ditemukan.', 404);
        }
        if ($user->hasRole('teknisi') && ! $isGlobalAdmin && (int) $spk->teknisi_id !== (int) $user->id) {
            return $this->jsonError('Anda hanya diizinkan melihat SPK yang ditugaskan kepada Anda.', 403);
        }

        return $this->jsonSuccess([
            'id'                => $spk->id,
            'kode_spk'          => $spk->kode,
            'status'            => $spk->status,
            'tanggal_penugasan' => $spk->tanggal_penugasan?->format('Y-m-d'),
            'target_selesai'    => $spk->target_selesai?->format('Y-m-d'),
            'instruksi_khusus'  => $spk->instruksi_khusus,
            'teknisi'           => [
                'id'   => $spk->teknisi?->id,
                'nama' => $spk->teknisi?->nama,
            ],
            'tiket'             => [
                'id'                => $spk->permintaanMaintenance?->id,
                'kode'              => $spk->permintaanMaintenance?->kode,
                'prioritas'         => $spk->permintaanMaintenance?->prioritas,
                'status'            => $spk->permintaanMaintenance?->status,
                'deskripsi_masalah' => $spk->permintaanMaintenance?->deskripsi_masalah,
                'foto_url'          => $spk->permintaanMaintenance?->foto_kerusakan
                    ? asset('storage/' . ltrim($spk->permintaanMaintenance->foto_kerusakan, '/'))
                    : null,
                'pelapor'           => $spk->permintaanMaintenance?->pelapor?->nama,
            ],
            'aset'              => [
                'id'         => $spk->permintaanMaintenance?->aset?->id,
                'kode_aset'  => $spk->permintaanMaintenance?->aset?->kode_aset,
                'nama'       => $spk->permintaanMaintenance?->aset?->barang?->nama,
                'nomor_seri' => $spk->permintaanMaintenance?->aset?->nomor_seri,
                'unit'       => $spk->permintaanMaintenance?->unit?->nama,
            ],
            'riwayat_log'       => $spk->logMaintenance->map(fn ($log) => [
                'id'                 => $log->id,
                'tanggal_pengerjaan' => $log->tanggal_pengerjaan?->toIso8601String(),
                'teknisi'            => $log->teknisi?->nama,
                'diagnosa'           => $log->diagnosa,
                'tindakan'           => $log->tindakan,
                'hasil'              => $log->hasil,
                'catatan'            => $log->catatan,
                'foto_url'           => $log->foto_pengerjaan
                    ? Storage::disk('public')->url($log->foto_pengerjaan)
                    : null,
                'suku_cadang'        => $log->sukuCadang->map(fn ($sc) => [
                    'barang'   => $sc->barang?->nama,
                    'jumlah'   => $sc->jumlah_digunakan,
                    'subtotal' => (float) $sc->subtotal,
                ]),
            ]),
        ], 'Detail SPK berhasil dimuat.');
    }
}
