<?php

namespace App\Http\Controllers\Api\V1\Ipsrs;

use App\Http\Controllers\Api\ApiController;
use App\Enums\Ipsrs\StatusMaintenance;
use App\Enums\Ipsrs\StatusSpk;
use App\Models\Ipsrs\JadwalMaintenance;
use App\Models\Ipsrs\PenugasanMaintenance;
use App\Models\Ipsrs\PermintaanMaintenance;
use App\Support\Generator\CodeGenerator;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Jadwal pemeliharaan preventif berkala IPSRS.
 */
class IpsrsJadwalApiController extends ApiController
{
    /**
     * 1. Daftar Jadwal Pemeliharaan Preventif Berkala
     * GET /v1/maintenance/preventif/jadwal
     */
    public function jadwalPreventif(Request $request): JsonResponse
    {
        $user = $request->user();
        $canView = $user && (
            $user->hasRole(['super_admin', 'admin_logistik', 'kepala_teknisi', 'auditor', 'manajemen'])
            || $user->can('view_any_pemeliharaan::pemeliharaan::berkala')
            || ($user->hasRole('teknisi') && $user->can('view_pemeliharaan::pemeliharaan::berkala'))
        );
        if (! $canView) {
            return $this->jsonError('Anda tidak memiliki wewenang melihat jadwal pemeliharaan preventif.', 403);
        }

        $statusFilter = strtoupper($request->query('status', 'SEMUA')); // SEMUA, OVERDUE, HARI_INI, MENDATANG
        $teknisiId = $request->query('teknisi_id');
        $cari = $request->query('cari');
        $today = now()->toDateString();

        $query = JadwalMaintenance::with([
            'aset.barang.kategori:id,nama',
            'aset.unit:id,kode,nama',
            'aset.gudang:id,kode,nama',
            'teknisi:id,nama',
        ])
        ->when($teknisiId, fn ($q) => $q->where('teknisi_id', $teknisiId))
        ->when($user && $user->hasRole('teknisi') && ! $user->hasRole('super_admin') && ! $user->hasRole('admin_logistik'), function ($q) use ($user) {
            $q->where('teknisi_id', $user->id);
        })
        ->when($cari, function ($q) use ($cari) {
            $q->where(function ($sub) use ($cari) {
                $sub->where('kode', 'ilike', "%{$cari}%")
                    ->orWhere('jenis_maintenance', 'ilike', "%{$cari}%")
                    ->orWhereHas('aset', fn ($a) => $a->where('kode_aset', 'ilike', "%{$cari}%")->orWhere('nomor_seri', 'ilike', "%{$cari}%"))
                    ->orWhereHas('aset.barang', fn ($b) => $b->where('nama', 'ilike', "%{$cari}%"));
            });
        });

        // Filter Jatuh Tempo
        if ($statusFilter === 'OVERDUE') {
            $query->where('status', 'AKTIF')->where('tanggal_berikutnya', '<', $today);
        } elseif ($statusFilter === 'HARI_INI') {
            $query->where('status', 'AKTIF')->where('tanggal_berikutnya', $today);
        } elseif ($statusFilter === 'MENDATANG') {
            $query->where('status', 'AKTIF')->where('tanggal_berikutnya', '>', $today);
        }

        $items = $query->orderBy('tanggal_berikutnya', 'asc')
            ->limit(50)
            ->get()
            ->map(fn ($j) => $this->formatJadwalPreventif($j, $today));

        return $this->jsonSuccess($items, 'Jadwal preventif berkala berhasil dimuat.');
    }

    /**
     * Format satu jadwal preventif menjadi array respons API beserta status jatuh tempo.
     */
    private function formatJadwalPreventif($j, string $today): array
    {
        $tgl = $j->tanggal_berikutnya;
        $sisaHari = null;
        $statusJadwal = 'MENDATANG'; // OVERDUE, HARI_INI, MENDATANG, NONAKTIF

        if ($j->status !== 'AKTIF') {
            $statusJadwal = 'NONAKTIF';
        } elseif ($tgl) {
            $sisaHari = Carbon::parse($today)->diffInDays(Carbon::parse($tgl), false);
            if ($sisaHari < 0) {
                $statusJadwal = 'OVERDUE';
            } elseif ($sisaHari == 0) {
                $statusJadwal = 'HARI_INI';
            } else {
                $statusJadwal = 'MENDATANG';
            }
        }

        $lokasi = $j->aset?->unit ? $j->aset->unit->nama : ($j->aset?->gudang ? $j->aset->gudang->nama : '-');

        return [
            'id'                   => $j->id,
            'kode'                 => $j->kode,
            'aset_id'              => $j->aset_id,
            'kode_aset'            => $j->aset?->kode_aset ?? '-',
            'nomor_seri'           => $j->aset?->nomor_seri ?? '-',
            'nama_sarana'          => $j->aset?->barang?->nama ?? 'Sarana Medis/Non-Medis',
            'lokasi'               => $lokasi,
            'jenis_maintenance'    => $j->jenis_maintenance,
            'interval_nilai'       => (int) $j->interval_nilai,
            'interval_tipe'        => $j->interval_tipe,
            'interval_label'       => "Setiap {$j->interval_nilai} {$j->interval_tipe}",
            'tanggal_terakhir'     => $j->tanggal_terakhir?->format('Y-m-d') ?? '-',
            'tanggal_berikutnya'   => $tgl?->format('Y-m-d') ?? '-',
            'sisa_hari'            => $sisaHari,
            'status_jadwal'        => $statusJadwal,
            'status_aktif'         => $j->status,
            'teknisi_id'           => $j->teknisi_id,
            'teknisi_nama'         => $j->teknisi?->nama ?? 'Teknisi IPSRS',
            'catatan'              => $j->catatan ?? '-',
        ];
    }

