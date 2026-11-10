<?php

use App\Models\Ipsrs\JadwalMaintenance;
use App\Models\Master\Notifikasi;
use App\Models\Ipsrs\PenugasanMaintenance;
use App\Models\Ipsrs\PermintaanMaintenance;
use App\Models\Aset\PermintaanPeminjaman;
use App\Models\Persediaan\StokBatch;
use App\Models\User;
use App\Support\Generator\CodeGenerator;
use Illuminate\Support\Facades\Artisan;
use App\Services\Notifikasi\NotifikasiService;
use Illuminate\Support\Facades\Cache;
use App\Enums\Audit\StatusCapa;
use App\Models\Audit\TemuanAudit;
use App\Services\Notifikasi\PenerimaResolver;
use App\Models\Master\Barang;
use App\Models\Distribusi\StokRuangan;
use App\Models\Aset\Aset;

/*
|--------------------------------------------------------------------------
| Console Commands & Scheduled Tasks (SIMLOG RS Non-Medis v2 - Fase 9)
|--------------------------------------------------------------------------
*/

/**
 * 1. Sweep Expired Batches (Jadwal: Harian 00:01 WIB)
 * Mengunci status batch kedaluwarsa menjadi KEDALUWARSA dan menandai
 * batch yang mendekati kedaluwarsa (<= 90 hari) menjadi HAMPIR_KEDALUWARSA.
 */
Artisan::command('simlog:sweep-expired-batches', function () {
    $this->info('[' . now()->toDateTimeString() . '] Memulai sweeping stok batch kedaluwarsa...');

    $today = now()->toDateString();
    $in90Days = now()->addDays(90)->toDateString();

    // Batch yang telah melewati tanggal kedaluwarsa -> KEDALUWARSA
    $expiredCount = StokBatch::whereNotNull('tanggal_kedaluwarsa')
        ->where('tanggal_kedaluwarsa', '<=', $today)
        ->whereNotIn('status', ['KEDALUWARSA', 'HABIS'])
        ->update(['status' => 'KEDALUWARSA']);

    // Batch yang akan kedaluwarsa dalam 90 hari (pantauan FEFO aktif)
    $warningCount = StokBatch::whereNotNull('tanggal_kedaluwarsa')
        ->where('tanggal_kedaluwarsa', '>', $today)
        ->where('tanggal_kedaluwarsa', '<=', $in90Days)
        ->where('status', 'AKTIF')
        ->count();

    Cache::put('simlog:last_run:sweep_expired', [
        'waktu' => now()->toDateTimeString(),
        'status' => 'Sukses',
        'ringkasan' => "{$expiredCount} batch dikunci KEDALUWARSA, {$warningCount} batch dalam pantauan <90 hari",
        'expired_count' => $expiredCount,
        'warning_count' => $warningCount,
    ]);

    $this->info("Sweeping selesai: {$expiredCount} batch kedaluwarsa, {$warningCount} batch aktif dalam pantauan FEFO <90 hari.");
})->purpose('Mengunci status batch kedaluwarsa dan memantau batch <90 hari')->dailyAt('00:01');

/**
 * Helper bersama command peringatan peminjaman: kirim ke pengaju dan penyetuju (bila beda)
 * lewat NotifikasiService, dengan dedup harian per (pengguna, tipe, referensi_id).
 * Mengembalikan jumlah notifikasi yang benar-benar diterbitkan.
 */
