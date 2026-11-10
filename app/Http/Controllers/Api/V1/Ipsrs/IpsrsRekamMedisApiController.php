<?php

namespace App\Http\Controllers\Api\V1\Ipsrs;

use App\Http\Controllers\Api\ApiController;
use App\Models\Aset\Aset;
use App\Models\Ipsrs\JadwalMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Katalog & rekam medis sarana (aset).
 */
class IpsrsRekamMedisApiController extends ApiController
{
    /**
     * 3. Rekam Medis Aset Fisik (Comprehensive Life-Cycle History)
     * GET /v1/maintenance/rekam-medis/{aset_id}
     */
    public function rekamMedisAset(Request $request, int $asetId): JsonResponse
    {
        $user = $request->user();
        $canView = $user && (
            $user->hasRole(['super_admin', 'admin_logistik', 'kepala_teknisi', 'teknisi', 'auditor', 'manajemen']) ||
            $user->can('view_any_pemeliharaan::rekam::medis::aset') ||
            $user->can('view_pemeliharaan::rekam::medis::aset')
        );
        if (! $canView) {
            return $this->jsonError('Anda tidak memiliki wewenang melihat rekam medis aset.', 403);
        }

        $aset = Aset::with([
            'barang.kategori',
            'barang.satuan',
            'barang.merek',
            'unit',
            'gudang',
            'lokasiGudang',
            'permintaanMaintenance' => fn ($q) => $q->orderBy('id', 'desc'),
            'permintaanMaintenance.pelapor:id,nama',
            'permintaanMaintenance.penugasan.teknisi:id,nama',
            'permintaanMaintenance.penugasan.penugas:id,nama',
            'permintaanMaintenance.logMaintenance.teknisi:id,nama',
            'permintaanMaintenance.sukuCadang.barang:id,kode,nama',
            'permintaanMaintenance.sukuCadang.stokBatch:id,nomor_batch',
        ])
        ->withCount('permintaanMaintenance')
        ->find($asetId);

        if (! $aset) {
            return $this->jsonError('Data aset fisik tidak ditemukan.', 404);
        }

        $lokasi = $aset->unit ? $aset->unit->nama : ($aset->gudang ? $aset->gudang->nama . ($aset->lokasiGudang ? " (Rak: {$aset->lokasiGudang->nama})" : '') : '-');

        // Timeline Riwayat Servis / Gangguan
        $riwayatServis = $aset->permintaanMaintenance->map(fn ($pm) => $this->formatRiwayatServis($pm));

        // Agenda Preventif Terkait Aset
        $jadwalPreventif = JadwalMaintenance::where('aset_id', $aset->id)
            ->with('teknisi:id,nama')
            ->get()
            ->map(fn ($j) => [
                'id'                => $j->id,
                'kode'              => $j->kode,
                'jenis_maintenance' => $j->jenis_maintenance,
                'interval'          => "Setiap {$j->interval_nilai} {$j->interval_tipe}",
                'tanggal_berikutnya'=> $j->tanggal_berikutnya?->format('Y-m-d') ?? '-',
                'teknisi'           => $j->teknisi?->nama ?? 'Teknisi IPSRS',
                'status'            => $j->status,
            ]);

        $data = [
            'identitas' => [
                'id'               => $aset->id,
                'kode_aset'        => $aset->kode_aset,
                'nomor_seri'       => $aset->nomor_seri ?? '-',
                'nama_sarana'      => $aset->barang?->nama ?? 'Sarana Medis/Non-Medis',
                'kategori'         => $aset->barang?->kategori?->nama ?? 'Umum',
                'merek'            => $aset->barang?->merek?->nama ?? '-',
                'spesifikasi'      => $aset->barang?->spesifikasi ?? '-',
                'kondisi'          => $aset->kondisi,
                'status'           => $aset->status,
                'lokasi'           => $lokasi,
                'tanggal_perolehan'=> $aset->tanggal_perolehan?->format('Y-m-d') ?? '-',
                'nilai_perolehan'  => (float) ($aset->nilai_perolehan ?? 0),
                'total_servis'     => $aset->permintaan_maintenance_count,
            ],
            'riwayat_servis'   => $riwayatServis,
            'jadwal_preventif' => $jadwalPreventif,
        ];

        return $this->jsonSuccess($data, 'Rekam medis aset berhasil dimuat.');
    }

