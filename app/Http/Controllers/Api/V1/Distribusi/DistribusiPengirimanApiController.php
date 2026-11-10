<?php

namespace App\Http\Controllers\Api\V1\Distribusi;

use App\Enums\Distribusi\StatusPengirimanBarang;
use App\Enums\Distribusi\StatusPermintaanDistribusi;
use App\Http\Controllers\Api\ApiController;
use App\Models\Aset\Aset;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Distribusi\PenerimaanDistribusi;
use App\Models\Distribusi\PenerimaanDistribusiDetail;
use App\Models\Distribusi\PengirimanBarang;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Distribusi\StokRuangan;
use App\Models\Master\Unit;
use App\Services\Distribusi\DistribusiService;
use App\Services\Distribusi\PickingScope;
use App\Support\Generator\CodeGenerator;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DistribusiPengirimanApiController extends ApiController
{
    /**
     * Antrean amprah siap picking FEFO untuk Petugas Gudang (Status DISETUJUI_GUDANG).
     * GET /v1/distribusi/antrean-picking
     */
    public function antreanPicking(Request $request): JsonResponse
    {
        $user = $request->user();

        $canPick = $user->can('view_any_gudang_stok::picking::distribusi') ||
            $user->can('view_gudang_stok::picking::distribusi') ||
            $user->can('create_gudang_stok::picking::distribusi') ||
            $user->can('update_gudang_stok::picking::distribusi');

        if (! $canPick) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk melihat antrean picking FEFO.', 403);
        }

        $isGlobalPickAdmin = PickingScope::isGlobalOperator($user);
        $filterGudang = PickingScope::ownGudangIds($user);

        if (! $isGlobalPickAdmin && empty($filterGudang)) {
            return $this->jsonError('Akun Anda tidak terhubung dengan fasilitas gudang.', 403);
        }

        $items = PermintaanDistribusi::query()
            ->whereIn('status', StatusPermintaanDistribusi::dapatDikirim())
            ->when(! $isGlobalPickAdmin, fn ($q) => $q->whereIn('gudang_id', $filterGudang))
            ->with(['unit:id,kode,nama', 'pengaju:id,nama', 'detail.barang.satuan'])
            ->latest('id')
            ->get()
            ->map(fn ($p) => [
                'id'                 => $p->id,
                'kode'               => $p->kode,
                'unit'               => $p->unit?->nama ?? 'Unit Ruangan',
                'pengaju'            => $p->pengaju?->nama ?? 'Staf',
                'tanggal_permintaan' => $p->tanggal_permintaan?->format('Y-m-d'),
                'urgensi'            => $p->urgensi,
                'status'             => $p->status,
                'total_item'         => $p->detail->count(),
                'items'              => $p->detail->map(fn ($d) => [
                    'barang_id'        => $d->barang_id,
                    'nama_barang'      => $d->barang?->nama ?? 'Barang',
                    'satuan'           => $d->barang?->satuan?->nama ?? 'Pcs',
                    'jumlah_disetujui' => (int) ($d->jumlah_disetujui ?: $d->jumlah),
                ]),
            ]);

        return $this->jsonSuccess($items, 'Antrean picking distribusi berhasil dimuat.');
    }

    /**
     * Eksekusi alokasi picking FEFO dan penerbitan SPPB resmi.
     * POST /v1/distribusi/picking
     */
    public function eksekusiPicking(Request $request, DistribusiService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('create_gudang_stok::picking::distribusi')) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk eksekusi picking FEFO.', 403);
        }

        $request->validate([
            'permintaan_id' => 'required|exists:permintaan_distribusi,id',
            'gudang_id'     => 'nullable|exists:gudang,id',
        ]);

        $permintaan = PermintaanDistribusi::with(['detail.barang', 'unit'])->find($request->input('permintaan_id'));

        if (! in_array($permintaan->status, StatusPermintaanDistribusi::dapatDikirim(), true)) {
            return $this->jsonError("Permintaan {$permintaan->kode} tidak dalam status siap picking (saat ini: {$permintaan->status}).", 422);
        }

        PickingScope::authorize($user, $permintaan);

        try {
            $isGlobal = PickingScope::isGlobalOperator($user);
            $ownGudangIds = PickingScope::ownGudangIds($user);
            $gudangId = ($isGlobal && $request->filled('gudang_id'))
                ? (int) $request->input('gudang_id')
                : ($permintaan->gudang_id ?: ($user->gudang_id ?: ($ownGudangIds[0] ?? null)));

            if (! $permintaan->gudang_id && $gudangId) {
                $permintaan->update(['gudang_id' => $gudangId]);
                $permintaan->refresh();
            }

            $sppb = $service->eksekusiPickingFefo($permintaan, $user, $gudangId);
            $sppb->load(['detail.barang', 'detail.stokBatch', 'unit']);

            return $this->jsonSuccess([
                'id'             => $sppb->id,
                'sppb_kode'      => $sppb->kode,
                'permintaan_id'  => $permintaan->id,
                'unit_tujuan'    => $sppb->unit?->nama,
                'tanggal_kirim'  => $sppb->tanggal_kirim?->format('Y-m-d'),
                'total_batch'    => $sppb->detail->count(),
            ], "Picking FEFO berhasil. Dokumen {$sppb->kode} telah diterbitkan dan barang siap dikirim.", 201);
        } catch (Exception $e) {
            return $this->jsonError('Gagal eksekusi picking: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Daftar SPPB dalam pengiriman yang menuju ke unit pemohon (Inbound Ruangan).
     * GET /v1/distribusi/sppb-inbound
     */
    public function sppbInbound(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->jsonError('Unauthenticated.', 401);
        }

        $isGlobalAdmin = $user->hasRole('super_admin') || $user->hasRole('admin_logistik');
        $userUnitId = $user->unit_id;

        if (! $isGlobalAdmin && ! $userUnitId) {
            return $this->jsonSuccess([], 'Daftar pengiriman barang menuju ruangan berhasil dimuat.');
        }

        $sppbs = PengirimanBarang::query()
            ->where('status', StatusPengirimanBarang::Dikirim->value)
            ->when(! $isGlobalAdmin, fn ($q) => $q->where('unit_id', $userUnitId))
            ->with(['gudang:id,kode,nama', 'unit:id,kode,nama', 'pengirim:id,nama', 'detail.barang.satuan', 'detail.stokBatch'])
            ->latest('id')
            ->get()
            ->map(fn ($sppb) => [
                'id'            => $sppb->id,
                'kode'          => $sppb->kode,
                'gudang'        => $sppb->gudang?->nama ?? 'Gudang Sentral',
                'unit'          => $sppb->unit?->nama ?? '-',
                'pengirim'      => $sppb->pengirim?->nama ?? 'Petugas Gudang',
                'tanggal_kirim' => $sppb->tanggal_kirim?->format('Y-m-d'),
                'status'        => $sppb->status,
                'catatan'       => $sppb->catatan,
                'total_item'    => $sppb->detail->count(),
                'items'         => $sppb->detail->map(fn ($d) => [
                    'barang_id'      => $d->barang_id,
                    'nama_barang'    => $d->barang?->nama ?? 'Barang',
                    'kode_barang'    => $d->barang?->kode ?? '-',
                    'satuan'         => $d->barang?->satuan?->nama ?? 'Pcs',
                    'jumlah_dikirim' => (int) $d->jumlah_dikirim,
                    'nomor_batch'    => $d->stokBatch?->nomor_batch ?? '-',
                ]),
            ]);

        return $this->jsonSuccess($sppbs, 'Daftar pengiriman barang menuju ruangan berhasil dimuat.');
    }

    /**
     * Konfirmasi penerimaan fisik barang dari SPPB di ruangan (RCV-YYYYMM-XXX).
     * POST /v1/distribusi/sppb/{id}/terima
     */
    public function konfirmasiTerimaSppb(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('update_layanan_unit::penerimaan::barang')) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk konfirmasi penerimaan barang ruangan.', 403);
        }

        $sppb = PengirimanBarang::with(['permintaanDistribusi', 'unit', 'gudang', 'detail.barang'])->find($id);

        if (! $sppb) {
            return $this->jsonError('Data pengiriman SPPB tidak ditemukan.', 404);
        }



        $isGlobalAdmin = $user->hasRole('super_admin') || $user->hasRole('admin_logistik');
        $effectiveRcvUnitId = $user->unit_id ?: ($user->hasRole('pengadaan') ? Unit::where('kode', 'LOGISTIK')->value('id') : null);
        if (! $isGlobalAdmin && (int) $effectiveRcvUnitId !== (int) $sppb->unit_id) {
            return $this->jsonError('Anda hanya berhak mengonfirmasi penerimaan barang untuk ruangan Anda sendiri.', 403);
        }

        try {
            $penerimaan = DB::transaction(function () use ($id, $user) {
                $sppb = PengirimanBarang::lockForUpdate()->with(['permintaanDistribusi', 'unit', 'gudang', 'detail.barang', 'detail.stokBatch'])->findOrFail($id);

                if ($sppb->status !== StatusPengirimanBarang::Dikirim->value) {
                    throw new Exception("Pengiriman {$sppb->kode} telah diproses sebelumnya (saat ini: {$sppb->status}).");
                }

                $kodeRcv = CodeGenerator::generate('RCV', PenerimaanDistribusi::class, 'kode', 'Ym', 3);

                $rcv = PenerimaanDistribusi::create([
                    'kode'                 => $kodeRcv,
                    'pengiriman_barang_id' => $sppb->id,
                    'unit_id'              => $sppb->unit_id,
                    'tanggal_terima'       => now()->toDateString(),
                    'status'               => 'SELESAI',
                    'catatan'              => 'Penerimaan fisik barang dari SPPB ' . $sppb->kode,
                    'diterima_oleh'        => $user->id,
                ]);

                $hasAssetItem = false;
                foreach ($sppb->detail as $detail) {
                    if ($this->prosesDetailPenerimaan($detail, $sppb, $rcv->id)) {
                        $hasAssetItem = true;
                    }
                }
                $sppb->update(['status' => StatusPengirimanBarang::Diterima->value]);

                $this->perbaruiStatusPermintaanSetelahTerima($sppb);

                return $rcv;
            });

            $msg = ($hasAssetItem ?? false)
                ? "Barang dari SPPB {$sppb->kode} berhasil diterima dan didaftarkan ke inventaris aset unit Anda ({$penerimaan->kode})."
                : "Barang dari SPPB {$sppb->kode} berhasil diterima dan dimasukkan ke stok ruangan ({$penerimaan->kode}).";
            return $this->jsonSuccess([
                'id'       => $penerimaan->id,
                'kode_rcv' => $penerimaan->kode,
                'status'   => 'SELESAI',
            ], $msg, 201);
        } catch (\Throwable $e) {
            return $this->jsonError('Gagal konfirmasi penerimaan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Set status permintaan menjadi SELESAI bila semua barang diterima, selain itu DIKIRIM_SEBAGIAN.
     */
    private function perbaruiStatusPermintaanSetelahTerima(PengirimanBarang $sppb): void
    {
        if ($sppb->permintaan_distribusi_id) {
            $permintaan = PermintaanDistribusi::lockForUpdate()->find($sppb->permintaan_distribusi_id);
            if (! $permintaan) {
                return;
            }
            $permintaan->load('detail');

            if ($permintaan->semuaBarangDiterima()) {
                $permintaan->update(['status' => StatusPermintaanDistribusi::Selesai->value]);
            } elseif ($permintaan->semuaBarangDikirim()) {
                $permintaan->update(['status' => StatusPermintaanDistribusi::SedangDikirim->value]);
            } else {
                $permintaan->update(['status' => StatusPermintaanDistribusi::DikirimSebagian->value]);
            }
        }
    }

    /**
     * Catat detail penerimaan dan tambah stok ruangan; barang aset ditangani terpisah. Mengembalikan true bila aset.
     */
    protected function prosesDetailPenerimaan($detail, $sppb, int $penerimaanId): bool
    {
        PenerimaanDistribusiDetail::create([
            'penerimaan_distribusi_id' => $penerimaanId,
            'barang_id'                => $detail->barang_id,
            'jumlah_diterima'          => $detail->jumlah_dikirim,
        ]);

        $barang = $detail->barang;
        $isAset = $barang && ($barang->tipe === 'ASET' || str_contains(strtoupper($barang->kategori?->nama ?? ''), 'ASET'));
        $qty = (int) $detail->jumlah_dikirim;

        if ($isAset) {
            $this->prosesPenerimaanAset($detail, $sppb, $barang, $qty);
            return true;
        }

        $stok = StokRuangan::firstOrCreate(
            ['unit_id' => $sppb->unit_id, 'barang_id' => $detail->barang_id],
            ['jumlah' => 0]
        );
        $stok->increment('jumlah', $qty);

        return false;
    }

    /**
     * Pindahkan aset gudang yang tersedia menjadi milik unit penerima sesuai jumlah diterima.
     */
    protected function prosesPenerimaanAset($detail, $sppb, $barang, int $qty): void
    {
        $existingGudangAsets = Aset::where('barang_id', $detail->barang_id)
            ->where('gudang_id', $sppb->gudang_id)
            ->whereNull('unit_id')
            ->where('status', 'TERSEDIA')
            ->lockForUpdate()
            ->limit($qty)
            ->get();

        $transferredCount = 0;
        foreach ($existingGudangAsets as $asetGudang) {
            $asetGudang->update([
                'unit_id'          => $sppb->unit_id,
                'gudang_id'        => null,
                'lokasi_gudang_id' => null,
                'status'           => 'TERSEDIA',
                'kondisi'          => 'BAIK',
            ]);
            $transferredCount++;
        }

        $remainingToCreate = $qty - $transferredCount;
        for ($i = 0; $i < $remainingToCreate; $i++) {
            $kodeAset = CodeGenerator::generate('AST', Aset::class, 'kode_aset');
            $batch = $detail->stokBatch;
            $nilaiPerolehan = (float) ($batch?->harga_satuan ?: ($barang->harga_beli_terakhir ?: ($barang->harga_estimasi ?: 0)));
            $nomorSeri = $batch?->nomor_batch ?: ('SN-' . $barang->kode . '-' . date('Ymd') . '-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT));

            Aset::create([
                'barang_id'         => $barang->id,
                'unit_id'           => $sppb->unit_id,
                'gudang_id'         => null,
                'lokasi_gudang_id'  => null,
                'kode_aset'         => $kodeAset,
                'nomor_seri'        => $nomorSeri,
                'tanggal_perolehan' => now()->toDateString(),
                'nilai_perolehan'   => $nilaiPerolehan,
                'kondisi'           => 'BAIK',
                'status'            => 'TERSEDIA',
            ]);
        }
    }
}