$kirimPeringatanPinjaman = function (
    PermintaanPeminjaman $loan,
    string $tipe,
    string $judulPengaju,
    string $pesanPengaju,
    string $judulPenyetuju,
    string $pesanPenyetuju,
): int {
    $sudahTerkirim = fn (int $penggunaId): bool => Notifikasi::where('pengguna_id', $penggunaId)
        ->where('tipe', $tipe)
        ->where('referensi_id', (string) $loan->id)
        ->whereDate('dibuat_pada', now()->toDateString())
        ->exists();

    $service = app(NotifikasiService::class);
    $count = 0;

    if ($loan->diajukan_oleh && ! $sudahTerkirim($loan->diajukan_oleh)) {
        $count += $service->kirim([$loan->diajukan_oleh], $judulPengaju, $pesanPengaju, $tipe, (string) $loan->id, '/petugas-unit/peminjaman-aset')->count();
    }

    if ($loan->disetujui_oleh && $loan->disetujui_oleh !== $loan->diajukan_oleh && ! $sudahTerkirim($loan->disetujui_oleh)) {
        $count += $service->kirim([$loan->disetujui_oleh], $judulPenyetuju, $pesanPenyetuju, $tipe, (string) $loan->id, '/kepala-unit/approval-peminjaman')->count();
    }

    return $count;
};

/**
 * 2. Alert Overdue Loans (Jadwal: Harian 06:00 WIB)
 * Memindai peminjaman sarana aset yang melewati tanggal rencana pengembalian
 * dan menerbitkan notifikasi lonceng internal kepada peminjam & penanggung jawab.
 */
Artisan::command('simlog:alert-overdue-loans', function () use ($kirimPeringatanPinjaman) {
    $this->info('[' . now()->toDateTimeString() . '] Memeriksa peminjaman aset yang melewati jatuh tempo...');

    $overdueLoans = PermintaanPeminjaman::with(['pengaju', 'unitPeminjam', 'penyetuju'])
        ->whereIn('status', ['DISETUJUI', 'DIPINJAM'])
        ->where('tanggal_kembali_rencana', '<', now()->toDateString())
        ->get();

    $notifCount = 0;
    foreach ($overdueLoans as $loan) {
        $tgl = $loan->tanggal_kembali_rencana->format('d/m/Y');
        $notifCount += $kirimPeringatanPinjaman(
            $loan,
            'PINJAM_TERLAMBAT',
            "Peringatan Keterlambatan Pengembalian Aset ({$loan->kode})",
            "Peminjaman aset {$loan->kode} telah melewati batas rencana kembali ({$tgl}). Mohon segera lakukan pengembalian fisik ke unit penyedia.",
            "Pemberitahuan Peminjaman Melewati Jatuh Tempo ({$loan->kode})",
            "Peminjaman aset {$loan->kode} oleh unit {$loan->unitPeminjam?->nama} belum dikembalikan melewati batas {$tgl}.",
        );
    }

    Cache::put('simlog:last_run:alert_overdue', [
        'waktu' => now()->toDateTimeString(),
        'status' => 'Sukses',
        'ringkasan' => "{$overdueLoans->count()} peminjaman overdue terdeteksi, {$notifCount} notifikasi diterbitkan",
        'overdue_count' => $overdueLoans->count(),
        'notif_count' => $notifCount,
    ]);

    $this->info("Pemeriksaan selesai: {$overdueLoans->count()} overdue, {$notifCount} notifikasi terkirim.");
})->purpose('Mendeteksi peminjaman aset overdue dan mengirim notifikasi internal')->dailyAt('06:00');

/**
 * 2b. Alert Due-Soon Loans (Jadwal: Harian 07:00 WIB)
 * Peminjaman aset yang jatuh tempo besok (H-1) diingatkan ke peminjam & penyetuju.
 */
