<?php

namespace App\Livewire\Dashboard;

use App\Enums\Distribusi\StatusPengirimanBarang;
use App\Enums\Distribusi\StatusPermintaanDistribusi;
use App\Enums\Ipsrs\StatusMaintenance;
use App\Models\Aset\PermintaanPeminjaman;
use App\Models\Distribusi\PengirimanBarang;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Ipsrs\PermintaanMaintenance;
use Livewire\Component;

class UnitDashboard extends Component
{
    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        abort_unless(auth()->user()?->can('page_UnitDashboard'), 403);

        $user = auth()->user();
        $isGlobal = (bool) $user?->hasRole(['super_admin', 'admin_logistik']);
        $unitId = $user?->unit_id;
        $scopeUnit = fn ($q, string $col = 'unit_id') => $unitId
            ? $q->where($col, $unitId)
            : ($isGlobal ? $q : $q->whereRaw('1 = 0'));

        // 1. Pengiriman SPPB Menunggu Konfirmasi Terima (RCV)
        $antreanRcv = $scopeUnit(PengirimanBarang::where('status', StatusPengirimanBarang::Dikirim->value))
            ->with(['gudang', 'permintaanDistribusi'])
            ->latest('id')
            ->take(5)
            ->get();
        $totalAntreanRcv = $scopeUnit(PengirimanBarang::where('status', StatusPengirimanBarang::Dikirim->value))
            ->count();

        // 2. Permintaan Amprah Masih Berjalan
        $amprahBerjalan = $scopeUnit(PermintaanDistribusi::whereIn('status', [
                StatusPermintaanDistribusi::Diajukan->value,
                StatusPermintaanDistribusi::DisetujuiKaru->value,
                StatusPermintaanDistribusi::DisetujuiGudang->value,
                StatusPermintaanDistribusi::SedangDikirim->value,
            ]))
            ->with(['gudang', 'detail.barang'])
            ->latest('id')
            ->take(5)
            ->get();
        $totalAmprahBerjalan = $scopeUnit(PermintaanDistribusi::whereIn('status', [
                StatusPermintaanDistribusi::Diajukan->value,
                StatusPermintaanDistribusi::DisetujuiKaru->value,
                StatusPermintaanDistribusi::DisetujuiGudang->value,
                StatusPermintaanDistribusi::SedangDikirim->value,
            ]))
            ->count();

        // 3. Aset Ruangan Sedang Dipinjamkan Keluar
        $totalAsetDipinjam = $scopeUnit(PermintaanPeminjaman::where('status', 'DISETUJUI'), 'unit_penyedia_id')
            ->count();

        // 4. Laporan Kerusakan Sarana Aktif
        $totalLaporServis = $scopeUnit(PermintaanMaintenance::whereIn('status', StatusMaintenance::aktif()))
            ->count();

        return view('livewire.dashboard.unit-dashboard', [
            'totalAntreanRcv'     => $totalAntreanRcv,
            'antreanRcv'          => $antreanRcv,
            'totalAmprahBerjalan' => $totalAmprahBerjalan,
            'amprahBerjalan'      => $amprahBerjalan,
            'totalAsetDipinjam'   => $totalAsetDipinjam,
            'totalLaporServis'    => $totalLaporServis,
        ])->layout('layouts.portal', ['portal' => 'petugas-unit', 'title' => 'Dashboard Petugas Ruangan']);
    }
}
