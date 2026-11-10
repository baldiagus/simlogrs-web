<?php

namespace App\Http\Controllers\Api\V1\Gudang;

use App\Enums\Persediaan\StatusStokOpname;
use App\Http\Controllers\Api\ApiController;
use App\Models\Aset\Aset;
use App\Models\Master\Barang;
use App\Models\Master\LokasiGudang;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Persediaan\StokBatch;
use App\Models\Persediaan\StokOpname;
use App\Models\Persediaan\StokOpnameDetail;
use App\Services\Distribusi\DistribusiService;
use App\Services\Distribusi\PickingScope;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GudangTataLetakApiController extends ApiController
{
    /**
     * Daftar rak gudang penyimpanan.
     * GET /v1/gudang/rak
     */
    public function daftarRak(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGlobal = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('view_any_master::lokasi::gudang') ||
            $user->can('view_master::lokasi::gudang')
        );

        $hasWarehouseAccess = $isGlobal || ($user && $user->gudang_id);
        if (! $hasWarehouseAccess) {
            return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat denah rak gudang.', 403);
        }

        $cari = $request->query('cari');
        $gudangId = $isGlobal ? $request->query('gudang_id') : $user->gudang_id;

        $rakList = LokasiGudang::query()
            ->with('gudang:id,kode,nama')
            ->when($gudangId, fn ($q) => $q->where('gudang_id', $gudangId))
            ->when($cari, function ($q) use ($cari) {
                $q->where(function ($sub) use ($cari) {
                    $sub->where('kode', 'like', "%{$cari}%")
                        ->orWhere('nama', 'like', "%{$cari}%");
                });
            })
            ->withCount([
                'stokBatch as total_barang' => function ($q) {
                    $q->where('jumlah', '>', 0);
                },
                'aset as total_aset',
            ])
            ->orderBy('kode')
            ->get()
            ->map(fn ($rak) => [
                'id' => $rak->id,
                'kode' => $rak->kode,
                'nama' => $rak->nama,
                'baris' => $rak->baris,
                'kolom' => $rak->kolom,
                'level' => $rak->level,
                'status' => $rak->status,
                'gudang_id' => $rak->gudang_id,
                'nama_gudang' => $rak->gudang?->nama ?? '-',
                'total_barang' => (int) ($rak->total_barang ?? 0),
                'total_aset' => (int) ($rak->total_aset ?? 0),
            ]);

        return $this->jsonSuccess($rakList, 'Daftar rak gudang berhasil diambil.');
    }

    /**
     * Detail rak gudang: daftar stok batch barang dan aset fisik.
     * GET /v1/gudang/rak/{id}
     */
    public function detailRak(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isGlobal = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('view_any_master::lokasi::gudang') ||
            $user->can('view_master::lokasi::gudang')
        );

        $rak = LokasiGudang::with(['gudang:id,kode,nama'])->find($id);

        if (! $rak) {
            return $this->jsonError('Data rak gudang tidak ditemukan.', 404);
        }

        if (! $isGlobal) {
            if (! $user?->gudang_id || (int) $user->gudang_id !== (int) $rak->gudang_id) {
                return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat detail rak fasilitas gudang ini.', 403);
            }
        }

        $barangList = $this->daftarBatchDiRak($id);
        $asetList = $this->daftarAsetDiRak($id);

        $data = [
            'id' => $rak->id,
            'kode' => $rak->kode,
            'nama' => $rak->nama,
            'baris' => $rak->baris,
            'kolom' => $rak->kolom,
            'level' => $rak->level,
            'status' => $rak->status,
            'gudang_id' => $rak->gudang_id,
            'nama_gudang' => $rak->gudang?->nama ?? '-',
            'total_barang' => $barangList->count(),
            'total_aset' => $asetList->count(),
            'barang' => $barangList,
            'aset' => $asetList,
        ];

        return $this->jsonSuccess($data, 'Detail rak gudang berhasil diambil.');
    }

    /**
     * Stok batch barang di rak tertentu (beserta status kedaluwarsa).
     */
    private function daftarBatchDiRak(int $rakId)
    {
        return StokBatch::query()
            ->where('lokasi_gudang_id', $rakId)
            ->where('jumlah', '>', 0)
            ->with(['barang.kategori:id,nama', 'barang.satuan:id,nama', 'barang.merek:id,nama'])
            ->get()
            ->map(function ($sb) {
                $exp = $sb->tanggal_kedaluwarsa;
                $statusKedaluwarsa = 'AMAN';
                if ($exp) {
                    $diffDays = now()->diffInDays($exp, false);
                    if ($diffDays < 0) {
                        $statusKedaluwarsa = 'KADALUWARSA';
                    } elseif ($diffDays <= 30) {
                        $statusKedaluwarsa = 'MENDEKATI_KADALUWARSA';
                    }
                }

                $tipe = $sb->barang?->tipe ?? 'HABIS_PAKAI';
                $tipeLabel = match ($tipe) {
                    'ASET'      => 'Aktiva / Aset Inventaris',
                    'SPAREPART' => 'Suku Cadang IPSRS',
                    default     => 'Habis Pakai (ATK/ART)',
                };

                return [
                    'id'                 => $sb->id,
                    'barang_id'          => $sb->barang_id,
                    'nama_barang'        => $sb->barang?->nama ?? 'Barang',
                    'kode_barang'        => $sb->barang?->kode ?? '-',
                    'tipe'               => $tipe,
                    'tipe_label'         => $tipeLabel,
                    'kategori'           => $sb->barang?->kategori?->nama ?? 'Umum',
                    'satuan'             => $sb->barang?->satuan?->nama ?? 'Pcs',
                    'merek'              => $sb->barang?->merek?->nama ?? '-',
                    'nomor_batch'        => $sb->nomor_batch,
                    'tanggal_kedaluwarsa'=> $sb->tanggal_kedaluwarsa?->format('Y-m-d'),
                    'status_kedaluwarsa' => $statusKedaluwarsa,
                    'kuantitas_stok'     => (int) $sb->jumlah,
                ];
            });
    }

    /**
     * Aset fisik di rak tertentu.
     */
    private function daftarAsetDiRak(int $rakId)
    {
        return Aset::query()
            ->where('lokasi_gudang_id', $rakId)
            ->with(['barang.merek:id,nama', 'barang.kategori:id,nama'])
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'kode_aset' => $a->kode_aset,
                'nama_aset'          => $a->barang?->nama ?? 'Aset Fisik',
                'tipe'               => $a->barang?->tipe ?? 'ASET',
                'tipe_label'         => 'Aktiva / Aset Inventaris',
                'merk'               => $a->barang?->merek?->nama ?? '-',
                'model'              => $a->barang?->spesifikasi ?? '-',
                'nomor_seri' => $a->nomor_seri ?? '-',
                'status_ketersediaan' => $a->status,
                'kondisi_fisik' => $a->kondisi,
            ]);
    }

    /**
     * Verifikasi barcode/QR batch & eksekusi picking FEFO via mobile scanner.
     */
    public function scanBatch(Request $request, DistribusiService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('create_gudang_stok::picking::distribusi') && ! $user->hasRole(['super_admin', 'admin_logistik'])) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk eksekusi picking FEFO.', 403);
        }

        $request->validate([
            'permintaan_id' => 'nullable|integer',
            'nomor_batch'   => 'nullable|string',
            'gudang_id'     => 'nullable|exists:gudang,id',
        ]);
        $barcode = trim($request->input('nomor_batch', $request->input('barcode', '')));
        $permintaanId = $request->input('permintaan_id');
        $permintaan = null;

        if ($permintaanId) {
            $permintaan = PermintaanDistribusi::with(['detail.barang', 'unit'])->find($permintaanId);
        }

        if (! $permintaan) {
            $ownGudangIds = PickingScope::ownGudangIds($user);
            $permintaan = PermintaanDistribusi::with(['detail.barang', 'unit'])
                ->whereIn('status', ['DISETUJUI_GUDANG', 'DIKIRIM_SEBAGIAN', 'DISETUJUI_KARU'])
                ->when(! PickingScope::isGlobalOperator($user), fn ($q) => $q->whereIn('gudang_id', $ownGudangIds))
                ->latest('id')
                ->first();
        }

        // Jika ada permohonan distribusi yang siap dipicking, jalankan picking FEFO
        if ($permintaan && in_array($permintaan->status, ['DISETUJUI_GUDANG', 'DIKIRIM_SEBAGIAN', 'DISETUJUI_KARU'], true)) {
            PickingScope::authorize($user, $permintaan);

            return $this->eksekusiPickingResponse($request, $service, $permintaan, $user);
        }

        // Jika tidak ada antrean dokumen distribusi, jalankan verifikasi batch langsung di rak
        if ($barcode !== '') {
            return $this->verifikasiBatchRak($barcode);
        }

        return $this->jsonError('Belum ada dokumen permohonan distribusi yang siap dipicking.', 422);
    }

    private function eksekusiPickingResponse(Request $request, DistribusiService $service, PermintaanDistribusi $permintaan, $user): JsonResponse
    {
        try {
            $isGlobal = PickingScope::isGlobalOperator($user);
            $ownGudangIds = PickingScope::ownGudangIds($user);
            $gudangId = ($isGlobal && $request->filled('gudang_id'))
                ? (int) $request->input('gudang_id')
                : ($permintaan->gudang_id ?: ($user->gudang_id ?: ($ownGudangIds[0] ?? null)));
            $sppb = $service->eksekusiPickingFefo($permintaan, $user, $gudangId);

            $sppb->load(['detail.barang', 'detail.stokBatch', 'unit']);

            return $this->jsonSuccess([
                'sppb_kode'      => $sppb->kode,
                'permintaan_id'  => $permintaan->id,
                'unit_tujuan'    => $sppb->unit?->nama,
                'tanggal_kirim'  => $sppb->tanggal_kirim?->format('Y-m-d'),
                'status'         => $sppb->status,
                'alokasi_batch'  => $sppb->detail->map(fn ($d) => [
                    'barang'      => $d->barang?->nama,
                    'nomor_batch' => $d->stokBatch?->nomor_batch,
                    'expired'     => $d->stokBatch?->tanggal_kedaluwarsa?->format('Y-m-d'),
                    'qty_dikirim' => $d->jumlah_dikirim,
                ]),
            ], "Picking FEFO berhasil. SPPB {$sppb->kode} telah diterbitkan.");
        } catch (Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    private function verifikasiBatchRak(string $barcode): JsonResponse
    {
        $batch = StokBatch::with(['barang', 'lokasiGudang'])
            ->whereRaw('LOWER(nomor_batch) = ?', [strtolower($barcode)])
            ->orWhereHas('barang', fn ($q) => $q->whereRaw('LOWER(kode) = ?', [strtolower($barcode)]))
            ->first();

        if (! $batch) {
            return $this->jsonError("Nomor batch atau barcode '{$barcode}' tidak ditemukan di gudang.", 422);
        }

        $earliest = StokBatch::where('barang_id', $batch->barang_id)
            ->where('status', 'AKTIF')
            ->where('jumlah', '>', 0)
            ->orderByRaw('tanggal_kedaluwarsa ASC NULLS LAST')
            ->first();

        $isValid = ($earliest && $earliest->id === $batch->id);

        return $this->jsonSuccess([
            'sppb_kode'      => 'VERIFIKASI-FEFO',
            'permintaan_id'  => 0,
            'unit_tujuan'    => 'Pemeriksaan Rak Gudang',
            'tanggal_kirim'  => now()->toDateString(),
            'status'         => $isValid ? 'VALID' : 'NON_FEFO',
            'alokasi_batch'  => [
                [
                    'barang'      => $batch->barang?->nama,
                    'nomor_batch' => $batch->nomor_batch,
                    'expired'     => $batch->tanggal_kedaluwarsa?->format('Y-m-d'),
                    'qty_dikirim' => $batch->jumlah,
                ],
            ],
        ], $isValid ? "Batch '{$batch->nomor_batch}' valid sesuai urutan FEFO." : 'Peringatan: Bukan batch terdekat.');
    }

    /**
     * Input perhitungan fisik blind count per barcode/nomor batch di depan rak via mobile scanner.
     * POST /v1/gudang/opname-count
     */
    public function opnameCount(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('create_gudang_stok::stok::opname') && ! $user->can('update_gudang_stok::stok::opname') && ! $user->hasRole(['super_admin', 'admin_logistik'])) {
            return $this->jsonError('Anda tidak memiliki wewenang menginput hasil stock opname.', 403);
        }

        $validated = $request->validate([
            'stok_opname_id' => 'required|exists:stok_opname,id',
            'stok_batch_id'  => 'nullable|exists:stok_batch,id',
            'nomor_batch'    => 'nullable|string',
            'jumlah_fisik'   => 'required|integer|min:0',
            'catatan'        => 'nullable|string|max:500',
        ]);

        $opname = StokOpname::find($validated['stok_opname_id']);
        if (! $opname) {
            return $this->jsonError('Sesi stock opname tidak ditemukan.', 404);
        }

        $isGlobalAdmin = $user && $user->hasRole(['super_admin', 'admin_logistik']);

        if (! $isGlobalAdmin && (int) $user?->gudang_id !== (int) $opname->gudang_id) {
            return $this->jsonError('Anda tidak memiliki wewenang menginput stock opname untuk fasilitas gudang lain.', 403);
        }

        if ($opname->status !== StatusStokOpname::Berjalan->value) {
            return $this->jsonError("Sesi stock opname {$opname->kode} tidak sedang dalam status BERJALAN (status: {$opname->status}).", 422);
        }

        // Resolusi baris detail
        $detail = null;
        if (! empty($validated['stok_batch_id'])) {
            $detail = StokOpnameDetail::with(['barang', 'stokBatch'])
                ->where('stok_opname_id', $opname->id)
                ->where('stok_batch_id', $validated['stok_batch_id'])
                ->first();
        } elseif (! empty($validated['nomor_batch'])) {
            $batch = StokBatch::where('gudang_id', $opname->gudang_id)
                ->where('nomor_batch', trim($validated['nomor_batch']))
                ->first();

            if ($batch) {
                $detail = StokOpnameDetail::with(['barang', 'stokBatch'])
                    ->where('stok_opname_id', $opname->id)
                    ->where('stok_batch_id', $batch->id)
                    ->first();
            }
        }

        if (! $detail) {
            return $this->jsonError('Item batch tidak terdaftar pada sesi stock opname ini.', 404);
        }

        $fisik = (int) $validated['jumlah_fisik'];
        $selisih = $fisik - $detail->jumlah_sistem;

        $detail->update([
            'jumlah_fisik' => $fisik,
            'selisih'      => $selisih,
            'catatan'      => $validated['catatan'] ?? $detail->catatan,
        ]);

        return $this->jsonSuccess([
            'opname_kode'   => $opname->kode,
            'detail_id'     => $detail->id,
            'barang'        => $detail->barang?->nama,
            'nomor_batch'   => $detail->stokBatch?->nomor_batch,
            'jumlah_fisik'  => $detail->jumlah_fisik,
            'catatan'       => $detail->catatan,
        ], 'Hasil perhitungan fisik berhasil direkam.');
    }
}