Artisan::command('simlog:alert-due-soon-loans', function () use ($kirimPeringatanPinjaman) {
    $this->info('[' . now()->toDateTimeString() . '] Memeriksa peminjaman aset yang jatuh tempo besok...');

    $dueSoonLoans = PermintaanPeminjaman::with(['pengaju', 'unitPeminjam', 'penyetuju'])
        ->whereIn('status', ['DISETUJUI', 'DIPINJAM'])
        ->whereDate('tanggal_kembali_rencana', now()->addDay()->toDateString())
        ->get();

    $notifCount = 0;
    foreach ($dueSoonLoans as $loan) {
        $tgl = $loan->tanggal_kembali_rencana->format('d/m/Y');
        $notifCount += $kirimPeringatanPinjaman(
            $loan,
            'PINJAM_JATUH_TEMPO',
            "Pengingat Pengembalian Aset Besok ({$loan->kode})",
            "Peminjaman aset {$loan->kode} jatuh tempo besok ({$tgl}). Mohon siapkan pengembalian fisik ke unit penyedia.",
            "Pemberitahuan Peminjaman Jatuh Tempo Besok ({$loan->kode})",
            "Peminjaman aset {$loan->kode} oleh unit {$loan->unitPeminjam?->nama} jatuh tempo besok ({$tgl}).",
        );
    }

    Cache::put('simlog:last_run:alert_due_soon', [
        'waktu' => now()->toDateTimeString(),
        'status' => 'Sukses',
        'ringkasan' => "{$dueSoonLoans->count()} peminjaman jatuh tempo besok, {$notifCount} notifikasi diterbitkan",
        'due_soon_count' => $dueSoonLoans->count(),
        'notif_count' => $notifCount,
    ]);

    $this->info("Pemeriksaan selesai: {$dueSoonLoans->count()} jatuh tempo besok, {$notifCount} notifikasi terkirim.");
})->purpose('Mengingatkan peminjaman aset yang jatuh tempo besok (H-1)')->dailyAt('07:00');

/**
 * 2c. Alert CAPA Jatuh Tempo (Jadwal: Harian 07:30 WIB)
 * Temuan audit berstatus In Progress yang due_date_capa-nya besok (H-1) atau sudah lewat diingatkan
 * ke kepala unit/gudang terperiksa. Dedup harian per (pengguna, tipe, referensi_id).
 */
Artisan::command('simlog:alert-capa-jatuh-tempo', function () {
    $this->info('[' . now()->toDateTimeString() . '] Memeriksa CAPA temuan audit yang jatuh tempo...');

    $besok = now()->addDay()->toDateString();
    $hari = now()->toDateString();
    $temuans = TemuanAudit::where('status_capa', StatusCapa::InProgress->value)
        ->whereDate('due_date_capa', '<=', $besok)
        ->get();

    $resolver = app(PenerimaResolver::class);
    $service = app(NotifikasiService::class);
    $notifCount = 0;

    foreach ($temuans as $t) {
        $penerima = array_values(array_unique([
            ...($t->unit_id ? $resolver->karuUnit((int) $t->unit_id) : []),
            ...($t->gudang_id ? $resolver->kepalaGudang((int) $t->gudang_id) : []),
        ]));

        $sudah = Notifikasi::where('tipe', 'CAPA_JATUH_TEMPO')
            ->where('referensi_id', (string) $t->id)
            ->whereDate('dibuat_pada', $hari)
            ->pluck('pengguna_id')
            ->all();
        $penerima = array_values(array_diff($penerima, $sudah));

        $tgl = $t->due_date_capa->format('d/m/Y');
        $terlambat = $t->due_date_capa->toDateString() < $hari;
        $judul = $terlambat ? "CAPA Terlambat ({$t->kode})" : "Pengingat CAPA Jatuh Tempo Besok ({$t->kode})";
        $pesan = $terlambat
            ? "Tindakan korektif temuan {$t->kode} pada {$t->objek_audit} telah melewati batas waktu ({$tgl}). Mohon segera kirim tindak lanjut."
            : "Tindakan korektif temuan {$t->kode} pada {$t->objek_audit} jatuh tempo besok ({$tgl}). Mohon siapkan tindak lanjut.";

        $notifCount += $service->kirim($penerima, $judul, $pesan, 'CAPA_JATUH_TEMPO', (string) $t->id, '/kepala-unit/temuan-capa')->count();
    }

    Cache::put('simlog:last_run:alert_capa_jatuh_tempo', [
        'waktu' => now()->toDateTimeString(),
        'status' => 'Sukses',
        'ringkasan' => "{$temuans->count()} CAPA jatuh tempo/terlambat, {$notifCount} notifikasi diterbitkan",
        'capa_count' => $temuans->count(),
        'notif_count' => $notifCount,
    ]);

    $this->info("Pemeriksaan selesai: {$temuans->count()} CAPA jatuh tempo/terlambat, {$notifCount} notifikasi terkirim.");
})->purpose('Mengingatkan auditee atas CAPA yang jatuh tempo besok (H-1) atau terlambat')->dailyAt('07:30');

