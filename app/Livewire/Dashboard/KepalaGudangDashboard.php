<?php

namespace App\Livewire\Dashboard;

use App\Enums\Persediaan\StatusStokOpname;
use App\Models\Persediaan\PenyesuaianStok;
use App\Models\Persediaan\StokBatch;
use App\Models\Persediaan\StokOpname;
use Livewire\Component;

class KepalaGudangDashboard extends Component
{
    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        abort_unless(auth()->user()?->can('page_KepalaGudangDashboard'), 403);

        $user = auth()->user();
        $isGlobal = (bool) $user?->hasRole(['super_admin', 'admin_logistik']);
        $gudangId = $user?->gudang_id;
        $scopeGudang = fn ($q) => $gudangId
            ? $q->where('gudang_id', $gudangId)
            : ($isGlobal ? $q : $q->whereRaw('1 = 0'));

        // 1. Batch Kedaluwarsa Kritis (<= 90 Hari)
        $batchKritis = $scopeGudang(StokBatch::with(['barang', 'gudang'])
            ->where('status', 'AKTIF')
            ->whereNotNull('tanggal_kedaluwarsa')
            ->where('tanggal_kedaluwarsa', '<=', now()->addDays(90)->toDateString()))
            ->orderBy('tanggal_kedaluwarsa')
            ->take(5)
            ->get();
        $totalBatchKritis = $scopeGudang(StokBatch::where('status', 'AKTIF')
            ->whereNotNull('tanggal_kedaluwarsa')
            ->where('tanggal_kedaluwarsa', '<=', now()->addDays(90)->toDateString()))
            ->count();

        // 2. Sesi Stock Opname Berjalan
        $opnameBerjalan = $scopeGudang(StokOpname::with('gudang')
            ->whereIn('status', StatusStokOpname::aktif()))
            ->latest('id')
            ->take(5)
            ->get();
        $totalOpnameBerjalan = $scopeGudang(StokOpname::whereIn('status', StatusStokOpname::aktif()))
            ->count();

        // 3. Menunggu Otorisasi Penyesuaian Varians Opname
        $penyesuaianMenunggu = $scopeGudang(PenyesuaianStok::with(['gudang', 'pengaju'])
            ->where('status', 'MENUNGGU'))
            ->latest('id')
            ->take(5)
            ->get();
        $totalPenyesuaianMenunggu = $scopeGudang(PenyesuaianStok::where('status', 'MENUNGGU'))
            ->count();

        // 4. Total Batch Aktif Terdaftar
        $totalBatchAktif = $scopeGudang(StokBatch::where('status', 'AKTIF'))
            ->count();
        return view('livewire.dashboard.kepala-gudang-dashboard', [
            'totalBatchKritis'        => $totalBatchKritis,
            'batchKritis'             => $batchKritis,
            'totalOpnameBerjalan'     => $totalOpnameBerjalan,
            'opnameBerjalan'          => $opnameBerjalan,
            'totalPenyesuaianMenunggu'=> $totalPenyesuaianMenunggu,
            'penyesuaianMenunggu'     => $penyesuaianMenunggu,
            'totalBatchAktif'         => $totalBatchAktif,
        ])->layout('layouts.portal', ['portal' => 'kepala-gudang', 'title' => 'Dashboard Kepala Gudang']);
    }
}