    /**
     * 2. Eksekusi Terbitkan SPK dari Jadwal Preventif
     * POST /v1/maintenance/preventif/{id}/eksekusi
     */
    public function eksekusiPreventif(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Sama dengan web (canTerbitkanSpk): izin SPK DAN peran koordinator/admin.
        $canExecute = $user
            && $user->can('create_pemeliharaan::spk::perbaikan')
            && $user->hasRole(['kepala_teknisi', 'super_admin', 'admin_logistik']);

        if (! $canExecute) {
            return $this->jsonError('Hanya kepala teknisi dan admin logistik yang berwenang menerbitkan SPK preventif.', 403);
        }

        $jadwal = JadwalMaintenance::with(['aset', 'teknisi'])->find($id);

        if (! $jadwal) {
            return $this->jsonError('Jadwal pemeliharaan preventif tidak ditemukan.', 404);
        }

        $kodeMnt = CodeGenerator::generateMnt();
        $kodeSpk = CodeGenerator::generateSpk();

        try {
            DB::transaction(function () use ($jadwal, $user, $kodeMnt, $kodeSpk) {
                // 1. Buat Permintaan Maintenance Preventif
                $permintaan = PermintaanMaintenance::create([
                    'kode'              => $kodeMnt,
                    'aset_id'           => $jadwal->aset_id,
                    'unit_id'           => $jadwal->aset?->unit_id,
                    'dilaporkan_oleh'   => $user->id,
                    'deskripsi_masalah' => "Servis preventif berkala: {$jadwal->jenis_maintenance} (Sesuai agenda {$jadwal->kode})",
                    'prioritas'         => 'SEDANG',
                    'status'            => StatusMaintenance::Ditugaskan->value,
                ]);

                // 2. Buat Dokumen SPK Ditugaskan Langsung ke Teknisi Jadwal
                PenugasanMaintenance::create([
                    'kode'                      => $kodeSpk,
                    'permintaan_maintenance_id' => $permintaan->id,
                    'teknisi_id'                => $jadwal->teknisi_id,
                    'ditugaskan_oleh'           => $user->id,
                    'tanggal_penugasan'         => now()->toDateString(),
                    'target_selesai'            => now()->addDays(2)->toDateString(),
                    'instruksi_khusus'          => "Servis preventif berkala rutin sesuai jadwal {$jadwal->kode}. " . ($jadwal->catatan ?: ''),
                    'status'                    => StatusSpk::Ditugaskan->value,
                ]);

                // 3. Catat tanggal eksekusi terakhir dan majukan tanggal berikutnya
                $tglBerikutnya = $jadwal->hitungTanggalBerikutnya(now())->toDateString();
                $jadwal->update([
                    'tanggal_terakhir'   => now()->toDateString(),
                    'tanggal_berikutnya' => $tglBerikutnya,
                ]);
            });

            return $this->jsonSuccess([
                'kode_spk' => $kodeSpk,
                'kode_mnt' => $kodeMnt,
            ], "SPK Servis Preventif {$kodeSpk} berhasil diterbitkan dan ditugaskan.");
        } catch (Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * Tambah Jadwal Pemeliharaan Preventif Baru (Khusus Kepala Teknisi / Super Admin)
     * POST /v1/maintenance/preventif/jadwal
     */
    public function simpanJadwalPreventif(Request $request): JsonResponse
    {
        $user = $request->user();

        $canCreate = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('create_pemeliharaan::pemeliharaan::berkala')
        );

        if (! $canCreate) {
            return $this->jsonError('Hanya Kepala / Koordinator Teknisi yang berwenang menambahkan jadwal preventif.', 403);
        }

        $validator = Validator::make($request->all(), [
            'aset_id'            => 'required|exists:aset,id',
            'jenis_maintenance'  => 'required|string|max:150',
            'interval_nilai'     => 'required|integer|min:1',
            'interval_tipe'      => 'required|in:HARI,MINGGU,BULAN,TAHUN',
            'tanggal_berikutnya' => 'required|date',
            'teknisi_id'         => 'nullable|exists:pengguna,id',
            'catatan'            => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi data jadwal gagal.', 422, $validator->errors());
        }

        $intervalNilai = (int) $request->input('interval_nilai');
        $intervalTipe = $request->input('interval_tipe');
        $frekuensi = $intervalTipe === 'BULAN'
            ? ($intervalNilai === 1 ? 'BULANAN' : "{$intervalNilai}_BULAN")
            : "{$intervalNilai}_{$intervalTipe}";

        $kode = CodeGenerator::generatePrv();

        $jadwal = JadwalMaintenance::create([
            'kode'               => $kode,
            'aset_id'            => $request->input('aset_id'),
            'jenis_maintenance'  => $request->input('jenis_maintenance'),
            'interval_nilai'     => $intervalNilai,
            'interval_tipe'      => $intervalTipe,
            'frekuensi'          => $frekuensi,
            'teknisi_id'         => $request->input('teknisi_id'),
            'tanggal_berikutnya' => $request->input('tanggal_berikutnya'),
            'catatan'            => $request->input('catatan'),
            'status'             => 'AKTIF',
            'dibuat_oleh'        => $user->id,
        ]);

        return $this->jsonSuccess([
            'id'   => $jadwal->id,
            'kode' => $jadwal->kode,
        ], "Jadwal pemeliharaan preventif {$jadwal->kode} berhasil ditambahkan.", 201);
    }
}
