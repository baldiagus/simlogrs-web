<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Enums\Aset\StatusAset;
use App\Http\Controllers\Api\ApiController;
use App\Models\Aset\Aset;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\KategoriBarang;
use App\Models\Persediaan\StokBatch;
use App\Models\Distribusi\StokRuangan;
use App\Models\Master\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterApiController extends ApiController
{
    /**
     * Daftar seluruh master komoditas logistik dengan filter & status stok fisik.
     * GET /v1/master/barang-lengkap
     */
    public function barangLengkap(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewFinancials = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('view_any_pengadaan::pesanan::pembelian') ||
            $user->can('view_any_pengadaan::realisasi::anggaran')
        );

        $tipe = $request->query('tipe');
        $kategoriId = $request->query('kategori_id');
        $status = $request->query('status');
        $cari = $request->query('cari');
        $query = Barang::query()
            ->with(['kategori:id,nama', 'satuan:id,nama,simbol', 'merek:id,nama', 'pemasokDefault:id,nama'])
            ->when($tipe, fn ($q) => $q->where('tipe', $tipe))
            ->when($kategoriId, fn ($q) => $q->where('kategori_id', $kategoriId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($cari, function ($q) use ($cari) {
                $q->where(function ($sub) use ($cari) {
                    $sub->where('nama', 'ilike', "%{$cari}%")
                        ->orWhere('kode', 'ilike', "%{$cari}%");
                });
            });

        $items = $query->orderBy('nama')->limit(100)->get()->map(function ($b) use ($canViewFinancials) {
            $tipeLabel = match ($b->tipe) {
                'ASET'      => 'Aktiva / Aset Inventaris',
                'SPAREPART' => 'Suku Cadang IPSRS',
                default     => 'Habis Pakai (ATK/ART)',
            };

            $stokGudang = (int) StokBatch::where('barang_id', $b->id)
                ->where('status', 'AKTIF')
                ->where('jumlah', '>', 0)
                ->sum('jumlah');

            $stokRuangan = $b->tipe === 'ASET'
                ? (int) Aset::where('barang_id', $b->id)->whereNotNull('unit_id')->where('status', '!=', StatusAset::Dihapuskan->value)->count()
                : (int) StokRuangan::where('barang_id', $b->id)->sum('jumlah');
            $totalAset = (int) Aset::where('barang_id', $b->id)->count();

            return [
                'id'                  => $b->id,
                'kode'                => $b->kode,
                'nama'                => $b->nama,
                'spesifikasi'         => $b->spesifikasi,
                'tipe'                => $b->tipe,
                'tipe_label'          => $tipeLabel,
                'kategori'            => $b->kategori?->nama ?? 'Umum',
                'satuan'              => $b->satuan?->nama ?? 'Pcs',
                'simbol_satuan'       => $b->satuan?->simbol ?? $b->satuan?->nama ?? 'Pcs',
                'merek'               => $b->merek?->nama ?? '-',
                'pemasok'             => $b->pemasokDefault?->nama ?? 'Belum ditentukan',
                'harga_beli_terakhir' => (float) ($b->harga_beli_terakhir ?? 0),
                'harga_estimasi'      => (float) ($b->harga_estimasi ?? 0),
                'stok_minimum'        => (int) ($b->stok_minimum ?? 0),
                'stok_maksimum'       => (int) ($b->stok_maksimum ?? 0),
                'titik_pesan'         => (int) ($b->titik_pesan ?? 0),
                'status'              => $b->status,
                'stok_gudang'         => $stokGudang,
                'stok_ruangan'        => $stokRuangan,
                'total_aset'          => $totalAset,
            ];
        });

        return $this->jsonSuccess($items, 'Daftar master barang berhasil dimuat.');
    }

    /**
     * Rincian lengkap satu komoditas barang beserta sebaran fisik (batch gudang / unit aset).
     * GET /v1/master/barang/{id}
     */
    public function detailBarang(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $canViewFinancials = $user && (
            $user->hasRole(['super_admin', 'admin_logistik']) ||
            $user->can('view_any_pengadaan::pesanan::pembelian') ||
            $user->can('view_any_pengadaan::realisasi::anggaran')
        );

        $barang = Barang::with([
            'satuan',
            'merek',
            'pemasokDefault',
            'stokBatch.gudang',
            'stokBatch.lokasiGudang',
            'stokRuangan.unit',
            'aset.unit',
            'aset.gudang',
            'aset.lokasiGudang',
        ])->find($id);

        if (! $barang) {
            return $this->jsonError('Data komoditas barang tidak ditemukan.', 404);
        }

        $tipeLabel = match ($barang->tipe) {
            'ASET'      => 'Aktiva / Aset Inventaris',
            'SPAREPART' => 'Suku Cadang IPSRS',
            default     => 'Habis Pakai (ATK/ART)',
        };

        [$daftarAset, $daftarBatch, $daftarRuangan] = $this->sebaranFisikBarang($barang);
        [$stokGudang, $stokRuangan, $totalAset] = $this->hitungStokBarang($barang);

        $data = [
            'id'                  => $barang->id,
            'kode'                => $barang->kode,
            'nama'                => $barang->nama,
            'spesifikasi'         => $barang->spesifikasi,
            'tipe'                => $barang->tipe,
            'tipe_label'          => $tipeLabel,
            'kategori'            => $barang->kategori?->nama ?? 'Umum',
            'satuan'              => $barang->satuan?->nama ?? 'Pcs',
            'simbol_satuan'       => $barang->satuan?->simbol ?? $barang->satuan?->nama ?? 'Pcs',
            'merek'               => $barang->merek?->nama ?? '-',
            'pemasok'             => $barang->pemasokDefault?->nama ?? 'Belum ditentukan',
            'harga_beli_terakhir' => (float) ($barang->harga_beli_terakhir ?? 0),
            'harga_estimasi'      => (float) ($barang->harga_estimasi ?? 0),
            'stok_minimum'        => (int) ($barang->stok_minimum ?? 0),
            'stok_maksimum'       => (int) ($barang->stok_maksimum ?? 0),
            'titik_pesan'         => (int) ($barang->titik_pesan ?? 0),
            'status'              => $barang->status,
            'stok_gudang'         => $stokGudang,
            'stok_ruangan'        => $stokRuangan,
            'total_aset'          => $totalAset,
            'daftar_aset'         => $daftarAset,
            'daftar_batch'        => $daftarBatch,
            'daftar_ruangan'      => $daftarRuangan,
        ];

        return $this->jsonSuccess($data, 'Detail profil komoditas barang berhasil dimuat.');
    }

    /**
     * Daftar aset fisik, batch gudang, dan sebaran stok ruangan untuk satu barang.
     */
    private function sebaranFisikBarang(Barang $barang): array
    {
        // Jika Tipe ASET: daftar fisik unit terdaftar
        $daftarAset = [];
        if ($barang->tipe === 'ASET') {
            $daftarAset = $barang->aset->map(function ($a) {
                $isRuangan = $a->unit_id !== null;
                $lokasi = $isRuangan
                    ? ($a->unit?->nama ?? 'Ruangan')
                    : ($a->gudang?->nama . ($a->lokasiGudang ? " (Rak: {$a->lokasiGudang->nama})" : ''));

                return [
                    'id'         => $a->id,
                    'kode_aset'  => $a->kode_aset,
                    'nomor_seri' => $a->nomor_seri ?? '-',
                    'kondisi'    => $a->kondisi,
                    'status'     => $a->status,
                    'lokasi'     => $lokasi,
                ];
            });
        }

        // Rincian batch fisik gudang & sebaran stok ruangan (berlaku untuk seluruh komoditas jika ada batch fisik)
        $daftarBatch = $barang->stokBatch->where('jumlah', '>', 0)->map(fn ($sb) => [
            'id'                  => $sb->id,
            'nomor_batch'         => $sb->nomor_batch,
            'gudang'              => $sb->gudang?->nama ?? 'Gudang Sentral',
            'rak'                 => $sb->lokasiGudang?->nama ?? $sb->lokasiGudang?->kode ?? '-',
            'tanggal_kedaluwarsa' => $sb->tanggal_kedaluwarsa?->format('Y-m-d'),
            'jumlah'              => (int) $sb->jumlah,
            'harga_satuan'        => (float) $sb->harga_satuan,
        ])->values();

        $daftarRuangan = $barang->stokRuangan->where('jumlah', '>', 0)->map(fn ($sr) => [
            'unit'   => $sr->unit?->nama ?? 'Ruangan',
            'jumlah' => (int) $sr->jumlah,
        ])->values();

        return [$daftarAset, $daftarBatch, $daftarRuangan];
    }

    /**
     * Total stok gudang, stok ruangan, dan jumlah aset terdaftar untuk satu barang.
     */
    private function hitungStokBarang(Barang $barang): array
    {
        $stokGudang = (int) StokBatch::where('barang_id', $barang->id)
            ->where('status', 'AKTIF')
            ->where('jumlah', '>', 0)
            ->sum('jumlah');

        $stokRuangan = $barang->tipe === 'ASET'
            ? (int) Aset::where('barang_id', $barang->id)->whereNotNull('unit_id')->where('status', '!=', StatusAset::Dihapuskan->value)->count()
            : (int) StokRuangan::where('barang_id', $barang->id)->sum('jumlah');
        $totalAset = (int) Aset::where('barang_id', $barang->id)->count();

        return [$stokGudang, $stokRuangan, $totalAset];
    }

    /**
     * Register master seluruh sarana fisik rumah sakit.
     * GET /v1/master/aset-lengkap
     */
    public function asetLengkap(Request $request): JsonResponse
    {
        $lokasiTipe = $request->query('lokasi_tipe'); // 'RUANGAN' atau 'GUDANG'
        $status = $request->query('status');
        $kondisi = $request->query('kondisi');
        $cari = $request->query('cari');

        $query = Aset::query()
            ->with(['barang.kategori:id,nama', 'barang.merek:id,nama', 'unit:id,kode,nama', 'gudang:id,kode,nama', 'lokasiGudang:id,kode,nama'])
            ->when($lokasiTipe === 'RUANGAN', fn ($q) => $q->whereNotNull('unit_id'))
            ->when($lokasiTipe === 'GUDANG', fn ($q) => $q->whereNotNull('gudang_id'))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($kondisi, fn ($q) => $q->where('kondisi', $kondisi))
            ->when($cari, function ($q) use ($cari) {
                $q->where(function ($sub) use ($cari) {
                    $sub->where('kode_aset', 'ilike', "%{$cari}%")
                        ->orWhere('nomor_seri', 'ilike', "%{$cari}%")
                        ->orWhereHas('barang', fn ($b) => $b->where('nama', 'ilike', "%{$cari}%"));
                });
            });

        $items = $query->orderBy('kode_aset')->limit(100)->get()->map(function ($a) {
            $isRuangan = $a->unit_id !== null;
            $lokasi = $isRuangan
                ? ($a->unit?->nama ?? 'Ruangan')
                : ($a->gudang?->nama . ($a->lokasiGudang ? " (Rak: {$a->lokasiGudang->nama})" : ''));

            return [
                'id'               => $a->id,
                'kode_aset'        => $a->kode_aset,
                'nomor_seri'       => $a->nomor_seri ?? '-',
                'nama_komoditas'   => $a->barang?->nama ?? 'Aset Fisik',
                'kategori'         => $a->barang?->kategori?->nama ?? 'Umum',
                'merek'            => $a->barang?->merek?->nama ?? '-',
                'lokasi_tipe'      => $isRuangan ? 'RUANGAN' : 'GUDANG',
                'lokasi_nama'      => $lokasi,
                'kondisi'          => $a->kondisi,
                'status'           => $a->status,
                'tanggal_perolehan'=> $a->tanggal_perolehan?->format('Y-m-d') ?? '-',
                'nilai_perolehan'  => (float) ($a->nilai_perolehan ?? 0),
            ];
        });

        return $this->jsonSuccess($items, 'Register aset fisik berhasil dimuat.');
    }

    /**
     * Daftar kategori barang untuk opsi filter.
     * GET /v1/master/kategori
     */
    public function daftarKategori(): JsonResponse
    {
        $kategoris = KategoriBarang::where('status', 'AKTIF')->orderBy('nama')->get(['id', 'kode', 'nama']);
        return $this->jsonSuccess($kategoris, 'Daftar kategori berhasil dimuat.');
    }

    /**
     * Daftar fasilitas gudang logistik aktif.
     * GET /v1/master/gudang
     */
    public function daftarGudang(): JsonResponse
    {
        $gudangs = Gudang::where('status', 'AKTIF')
            ->withCount(['stokBatch as active_batches_count' => fn ($q) => $q->where('status', 'AKTIF')])
            ->orderBy('id')
            ->get(['id', 'kode', 'nama', 'jenis', 'lokasi_gedung']);
        return $this->jsonSuccess($gudangs, 'Daftar gudang aktif berhasil dimuat.');
    }

    /**
     * Daftar unit ruangan aktif.
     * GET /v1/master/unit
     */
    public function daftarUnit(): JsonResponse
    {
        $units = Unit::where('status', 'AKTIF')
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama', 'jenis', 'lokasi_gedung']);
        return $this->jsonSuccess($units, 'Daftar unit aktif berhasil dimuat.');
    }
}
