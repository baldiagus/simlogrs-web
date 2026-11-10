<?php

namespace App\Livewire\Dashboard;

use App\Enums\Distribusi\StatusPermintaanDistribusi;
use App\Enums\Ipsrs\StatusMaintenance;
use App\Models\Aset\PermintaanPeminjaman;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Distribusi\StokRuangan;
use App\Models\Ipsrs\PermintaanMaintenance;
use Livewire\Component;

class KepalaUnitDashboard extends Component
{
    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        abort_unless(auth()->user()?->can('page_KepalaUnitDashboard'), 403);

        $user = auth()->user();
        $isGlobal = (bool) $user?->hasRole(['super_admin', 'admin_logistik']);
        $unitId = $user?->unit_id;
        $scopeUnit = fn ($q, string $col = 'unit_id') => $unitId
            ? $q->where($col, $unitId)
            : ($isGlobal ? $q : $q->whereRaw('1 = 0'));

        // 1. Antrean Approval Amprah Karu (Meja 1)
        $antreanAmprah = $scopeUnit(PermintaanDistribusi::where('status', StatusPermintaanDistribusi::Diajukan->value))
            ->with(['pengaju', 'gudang', 'detail.barang'])
            ->latest('id')
            ->take(5)
            ->get();
        $totalAntreanAmprah = $scopeUnit(PermintaanDistribusi::where('status', StatusPermintaanDistribusi::Diajukan->value))
            ->count();

        // 2. Permohonan Pinjam Aset Menunggu Izin Karu Penyedia
        $antreanPinjam = $scopeUnit(PermintaanPeminjaman::where('status', 'DIAJUKAN'), 'unit_penyedia_id')
            ->with(['unitPeminjam', 'pengaju'])
            ->latest('id')
            ->take(5)
            ->get();
        $totalAntreanPinjam = $scopeUnit(PermintaanPeminjaman::where('status', 'DIAJUKAN'), 'unit_penyedia_id')
            ->count();

        // 3. Tiket Lapor Servis / Kerusakan Aktif di Ruangan
        $totalServisAktif = $scopeUnit(PermintaanMaintenance::whereIn('status', StatusMaintenance::aktif()))
            ->count();

        // 4. Komoditas Stok Ruangan Terdaftar
        $totalStokRuangan = $scopeUnit(StokRuangan::query())->count();
        return view('livewire.dashboard.kepala-unit-dashboard', [
            'totalAntreanAmprah' => $totalAntreanAmprah,
            'antreanAmprah'      => $antreanAmprah,
            'totalAntreanPinjam' => $totalAntreanPinjam,
            'antreanPinjam'      => $antreanPinjam,
            'totalServisAktif'   => $totalServisAktif,
            'totalStokRuangan'   => $totalStokRuangan,
        ])->layout('layouts.portal', ['portal' => 'kepala-unit', 'title' => 'Dashboard Kepala Unit']);
    }
}
