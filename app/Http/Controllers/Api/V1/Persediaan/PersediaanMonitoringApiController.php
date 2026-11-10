<?php

namespace App\Http\Controllers\Api\V1\Persediaan;

use App\Http\Controllers\Api\ApiController;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Persediaan\PergerakanStok;
use App\Models\Persediaan\StokBatch;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersediaanMonitoringApiController extends ApiController
{
    /**
     * 1. Monitoring Stok FEFO (First-Expired, First-Out)
     * GET /v1/gudang/fefo/monitoring
     */
    public function monitoringFefo(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGlobal = $user && $user->hasRole(['super_admin', 'admin_logistik']);
        $canView = $isGlobal || ($user && ($user->can('view_any_gudang_stok::monitoring::fefo') || $user->can('view_gudang_stok::monitoring::fefo')));

        $hasWarehouseAccess = $canView && ($isGlobal || ($user && $user->gudang_id));
        if (! $hasWarehouseAccess) {
            return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat monitoring FEFO gudang.', 403);
        }

        $gudangId = $isGlobal ? $request->query('gudang_id', $user->gudang_id) : $user->gudang_id;
        $kategori = $request->query('kategori', 'SEMUA');
        $cari = $request->query('cari');

        $today = now()->toDateString();
        $date30 = now()->addDays(30)->toDateString();
        $date90 = now()->addDays(90)->toDateString();

        $metrik = $this->hitungMetrikFefo($gudangId, $today, $date30, $date90);

        // Query Daftar Batch FEFO
        $query = StokBatch::with([
            'barang.kategori:id,nama',
            'barang.satuan:id,nama,simbol',
            'gudang:id,kode,nama',
            'lokasiGudang:id,kode,nama',
        ])
        ->where('status', 'AKTIF')
        ->where('jumlah', '>', 0)
        ->when($gudangId, fn ($q) => $q->where('gudang_id', $gudangId));

        $this->terapkanFilterFefo($query, $kategori, $cari, $today, $date30, $date90);

        $batches = $query->orderByRaw('tanggal_kedaluwarsa ASC NULLS LAST')
            ->limit(100)
            ->get()
            ->map(fn ($b) => $this->formatBatchFefo($b, $today));

        return $this->jsonSuccess([
            'metrik' => $metrik,
            'items'  => $batches,
        ], 'Data monitoring FEFO berhasil dimuat.');
    }

    /**
     * Metrik Ringkasan FEFO.
     */
    private function hitungMetrikFefo($gudangId, string $today, string $date30, string $date90): array
    {
        $baseMetrics = StokBatch::where('status', 'AKTIF')
            ->where('jumlah', '>', 0)
            ->when($gudangId, fn ($q) => $q->where('gudang_id', $gudangId));

        $countExpired = (clone $baseMetrics)
            ->whereNotNull('tanggal_kedaluwarsa')
            ->where('tanggal_kedaluwarsa', '<', $today)
            ->count();

        $countKritis30 = (clone $baseMetrics)
            ->whereNotNull('tanggal_kedaluwarsa')
            ->whereBetween('tanggal_kedaluwarsa', [$today, $date30])
            ->count();

        $countPeringatan90 = (clone $baseMetrics)
            ->whereNotNull('tanggal_kedaluwarsa')
            ->whereBetween('tanggal_kedaluwarsa', [$date30, $date90])
            ->count();

        $countRop = Barang::where('status', 'AKTIF')
            ->where('titik_pesan', '>', 0)
            ->whereRaw('(SELECT COALESCE(SUM(jumlah), 0) FROM stok_batch WHERE stok_batch.barang_id = barang.id AND stok_batch.status = \'AKTIF\') <= barang.titik_pesan')
            ->count();

        return [
            'count_expired'       => $countExpired,
            'count_kritis_30'     => $countKritis30,
            'count_peringatan_90' => $countPeringatan90,
            'count_rop'           => $countRop,
            'total_batch_aktif'   => $baseMetrics->count(),
        ];
    }

    /**
     * Filter kategori FEFO + pencarian kata kunci.
     */
    private function terapkanFilterFefo($query, $kategori, $cari, string $today, string $date30, string $date90): void
    {
        if ($kategori === 'KEDALUWARSA') {
            $query->whereNotNull('tanggal_kedaluwarsa')->where('tanggal_kedaluwarsa', '<', $today);
        } elseif ($kategori === 'KRITIS') {
            $query->whereNotNull('tanggal_kedaluwarsa')->whereBetween('tanggal_kedaluwarsa', [$today, $date30]);
        } elseif ($kategori === 'PERINGATAN') {
            $query->whereNotNull('tanggal_kedaluwarsa')->whereBetween('tanggal_kedaluwarsa', [$date30, $date90]);
        } elseif ($kategori === 'ROP') {
            $query->whereHas('barang', function ($b) {
                $b->where('titik_pesan', '>', 0)
                  ->whereRaw('(SELECT COALESCE(SUM(jumlah), 0) FROM stok_batch WHERE stok_batch.barang_id = barang.id AND stok_batch.status = \'AKTIF\') <= barang.titik_pesan');
            });
        }

        if ($cari) {
            $query->where(function ($sub) use ($cari) {
                $sub->where('nomor_batch', 'ilike', "%{$cari}%")
                    ->orWhereHas('barang', fn ($b) => $b->where('nama', 'ilike', "%{$cari}%")->orWhere('kode', 'ilike', "%{$cari}%"))
                    ->orWhereHas('lokasiGudang', fn ($lg) => $lg->where('nama', 'ilike', "%{$cari}%"));
            });
        }
    }

    /**
     * Format satu batch FEFO untuk respons.
     */
    private function formatBatchFefo($b, string $today): array
    {
        $ed = $b->tanggal_kedaluwarsa;
        $sisaHari = null;
        $statusFefo = 'AMAN'; // AMAN, PERINGATAN, KRITIS, KEDALUWARSA, TANPA_ED

        if ($ed) {
            $sisaHari = Carbon::parse($today)->diffInDays(Carbon::parse($ed), false);
            if ($sisaHari < 0) {
                $statusFefo = 'KEDALUWARSA';
            } elseif ($sisaHari <= 30) {
                $statusFefo = 'KRITIS';
            } elseif ($sisaHari <= 90) {
                $statusFefo = 'PERINGATAN';
            }
        } else {
            $statusFefo = 'TANPA_ED';
        }

        $nilaiTotal = (float) $b->jumlah * (float) ($b->harga_satuan ?? 0);

        return [
            'id'                  => $b->id,
            'nomor_batch'         => $b->nomor_batch,
            'barang_id'           => $b->barang_id,
            'kode_barang'         => $b->barang?->kode ?? '-',
            'nama_barang'         => $b->barang?->nama ?? 'Komoditas',
            'kategori'            => $b->barang?->kategori?->nama ?? 'Umum',
            'satuan'              => $b->barang?->satuan?->simbol ?? $b->barang?->satuan?->nama ?? 'Pcs',
            'gudang'              => $b->gudang?->nama ?? 'Gudang Sentral',
            'lokasi_rak'          => $b->lokasiGudang ? "{$b->lokasiGudang->nama} ({$b->lokasiGudang->kode})" : 'Tanpa Rak',
            'tanggal_kedaluwarsa' => $ed?->format('Y-m-d'),
            'sisa_hari'           => $sisaHari,
            'jumlah'              => (int) $b->jumlah,
            'harga_satuan'        => (float) ($b->harga_satuan ?? 0),
            'nilai_total'         => $nilaiTotal,
            'status_fefo'         => $statusFefo,
        ];
    }

    /**
     * 2. Buku Mutasi Kartu Stok
     * GET /v1/gudang/mutasi/buku
     */
    public function bukuMutasi(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGlobal = $user && $user->hasRole(['super_admin', 'admin_logistik']);
        $canView = $isGlobal || ($user && ($user->can('view_any_gudang_stok::buku::mutasi') || $user->can('view_gudang_stok::buku::mutasi')));

        $hasWarehouseAccess = $canView && ($isGlobal || ($user && $user->gudang_id));
        if (! $hasWarehouseAccess) {
            return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat buku mutasi gudang.', 403);
        }

        $jenis = $request->query('jenis'); // MASUK, KELUAR, PENYESUAIAN, TRANSFER
        $gudangId = $isGlobal ? $request->query('gudang_id') : $user->gudang_id;
        $barangId = $request->query('barang_id');
        $tanggalMulai = $request->query('tanggal_mulai');
        $tanggalSelesai = $request->query('tanggal_selesai');
        $cari = $request->query('cari');

        $query = PergerakanStok::with([
            'barang.satuan:id,nama,simbol',
            'stokBatch:id,nomor_batch,tanggal_kedaluwarsa',
            'gudang:id,kode,nama',
            'lokasiGudang:id,kode,nama',
            'pembuatRecord:id,nama',
        ])
        ->when($jenis, fn ($q) => $q->where('jenis', $jenis))
        ->when($gudangId, fn ($q) => $q->where('gudang_id', $gudangId))
        ->when($barangId, fn ($q) => $q->where('barang_id', $barangId))
        ->when($tanggalMulai, fn ($q) => $q->whereDate('dibuat_pada', '>=', $tanggalMulai))
        ->when($tanggalSelesai, fn ($q) => $q->whereDate('dibuat_pada', '<=', $tanggalSelesai))
        ->when($cari, function ($q) use ($cari) {
            $q->where(function ($sub) use ($cari) {
                $sub->where('keterangan', 'ilike', "%{$cari}%")
                    ->orWhere('referensi_jenis', 'ilike', "%{$cari}%")
                    ->orWhereHas('barang', fn ($b) => $b->where('nama', 'ilike', "%{$cari}%")->orWhere('kode', 'ilike', "%{$cari}%"))
                    ->orWhereHas('stokBatch', fn ($sb) => $sb->where('nomor_batch', 'ilike', "%{$cari}%"));
            });
        });

        $mutasi = $query->orderBy('id', 'desc')
            ->limit(100)
            ->get()
            ->map(function ($m) {
                return [
                    'id'               => $m->id,
                    'tanggal'          => $m->dibuat_pada?->format('Y-m-d H:i') ?? '-',
                    'jenis'            => $m->jenis,
                    'barang_id'        => $m->barang_id,
                    'kode_barang'      => $m->barang?->kode ?? '-',
                    'nama_barang'      => $m->barang?->nama ?? 'Komoditas',
                    'satuan'           => $m->barang?->satuan?->simbol ?? 'Pcs',
                    'nomor_batch'      => $m->stokBatch?->nomor_batch ?? '-',
                    'gudang'           => $m->gudang?->nama ?? 'Gudang',
                    'lokasi_rak'       => $m->lokasiGudang?->nama ?? 'Tanpa Rak',
                    'jumlah'           => (int) $m->jumlah,
                    'saldo_sebelum'    => (int) $m->saldo_sebelum,
                    'saldo_sesudah'    => (int) $m->saldo_sesudah,
                    'referensi_jenis'  => $m->referensi_jenis,
                    'referensi_id'     => $m->referensi_id,
                    'keterangan'       => $m->keterangan ?? '-',
                    'petugas'          => $m->pembuatRecord?->nama ?? 'Sistem',
                ];
            });

        return $this->jsonSuccess($mutasi, 'Buku mutasi stok berhasil dimuat.');
    }
}
