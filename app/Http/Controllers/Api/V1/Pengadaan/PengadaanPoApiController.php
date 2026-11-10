<?php

namespace App\Http\Controllers\Api\V1\Pengadaan;

use App\Enums\Pengadaan\StatusPermintaanPengadaan;
use App\Enums\Pengadaan\StatusPesananPembelian;
use App\Http\Controllers\Api\ApiController;
use App\Models\Master\Barang;
use App\Models\Master\Pemasok;
use App\Models\Pengadaan\PermintaanPengadaan;
use App\Models\Pengadaan\PesananPembelian;
use App\Models\Pengadaan\PesananPembelianDetail;
use App\Support\Generator\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Pemasok, penerbitan & pelacakan Purchase Order (PO) vendor.
 */
class PengadaanPoApiController extends ApiController
{
    /**
     * Daftar pemasok/rekanan aktif untuk dropdown penerbitan PO.
     * GET /v1/pengadaan/pemasok
     */
    public function daftarPemasok(Request $request): JsonResponse
    {
        $pemasok = Pemasok::where('status', 'AKTIF')
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama', 'kota', 'no_telepon', 'alamat']);

        return $this->jsonSuccess($pemasok, 'Daftar rekanan pemasok berhasil dimuat.');
    }

    /**
     * Daftar seluruh dokumen Pesanan Pembelian (PO).
     * GET /v1/pengadaan/po
     */
    public function daftarPo(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGlobal = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('view_any_pengadaan::pesanan::pembelian') ||
            $user->can('view_any_gudang_stok::penerimaan::po')
        );

        $isUnitUser = $user && $user->hasRole(['kepala_unit', 'petugas_unit']);

        if (! $isGlobal && (! $isUnitUser || ! $user->unit_id)) {
            return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat daftar PO.', 403);
        }
        $status = $request->query('status');
        $search = $request->query('search');

        $query = PesananPembelian::with([
            'pemasok:id,kode,nama,kota,no_telepon',
            'permintaanPengadaan.unit:id,nama',
            'pembuat:id,nama',
            'detail.barang.satuan',
        ])

        ->when($status, fn ($q) => $q->where('status', $status))
        ->when($search, function ($q) use ($search) {
            $q->where(function ($sub) use ($search) {
                $sub->where('kode', 'like', "%{$search}%")
                    ->orWhereHas('pemasok', fn ($p) => $p->where('nama', 'like', "%{$search}%"));
            });
        });

        if (! $isGlobal) {
            $query->whereHas('permintaanPengadaan', fn ($q) => $q->where('unit_id', $user->unit_id));
        }

        $pos = $query->limit(100)->get()->map(function ($po) {
            return [
                'id'                      => $po->id,
                'kode'                    => $po->kode,
                'pemasok_id'              => $po->pemasok_id,
                'nama_pemasok'            => $po->pemasok?->nama ?? 'Vendor',
                'kode_pemasok'            => $po->pemasok?->kode ?? '-',
                'permintaan_pengadaan_id' => $po->permintaan_pengadaan_id,
                'pr_kode'                 => $po->permintaanPengadaan?->kode,
                'pr_unit'                 => $po->permintaanPengadaan?->unit?->nama ?? 'Unit',
                'tanggal_po'              => $po->tanggal_po?->format('Y-m-d') ?? '-',
                'estimasi_tanggal_kirim'  => $po->estimasi_tanggal_kirim?->format('Y-m-d') ?? '-',
                'total_biaya'             => (float) ($po->total_biaya ?? 0),
                'syarat_pembayaran'       => $po->syarat_pembayaran,
                'status'                  => $po->status,
                'catatan'                 => $po->catatan,
                'pembuat'                 => $po->pembuat?->nama ?? 'Staf Pengadaan',
                'total_item'              => $po->detail->count(),
                'items'                   => $po->detail->map(fn ($d) => [
                    'id'               => $d->id,
                    'barang_id'        => $d->barang_id,
                    'nama_barang'      => $d->barang?->nama ?? 'Barang',
                    'satuan'           => $d->barang?->satuan?->nama ?? 'Pcs',
                    'jumlah_dipesan'   => (int) $d->jumlah_dipesan,
                    'jumlah_diterima'  => (int) ($d->jumlah_diterima ?? 0),
                    'harga_satuan'     => (float) ($d->harga_satuan ?? 0),
                    'subtotal'         => (float) ($d->subtotal ?? 0),
                ]),
            ];
        });

