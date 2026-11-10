<?php

namespace App\Http\Controllers\Api\V1\Persediaan;

use App\Enums\Persediaan\StatusPenyesuaianStok;
use App\Enums\Persediaan\StatusStokOpname;
use App\Http\Controllers\Api\ApiController;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Persediaan\PenyesuaianStok;
use App\Models\Persediaan\PenyesuaianStokDetail;
use App\Models\Persediaan\PergerakanStok;
use App\Models\Persediaan\StokBatch;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersediaanPenyesuaianApiController extends ApiController
{
    /**
     * 6. Daftar Otorisasi Penyesuaian Stok (Variance Authorization)
     * GET /v1/gudang/penyesuaian
     */
    public function daftarPenyesuaian(Request $request): JsonResponse
    {
        $user = $request->user();
        $canView = $user && (
            $user->can('view_any_gudang_stok::otorisasi::penyesuaian') ||
            $user->can('view_gudang_stok::otorisasi::penyesuaian') ||
            $user->hasRole(['super_admin', 'admin_logistik'])
        );

        if (! $canView) {
            return $this->jsonError('Anda tidak memiliki wewenang melihat daftar otorisasi penyesuaian stok.', 403);
        }
        $status = $request->query('status', StatusPenyesuaianStok::Menunggu->value);
        $isGlobal = $user && $user->hasRole(['super_admin', 'admin_logistik']);
        $gudangId = $isGlobal ? $request->query('gudang_id') : $user->gudang_id;

        if (! $isGlobal && ! $gudangId) {
            return $this->jsonError('Anda tidak memiliki wewenang melihat daftar otorisasi penyesuaian stok.', 403);
        }

        $query = PenyesuaianStok::with([
            'stokOpname:id,kode,tanggal_opname',
            'gudang:id,kode,nama',
            'pengaju:id,nama',
            'penyetuju:id,nama',
            'detail.barang.satuan',
            'detail.stokBatch.lokasiGudang',
        ])
        ->when($status && $status !== 'SEMUA', fn ($q) => $q->where('status', $status))
        ->when($gudangId, fn ($q) => $q->where('gudang_id', $gudangId));
        $items = $query->orderBy('id', 'desc')->limit(50)->get()->map(function ($adj) {
            $totalNilai = (float) $adj->detail->sum('subtotal_nilai');

            return [
                'id'                  => $adj->id,
                'kode'                => $adj->kode,
                'kode_opname'         => $adj->stokOpname?->kode ?? '-',
                'gudang_id'           => $adj->gudang_id,
                'gudang_nama'         => $adj->gudang?->nama ?? 'Gudang Sentral',
                'tanggal_penyesuaian' => $adj->tanggal_penyesuaian?->format('Y-m-d') ?? '-',
                'alasan'              => $adj->alasan ?? 'Selisih opname fisik',
                'status'              => $adj->status,
                'total_item'          => $adj->detail->count(),
                'total_nilai_selisih' => $totalNilai,
                'pengaju'             => $adj->pengaju?->nama ?? 'Petugas Gudang',
                'penyetuju'           => $adj->penyetuju?->nama ?? '-',
                'items'               => $adj->detail->map(fn ($d) => [
                    'id'                 => $d->id,
                    'barang_id'          => $d->barang_id,
                    'kode_barang'        => $d->barang?->kode ?? '-',
                    'nama_barang'        => $d->barang?->nama ?? 'Barang',
                    'satuan'             => $d->barang?->satuan?->simbol ?? 'Pcs',
                    'nomor_batch'        => $d->stokBatch?->nomor_batch ?? '-',
                    'lokasi_rak'         => $d->stokBatch?->lokasiGudang?->nama ?? 'Tanpa Rak',
                    'jenis_penyesuaian'  => $d->jenis_penyesuaian, // TAMBAH / KURANG
                    'jumlah_penyesuaian' => (int) $d->jumlah_penyesuaian,
                    'harga_satuan'       => (float) $d->harga_satuan,
                    'subtotal_nilai'     => (float) $d->subtotal_nilai,
                    'catatan'            => $d->catatan ?? '-',
                ]),
            ];
        });

        return $this->jsonSuccess($items, 'Daftar dokumen penyesuaian stok berhasil dimuat.');
    }

    /**
     * 7. Sahkan Otorisasi Penyesuaian Stok (Approve)
     * POST /v1/gudang/penyesuaian/{id}/approve
     */
    public function approvePenyesuaian(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $adj = PenyesuaianStok::with(['stokOpname', 'detail.stokBatch', 'detail.barang'])->find($id);

        if (! $adj) {
            return $this->jsonError('Dokumen penyesuaian stok tidak ditemukan.', 404);
        }
        $canAuthorize = $user && (
            $user->can('update_gudang_stok::otorisasi::penyesuaian') ||
            $user->hasRole(['super_admin', 'admin_logistik'])
        );

        if (! $canAuthorize) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk mengesahkan penyesuaian stok.', 403);
        }

        if (! $user->hasRole(['super_admin', 'admin_logistik']) && (! $user->gudang_id || (int) $user->gudang_id !== (int) $adj->gudang_id)) {
            return $this->jsonError('Anda tidak memiliki wewenang mengesahkan penyesuaian stok untuk fasilitas gudang ini.', 403);
        }

        if ($adj->status !== StatusPenyesuaianStok::Menunggu->value) {
            return $this->jsonError("Dokumen telah diproses sebelumnya dengan status {$adj->status}.", 422);
        }

        try {
            DB::transaction(function () use ($adj, $user) {
                $lockedAdj = PenyesuaianStok::with(['stokOpname', 'detail.stokBatch', 'detail.barang'])
                    ->lockForUpdate()
                    ->findOrFail($adj->id);

                if (! StatusPenyesuaianStok::tryFrom((string) $lockedAdj->status)?->bolehKe(StatusPenyesuaianStok::Disetujui)) {
                    throw new Exception("Dokumen penyesuaian telah diproses sebelumnya dengan status {$lockedAdj->status}.");
                }

                foreach ($lockedAdj->detail as $detail) {
                    $this->terapkanDetailPenyesuaian($lockedAdj, $detail, $user);
                }

                // Sahkan status penyesuaian dan sesi opname induk
                $lockedAdj->update([
                    'status'         => StatusPenyesuaianStok::Disetujui->value,
                    'disetujui_oleh' => $user->id,
                ]);

                if ($lockedAdj->stokOpname) {
                    $lockedAdj->stokOpname->update([
                        'status'         => StatusStokOpname::Disetujui->value,
                        'disetujui_oleh' => $user->id,
                    ]);
                }
            });

            return $this->jsonSuccess(null, "Otorisasi penyesuaian stok {$adj->kode} berhasil disahkan. Saldo batch fisik telah disinkronkan.");
        } catch (Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    private function terapkanDetailPenyesuaian(PenyesuaianStok $adj, PenyesuaianStokDetail $detail, User $user): void
    {
        $batch = StokBatch::lockForUpdate()->findOrFail($detail->stok_batch_id);
        $qty = (int) $detail->jumlah_penyesuaian;
        $saldoSebelum = $batch->jumlah;

        if ($detail->jenis_penyesuaian === 'KURANG') {
            if ($saldoSebelum < $qty) {
                throw new Exception("Saldo stok batch '{$batch->nomor_batch}' ({$detail->barang?->nama}) saat ini tidak mencukupi untuk dikurangi {$qty} unit (Saldo riil saat ini: {$saldoSebelum}).");
            }
            $saldoSesudah = $saldoSebelum - $qty;
            $batch->update([
                'jumlah' => $saldoSesudah,
                'status' => $saldoSesudah == 0 ? 'HABIS' : 'AKTIF',
            ]);
        } else {
            // TAMBAH
            $saldoSesudah = $saldoSebelum + $qty;
            $batch->update([
                'jumlah' => $saldoSesudah,
                'status' => 'AKTIF',
            ]);
        }

        // Catat mutasi kartu stok jenis PENYESUAIAN
        PergerakanStok::create([
            'barang_id'        => $detail->barang_id,
            'stok_batch_id'    => $batch->id,
            'gudang_id'        => $batch->gudang_id,
            'lokasi_gudang_id' => $batch->lokasi_gudang_id,
            'jenis'            => 'PENYESUAIAN',
            'jumlah'           => $qty,
            'saldo_sebelum'    => $saldoSebelum,
            'saldo_sesudah'    => $saldoSesudah,
            'referensi_jenis'  => 'STOCK_OPNAME',
            'referensi_id'     => $adj->id,
            'keterangan'       => "Otorisasi penyesuaian opname: {$adj->kode} ({$detail->catatan})",
            'dibuat_oleh'      => $user->id,
        ]);
    }

    /**
     * 8. Tolak Otorisasi Penyesuaian Stok (Reject)
     * POST /v1/gudang/penyesuaian/{id}/reject
     */
    public function rejectPenyesuaian(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $adj = PenyesuaianStok::with('stokOpname')->find($id);

        if (! $adj) {
            return $this->jsonError('Dokumen penyesuaian stok tidak ditemukan.', 404);
        }
        $canAuthorize = $user && (
            $user->can('update_gudang_stok::otorisasi::penyesuaian') ||
            $user->hasRole(['super_admin', 'admin_logistik'])
        );

        if (! $canAuthorize) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk menolak otorisasi penyesuaian stok.', 403);
        }

        if (! $user->hasRole(['super_admin', 'admin_logistik']) && (! $user->gudang_id || (int) $user->gudang_id !== (int) $adj->gudang_id)) {
            return $this->jsonError('Anda tidak memiliki wewenang menolak penyesuaian stok untuk fasilitas gudang ini.', 403);
        }

        if ($adj->status !== StatusPenyesuaianStok::Menunggu->value) {
            return $this->jsonError("Dokumen telah diproses sebelumnya dengan status {$adj->status}.", 422);
        }

        $alasan = $request->input('alasan', 'Penyesuaian stok ditolak oleh Kepala Gudang.');

        DB::transaction(function () use ($adj, $user, $alasan) {
            $lockedAdj = PenyesuaianStok::with('stokOpname')->lockForUpdate()->findOrFail($adj->id);

            if (! StatusPenyesuaianStok::tryFrom((string) $lockedAdj->status)?->bolehKe(StatusPenyesuaianStok::Ditolak)) {
                throw new Exception("Dokumen penyesuaian telah diproses sebelumnya dengan status {$lockedAdj->status}.");
            }

            $lockedAdj->update([
                'status'         => StatusPenyesuaianStok::Ditolak->value,
                'disetujui_oleh' => $user->id,
                'alasan'         => $lockedAdj->alasan . " [DITOLAK: {$alasan}]",
            ]);

            if ($lockedAdj->stokOpname) {
                $lockedAdj->stokOpname->update([
                    'status' => StatusStokOpname::Ditolak->value,
                ]);
            }
        });

        return $this->jsonSuccess(null, "Penyesuaian stok {$adj->kode} telah ditolak.");
    }
}
