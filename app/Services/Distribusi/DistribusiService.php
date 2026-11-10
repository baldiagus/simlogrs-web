<?php

namespace App\Services\Distribusi;

use App\Enums\Distribusi\StatusPermintaanDistribusi;
use App\Enums\Distribusi\StatusPengirimanBarang;
use App\Models\Distribusi\PengirimanBarang;
use App\Models\Distribusi\PengirimanBarangDetail;
use App\Models\Persediaan\PergerakanStok;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Persediaan\StokBatch;
use App\Models\User;
use App\Support\Generator\CodeGenerator;
use Exception;
use Illuminate\Support\Facades\DB;

class DistribusiService
{
    /**
     * Eksekusi pengeluaran barang berbasis FEFO (First Expired, First Out)
     * dengan pessimistic locking (lockForUpdate) dalam database transaction.
     *
     * @throws Exception
     */
    public function eksekusiPickingFefo(
        PermintaanDistribusi $permintaan,
        User $actor,
        ?int $gudangId = null
    ): PengirimanBarang {
        $gudangId = $gudangId ?: $permintaan->gudang_id;

        return DB::transaction(function () use ($permintaan, $actor, $gudangId) {
            // Kunci baris permintaan untuk mencegah eksekusi ganda / race condition
            $lockedPermintaan = PermintaanDistribusi::lockForUpdate()->with(['detail', 'unit'])->findOrFail($permintaan->id);

            // Validasi status permintaan dari database terkunci
            if (! in_array($lockedPermintaan->status, StatusPermintaanDistribusi::dapatDikirim())) {
                throw new Exception("Permintaan {$lockedPermintaan->kode} sudah diproses atau belum disetujui untuk pengeluaran barang.");
            }

            $alreadySentMap = $this->petaJumlahSudahDikirim($lockedPermintaan);

            // 1. Cek apakah masih ada sisa barang yang belum terpenuhi (terhutang)
            $sisaPerBarang = $this->hitungPetaSisaTerhutang($lockedPermintaan, $alreadySentMap);

            if (array_sum($sisaPerBarang) <= 0) {
                throw new Exception("Seluruh komoditas pada permintaan {$lockedPermintaan->kode} sudah terpenuhi 100%.");
            }

            // 2. Siapkan alokasi batch FEFO untuk SPPB baru
            $itemsToDispatch = $this->alokasiBatchFefo($lockedPermintaan, $sisaPerBarang, $gudangId);

            if (empty($itemsToDispatch)) {
                throw new Exception("Tidak ada saldo stok fisik tersedia di gudang saat ini. Sisa pesanan tetap terhutang dan akan dikirim saat restok.");
            }

            // 3. Buat Header SPPB (Surat Perintah Pengeluaran Barang)
            $kodeSppb = CodeGenerator::generate('SPPB', PengirimanBarang::class, 'kode', 'Ymd', 4);

            $sppb = PengirimanBarang::create([
                'kode'                     => $kodeSppb,
                'permintaan_distribusi_id' => $lockedPermintaan->id,
                'gudang_id'                => $gudangId,
                'unit_id'                  => $lockedPermintaan->unit_id,
                'tanggal_kirim'            => now()->toDateString(),
                'status'                   => StatusPengirimanBarang::Dikirim->value,
                'catatan'                  => $lockedPermintaan->catatan,
                'dikirim_oleh'             => $actor->id,
            ]);

            // 4. Potong saldo stok batch dan catat mutasi keluar
            foreach ($itemsToDispatch as $dispatch) {
                $this->potongBatchDanCatatMutasi($dispatch, $sppb, $lockedPermintaan, $gudangId, $actor);
            }

            // 5. Update status permintaan distribusi: lunas hanya bila setiap barang terpenuhi, bukan total agregat.
            // disetujui_oleh tidak ditimpa: tetap pejabat approval; picker tercatat di pengiriman_barang.dikirim_oleh.
            $newStatus = $lockedPermintaan->semuaBarangDikirim()
                ? StatusPermintaanDistribusi::SedangDikirim->value
                : StatusPermintaanDistribusi::DikirimSebagian->value;

            $lockedPermintaan->update(['status' => $newStatus]);
            $permintaan->status = $newStatus;

            return $sppb;
        });
    }

    /**
     * Total jumlah_dikirim per barang pada SPPB aktif (non-batal).
     */
    private function petaJumlahSudahDikirim(PermintaanDistribusi $lockedPermintaan): array
    {
        $existingShipments = PengirimanBarang::where('permintaan_distribusi_id', $lockedPermintaan->id)
            ->where('status', '!=', StatusPengirimanBarang::Batal->value)
            ->pluck('id');

        return PengirimanBarangDetail::whereIn('pengiriman_barang_id', $existingShipments)
            ->groupBy('barang_id')
            ->selectRaw('barang_id, sum(jumlah_dikirim) as total_sent')
            ->pluck('total_sent', 'barang_id')
            ->toArray();
    }