    private function formatRiwayatServis($pm): array
    {
        $spk = $pm->penugasan->first();
        $log = $pm->logMaintenance->first();

        $sukuCadangDipakai = $pm->sukuCadang->map(fn ($sc) => [
            'nama_barang' => $sc->barang?->nama ?? 'Suku Cadang',
            'nomor_batch' => $sc->stokBatch?->nomor_batch ?? '-',
            'jumlah'      => (int) $sc->jumlah,
        ]);

        return [
            'id'                 => $pm->id,
            'kode_tiket'         => $pm->kode,
            'tanggal_lapor'      => $pm->dibuat_pada?->format('Y-m-d H:i') ?? '-',
            'pelapor'            => $pm->pelapor?->nama ?? 'Staf',
            'deskripsi_masalah'  => $pm->deskripsi_masalah,
            'prioritas'          => $pm->prioritas,
            'status_tiket'       => $pm->status,
            'kode_spk'           => $spk?->kode ?? '-',
            'teknisi'            => $spk?->teknisi?->nama ?? '-',
            'tanggal_penugasan'  => $spk?->tanggal_penugasan?->format('Y-m-d') ?? '-',
            'status_spk'         => $spk?->status ?? '-',
            'diagnosa'           => $log?->diagnosa_kerusakan ?? '-',
            'tindakan'           => $log?->tindakan_perbaikan ?? '-',
            'hasil_pengerjaan'   => $log?->hasil_pengerjaan ?? '-',
            'tanggal_selesai'    => $log?->tanggal_selesai?->format('Y-m-d H:i') ?? '-',
            'suku_cadang'        => $sukuCadangDipakai,
        ];
    }

    /**
     * 4. Katalog Seluruh Aset untuk Pilihan Rekam Medis
     * GET /v1/maintenance/rekam-medis/katalog
     */
    public function katalogRekamMedis(Request $request): JsonResponse
    {
        $user = $request->user();
        $canView = $user && (
            $user->hasRole(['super_admin', 'admin_logistik', 'kepala_teknisi', 'teknisi', 'auditor', 'manajemen']) ||
            $user->can('view_any_pemeliharaan::rekam::medis::aset') ||
            $user->can('view_pemeliharaan::rekam::medis::aset')
        );
        if (! $canView) {
            return $this->jsonError('Anda tidak memiliki wewenang melihat katalog rekam medis aset.', 403);
        }

        $cari = $request->query('cari');
        $kondisi = $request->query('kondisi');
        $query = Aset::with(['barang.kategori', 'barang.merek', 'unit', 'gudang'])
            ->withCount('permintaanMaintenance')
            ->when($kondisi, fn ($q) => $q->where('kondisi', $kondisi))
            ->when($cari, function ($q) use ($cari) {
                $q->where(function ($sub) use ($cari) {
                    $sub->where('kode_aset', 'ilike', "%{$cari}%")
                        ->orWhere('nomor_seri', 'ilike', "%{$cari}%")
                        ->orWhereHas('barang', fn ($b) => $b->where('nama', 'ilike', "%{$cari}%"))
                        ->orWhereHas('unit', fn ($u) => $u->where('nama', 'ilike', "%{$cari}%"));
                });
            });

        $items = $query->orderBy('kode_aset', 'asc')
            ->limit(50)
            ->get()
            ->map(function ($a) {
                $lokasi = $a->unit ? $a->unit->nama : ($a->gudang ? $a->gudang->nama : '-');
                return [
                    'id'           => $a->id,
                    'kode_aset'    => $a->kode_aset,
                    'nomor_seri'   => $a->nomor_seri ?? '-',
                    'nama_sarana'  => $a->barang?->nama ?? 'Sarana Fisik',
                    'kategori'     => $a->barang?->kategori?->nama ?? 'Umum',
                    'merek'        => $a->barang?->merek?->nama ?? '-',
                    'kondisi'      => $a->kondisi,
                    'status'       => $a->status,
                    'lokasi'       => $lokasi,
                    'total_servis' => $a->permintaan_maintenance_count,
                ];
            });

        return $this->jsonSuccess($items, 'Katalog rekam medis aset berhasil dimuat.');
    }
}
