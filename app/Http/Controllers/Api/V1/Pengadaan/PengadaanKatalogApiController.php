<?php

namespace App\Http\Controllers\Api\V1\Pengadaan;

use App\Http\Controllers\Api\ApiController;
use App\Models\Master\Barang;
use App\Models\Persediaan\StokBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Katalog master barang untuk pengajuan pengadaan/amprah.
 */
class PengadaanKatalogApiController extends ApiController
{
    /**
     * Katalog pencarian master barang untuk pengajuan pengadaan/amprah.
     * GET /v1/master/barang
     */
    public function katalogBarang(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cari' => 'nullable|string|max:255',
            'tipe' => 'nullable|in:HABIS_PAKAI,ASET,SPAREPART',
            'page' => 'sometimes|integer|min:1',
            'per_halaman' => 'sometimes|integer|min:1|max:100',
        ]);
        $cari = trim($validated['cari'] ?? '');
        $tipe = $validated['tipe'] ?? null;

        $paginated = Barang::query()
            ->where('status', 'AKTIF')
            ->with(['kategori:id,nama', 'satuan:id,nama', 'merek:id,nama'])
            ->when($tipe, fn ($q) => $q->where('tipe', $tipe))
            ->when($cari !== '', function ($q) use ($cari) {
                $q->where(function ($sub) use ($cari) {
                    $sub->where('nama', 'ilike', "%{$cari}%")
                        ->orWhere('kode', 'ilike', "%{$cari}%");
                });
            })
            ->orderBy('nama')
            ->orderBy('id')
            ->paginate((int) ($validated['per_halaman'] ?? 50), ['*'], 'page', (int) ($validated['page'] ?? 1));

        $barang = $paginated->getCollection()
            ->map(function ($b) {
                $tipe = $b->tipe ?? 'HABIS_PAKAI';
                $tipeLabel = match ($tipe) {
                    'ASET'      => 'Aktiva / Aset Inventaris',
                    'SPAREPART' => 'Suku Cadang IPSRS',
                    default     => 'Habis Pakai (ATK/ART)',
                };
                $stokGudang = (int) StokBatch::where('barang_id', $b->id)
                    ->where('status', 'AKTIF')
                    ->where('jumlah', '>', 0)
                    ->sum('jumlah');

                return [
                    'id'             => $b->id,
                    'kode'           => $b->kode,
                    'nama'           => $b->nama,
                    'spesifikasi'    => $b->nama,
                    'tipe'           => $tipe,
                    'tipe_label'     => $tipeLabel,
                    'kategori'       => $b->kategori?->nama ?? 'Umum',
                    'satuan'         => $b->satuan?->nama ?? 'Pcs',
                    'merek'          => $b->merek?->nama ?? '-',
                    'harga_estimasi' => (float) ($b->harga_estimasi ?? 0),
                    'stok'           => $stokGudang,
                ];
            });

        return $this->jsonSuccess([
            'items' => $barang,
            'paginasi' => [
                'total' => $paginated->total(),
                'per_halaman' => $paginated->perPage(),
                'halaman_ini' => $paginated->currentPage(),
                'total_halaman' => $paginated->lastPage(),
            ],
        ], 'Katalog barang berhasil diambil.');
    }
}