    /**
     * Hitung sisa jumlah terhutang per barang_id (kuota disetujui gabungan dikurangi yang sudah terkirim).
     */
    private function hitungPetaSisaTerhutang(PermintaanDistribusi $lockedPermintaan, array $alreadySentMap): array
    {
        $sisaMap = [];
        $kuotaMap = [];
        foreach ($lockedPermintaan->detail as $detail) {
            $qty = $detail->jumlah_disetujui !== null ? (int) $detail->jumlah_disetujui : (int) $detail->jumlah;
            $kuotaMap[$detail->barang_id] = ($kuotaMap[$detail->barang_id] ?? 0) + $qty;
        }
        foreach ($kuotaMap as $bId => $kuota) {
            $sent = (int) ($alreadySentMap[$bId] ?? 0);
            $sisaMap[$bId] = max(0, $kuota - $sent);
        }

        return $sisaMap;
    }

    /**
     * Alokasi batch FEFO (dengan lockForUpdate) untuk sisa terhutang tiap barang.
     */
    private function alokasiBatchFefo(PermintaanDistribusi $lockedPermintaan, array $sisaPerBarang, $gudangId): array
    {
        $itemsToDispatch = [];

        // Kelompokkan detail per barang_id dan urutkan agar urutan kunci StokBatch konsisten antar transaksi (cegah deadlock).
        foreach ($lockedPermintaan->detail->sortBy('barang_id')->groupBy('barang_id') as $barangId => $details) {
            $owed = (int) ($sisaPerBarang[$barangId] ?? 0);

            if ($owed <= 0) {
                continue;
            }

            $detail = $details->first();

            // Ambil stok batch aktif, urutkan berdasarkan expired date terdekat (FEFO)
            $batches = StokBatch::where('barang_id', $barangId)
                ->where('gudang_id', $gudangId)
                ->where('status', 'AKTIF')
                ->where('jumlah', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('tanggal_kedaluwarsa')
                        ->orWhere('tanggal_kedaluwarsa', '>=', now()->toDateString());
                })
                ->orderByRaw('tanggal_kedaluwarsa ASC NULLS LAST')
                ->lockForUpdate()
                ->get();

            $remainingToFulfill = $owed;

            foreach ($batches as $batch) {
                if ($remainingToFulfill <= 0) {
                    break;
                }

                $takeFromBatch = min($batch->jumlah, $remainingToFulfill);
                if ($takeFromBatch <= 0) {
                    continue;
                }

                $itemsToDispatch[] = [
                    'detail'        => $detail,
                    'batch'         => $batch,
                    'takeFromBatch' => $takeFromBatch,
                ];

                $remainingToFulfill -= $takeFromBatch;
            }
        }

        return $itemsToDispatch;
    }

    /**
     * Kurangi saldo batch, catat detail SPPB dan kartu pergerakan stok KELUAR.
     */
    private function potongBatchDanCatatMutasi(
        array $dispatch,
        PengirimanBarang $sppb,
        PermintaanDistribusi $lockedPermintaan,
        $gudangId,
        User $actor
    ): void {
        $batch = $dispatch['batch'];
        $takeFromBatch = $dispatch['takeFromBatch'];
        $detail = $dispatch['detail'];

        $saldoSebelum = $batch->jumlah;
        $saldoSesudah = $saldoSebelum - $takeFromBatch;

        $batch->decrement('jumlah', $takeFromBatch);
        if ($saldoSesudah === 0) {
            $batch->update(['status' => 'HABIS']);
        }

        // Catat detail SPPB
        PengirimanBarangDetail::create([
            'pengiriman_barang_id' => $sppb->id,
            'barang_id'            => $detail->barang_id,
            'stok_batch_id'        => $batch->id,
            'jumlah_dikirim'       => $takeFromBatch,
        ]);

        // Catat mutasi KELUAR pada kartu pergerakan stok
        PergerakanStok::create([
            'barang_id'        => $detail->barang_id,
            'stok_batch_id'    => $batch->id,
            'gudang_id'        => $gudangId,
            'lokasi_gudang_id' => $batch->lokasi_gudang_id,
            'jenis'            => 'KELUAR',
            'jumlah'           => $takeFromBatch,
            'saldo_sebelum'    => $saldoSebelum,
            'saldo_sesudah'    => $saldoSesudah,
            'referensi_jenis'  => 'PENGIRIMAN_DISTRIBUSI',
            'referensi_id'     => $sppb->id,
            'keterangan'       => "Pengeluaran FEFO SPPB {$sppb->kode} untuk {$lockedPermintaan->unit?->nama} (Batch {$batch->nomor_batch})",
            'dibuat_oleh'      => $actor->id,
        ]);
    }
}