        return $this->jsonSuccess($pos, 'Daftar pesanan pembelian (PO) berhasil dimuat.');
    }

    /**
     * Detail lengkap satu dokumen PO.
     * GET /v1/pengadaan/po/{id}
     */
    public function detailPo(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $hasPoAccess = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('view_any_pengadaan::pesanan::pembelian') ||
            $user->can('view_any_gudang_stok::penerimaan::po')
        );

        if (! $hasPoAccess) {
            return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat dokumen PO ini.', 403);
        }

        $po = PesananPembelian::with([
            'pemasok',
            'permintaanPengadaan.unit',
            'pembuat',
            'detail.barang.satuan',
        ])->find($id);

        if (! $po) {
            return $this->jsonError('Dokumen PO tidak ditemukan.', 404);
        }


        return $this->jsonSuccess([
            'id'                      => $po->id,
            'kode'                    => $po->kode,
            'pemasok'                 => $po->pemasok ? [
                'id'       => $po->pemasok->id,
                'kode'     => $po->pemasok->kode,
                'nama'     => $po->pemasok->nama,
                'kota' => $po->pemasok->kota,
                'telepon'  => $po->pemasok->no_telepon,
                'alamat'   => $po->pemasok->alamat,
            ] : null,
            'pr_kode'                 => $po->permintaanPengadaan?->kode,
            'pr_unit'                 => $po->permintaanPengadaan?->unit?->nama ?? '-',
            'tanggal_po'              => $po->tanggal_po?->format('Y-m-d') ?? '-',
            'estimasi_tanggal_kirim'  => $po->estimasi_tanggal_kirim?->format('Y-m-d') ?? '-',
            'total_biaya'             => (float) ($po->total_biaya ?? 0),
            'syarat_pembayaran'       => $po->syarat_pembayaran,
            'status'                  => $po->status,
            'catatan'                 => $po->catatan,
            'pembuat'                 => $po->pembuat?->nama ?? '-',
            'items'                   => $po->detail->map(fn ($d) => [
                'id'               => $d->id,
                'barang_id'        => $d->barang_id,
                'nama_barang'      => $d->barang?->nama ?? 'Barang',
                'satuan'           => $d->barang?->satuan?->nama ?? 'Pcs',
                'jumlah_dipesan'   => (int) $d->jumlah_dipesan,
                'jumlah_diterima'  => (int) ($d->jumlah_diterima ?? 0),
                'harga_satuan'     => (float) ($d->harga_satuan ?? 0),
                'subtotal'         => (float) ($d->subtotal ?? 0),
            ]),
        ], 'Detail PO berhasil dimuat.');
    }

    /**
     * Penerbitan dokumen PO baru dari PR yang statusnya sudah diserahkan ke pengadaan (DISETUJUI).
     * POST /v1/pengadaan/po
     */
    public function terbitkanPo(Request $request): JsonResponse
    {
        $user = $request->user();

        $canManage = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('create_pengadaan::pesanan::pembelian')
        );

        if (! $canManage) {
            return $this->jsonError('Hanya bagian pengadaan dan admin logistik yang berwenang menerbitkan PO.', 403);
        }

        $validator = Validator::make($request->all(), [
            'permintaan_pengadaan_id' => 'required|exists:permintaan_pengadaan,id',
            'pemasok_id'              => 'required|exists:pemasok,id',
            'estimasi_tanggal_kirim'  => 'nullable|date',
            'syarat_pembayaran'       => 'required|in:TEMPO_30_HARI,TEMPO_60_HARI,TUNAI',
            'catatan'                 => 'nullable|string|max:1000',
            'items'                   => 'required|array|min:1',
            'items.*.barang_id'       => 'required|exists:barang,id',
            'items.*.jumlah'          => 'required|integer|min:1',
            'items.*.harga_satuan'    => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi penerbitan PO gagal.', 422, $validator->errors());
        }

        $pr = PermintaanPengadaan::with('detail')->find($request->input('permintaan_pengadaan_id'));
        if (! $pr) {
            return $this->jsonError('Dokumen PR tidak ditemukan.', 404);
        }

        if (! in_array($pr->status, StatusPermintaanPengadaan::siapPo())) {
            return $this->jsonError("Hanya PR yang telah diserahkan ke pengadaan yang dapat diterbitkan PO (saat ini: {$pr->status}).", 422);
        }

        $errorItem = $this->validasiItemTerhadapPr($pr, $request->input('items'));
        if ($errorItem) {
            return $errorItem;
        }

        try {
            $po = $this->buatPoDariRequest($request, $user, $pr);

            return $this->jsonSuccess([
                'id'          => $po->id,
                'kode'        => $po->kode,
                'status'      => $po->status,
                'total_biaya' => (float) $po->total_biaya,
            ], "Pesanan Pembelian {$po->kode} berhasil diterbitkan.", 201);
        } catch (\Throwable $e) {
            return $this->jsonError('Gagal menerbitkan PO: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Validasi item PO terhadap detail PR (keterdaftaran barang dan batas kuantitas disetujui).
     */
    private function validasiItemTerhadapPr(PermintaanPengadaan $pr, array $items): ?JsonResponse
    {
        $prDetailsByBarang = $pr->detail->keyBy('barang_id');
        foreach ($items as $row) {
            $barangId = $row['barang_id'];
            $qty = (int) $row['jumlah'];

            $prDetail = $prDetailsByBarang->get($barangId);
            if (! $prDetail) {
                return $this->jsonError("Barang dengan ID {$barangId} tidak terdaftar pada dokumen PR {$pr->kode}.", 422);
            }

            $maxAllowed = (int) ($prDetail->jumlah_disetujui ?? $prDetail->jumlah);
            if ($qty > $maxAllowed) {
                return $this->jsonError("Jumlah barang ({$qty}) melebihi kuantitas yang disetujui pada PR ({$maxAllowed}).", 422);
            }
        }

        return null;
    }

    /**
     * Buat header + detail PO dan tandai PR sebagai DIPROSES dalam satu transaksi.
     */
    private function buatPoDariRequest(Request $request, $user, PermintaanPengadaan $pr): PesananPembelian
    {
        return DB::transaction(function () use ($request, $user, $pr) {
            $totalBiaya = 0;
            foreach ($request->input('items') as $row) {
                $totalBiaya += ((int) $row['jumlah']) * ((float) $row['harga_satuan']);
            }

            $kodePo = CodeGenerator::generatePo();

            $poHeader = PesananPembelian::create([
                'kode'                    => $kodePo,
                'pemasok_id'              => $request->input('pemasok_id'),
                'permintaan_pengadaan_id' => $pr->id,
                'tanggal_po'              => now()->toDateString(),
                'estimasi_tanggal_kirim'  => $request->input('estimasi_tanggal_kirim'),
                'total_biaya'             => $totalBiaya,
                'syarat_pembayaran'       => $request->input('syarat_pembayaran'),
                'status'                  => StatusPesananPembelian::Diterbitkan->value,
                'catatan'                 => $request->input('catatan'),
                'dibuat_oleh'             => $user->id,
            ]);

            foreach ($request->input('items') as $row) {
                $subtotal = ((int) $row['jumlah']) * ((float) $row['harga_satuan']);

                PesananPembelianDetail::create([
                    'pesanan_pembelian_id' => $poHeader->id,
                    'barang_id'            => $row['barang_id'],
                    'jumlah_dipesan'       => $row['jumlah'],
                    'jumlah_diterima'      => 0,
                    'harga_satuan'         => $row['harga_satuan'],
                    'subtotal'             => $subtotal,
                ]);
            }

            // Update status PR menjadi DIPROSES
            $pr->update(['status' => StatusPermintaanPengadaan::Diproses->value]);

            return $poHeader;
        });
    }

    /**
     * Update status PO yang sudah diterbitkan menjadi DIKIRIM_VENDOR.
     * POST /v1/pengadaan/po/{id}/kirim
     */
    public function updateStatusKirim(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $canManage = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('update_pengadaan::pesanan::pembelian')
        );

        if (! $canManage) {
            return $this->jsonError('Hanya bagian pengadaan dan admin logistik yang berwenang memperbarui status pengiriman PO.', 403);
        }

        $po = PesananPembelian::with('pemasok')->find($id);
        if (! $po) {
            return $this->jsonError('Dokumen PO tidak ditemukan.', 404);
        }

        if ($po->status !== StatusPesananPembelian::Diterbitkan->value) {
            return $this->jsonError("Hanya PO dengan status DITERBITKAN yang dapat diubah menjadi DIKIRIM_VENDOR (saat ini: {$po->status}).", 422);
        }

        $estimasiBaru = $request->input('estimasi_tanggal_kirim', $po->estimasi_tanggal_kirim);

        $po->update([
            'status'                 => StatusPesananPembelian::DikirimVendor->value,
            'estimasi_tanggal_kirim' => $estimasiBaru,
        ]);

        return $this->jsonSuccess([
            'id'                     => $po->id,
            'kode'                   => $po->kode,
            'status'                 => $po->status,
            'estimasi_tanggal_kirim' => $po->estimasi_tanggal_kirim?->format('Y-m-d'),
        ], "Status PO {$po->kode} berhasil diubah menjadi DIKIRIM_VENDOR (dalam pengiriman rekanan).");
    }
}
