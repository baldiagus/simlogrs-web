<?php

namespace App\Http\Controllers\Api\V1\Persediaan;

use App\Enums\Persediaan\StatusPenyesuaianStok;
use App\Enums\Persediaan\StatusStokOpname;
use App\Http\Controllers\Api\ApiController;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Persediaan\PenyesuaianStok;
use App\Models\Persediaan\PenyesuaianStokDetail;
use App\Models\Persediaan\StokBatch;
use App\Models\Persediaan\StokOpname;
use App\Models\Persediaan\StokOpnameDetail;
use App\Support\Generator\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PersediaanOpnameApiController extends ApiController
{
    /**
     * 3. Daftar Sesi Stock Opname
     * GET /v1/gudang/opname/sesi
     */
    public function daftarOpname(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGlobal = $user && $user->hasRole(['super_admin', 'admin_logistik']);
        $canView = $isGlobal || ($user && ($user->can('view_any_gudang_stok::stok::opname') || $user->can('view_gudang_stok::stok::opname')));

        $hasWarehouseAccess = $canView && ($isGlobal || ($user && $user->gudang_id));
        if (! $hasWarehouseAccess) {
            return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat sesi stock opname.', 403);
        }

        $status = $request->query('status');
        $gudangId = $isGlobal ? $request->query('gudang_id') : $user->gudang_id;
        $query = StokOpname::with([
            'gudang:id,kode,nama',
            'pembuat:id,nama',
            'detail.barang.satuan',
            'detail.stokBatch.lokasiGudang',
        ])
        ->when($status, fn ($q) => $q->where('status', $status))
        ->when($gudangId, fn ($q) => $q->where('gudang_id', $gudangId));

        $opnames = $query->orderBy('id', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($op) {
                $totalItem = $op->detail->count();
                $totalTerhitung = $op->detail->whereNotNull('jumlah_fisik')->count();
                $totalSelisih = $op->detail->where('selisih', '!=', 0)->count();

                return [
                    'id'               => $op->id,
                    'kode'             => $op->kode,
                    'gudang_id'        => $op->gudang_id,
                    'gudang_nama'      => $op->gudang?->nama ?? 'Gudang',
                    'tanggal_opname'   => $op->tanggal_opname?->format('Y-m-d') ?? '-',
                    'status'           => $op->status,
                    'catatan'          => $op->catatan ?? '-',
                    'petugas_inisiasi' => $op->pembuat?->nama ?? 'Kepala Gudang',
                    'total_item'       => $totalItem,
                    'total_terhitung'  => $totalTerhitung,
                    'total_selisih'    => $totalSelisih,
                    'details'          => $op->detail->map(fn ($d) => [
                        'id'             => $d->id,
                        'stok_batch_id'  => $d->stok_batch_id,
                        'barang_id'      => $d->barang_id,
                        'kode_barang'    => $d->barang?->kode ?? '-',
                        'nama_barang'    => $d->barang?->nama ?? 'Barang',
                        'satuan'         => $d->barang?->satuan?->simbol ?? 'Pcs',
                        'nomor_batch'    => $d->stokBatch?->nomor_batch ?? '-',
                        'lokasi_rak'     => $d->stokBatch?->lokasiGudang?->nama ?? 'Tanpa Rak',
                        'jumlah_sistem'  => (int) $d->jumlah_sistem,
                        'jumlah_fisik'   => $d->jumlah_fisik !== null ? (int) $d->jumlah_fisik : null,
                        'selisih'        => (int) $d->selisih,
                        'catatan'        => $d->catatan ?? '',
                    ]),
                ];
            });

        return $this->jsonSuccess($opnames, 'Daftar sesi stock opname berhasil dimuat.');
    }

    /**
     * 4. Inisiasi Sesi Stock Opname Baru
     * POST /v1/gudang/opname/inisiasi
     */
    public function inisiasiOpname(Request $request): JsonResponse
    {
        $user = $request->user();
        $canInitiate = $user && (
            $user->can('create_gudang_stok::stok::opname') ||
            $user->hasRole(['super_admin', 'admin_logistik'])
        );

        if (! $canInitiate) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk menginisiasi stock opname.', 403);
        }

        $validator = Validator::make($request->all(), [
            'gudang_id'      => 'required|exists:gudang,id',
            'tanggal_opname' => 'required|date',
            'catatan'        => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi inisiasi opname gagal.', 422, $validator->errors());
        }

        $gudang = Gudang::findOrFail($request->input('gudang_id'));
        if (! $user->hasRole(['super_admin', 'admin_logistik']) && (! $user->gudang_id || (int) $user->gudang_id !== (int) $gudang->id)) {
            return $this->jsonError('Anda tidak memiliki wewenang menginisiasi opname di fasilitas gudang ini.', 403);
        }

        $batches = StokBatch::where('gudang_id', $gudang->id)
            ->where('status', 'AKTIF')
            ->get();

        if ($batches->isEmpty()) {
            return $this->jsonError("Tidak ada stok batch aktif di fasilitas {$gudang->nama} untuk diopname.", 422);
        }

        $kodeOpn = CodeGenerator::generateOpn();

        $opname = DB::transaction(function () use ($kodeOpn, $gudang, $user, $batches, $request) {
            $op = StokOpname::create([
                'kode'           => $kodeOpn,
                'gudang_id'      => $gudang->id,
                'tanggal_opname' => $request->input('tanggal_opname'),
                'status'         => StatusStokOpname::Berjalan->value,
                'catatan'        => $request->input('catatan'),
                'dibuat_oleh'    => $user->id,
            ]);

            foreach ($batches as $b) {
                StokOpnameDetail::create([
                    'stok_opname_id' => $op->id,
                    'barang_id'      => $b->barang_id,
                    'stok_batch_id'  => $b->id,
                    'jumlah_sistem'  => $b->jumlah,
                    'jumlah_fisik'   => 0,
                    'selisih'        => 0 - $b->jumlah,
                    'catatan'        => null,
                ]);
            }

            return $op;
        });

        return $this->jsonSuccess([
            'id'             => $opname->id,
            'kode'           => $opname->kode,
            'status'         => $opname->status,
            'total_batch'    => $batches->count(),
        ], "Sesi Stock Opname {$kodeOpn} berhasil diinisiasi.");
    }

    /**
     * 5. Simpan Hasil Hitung Fisik (Blind Count) & Finalisasi
     * POST /v1/gudang/opname/{id}/count
     */
    public function simpanHitungFisik(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $opname = StokOpname::with('detail.stokBatch')->find($id);

        if (! $opname) {
            return $this->jsonError('Sesi stock opname tidak ditemukan.', 404);
        }
        $canCount = $user && (
            $user->can('update_gudang_stok::stok::opname') ||
            $user->can('create_gudang_stok::stok::opname') ||
            $user->hasRole(['super_admin', 'admin_logistik'])
        );

        if (! $canCount) {
            return $this->jsonError('Anda tidak memiliki wewenang menginput hasil stock opname.', 403);
        }

        if (! $user->hasRole(['super_admin', 'admin_logistik']) && (! $user->gudang_id || (int) $user->gudang_id !== (int) $opname->gudang_id)) {
            return $this->jsonError('Anda tidak memiliki wewenang memproses opname di fasilitas gudang ini.', 403);
        }

        if (! in_array($opname->status, StatusStokOpname::bisaDihitung())) {
            return $this->jsonError("Sesi opname telah ditutup dengan status {$opname->status}.", 422);
        }

        $validator = Validator::make($request->all(), [
            'items'                  => 'required|array|min:1',
            'items.*.detail_id'      => 'required|exists:stok_opname_detail,id',
            'items.*.jumlah_fisik'   => 'required|integer|min:0',
            'items.*.catatan'        => 'nullable|string|max:255',
            'finalisasi'             => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi perhitungan fisik gagal.', 422, $validator->errors());
        }

        $finalisasi = $request->boolean('finalisasi', false);

        DB::transaction(function () use ($id, $request, $user, $finalisasi) {
            $lockedOpname = StokOpname::with('detail.stokBatch')->lockForUpdate()->findOrFail($id);

            if (! in_array($lockedOpname->status, StatusStokOpname::bisaDihitung(), true)) {
                throw new \Exception("Sesi opname telah ditutup atau diajukan dengan status {$lockedOpname->status}.");
            }

            $selisihRows = $this->catatHasilHitung($lockedOpname, $request->input('items'));
            $this->tetapkanStatusOpname($lockedOpname, $user, $finalisasi, $selisihRows);
        });

        return $this->jsonSuccess([
            'opname_id' => $opname->id,
            'status'    => $opname->fresh()->status,
        ], 'Perhitungan fisik stock opname berhasil dicatat.');
    }

    /**
     * Update detail hasil hitung; kembalikan baris selisih (non-nol) untuk dokumen penyesuaian.
     */
    private function catatHasilHitung(StokOpname $opname, array $items): array
    {
        $selisihRows = [];

        foreach ($items as $row) {
            $detail = $opname->detail->firstWhere('id', $row['detail_id']);
            if (! $detail) continue;

            $fisik = (int) $row['jumlah_fisik'];
            $selisih = $fisik - (int) $detail->jumlah_sistem;

            $detail->update([
                'jumlah_fisik' => $fisik,
                'selisih'      => $selisih,
                'catatan'      => $row['catatan'] ?? $detail->catatan,
            ]);

            if ($selisih !== 0) {
                $selisihRows[] = [
                    'barang_id'      => $detail->barang_id,
                    'stok_batch_id'  => $detail->stok_batch_id,
                    'jumlah'         => abs($selisih),
                    'jenis'          => $selisih > 0 ? 'TAMBAH' : 'KURANG',
                    'harga_satuan'   => $detail->stokBatch?->harga_satuan ?? 0,
                    'subtotal'       => abs($selisih) * (float) ($detail->stokBatch?->harga_satuan ?? 0),
                    'catatan'        => $row['catatan'] ?? 'Selisih opname fisik',
                ];
            }
        }

        return $selisihRows;
    }

    private function tetapkanStatusOpname(StokOpname $opname, $user, bool $finalisasi, array $selisihRows): void
    {
        if (! $finalisasi) {
            $opname->update(['status' => StatusStokOpname::ProsesCount->value]);

            return;
        }

        if (empty($selisihRows)) {
            $canAuthorize = $user->can('update_gudang_stok::otorisasi::penyesuaian') &&
                ($user->hasRole(['super_admin', 'admin_logistik', 'kepala_gudang']));

            $opname->update([
                'status'         => $canAuthorize ? StatusStokOpname::Disetujui->value : StatusStokOpname::MenungguApproval->value,
                'disetujui_oleh' => $canAuthorize ? $user->id : null,
            ]);

            return;
        }

        $opname->update(['status' => StatusStokOpname::MenungguApproval->value]);

        // Buat dokumen PenyesuaianStok jika belum ada
        if (! PenyesuaianStok::where('stok_opname_id', $opname->id)->exists()) {
            $this->buatDokumenPenyesuaian($opname, $user, $selisihRows);
        }
    }

    private function buatDokumenPenyesuaian(StokOpname $opname, $user, array $selisihRows): void
    {
        $penyesuaian = PenyesuaianStok::create([
            'kode'                => CodeGenerator::generateAdj(),
            'stok_opname_id'      => $opname->id,
            'gudang_id'           => $opname->gudang_id,
            'tanggal_penyesuaian' => now()->toDateString(),
            'alasan'              => "Selisih perhitungan fisik Stock Opname {$opname->kode}",
            'status'              => StatusPenyesuaianStok::Menunggu->value,
            'diajukan_oleh'       => $user->id,
        ]);

        foreach ($selisihRows as $sr) {
            PenyesuaianStokDetail::create([
                'penyesuaian_stok_id' => $penyesuaian->id,
                'barang_id'           => $sr['barang_id'],
                'stok_batch_id'       => $sr['stok_batch_id'],
                'jumlah_penyesuaian'  => $sr['jumlah'],
                'jenis_penyesuaian'   => $sr['jenis'],
                'harga_satuan'        => $sr['harga_satuan'],
                'subtotal_nilai'      => $sr['subtotal'],
                'catatan'             => $sr['catatan'],
            ]);
        }
    }
}
