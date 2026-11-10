<?php

namespace App\Support\Generator;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CodeGenerator
{
    /**
     * Menerbitkan nomor kode dokumen transaksi resmi secara terpusat.
     * Format: {PREFIX}-{YYYY}-{XXX} atau {PREFIX}-{YYYYMM}-{XXX}
     */
    public static function generate(
        string $prefix,
        string $modelClass,
        string $column = 'kode',
        string $dateFormat = 'Y',
        int $pad = 3
    ): string {
        $now = Carbon::now();
        $datePart = $now->format($dateFormat);
        $searchPrefix = "{$prefix}-{$datePart}-";

        // Serialisasi per periode agar dua transaksi tidak membaca nomor terakhir yang sama.
        // ponytail: kunci hanya efektif di dalam transaksi (lepas saat commit); di luar transaksi pakai sequence DB.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$searchPrefix]);
        }

        // Urut panjang dulu: sebagai string 'X-999' > 'X-1000', jadi urutan leksikografis salah saat nomor melewati pad.
        $lastRecord = $modelClass::query()
            ->where($column, 'like', "{$searchPrefix}%")
            ->whereRaw("{$column} ~ ?", ["^{$searchPrefix}[0-9]+$"])
            ->orderByRaw("length({$column}) desc")
            ->orderBy($column, 'desc')
            ->value($column);

        $nextNumber = 1;

        if ($lastRecord) {
            $parts = explode('-', $lastRecord);
            $lastSeq = end($parts);
            if (is_numeric($lastSeq)) {
                $nextNumber = (int) $lastSeq + 1;
            }
        }

        $paddedNumber = str_pad((string) $nextNumber, $pad, '0', STR_PAD_LEFT);

        return "{$searchPrefix}{$paddedNumber}";
    }

    protected static array $presets = [
        'sppb' => ['SPPB', 'App\Models\Distribusi\PengirimanBarang', 'kode', 'Ymd', 4],
        'pr'   => ['PR', 'App\Models\Pengadaan\PermintaanPengadaan', 'kode', 'Y', 3],
        'po'   => ['PO', 'App\Models\Pengadaan\PesananPembelian', 'kode', 'Y', 3],
        'rcv'  => ['RCV', 'App\Models\Distribusi\PenerimaanDistribusi', 'kode', 'Ym', 3],
        'pinj' => ['PINJ', 'App\Models\Aset\PermintaanPeminjaman', 'kode', 'Y', 3],
        'mnt'  => ['MNT', 'App\Models\Ipsrs\PermintaanMaintenance', 'kode', 'Ym', 3],
        'spk'  => ['SPK', 'App\Models\Ipsrs\PenugasanMaintenance', 'kode', 'Ym', 3],
        'prv'  => ['PRV', 'App\Models\Ipsrs\JadwalMaintenance', 'kode', 'Ym', 3],
        'opn'  => ['OPN', 'App\Models\Persediaan\StokOpname', 'kode', 'Ym', 3],
        'adj'  => ['ADJ', 'App\Models\Persediaan\PenyesuaianStok', 'kode', 'Ym', 3],
        'aud'  => ['AUD', 'App\Models\Audit\TemuanAudit', 'kode', 'Ym', 3],
    ];

    public static function __callStatic(string $name, array $args)
    {
        if (str_starts_with($name, 'generate')) {
            $key = strtolower(substr($name, 8));
            if (isset(static::$presets[$key])) {
                [$prefix, $model, $col, $date, $pad] = static::$presets[$key];
                return static::generate($prefix, $args[0] ?? $model, $args[1] ?? $col, $date, $pad);
            }
        }
        throw new \BadMethodCallException("Method {$name} tidak ditemukan.");
    }
}
