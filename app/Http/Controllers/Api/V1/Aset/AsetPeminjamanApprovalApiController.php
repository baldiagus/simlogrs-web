<?php

namespace App\Http\Controllers\Api\V1\Aset;

use App\Enums\Aset\StatusPeminjaman;
use App\Http\Controllers\Api\ApiController;
use App\Models\Aset\Aset;
use App\Models\Aset\PermintaanPeminjaman;
use App\Services\Aset\PeminjamanAsetService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsetPeminjamanApprovalApiController extends ApiController
{
    /**
     * Daftar antrean otorisasi peminjaman masuk untuk Karu & Kepala Gudang.
     * GET /v1/aset/approval-peminjaman
     */
    public function daftarApprovalPeminjaman(Request $request): JsonResponse
    {
        $user = $request->user();

        $canApprove = $user->hasRole(['super_admin', 'admin_logistik']) ||
            ($user->can('update_layanan_unit::approval::peminjaman') && $user->hasRole(['kepala_unit', 'kepala_gudang', 'kepala_teknisi']));
        if (! $canApprove) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk mengakses otorisasi peminjaman aset.', 403);
        }

        $isGlobalAdmin = $user->hasRole('super_admin') || $user->hasRole('admin_logistik');

        $query = PermintaanPeminjaman::query()
            ->whereIn('status', StatusPeminjaman::antreanOtorisasi())
            ->with([
                'unitPeminjam:id,kode,nama',
                'unitPenyedia:id,kode,nama',
                'gudangPenyedia:id,kode,nama',
                'pengaju:id,nama',
                'detail.aset.barang:id,kode,nama',
            ]);

        if (! $isGlobalAdmin) {
            if ($user->hasRole(['kepala_unit', 'kepala_teknisi'])) {
                $query->where('tipe_penyedia', 'RUANGAN')->where('unit_penyedia_id', $user->unit_id);
            } elseif ($user->hasRole('kepala_gudang')) {
                $query->where('tipe_penyedia', 'GUDANG')->where('gudang_penyedia_id', $user->gudang_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $list = $query->latest('id')
            ->limit(100)
            ->get()
            ->map(function ($p) {
                $firstDetail = $p->detail->first();
                $aset = $firstDetail?->aset;

                return [
                    'id'                      => $p->id,
                    'kode'                    => $p->kode,
                    'tipe_penyedia'           => $p->tipe_penyedia,
                    'unit_peminjam'           => $p->unitPeminjam?->nama ?? '-',
                    'pengaju'                 => $p->pengaju?->nama ?? 'Staf',
                    'tanggal_pinjam'          => $p->tanggal_pinjam?->format('Y-m-d') ?? '',
                    'tanggal_kembali_rencana' => $p->tanggal_kembali_rencana?->format('Y-m-d') ?? '',
                    'keperluan'               => $p->keperluan,
                    'status'                  => $p->status,
                    'catatan'                 => $p->catatan,
                    'nama_aset'               => $aset?->barang?->nama ?? 'Aset Fisik',
                    'kode_aset'               => $aset?->kode_aset ?? '-',
                ];
            });

        return $this->jsonSuccess($list, 'Antrean otorisasi peminjaman aset berhasil diambil.');
    }

    /**
     * Otorisasi peminjaman aset (Setujui, Tolak, atau Serah Terima Fisik).
     * POST /v1/aset/peminjaman/{id}/otorisasi
     */
    public function otorisasiPeminjaman(Request $request, int $id, PeminjamanAsetService $service): JsonResponse
    {
        $user = $request->user();

        $canApprove = $user->hasRole(['super_admin', 'admin_logistik']) ||
            ($user->can('update_layanan_unit::approval::peminjaman') && $user->hasRole(['kepala_unit', 'kepala_gudang', 'kepala_teknisi']));
        if (! $canApprove) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk mengotorisasi peminjaman ini.', 403);
        }

        $pinjam = PermintaanPeminjaman::with('detail.aset')->find($id);
        if (! $pinjam) {
            return $this->jsonError('Data peminjaman tidak ditemukan.', 404);
        }

        $isGlobalAdmin = $user->hasRole('super_admin') || $user->hasRole('admin_logistik');
        if (! $isGlobalAdmin) {
            $hasProviderRole = $pinjam->tipe_penyedia === 'RUANGAN'
                ? $user->hasRole(['kepala_unit', 'kepala_teknisi'])
                : $user->hasRole('kepala_gudang');
            $hasProviderScope = $pinjam->tipe_penyedia === 'RUANGAN'
                ? (int) $user->unit_id === (int) $pinjam->unit_penyedia_id
                : (int) $user->gudang_id === (int) $pinjam->gudang_penyedia_id;
            if (! $hasProviderRole || ! $hasProviderScope) {
                return $this->jsonError('Anda tidak memiliki wewenang memproses permintaan dari fasilitas penyedia ini.', 403);
            }
        }

        $request->validate([
            'aksi'    => 'required|in:setujui,tolak,serah_terima',
            'catatan' => 'nullable|string|max:500',
        ]);

        $aksi = $request->input('aksi');

        if ($aksi === 'setujui') {
            return $this->prosesSetujui($pinjam, $user);
        }

        if ($aksi === 'tolak') {
            return $this->prosesTolak($request, $pinjam, $user);
        }

        if ($aksi === 'serah_terima') {
            return $this->prosesSerahTerima($pinjam, $user, $service);
        }

        return $this->jsonError('Aksi tidak dikenali.', 422);
    }

    private function prosesSetujui(PermintaanPeminjaman $pinjam, $user): JsonResponse
    {
        if ($pinjam->status !== StatusPeminjaman::Diajukan->value) {
            return $this->jsonError("Hanya permintaan berstatus DIAJUKAN yang dapat disetujui (saat ini: {$pinjam->status}).", 422);
        }

        // Kunci integritas: periksa apakah ada aset di permohonan ini yang sudah disetujui/dipinjam pada transaksi lain
        $asetIds = $pinjam->detail->pluck('aset_id')->filter();
        $konflik = PermintaanPeminjaman::whereIn('status', StatusPeminjaman::mengikatAset())
            ->where('id', '!=', $pinjam->id)
            ->whereHas('detail', fn ($d) => $d->whereIn('aset_id', $asetIds))
            ->first();

        if ($konflik) {
            return $this->jsonError("Aset pada permohonan ini telah disetujui atau sedang aktif dipinjam pada transaksi {$konflik->kode}.", 422);
        }

        $pinjam->update([
            'status'         => StatusPeminjaman::Disetujui->value,
            'disetujui_oleh' => $user->id,
        ]);
        return $this->jsonSuccess([
            'kode'   => $pinjam->kode,
            'status' => StatusPeminjaman::Disetujui->value,
        ], "Permintaan peminjaman {$pinjam->kode} berhasil disetujui, siap diserahterimakan.");
    }

    private function prosesTolak(Request $request, PermintaanPeminjaman $pinjam, $user): JsonResponse
    {
        if ($pinjam->status !== StatusPeminjaman::Diajukan->value) {
            return $this->jsonError("Hanya permintaan berstatus DIAJUKAN yang dapat ditolak (saat ini: {$pinjam->status}).", 422);
        }

        $pinjam->update([
            'status'         => StatusPeminjaman::Ditolak->value,
            'disetujui_oleh' => $user->id,
            'catatan'        => $request->input('catatan') ?? $pinjam->catatan,
        ]);

        return $this->jsonSuccess([
            'kode'   => $pinjam->kode,
            'status' => StatusPeminjaman::Ditolak->value,
        ], "Permintaan peminjaman {$pinjam->kode} ditolak.");
    }

    private function prosesSerahTerima(PermintaanPeminjaman $pinjam, $user, PeminjamanAsetService $service): JsonResponse
    {
        if ($pinjam->status !== StatusPeminjaman::Disetujui->value) {
            return $this->jsonError("Hanya peminjaman berstatus DISETUJUI yang dapat diserahterimakan (saat ini: {$pinjam->status}).", 422);
        }

        try {
            $service->serahTerimaFisik($pinjam, $user);

            return $this->jsonSuccess([
                'kode'   => $pinjam->kode,
                'status' => StatusPeminjaman::Dipinjam->value,
            ], "Serah terima fisik berhasil. Aset resmi berstatus DIPINJAM oleh {$pinjam->unitPeminjam?->nama}.");
        } catch (Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * Konfirmasi serah terima fisik aset ke peminjam via mobile scanner.
     */
    public function serahTerima(Request $request, PeminjamanAsetService $service): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'peminjaman_id' => 'required|exists:permintaan_peminjaman,id',
        ]);

        $pinjam = PermintaanPeminjaman::with(['detail.aset', 'unitPenyedia', 'gudangPenyedia'])->find($request->input('peminjaman_id'));

        if (! $pinjam) {
            return $this->jsonError('Data peminjaman tidak ditemukan.', 404);
        }

        if ($pinjam->status !== StatusPeminjaman::Disetujui->value) {
            return $this->jsonError("Permintaan {$pinjam->kode} tidak dalam status siap serah terima (saat ini: {$pinjam->status}).", 422);
        }

        $isGlobalAdmin = $user->hasRole('super_admin') || $user->hasRole('admin_logistik');
        if (! $isGlobalAdmin) {
            if (! $user->canAny(['update_layanan_unit::approval::peminjaman', 'update_layanan_unit::peminjaman::aset'])) {
                return $this->jsonError('Anda tidak memiliki wewenang menyerahkan fisik aset peminjaman ini.', 403);
            }
            if ($pinjam->tipe_penyedia === 'RUANGAN' && (! $user->unit_id || (int) $user->unit_id !== (int) $pinjam->unit_penyedia_id)) {
                return $this->jsonError('Anda tidak memiliki wewenang menyerahkan aset dari ruangan penyedia ini.', 403);
            }
            if ($pinjam->tipe_penyedia === 'GUDANG' && (! $user->gudang_id || (int) $user->gudang_id !== (int) $pinjam->gudang_penyedia_id)) {
                return $this->jsonError('Anda tidak memiliki wewenang menyerahkan aset dari gudang penyedia ini.', 403);
            }
        }

        try {
            $service->serahTerimaFisik($pinjam, $user);

            return $this->jsonSuccess([
                'kode'            => $pinjam->kode,
                'status'          => StatusPeminjaman::Dipinjam->value,
                'diserahkan_oleh' => [
                    'id'       => $user->id,
                    'nama'     => $user->nama,
                    'nip'      => $user->nip,
                    'username' => $user->username,
                ],
            ], "Serah terima fisik berhasil dicatat oleh {$user->nama}. Aset resmi berstatus DIPINJAM oleh {$pinjam->unitPeminjam?->nama}.");
        } catch (Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * Konfirmasi pengembalian dan inspeksi fisik aset via mobile.
     */
    public function kembalikan(Request $request, PeminjamanAsetService $service): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'peminjaman_id'                             => 'required|exists:permintaan_peminjaman,id',
            'inspeksi'                                  => 'required|array|min:1',
            'inspeksi.*.permintaan_peminjaman_detail_id' => 'nullable|integer',
            'inspeksi.*.aset_id'                        => 'required|exists:aset,id',
            'inspeksi.*.kondisi_saat_kembali'           => 'required|in:BAIK,RUSAK_RINGAN,RUSAK_BERAT',
            'inspeksi.*.catatan'                        => 'nullable|string|max:500',
            'catatan'                                   => 'nullable|string|max:1000',
        ]);

        $pinjam = PermintaanPeminjaman::with('detail')->find($request->input('peminjaman_id'));

        if (! $pinjam) {
            return $this->jsonError('Data peminjaman tidak ditemukan.', 404);
        }

        if ($pinjam->status !== StatusPeminjaman::Dipinjam->value) {
            return $this->jsonError("Peminjaman {$pinjam->kode} tidak dalam status aktif dipinjam (saat ini: {$pinjam->status}).", 422);
        }

        $isGlobalAdmin = $user->hasRole('super_admin') || $user->hasRole('admin_logistik');
        if (! $isGlobalAdmin && ! $user->canAny(['create_layanan_unit::pengembalian::aset', 'update_layanan_unit::pengembalian::aset'])) {
            return $this->jsonError('Anda tidak memiliki izin memproses pengembalian dan inspeksi fisik aset.', 403);
        }
        $isPenyedia = ($pinjam->tipe_penyedia === 'RUANGAN' && $user->unit_id && (int) $user->unit_id === (int) $pinjam->unit_penyedia_id)
            || ($pinjam->tipe_penyedia === 'GUDANG' && $user->gudang_id && (int) $user->gudang_id === (int) $pinjam->gudang_penyedia_id);

        if (! $isGlobalAdmin && ! $isPenyedia) {
            $isPeminjam = ((int) $user->unit_id === (int) $pinjam->unit_id)
                || ((int) $user->id === (int) $pinjam->diajukan_oleh);
            if ($isPeminjam) {
                return $this->jsonError('Peminjam tidak berwenang melakukan inspeksi fisik pengembalian aset. Pemeriksaan fisik wajib dilakukan oleh unit penyedia pemilik aset untuk menghindari konflik kepentingan.', 403);
            }

            return $this->jsonError('Hanya ruangan atau fasilitas gudang penyedia pemilik aset yang berhak memproses inspeksi dan pengembalian fisik aset.', 403);
        }
        $inspeksi = $request->input('inspeksi');
        foreach ($inspeksi as &$row) {
            $detailId = $row['permintaan_peminjaman_detail_id'] ?? null;
            $exists = $detailId && \App\Models\Aset\PermintaanPeminjamanDetail::where('id', $detailId)->where('permintaan_peminjaman_id', $pinjam->id)->exists();
            if (! $exists) {
                // Klien lama tanpa detail id: cocokkan lewat aset_id saja; aset asing ditolak service.
                $matchedDetail = $pinjam->detail->firstWhere('aset_id', (int) $row['aset_id']);
                if ($matchedDetail) {
                    $row['permintaan_peminjaman_detail_id'] = $matchedDetail->id;
                }
            }
        }
        unset($row);

        try {
            $pengembalian = $service->prosesPengembalian(
                $pinjam,
                $inspeksi,
                $user,
                $request->input('catatan')
            );

            return $this->jsonSuccess([
                'kode_pengembalian' => $pengembalian->kode,
                'kode_peminjaman'   => $pinjam->kode,
                'status_transaksi'  => StatusPeminjaman::Selesai->value,
            ], "Pengembalian aset berhasil diproses. Status transaksi: SELESAI.");
        } catch (Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }
}
