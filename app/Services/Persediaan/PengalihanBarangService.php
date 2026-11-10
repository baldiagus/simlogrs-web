<?php

namespace App\Services\Persediaan;

use App\Enums\Aset\StatusPengalihan;
use App\Models\Persediaan\PengalihanBarang;
use App\Models\Persediaan\PengalihanBarangDetail;
use App\Models\Persediaan\PergerakanStok;
use App\Models\Persediaan\StokBatch;
use App\Models\Distribusi\StokRuangan;
use App\Support\Generator\CodeGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PengalihanBarangService
{
    /**
     * Mengajukan pengalihan/mutasi barang BHP.
     */
    public function ajukanPengalihan(array $data): PengalihanBarang
    {
        $data = $this->validasiAjukan($data);

        return DB::transaction(function () use ($data) {
            $transfer = $this->buatHeaderPengalihan($data);

            foreach ($data['items'] as $item) {
                $jumlah = (int) ($item['jumlah'] ?? 0);
                if ($jumlah <= 0) {
                    throw new InvalidArgumentException('Jumlah barang yang dialihkan harus lebih dari 0.');
                }

                $batchId = $item['stok_batch_id'] ?? null;
                $this->kurangiStokAsal($data, $item['barang_id'], $batchId, $jumlah);

                PengalihanBarangDetail::create([
                    'pengalihan_barang_id' => $transfer->id,
                    'barang_id' => $item['barang_id'],
                    'stok_batch_id' => $batchId,
                    'jumlah' => $jumlah,
                    'catatan' => $item['catatan'] ?? null,
                ]);
            }

            return $transfer->load(['detail.barang', 'gudangAsal', 'unitAsal', 'gudangTujuan', 'unitTujuan']);
        });
    }

    /** Validasi input pengajuan; mengembalikan $data dengan default tipe_asal/tipe_tujuan terisi. */
    private function validasiAjukan(array $data): array
    {
        if (empty($data['items'] ?? [])) {
            throw new InvalidArgumentException('Minimal harus memilih 1 komoditas barang untuk dialihkan.');
        }

        $data['tipe_asal'] = $tipeAsal = $data['tipe_asal'] ?? 'GUDANG';
        $data['tipe_tujuan'] = $tipeTujuan = $data['tipe_tujuan'] ?? 'GUDANG';
        $gudangAsalId = $data['gudang_asal_id'] ?? null;
        $unitAsalId = $data['unit_asal_id'] ?? null;
        $gudangTujuanId = $data['gudang_tujuan_id'] ?? null;
        $unitTujuanId = $data['unit_tujuan_id'] ?? null;

        if (! in_array($tipeAsal, ['RUANGAN', 'GUDANG'], true) || ! in_array($tipeTujuan, ['RUANGAN', 'GUDANG'], true)) {
            throw new InvalidArgumentException('Tipe asal dan tipe tujuan pengalihan harus RUANGAN atau GUDANG.');
        }
        if (($tipeAsal === 'GUDANG' ? ! $gudangAsalId : ! $unitAsalId) || ($tipeTujuan === 'GUDANG' ? ! $gudangTujuanId : ! $unitTujuanId)) {
            throw new InvalidArgumentException('Fasilitas asal dan tujuan pengalihan wajib dipilih sesuai tipenya.');
        }

        // Anti self-transfer guard (cast int: ID dari request bisa berupa string)
        if ($tipeAsal === 'GUDANG' && $tipeTujuan === 'GUDANG' && (int) $gudangAsalId === (int) $gudangTujuanId) {
            throw new InvalidArgumentException('Gudang asal dan tujuan pengalihan tidak boleh sama.');
        }
        if ($tipeAsal === 'RUANGAN' && $tipeTujuan === 'RUANGAN' && (int) $unitAsalId === (int) $unitTujuanId) {
            throw new InvalidArgumentException('Unit asal dan tujuan pengalihan tidak boleh sama.');
        }

        return $data;
    }

    private function buatHeaderPengalihan(array $data): PengalihanBarang
    {
        $tipeAsal = $data['tipe_asal'];
        $tipeTujuan = $data['tipe_tujuan'];

        return PengalihanBarang::create([
            'kode' => CodeGenerator::generate('TRF-BRG', PengalihanBarang::class, 'kode', 'Ym', 3),
            'tipe_asal' => $tipeAsal,
            'gudang_asal_id' => $tipeAsal === 'GUDANG' ? ($data['gudang_asal_id'] ?? null) : null,
            'unit_asal_id' => $tipeAsal === 'RUANGAN' ? ($data['unit_asal_id'] ?? null) : null,
            'tipe_tujuan' => $tipeTujuan,
            'gudang_tujuan_id' => $tipeTujuan === 'GUDANG' ? ($data['gudang_tujuan_id'] ?? null) : null,
            'unit_tujuan_id' => $tipeTujuan === 'RUANGAN' ? ($data['unit_tujuan_id'] ?? null) : null,
            'lokasi_gudang_tujuan_id' => $tipeTujuan === 'GUDANG' ? ($data['lokasi_gudang_tujuan_id'] ?? null) : null,
            'tanggal_pengalihan' => $data['tanggal_pengalihan'] ?? now()->toDateString(),
            'alasan' => $data['alasan'] ?? '-',
            'status' => StatusPengalihan::Diajukan->value,
            'diajukan_oleh' => $data['diajukan_oleh'],
            'catatan' => $data['catatan'] ?? null,
        ]);
    }

    /** Kunci dan potong stok asal (batch gudang atau stok ruangan). */
    private function kurangiStokAsal(array $data, $barangId, $batchId, int $jumlah): void
    {
        if ($data['tipe_asal'] === 'GUDANG') {
            if (! $batchId) {
                throw new InvalidArgumentException('Batch stok asal wajib dipilih untuk pengalihan dari gudang.');
            }
            $batch = StokBatch::lockForUpdate()->find($batchId);
            if (! $batch
                || (int) $batch->gudang_id !== (int) ($data['gudang_asal_id'] ?? null)
                || (int) $batch->barang_id !== (int) $barangId
                || $batch->status !== 'AKTIF') {
                throw new InvalidArgumentException('Batch stok tidak valid: bukan milik gudang asal, bukan barang yang dipilih, atau tidak aktif.');
            }
            if ($batch->jumlah < $jumlah) {
                throw new InvalidArgumentException("Stok batch {$batch->nomor_batch} tidak mencukupi untuk dialihkan.");
            }
            // Kunci stok batch dengan memotong langsung
            $batch->decrement('jumlah', $jumlah);
            if ($batch->jumlah <= 0) {
                $batch->update(['status' => 'HABIS']);
            }

            return;
        }

        $stokRuangan = StokRuangan::lockForUpdate()
            ->where('unit_id', $data['unit_asal_id'] ?? null)
            ->where('barang_id', $barangId)
            ->first();
        if (! $stokRuangan || $stokRuangan->jumlah < $jumlah) {
            throw new InvalidArgumentException('Stok persediaan ruangan asal tidak mencukupi.');
        }
        $stokRuangan->decrement('jumlah', $jumlah);
    }

    /**
     * Menyetujui pengalihan barang.
     */
    public function setujuiPengalihan(int $pengalihanId, int $userId, ?string $catatan = null): PengalihanBarang
    {
        return DB::transaction(function () use ($pengalihanId, $userId, $catatan) {
            $transfer = PengalihanBarang::lockForUpdate()->findOrFail($pengalihanId);

            if ($transfer->status !== StatusPengalihan::Diajukan->value) {
                throw new InvalidArgumentException("Dokumen {$transfer->kode} tidak dalam status DIAJUKAN.");
            }

            if ((int) $transfer->diajukan_oleh === (int) $userId) {
                throw new InvalidArgumentException('Konflik Kepentingan: Pemohon dilarang menyetujui permohonan pengalihannya sendiri.');
            }

            $transfer->update([
                'status' => StatusPengalihan::Disetujui->value,
                'disetujui_oleh' => $userId,
                'catatan' => $catatan ?: $transfer->catatan,
            ]);

            return $transfer;
        });
    }

    /**
     * Menolak pengalihan dan mengembalikan stok ke lokasi asal.
     */
    public function tolakPengalihan(int $pengalihanId, int $userId, ?string $alasan = null): PengalihanBarang
    {
        return DB::transaction(function () use ($pengalihanId, $userId, $alasan) {
            $transfer = PengalihanBarang::with('detail')->lockForUpdate()->findOrFail($pengalihanId);

            if (! in_array($transfer->status, StatusPengalihan::aktif(), true)) {
                throw new InvalidArgumentException("Dokumen {$transfer->kode} tidak dapat ditolak.");
            }

            if ((int) $transfer->diajukan_oleh === (int) $userId) {
                throw new InvalidArgumentException('Konflik Kepentingan: Pemohon tidak berwenang menolak permohonan pengalihan; gunakan pembatalan pengajuan.');
            }

            // Kembalikan stok ke lokasi asal
            foreach ($transfer->detail as $item) {
                if ($transfer->tipe_asal === 'GUDANG' && $item->stok_batch_id) {
                    $batch = StokBatch::lockForUpdate()->find($item->stok_batch_id);
                    if ($batch) {
                        $batch->increment('jumlah', $item->jumlah);
                        if ($batch->status === 'HABIS' && $batch->jumlah > 0) {
                            $batch->update(['status' => 'AKTIF']);
                        }
                    }
                } elseif ($transfer->tipe_asal === 'RUANGAN') {
                    $stok = StokRuangan::firstOrCreate(
                        ['unit_id' => $transfer->unit_asal_id, 'barang_id' => $item->barang_id],
                        ['jumlah' => 0]
                    );
                    $stok->increment('jumlah', $item->jumlah);
                }
            }

            $transfer->update([
                'status' => StatusPengalihan::Ditolak->value,
                'disetujui_oleh' => $userId,
                'catatan' => $alasan ?: $transfer->catatan,
            ]);

            return $transfer;
        });
    }

    /**
     * Membatalkan pengalihan dan mengembalikan stok ke lokasi asal.
     */
    public function batalkanPengalihan(int $pengalihanId, int $userId, ?string $alasan = null): PengalihanBarang
    {
        return DB::transaction(function () use ($pengalihanId, $userId, $alasan) {
            $transfer = PengalihanBarang::with('detail')->lockForUpdate()->findOrFail($pengalihanId);

            if ($transfer->status !== StatusPengalihan::Diajukan->value) {
                throw new InvalidArgumentException("Hanya pengalihan berstatus DIAJUKAN yang dapat dibatalkan.");
            }

            // Kembalikan stok
            foreach ($transfer->detail as $item) {
                if ($transfer->tipe_asal === 'GUDANG' && $item->stok_batch_id) {
                    $batch = StokBatch::lockForUpdate()->find($item->stok_batch_id);
                    if ($batch) {
                        $batch->increment('jumlah', $item->jumlah);
                        if ($batch->status === 'HABIS' && $batch->jumlah > 0) {
                            $batch->update(['status' => 'AKTIF']);
                        }
                    }
                } elseif ($transfer->tipe_asal === 'RUANGAN') {
                    $stok = StokRuangan::firstOrCreate(
                        ['unit_id' => $transfer->unit_asal_id, 'barang_id' => $item->barang_id],
                        ['jumlah' => 0]
                    );
                    $stok->increment('jumlah', $item->jumlah);
                }
            }

            $transfer->update([
                'status' => StatusPengalihan::Dibatalkan->value,
                'catatan' => $alasan ?: $transfer->catatan,
            ]);

            return $transfer;
        });
    }

    /**
     * Konfirmasi serah terima fisik pengalihan barang.
     * Mengalirkan stok ke inventaris lokasi tujuan dan mencatat riwayat kartu stok.
     */
    public function eksekusiSerahTerima(int $pengalihanId, int $userId): PengalihanBarang
    {
        return DB::transaction(function () use ($pengalihanId, $userId) {
            $transfer = PengalihanBarang::with(['detail.stokBatch', 'gudangAsal', 'unitAsal', 'gudangTujuan', 'unitTujuan'])
                ->lockForUpdate()
                ->findOrFail($pengalihanId);

            if ($transfer->status !== StatusPengalihan::Disetujui->value) {
                throw new InvalidArgumentException("Dokumen {$transfer->kode} belum disetujui atau sudah selesai diproses.");
            }

            foreach ($transfer->detail as $item) {
                $batchAsal = $item->stokBatch;

                // 1. Catat mutasi KELUAR pada kartu pergerakan stok jika asal adalah gudang
                if ($transfer->tipe_asal === 'GUDANG' && $batchAsal) {
                    $this->catatMutasiKeluar($transfer, $item, $batchAsal, $userId);
                }

                // 2. Alirkan stok ke tujuan
                if ($transfer->tipe_tujuan === 'GUDANG') {
                    $this->masukkanStokKeGudang($transfer, $item, $batchAsal, $userId);
                } else {
                    $this->masukkanStokKeRuangan($transfer, $item);
                }
            }

            $transfer->update([
                'status' => StatusPengalihan::Selesai->value,
                'diterima_oleh' => $userId,
                'tanggal_serah_terima' => now(),
            ]);

            return $transfer->load(['detail.barang', 'gudangAsal', 'unitAsal', 'gudangTujuan', 'unitTujuan', 'penerima', 'penyetuju']);
        });
    }

    private function catatMutasiKeluar(PengalihanBarang $transfer, PengalihanBarangDetail $item, StokBatch $batchAsal, int $userId): void
    {
        PergerakanStok::create([
            'barang_id' => $item->barang_id,
            'stok_batch_id' => $batchAsal->id,
            'gudang_id' => $transfer->gudang_asal_id,
            'lokasi_gudang_id' => $batchAsal->lokasi_gudang_id,
            'jenis' => 'TRANSFER',
            'jumlah' => $item->jumlah,
            'saldo_sebelum' => $batchAsal->jumlah + $item->jumlah,
            'saldo_sesudah' => $batchAsal->jumlah,
            'referensi_jenis' => 'PENGALIHAN_BARANG',
            'referensi_id' => $transfer->id,
            'keterangan' => "Mutasi pengalihan barang {$transfer->kode} ke " . $transfer->nama_lokasi_tujuan,
            'dibuat_oleh' => $userId,
            'dibuat_pada' => now(),
        ]);
    }

    /** Masuk ke batch gudang tujuan + catat mutasi MASUK kartu stok. */
    private function masukkanStokKeGudang(PengalihanBarang $transfer, PengalihanBarangDetail $item, ?StokBatch $batchAsal, int $userId): void
    {
        $batchTujuan = StokBatch::firstOrCreate(
            [
                'barang_id' => $item->barang_id,
                'gudang_id' => $transfer->gudang_tujuan_id,
                'nomor_batch' => $batchAsal?->nomor_batch ?: 'BATCH-MUTASI-' . date('Ymd'),
            ],
            [
                'lokasi_gudang_id' => $transfer->lokasi_gudang_tujuan_id,
                'tanggal_kedaluwarsa' => $batchAsal?->tanggal_kedaluwarsa,
                'jumlah' => 0,
                'status' => 'AKTIF',
            ]
        );

        $saldoSebelum = $batchTujuan->jumlah;
        $batchTujuan->increment('jumlah', $item->jumlah);
        if ($batchTujuan->status === 'HABIS' && $batchTujuan->jumlah > 0) {
            $batchTujuan->update(['status' => 'AKTIF']);
        }

        PergerakanStok::create([
            'barang_id' => $item->barang_id,
            'stok_batch_id' => $batchTujuan->id,
            'gudang_id' => $transfer->gudang_tujuan_id,
            'lokasi_gudang_id' => $transfer->lokasi_gudang_tujuan_id,
            'jenis' => 'MASUK',
            'jumlah' => $item->jumlah,
            'saldo_sebelum' => $saldoSebelum,
            'saldo_sesudah' => $saldoSebelum + $item->jumlah,
            'referensi_jenis' => 'PENGALIHAN_BARANG',
            'referensi_id' => $transfer->id,
            'keterangan' => "Penerimaan mutasi barang {$transfer->kode} dari " . $transfer->nama_lokasi_asal,
            'dibuat_oleh' => $userId,
            'dibuat_pada' => now(),
        ]);
    }

    /** Masuk ke Persediaan Lokal Ruangan tujuan. */
    private function masukkanStokKeRuangan(PengalihanBarang $transfer, PengalihanBarangDetail $item): void
    {
        $stokRuangan = StokRuangan::firstOrCreate(
            [
                'unit_id' => $transfer->unit_tujuan_id,
                'barang_id' => $item->barang_id,
            ],
            [
                'jumlah' => 0,
            ]
        );
        $stokRuangan->increment('jumlah', $item->jumlah);
    }
}