/**
 * 2d. Alert Stok Minimum (Jadwal: Harian 06:30 WIB)
 * Memindai persediaan fisik komoditas aktif. Jika total saldo fisik di bawah batas minimum (stok_minimum),
 * otomatis menerbitkan notifikasi lonceng internal kepada staf gudang, admin logistik, dan pengadaan.
 */
Artisan::command('simlog:alert-stok-minimum', function () {
    $this->info('[' . now()->toDateTimeString() . '] Memeriksa stok komoditas di bawah batas minimum...');

    $barangs = Barang::with('satuan')
        ->where('status', 'AKTIF')
        ->where('stok_minimum', '>', 0)
        ->get();

    $notifService = app(NotifikasiService::class);
    $resolver = app(PenerimaResolver::class);
    $today = now()->toDateString();
    $lowStockCount = 0;
    $notifCount = 0;

    $penerimaIds = array_values(array_unique([
        ...$resolver->adminLogistik(),
        ...$resolver->semuaGudangStaf(),
        ...$resolver->role('pengadaan'),
    ]));

    foreach ($barangs as $b) {
        $stokGudang = (int) StokBatch::where('barang_id', $b->id)
            ->where('status', 'AKTIF')
            ->where('jumlah', '>', 0)
            ->sum('jumlah');

        $stokRuangan = (int) StokRuangan::where('barang_id', $b->id)->sum('jumlah');

        if ($b->tipe === 'ASET' && $stokGudang === 0) {
            $stokGudang = Aset::where('barang_id', $b->id)
                ->whereNotNull('gudang_id')
                ->where('status', '!=', 'DIHAPUSKAN')
                ->count();
            $stokRuangan = Aset::where('barang_id', $b->id)
                ->whereNotNull('unit_id')
                ->where('status', '!=', 'DIHAPUSKAN')
                ->count();
        }

        $totalStok = $stokGudang + $stokRuangan;

        if ($totalStok < $b->stok_minimum) {
            $lowStockCount++;
            $dedupKey = "simlog:notif_stok_min:{$b->id}:{$today}";

            if (! Cache::has($dedupKey) && ! empty($penerimaIds)) {
                $satuan = $b->satuan?->simbol ?? $b->satuan?->kode ?? 'Unit';
                $terbit = $notifService->kirim(
                    $penerimaIds,
                    'Stok di Bawah Minimum',
                    "Stok {$b->nama} ({$b->kode}) tersisa {$totalStok} {$satuan}, di bawah batas minimum ({$b->stok_minimum} {$satuan}). Segera lakukan pengadaan.",
                    'STOK_MINIMUM',
                    (string) $b->id,
                    '/pengadaan/permintaan-pengadaan'
                );
                $notifCount += $terbit->count();
                Cache::put($dedupKey, true, now()->addDay());
            }
        }
    }

    Cache::put('simlog:last_run:alert_stok_minimum', [
        'waktu' => now()->toDateTimeString(),
        'status' => 'Sukses',
        'ringkasan' => "{$lowStockCount} komoditas di bawah minimum terdeteksi, {$notifCount} notifikasi diterbitkan",
        'low_stock_count' => $lowStockCount,
        'notif_count' => $notifCount,
    ]);

    $this->info("Pemeriksaan selesai: {$lowStockCount} komoditas di bawah minimum terdeteksi, {$notifCount} notifikasi diterbitkan.");
})->purpose('Mendeteksi stok barang di bawah batas minimum dan menerbitkan notifikasi ke staf logistik & pengadaan')->dailyAt('06:30');

