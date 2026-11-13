<?php

namespace App\Http\Controllers\Api\V1\Gudang;

use App\Enums\Pengadaan\StatusPenerimaanPo;
use App\Enums\Pengadaan\StatusPermintaanPengadaan;
use App\Enums\Pengadaan\StatusPesananPembelian;
use App\Http\Controllers\Api\ApiController;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\LokasiGudang;
use App\Models\Pengadaan\PenerimaanBarang;
use App\Models\Pengadaan\PenerimaanBarangDetail;
use App\Models\Persediaan\PergerakanStok;
use App\Models\Pengadaan\PesananPembelian;
use App\Models\Persediaan\StokBatch;
use App\Models\User;
use App\Support\Generator\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GudangPenerimaanPoApiController extends ApiController
{
    /**
     * Daftar PO siap diterima di gudang (Inbound Pending).
     * GET /v1/gudang/po-inbound
     */
    public function poInbound(Request $request): JsonResponse
    {
        $user = $request->user();

        $canView = $user->can('view_any_gudang_stok::penerimaan::po') ||
            $user->can('view_gudang_stok::penerimaan::po') ||
            $user->hasRole(['super_admin', 'admin_logistik']);

        if (! $canView) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk melihat daftar penerimaan PO.', 403);
        }

        $status = $request->query('status');
        $search = $request->query('search');

        $query = PesananPembelian::query()
            ->when($status && $status !== 'SEMUA', fn ($q) => $q->where('status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('kode', 'like', "%{$search}%")
                        ->orWhereHas('pemasok', fn ($p) => $p->where('nama', 'like', "%{$search}%"));
                });
            })
            ->with([
                'pemasok:id,kode,nama',
                'detail.barang:id,kode,nama,satuan_id,kategori_id',
                'detail.barang.satuan:id,nama',
            ])
            ->latest('id');

        $pos = $query->limit(100)
            ->get()
            ->map(function ($po) {
                return [
                    'id'                     => $po->id,
                    'kode_po'                => $po->kode,
                    'pemasok'                => $po->pemasok?->nama ?? '-',
                    'tanggal_po'             => $po->tanggal_po?->format('Y-m-d') ?? '',
                    'estimasi_tanggal_kirim' => $po->estimasi_tanggal_kirim?->format('Y-m-d'),
                    'status'                 => $po->status,
                    'total_item'             => $po->detail->count(),
                    'items'                  => $po->detail->map(function ($d) {
                        $sisa = max(0, (int) $d->jumlah_dipesan - (int) $d->jumlah_diterima);
                        return [
                            'id'                   => $d->id,
                            'barang_id'            => $d->barang_id,
                            'kode_barang'          => $d->barang?->kode ?? '-',
                            'nama_barang'          => $d->barang?->nama ?? 'Barang',
                            'satuan'               => $d->barang?->satuan?->nama ?? 'Pcs',
                            'jumlah_dipesan'       => (int) $d->jumlah_dipesan,
                            'jumlah_sudah_diterima'=> (int) $d->jumlah_diterima,
                            'sisa_kebutuhan'       => $sisa,
                            'harga_satuan'         => (float) $d->harga_satuan,
                        ];
                    }),
                ];
            });

        return $this->jsonSuccess($pos, 'Daftar PO inbound siap terima berhasil dimuat.');
    }

    /**
     * Konfirmasi penerimaan fisik barang dari vendor dan alokasi ke rak gudang.
     * POST /v1/gudang/penerimaan-po
     */
    public function simpanPenerimaanPo(Request $request): JsonResponse
    {
        $user = $request->user();

        $canReceive = $user->can('create_gudang_stok::penerimaan::po') ||
            $user->hasRole(['super_admin', 'admin_logistik']);

        if (! $canReceive) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk melakukan penerimaan PO gudang.', 403);
        }

        $isGlobalAdmin = $user->hasRole(['super_admin', 'admin_logistik']);
        if (! $user->gudang_id && ! $isGlobalAdmin) {
            return $this->jsonError('Akun Anda belum memiliki penugasan fasilitas gudang penerima resmi.', 403);
        }

        $validator = $this->validatorPenerimaanPo($request);

        if ($validator->fails()) {
            return $this->jsonError('Validasi data penerimaan gagal.', 422, $validator->errors());
        }

        $po = PesananPembelian::with('detail')->find($request->input('pesanan_pembelian_id'));
        if (! $po) {
            return $this->jsonError('Data PO tidak ditemukan.', 404);
        }

        if (! in_array($po->status, StatusPesananPembelian::bisaDiterima())) {
            return $this->jsonError("PO {$po->kode} belum dikirim oleh vendor atau tidak dalam status dapat diterima (saat ini: {$po->status}).", 422);
        }
        $gudangId = (int) ($user->gudang_id ?? $request->input('gudang_id'));
        if ($user->gudang_id && $request->has('gudang_id') && (int) $request->input('gudang_id') !== $gudangId) {
            return $this->jsonError('Gudang penerima harus sesuai dengan penugasan akun Anda.', 403);
        }

        $hasReceived = false;
        foreach ($request->input('items') as $row) {
            if ((int) $row['jumlah_diterima'] > 0) {
                $hasReceived = true;
                break;
            }
        }

        if (! $hasReceived) {
            return $this->jsonError('Minimal satu komoditas barang harus memiliki jumlah diterima > 0.', 422);
        }

        try {
            $this->validasiAlokasiPenerimaan($request, $gudangId);
            $penerimaan = $this->eksekusiPenerimaanPo($request, $user, $po, $gudangId);

            return $this->jsonSuccess([
                'id'       => $penerimaan->id,
                'kode_rcv' => $penerimaan->kode,
                'status'   => $penerimaan->status,
            ], "Penerimaan fisik barang {$penerimaan->kode} berhasil disimpan ke gudang.", 201);
        } catch (ValidationException $e) {
            return $this->jsonError($e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->jsonError('Gagal memproses penerimaan barang: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Tolak penerimaan fisik barang PO dari vendor.
     * POST /v1/gudang/penerimaan-po/tolak
     */
    public function tolakPenerimaanPo(Request $request): JsonResponse
    {
        $user = $request->user();

        $canReject = $user->can('create_gudang_stok::penerimaan::po') ||
            $user->hasRole(['super_admin', 'admin_logistik']);

        if (! $canReject) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk menolak penerimaan kiriman PO.', 403);
        }

        $validator = Validator::make($request->all(), [
            'pesanan_pembelian_id' => 'required|integer|exists:pesanan_pembelian,id',
            'nomor_surat_jalan'    => 'required|string|min:3|max:100',
            'alasan_penolakan'     => 'required|string|min:5|max:1000',
            'items'                => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validasi data penolakan gagal.', 422, $validator->errors());
        }

        $po = PesananPembelian::find($request->input('pesanan_pembelian_id'));
        if (! in_array($po->status, StatusPesananPembelian::bisaDiterima(), true)) {
            return $this->jsonError('PO tidak dalam status yang dapat menerima dokumen penolakan (saat ini: ' . $po->status . ').', 422);
        }

        $gudangId = $user->gudang_id;
        if (! $gudangId && $user->hasRole(['super_admin', 'admin_logistik'])) {
            $gudangId = $request->input('gudang_id') ?? \App\Models\Master\Gudang::where('status', 'AKTIF')->value('id');
        }

        if (! $gudangId) {
            return $this->jsonError('Akun Anda belum memiliki penugasan fasilitas gudang penerima resmi.', 403);
        }

        $service = app(\App\Services\Pengadaan\PenerimaanPoService::class);
        $rcvKode = $service->tolakKiriman($po->id, (int) $gudangId, $request->input('nomor_surat_jalan'), $request->input('alasan_penolakan'), $user);

        if (! $rcvKode) {
            return $this->jsonError('Pesanan pembelian sudah tidak dalam status yang dapat menerima dokumen penolakan. Muat ulang data PO.', 422);
        }

        return $this->jsonSuccess([
            'kode_rcv' => $rcvKode,
            'status'   => 'DITOLAK',
        ], 'Kiriman fisik PO berhasil ditolak. Berita acara penolakan diterbitkan dengan nomor ' . $rcvKode . '.');
    }

    protected function eksekusiPenerimaanPo(Request $request, User $user, PesananPembelian $po, int $gudangId): PenerimaanBarang
    {
        return DB::transaction(function () use ($request, $user, $po, $gudangId) {
            $lockedPo = PesananPembelian::with('detail')->lockForUpdate()->findOrFail($po->id);

            if (! in_array($lockedPo->status, StatusPesananPembelian::bisaDiterima(), true)) {
                throw ValidationException::withMessages(['po' => "PO {$lockedPo->kode} sudah tidak dalam status dapat diterima."]);
            }

            // Validasi sisa kuantitas pesanan yang belum diterima di bawah lock
            $terimaPerDetail = [];
            foreach ($request->input('items') as $row) {
                $qty = (int) ($row['jumlah_diterima'] ?? 0);
                if ($qty <= 0) continue;

                $poDetail = $lockedPo->detail->firstWhere('barang_id', $row['barang_id']);
                if (! $poDetail) {
                    throw ValidationException::withMessages(['items' => "Barang ID {$row['barang_id']} tidak terdaftar dalam rincian PO {$lockedPo->kode}."]);
                }

                $rusak = (int) ($row['jumlah_rusak'] ?? 0);
                if ($rusak < 0 || $rusak > $qty) {
                    throw ValidationException::withMessages(['items' => "Jumlah rusak tidak valid untuk barang {$poDetail->barang?->nama}."]);
                }

                $terimaPerDetail[$poDetail->id] = ($terimaPerDetail[$poDetail->id] ?? 0) + $qty;
                $sisaTerkunci = max(0, (int) $poDetail->jumlah_dipesan - (int) $poDetail->jumlah_diterima);
                if ($terimaPerDetail[$poDetail->id] > $sisaTerkunci) {
                    throw ValidationException::withMessages(['items' => "Jumlah penerimaan komoditas barang melebihi sisa pesanan terkini ({$sisaTerkunci})."]);
                }
            }

            // Pertahankan urutan lock PO, lalu tahan master gudang/rak sampai seluruh stok tercatat.
            $this->validasiAlokasiPenerimaan($request, $gudangId, true);

            $kodeRcv = CodeGenerator::generate('RCV', PenerimaanBarang::class, 'kode', 'Ym', 3);

            // 1. Update jumlah diterima di pesanan_pembelian_detail
            $this->tambahJumlahDiterimaPoDetail($request, $lockedPo);

            // 2. Evaluasi status PO
            $lockedPo->refresh();
            $lockedPo->load('detail');
            $allReceived = $lockedPo->detail->every(fn ($d) => (int) $d->jumlah_diterima >= (int) $d->jumlah_dipesan);
            $statusRcv = $allReceived ? StatusPenerimaanPo::Lengkap->value : StatusPenerimaanPo::Sebagian->value;
            $statusPo  = match (true) {
                $allReceived => StatusPesananPembelian::DiterimaLengkap->value,
                default => StatusPesananPembelian::DiterimaSebagian->value,
            };

            // 3. Buat dokumen penerimaan barang
            $rcv = PenerimaanBarang::create([
                'kode'                 => $kodeRcv,
                'pesanan_pembelian_id' => $lockedPo->id,
                'gudang_id'            => $gudangId,
                'nomor_surat_jalan'    => $request->input('nomor_surat_jalan'),
                'tanggal_terima'       => now()->toDateString(),
                'status'               => $statusRcv,
                'catatan'              => $request->input('catatan'),
                'diterima_oleh'        => $user->id,
            ]);

            // 4. Catat detail & stok batch
            $this->catatDetailDanStokBatch($request, $user, $lockedPo, $rcv, $gudangId);

            // 5. Update status PO
            $lockedPo->update(['status' => $statusPo]);

            // 6. Jika seluruh barang PO telah diterima lengkap, update status PR induk menjadi SELESAI
            if ($statusPo === StatusPesananPembelian::DiterimaLengkap->value && $lockedPo->permintaan_pengadaan_id) {
                $lockedPo->permintaanPengadaan()->update(['status' => StatusPermintaanPengadaan::Selesai->value]);
            }
            return $rcv;
        });
    }

    protected function tambahJumlahDiterimaPoDetail(Request $request, PesananPembelian $po): void
    {
        foreach ($request->input('items') as $row) {
            $qty = (int) $row['jumlah_diterima'];
            $rusak = (int) ($row['jumlah_rusak'] ?? 0);
            $net = max(0, $qty - $rusak);
            if ($qty <= 0) continue;

            $poDetail = $po->detail->firstWhere('barang_id', $row['barang_id']);
            if ($poDetail) {
                if ($net > 0) {
                    $poDetail->increment('jumlah_diterima', $net);
                }

                if ((float) $poDetail->harga_satuan > 0) {
                    Barang::where('id', $row['barang_id'])->update([
                        'harga_beli_terakhir' => $poDetail->harga_satuan,
                    ]);
                }
            }
        }
    }

    protected function catatDetailDanStokBatch(Request $request, User $user, PesananPembelian $po, PenerimaanBarang $rcv, int $gudangId): void
    {
        foreach ($request->input('items') as $row) {
            $qty = (int) $row['jumlah_diterima'];
            $rusak = (int) ($row['jumlah_rusak'] ?? 0);
            $net = max(0, $qty - $rusak);

            if ($qty <= 0) continue;

            $poDetail = $po->detail->firstWhere('barang_id', $row['barang_id']);
            $hargaSatuan = $poDetail ? $poDetail->harga_satuan : 0;

            PenerimaanBarangDetail::create([
                'penerimaan_barang_id' => $rcv->id,
                'barang_id'            => $row['barang_id'],
                'lokasi_gudang_id'     => $row['lokasi_gudang_id'],
                'nomor_batch'          => $row['nomor_batch'],
                'tanggal_kedaluwarsa'  => $row['tanggal_kedaluwarsa'] ?? null,
                'jumlah_diterima'      => $qty,
                'jumlah_rusak'         => $rusak,
                'harga_satuan'         => $hargaSatuan,
            ]);

            if ($net > 0) {
                $batch = StokBatch::create([
                    'barang_id'           => $row['barang_id'],
                    'gudang_id'           => $gudangId,
                    'lokasi_gudang_id'    => $row['lokasi_gudang_id'],
                    'nomor_batch'         => $row['nomor_batch'],
                    'tanggal_kedaluwarsa' => $row['tanggal_kedaluwarsa'] ?? null,
                    'jumlah'              => $net,
                    'harga_satuan'        => $hargaSatuan,
                    'status'              => 'AKTIF',
                ]);

                PergerakanStok::create([
                    'barang_id'        => $row['barang_id'],
                    'stok_batch_id'    => $batch->id,
                    'gudang_id'        => $gudangId,
                    'lokasi_gudang_id' => $row['lokasi_gudang_id'],
                    'jenis'            => 'MASUK',
                    'jumlah'           => $net,
                    'saldo_sebelum'    => 0,
                    'saldo_sesudah'    => $net,
                    'referensi_jenis'  => 'PENERIMAAN_PO',
                    'referensi_id'     => $rcv->id,
                    'keterangan'       => "Penerimaan PO {$po->kode} - Batch {$row['nomor_batch']}",
                    'dibuat_oleh'      => $user->id,
                ]);
            }
        }
    }

    private function validatorPenerimaanPo(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'pesanan_pembelian_id'              => 'required|exists:pesanan_pembelian,id',
            'gudang_id'                        => [$request->user()->gudang_id ? 'sometimes' : 'required', 'integer'],
            'nomor_surat_jalan'                 => 'required|string|max:100',
            'catatan'                           => 'nullable|string|max:1000',
            'items'                             => 'required|array|min:1',
            'items.*.barang_id'                 => 'required|exists:barang,id',
            'items.*.lokasi_gudang_id'          => 'required|exists:lokasi_gudang,id',
            'items.*.nomor_batch'               => 'required|string|max:100',
            'items.*.tanggal_kedaluwarsa'       => 'nullable|date',
            'items.*.jumlah_diterima'           => 'required|integer|min:0',
            'items.*.jumlah_rusak'              => 'nullable|integer|min:0',
        ], [
            'nomor_surat_jalan.required'        => 'Nomor surat jalan vendor wajib diisi.',
            'gudang_id.required'                => 'Gudang penerima aktif wajib dipilih secara eksplisit.',
            'gudang_id.integer'                 => 'Gudang penerima harus berupa ID yang valid.',
            'items.required'                    => 'Minimal satu barang harus dicatat penerimaannya.',
            'items.*.lokasi_gudang_id.required' => 'Rak penyimpanan fisik wajib dipilih.',
            'items.*.nomor_batch.required'      => 'Nomor batch barang wajib diisi.',
        ]);
    }

    private function validasiAlokasiPenerimaan(Request $request, int $gudangId, bool $lock = false): void
    {
        $gudangQuery = Gudang::whereKey($gudangId);
        if ($lock) {
            $gudangQuery->sharedLock();
        }
        $gudang = $gudangQuery->first(['id', 'status']);
        if (! $gudang || $gudang->status !== 'AKTIF') {
            throw ValidationException::withMessages([
                'gudang_id' => 'Gudang penerima tidak ditemukan atau tidak aktif. Pilih gudang penerima aktif.',
            ]);
        }

        $rackIds = collect($request->input('items'))->pluck('lokasi_gudang_id')->unique()->values();
        $rackQuery = LokasiGudang::whereIn('id', $rackIds)->orderBy('id');
        if ($lock) {
            $rackQuery->sharedLock();
        }
        $racks = $rackQuery->get(['id', 'gudang_id', 'status']);
        if ($racks->count() !== $rackIds->count() || $racks->contains(
            fn ($rack) => (int) $rack->gudang_id !== $gudangId || $rack->status !== 'AKTIF'
        )) {
            throw ValidationException::withMessages([
                'lokasi_gudang_id' => 'Alokasi rak tidak valid. Setiap rak harus aktif dan berada di gudang penerima yang sama.',
            ]);
        }
    }
}