/**
 * 3. Generate Preventive Maintenance (Jadwal: Harian 01:00 WIB)
 * Memindai jadwal pemeliharaan preventif berkala yang jatuh tempo dan
 * otomatis menerbitkan tiket servis IPSRS serta memajukan tanggal berikutnya.
 */
Artisan::command('simlog:generate-preventive-maintenance', function () {
    $this->info('[' . now()->toDateTimeString() . '] Memindai jadwal maintenance preventif IPSRS...');

    $today = now()->toDateString();
    $jadwals = JadwalMaintenance::with(['aset.barang', 'teknisi'])
        ->where('status', 'AKTIF')
        ->where('tanggal_berikutnya', '<=', $today)
        ->whereHas('aset', fn ($q) => $q->whereIn('status', ['TERSEDIA', 'DIGUNAKAN']))
        ->get();

    $defaultUser = User::where('email', 'teknisi@simlogrs.local')->first() ?? User::first();
    $createdCount = 0;

    foreach ($jadwals as $jadwal) {
        $kodeMnt = CodeGenerator::generateMnt();
        $pelaporId = $jadwal->dibuat_oleh ?? $defaultUser?->id;

        $statusTiket = $jadwal->teknisi_id ? 'DITUGASKAN' : 'DILAPORKAN';

        $tiket = PermintaanMaintenance::create([
            'kode' => $kodeMnt,
            'aset_id' => $jadwal->aset_id,
            'unit_id' => $jadwal->aset?->unit_id,
            'dilaporkan_oleh' => $pelaporId,
            'deskripsi_masalah' => "Pemeliharaan Preventif Terjadwal: {$jadwal->jenis_maintenance} (ID Jadwal: {$jadwal->kode})",
            'prioritas' => 'SEDANG',
            'status' => $statusTiket,
            'dibuat_pada' => now(),
        ]);

        if ($jadwal->teknisi_id) {
            $penugasan = PenugasanMaintenance::create([
                'kode' => CodeGenerator::generateSpk(),
                'permintaan_maintenance_id' => $tiket->id,
                'teknisi_id' => $jadwal->teknisi_id,
                'ditugaskan_oleh' => $pelaporId,
                'tanggal_penugasan' => now()->toDateString(),
                'target_selesai' => now()->addDays(3)->toDateString(),
                'instruksi_khusus' => "Lakukan pemeliharaan preventif berkala sesuai SOP: {$jadwal->jenis_maintenance}",
                'status' => 'DITUGASKAN',
                'dibuat_pada' => now(),
            ]);

            app(NotifikasiService::class)->kirim(
                [$jadwal->teknisi_id],
                "SPK Preventif Baru: {$tiket->kode}",
                "Anda ditugaskan pada pemeliharaan preventif " . ($jadwal->aset?->barang?->nama ?? 'Aset') . " ({$jadwal->jenis_maintenance}).",
                'SPK_PREVENTIF',
                (string) $penugasan->id,
                '/teknisi/lembar-kerja',
            );
        }
        if ($jadwal->aset && $jadwal->aset->status !== 'DALAM_MAINTENANCE') {
            $jadwal->aset->update(['status' => 'DALAM_MAINTENANCE']);
        }


        $jadwal->update([
            'tanggal_terakhir' => $today,
            'tanggal_berikutnya' => $jadwal->hitungTanggalBerikutnya(now())->toDateString(),
        ]);

        $createdCount++;
    }

    Cache::put('simlog:last_run:preventive_maintenance', [
        'waktu' => now()->toDateTimeString(),
        'status' => 'Sukses',
        'ringkasan' => "{$createdCount} tiket pemeliharaan preventif diterbitkan",
        'created_count' => $createdCount,
    ]);

    $this->info("Pemindaian selesai: {$createdCount} tiket preventif otomatis diterbitkan.");
})->purpose('Menerbitkan tiket servis preventif IPSRS otomatis dari jadwal berkala')->dailyAt('01:00');
