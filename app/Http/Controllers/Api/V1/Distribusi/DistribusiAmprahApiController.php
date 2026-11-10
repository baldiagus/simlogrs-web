<?php

namespace App\Http\Controllers\Api\V1\Distribusi;

use App\Enums\Distribusi\StatusPermintaanDistribusi;
use App\Http\Controllers\Api\ApiController;
use App\Models\Aset\Aset;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Distribusi\PengirimanBarang;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Distribusi\PermintaanDistribusiDetail;
use App\Models\Master\Unit;
use App\Models\Persediaan\StokBatch;
use App\Models\User;
use App\Services\Distribusi\PickingScope;
use App\Support\Generator\CodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DistribusiAmprahApiController extends ApiController
{
    /**
     * Katalog komoditas barang siap amprah beserta informasi ketersediaan stok gudang.
     * GET /v1/distribusi/katalog-barang
     */
    public function katalogBarang(Request $request): JsonResponse
    {
        $cari = $request->query('cari');
        $hasExplicitGudang = $request->has('gudang_id') && !empty($request->query('gudang_id'));
        $gudangId = $hasExplicitGudang ? (int) $request->query('gudang_id') : null;

        $barangs = Barang::query()
            ->whereIn('tipe', ['HABIS_PAKAI', 'SPAREPART', 'ASET'])
            ->where('status', 'AKTIF')
            ->with(['kategori:id,nama', 'satuan:id,nama', 'merek:id,nama'])
            ->when($cari, function ($q) use ($cari) {
                $q->where(function ($sub) use ($cari) {
                    $sub->where('nama', 'like', "%{$cari}%")
                        ->orWhere('kode', 'like', "%{$cari}%");
                });
            })
            ->limit(50)
            ->get()
            ->map(function ($b) use ($hasExplicitGudang, $gudangId) {
                $stokGudangQuery = StokBatch::where('barang_id', $b->id)
                    ->where('status', 'AKTIF')
                    ->where('jumlah', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('tanggal_kedaluwarsa')
                            ->orWhere('tanggal_kedaluwarsa', '>=', now()->toDateString());
                    });

                $stokGudang = $hasExplicitGudang
                    ? (int) (clone $stokGudangQuery)->where('gudang_id', $gudangId)->sum('jumlah')
                    : (int) (clone $stokGudangQuery)->sum('jumlah');

                $bestBatch = (clone $stokGudangQuery)
                    ->groupBy('gudang_id')
                    ->selectRaw('gudang_id, sum(jumlah) as total')
                    ->orderByDesc('total')
                    ->first();

                $bestGudangId = $bestBatch ? (int) $bestBatch->gudang_id : null;
                $bestGudangNama = $bestGudangId ? \App\Models\Master\Gudang::where('id', $bestGudangId)->value('nama') : null;
                $bestGudangStok = (int) ($bestBatch?->total ?? 0);

                $tipeLabel = match ($b->tipe) {
                    'ASET'      => 'Aktiva / Aset Inventaris',
                    'SPAREPART' => 'Suku Cadang IPSRS',
                    default     => 'Habis Pakai (ATK/ART)',
                };

                return [
                    'id'               => $b->id,
                    'kode'             => $b->kode,
                    'nama'             => $b->nama,
                    'spesifikasi'      => $b->spesifikasi,
                    'tipe'             => $b->tipe,
                    'tipe_label'       => $tipeLabel,
                    'kategori'         => $b->kategori?->nama ?? 'Umum',
                    'satuan'           => $b->satuan?->nama ?? 'Pcs',
                    'merek'            => $b->merek?->nama ?? '-',
                    'stok_tersedia'    => $stokGudang,
                    'best_gudang_id'   => $bestGudangId,
                    'best_gudang_nama' => $bestGudangNama,
                    'best_gudang_stok' => $bestGudangStok,
                ];
            });

        return $this->jsonSuccess($barangs, 'Katalog barang amprah berhasil dimuat.');
    }

    /**
     * Riwayat daftar permintaan amprah ruangan milik unit pengguna.
     * GET /v1/distribusi/amprah
     */
    public function daftarAmprah(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->jsonError('Unauthenticated.', 401);
        }

        $filterStatus = $request->query('status'); // DIAJUKAN, DISETUJUI_KARU, DISETUJUI_GUDANG, SELESAI, DITOLAK
        $filterUrgensi = $request->query('urgensi');

        $query = PermintaanDistribusi::query()
            ->with([
                'unit:id,kode,nama',
                'gudang:id,kode,nama',
                'pengaju:id,nama',
                'penyetuju:id,nama',
                'detail.barang:id,kode,nama,satuan_id',
                'detail.barang.satuan:id,nama',
                'pengiriman:id,permintaan_distribusi_id,status',
            ])
            ->tap(fn ($q) => $this->applyReadScope($q, $user))
            ->when($filterStatus, fn ($q) => $q->where('status', $filterStatus))
            ->when($filterUrgensi, fn ($q) => $q->where('urgensi', $filterUrgensi));

        $list = $query->latest('id')
            ->limit(100)
            ->get()
            ->map(function ($item) use ($user) {
                return [
                    'id'                 => $item->id,
                    'kode'               => $item->kode,
                    'unit'               => $item->unit?->nama ?? 'Unit',
                    'gudang'             => $item->gudang?->nama ?? 'Gudang Sentral',
                    'pengaju'            => $item->pengaju?->nama ?? 'Staf',
                    'diajukan_oleh'      => (int) $item->diajukan_oleh,
                    'tanggal_permintaan' => $item->tanggal_permintaan?->format('Y-m-d') ?? '',
                    'urgensi'            => $item->urgensi,
                    'status'             => $item->status,
                    'catatan'            => $item->catatan,
                    'can_cancel'         => $this->canCancel($user, $item),
                    'can_tutup_sisa'     => $this->canTutupSisa($user, $item),
                    'jumlah_item'        => $item->detail->count(),
                    'items'              => $item->detail->map(fn ($d) => [
                        'id'               => $d->id,
                        'barang_id'        => $d->barang_id,
                        'nama_barang'      => $d->barang?->nama ?? 'Barang',
                        'kode_barang'      => $d->barang?->kode ?? '-',
                        'satuan'           => $d->barang?->satuan?->nama ?? 'Pcs',
                        'jumlah_diminta'   => (int) $d->jumlah,
                        'jumlah_disetujui' => $d->jumlah_disetujui !== null ? (int) $d->jumlah_disetujui : (int) $d->jumlah,
                        'stok_tersedia'    => $this->getStokTersediaGudang((int) $d->barang_id, $item->gudang_id ? (int) $item->gudang_id : null, (int) $item->id),
                        'catatan'          => $d->catatan,
                    ]),
                ];
            });

        return $this->jsonSuccess($list, 'Daftar amprah ruangan berhasil dimuat.');
    }

    /**
     * Detail lengkap satu permohonan amprah ruangan.
     * GET /v1/distribusi/amprah/{id}
     */
    public function detailAmprah(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->jsonError('Unauthenticated.', 401);
        }

        $amprah = PermintaanDistribusi::with([
            'unit',
            'gudang',
            'pengaju',
            'penyetuju',
            'detail.barang.satuan',
            'pengiriman.detail',
        ])->find($id);

        if (! $amprah) {
            return $this->jsonError('Data amprah tidak ditemukan.', 404);
        }

        if (! $this->canReadScoped($user, $amprah->unit_id, $amprah->gudang_id)) {
            return $this->jsonError('Akses ditolak: Anda tidak memiliki wewenang melihat dokumen amprah di luar unit/gudang Anda.', 403);
        }

        return $this->jsonSuccess([
            'id'                 => $amprah->id,
            'kode'               => $amprah->kode,
            'unit'               => $amprah->unit?->nama ?? '-',
            'gudang'             => $amprah->gudang?->nama ?? '-',
            'pengaju'            => $amprah->pengaju?->nama ?? '-',
            'diajukan_oleh'      => (int) $amprah->diajukan_oleh,
            'tanggal_permintaan' => $amprah->tanggal_permintaan?->format('Y-m-d'),
            'urgensi'            => $amprah->urgensi,
            'status'             => $amprah->status,
            'catatan'            => $amprah->catatan,
            'can_cancel'         => $this->canCancel($user, $amprah),
            'can_tutup_sisa'     => $this->canTutupSisa($user, $amprah),
            'items'              => $amprah->detail->map(fn ($d) => [
                'id'               => $d->id,
                'barang_id'        => $d->barang_id,
                'nama_barang'      => $d->barang?->nama ?? 'Barang',
                'kode_barang'      => $d->barang?->kode ?? '-',
                'satuan'           => $d->barang?->satuan?->nama ?? 'Pcs',
                'jumlah_diminta'   => (int) $d->jumlah,
                'jumlah_disetujui' => $d->jumlah_disetujui !== null ? (int) $d->jumlah_disetujui : (int) $d->jumlah,
                'stok_tersedia'    => $this->getStokTersediaGudang((int) $d->barang_id, $amprah->gudang_id ? (int) $amprah->gudang_id : null, (int) $amprah->id),
                'catatan'          => $d->catatan,
            ]),
        ], 'Detail amprah berhasil dimuat.');
    }

    /**
     * Pengajuan dokumen amprah ruangan baru (REQ-YYYYMM-XXX).
     * POST /v1/distribusi/amprah
     */
    public function simpanAmprah(Request $request): JsonResponse
    {
        $user = $request->user();

        $canAmprah = $user && $user->can('create_layanan_unit::permintaan::barang');

        if (! $canAmprah) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk mengajukan amprah barang ruangan.', 403);
        }

        if (! $user->unit_id && ! $user->gudang_id) {
            return $this->jsonError('Akun Anda belum terhubung dengan unit ruangan atau gudang. Hubungi administrator.', 403);
        }

        $unitId = $user->unit_id;
        if (! $unitId) {
            return $this->jsonError('Anda harus terdaftar pada unit/ruangan untuk mengajukan amprah.', 422);
        }

        $validator = $this->validatorAmprah($request);

        if ($validator->fails()) {
            return $this->jsonError('Validasi data gagal.', 422, $validator->errors());
        }

        // Auto-route ke gudang yang memiliki stok barang pertama jika gudang_id tidak dikirim
        $gudangId = $request->input('gudang_id');
        if (! $gudangId) {
            $firstBarangId = (int) ($request->input('items.0.barang_id') ?? 0);
            $gudangId = $firstBarangId
                ? StokBatch::where('barang_id', $firstBarangId)->where('status', 'AKTIF')->where('jumlah', '>', 0)->groupBy('gudang_id')->selectRaw('gudang_id, sum(jumlah) as total')->orderByDesc('total')->value('gudang_id')
                : null;
            $gudangId = $gudangId ?: Gudang::where('status', 'AKTIF')->value('id');
        }
        if (! $gudangId) {
            return $this->jsonError('Fasilitas gudang aktif belum tersedia.', 422);
        }
        // Komoditas dengan stok gudang kosong atau kurang tetap diizinkan diajukan via API
        // Selisih kebutuhan akan otomatis dicatat sebagai hutang gudang (backorder)

        $directToGudang = $user->bolehLangsungKeGudang();
        $statusAwal     = $directToGudang ? StatusPermintaanDistribusi::DisetujuiKaru->value : StatusPermintaanDistribusi::Diajukan->value;
        $disetujuiOleh  = $directToGudang ? $user->id : null;

        try {
            $amprah = DB::transaction(fn () => $this->buatDokumenAmprah($request, $user, $unitId, $gudangId, $statusAwal, $disetujuiOleh));

            return $this->jsonSuccess([
                'id'      => $amprah->id,
                'kode'    => $amprah->kode,
                'status'  => $amprah->status,
                'urgensi' => $amprah->urgensi,
            ], "Permintaan amprah {$amprah->kode} berhasil diajukan.", 201);
        } catch (\Throwable $e) {
            return $this->jsonError('Gagal mengajukan amprah: ' . $e->getMessage(), 500);
        }
    }

    private function buatDokumenAmprah(Request $request, $user, $unitId, $gudangId, string $statusAwal, $disetujuiOleh): PermintaanDistribusi
    {
        $kodeReq = CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3);

        $header = PermintaanDistribusi::create([
            'kode'               => $kodeReq,
            'unit_id'            => $unitId,
            'gudang_id'          => $gudangId,
            'diajukan_oleh'      => $user->id,
            'disetujui_oleh'     => $disetujuiOleh,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi'            => $request->input('urgensi'),
            'status'             => $statusAwal,
            'catatan'            => $request->input('catatan'),
        ]);

        foreach ($request->input('items') as $row) {
            PermintaanDistribusiDetail::create([
                'permintaan_distribusi_id' => $header->id,
                'barang_id'                => $row['barang_id'],
                'jumlah'                   => $row['jumlah'],
                'jumlah_disetujui'         => $row['jumlah'],
                'catatan'                  => $row['catatan'] ?? null,
            ]);
        }

        return $header;
    }

    private function validatorAmprah(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'gudang_id'        => 'nullable|exists:gudang,id',
            'urgensi'          => 'required|in:REGULER,CITO',
            'catatan'          => 'nullable|string|max:1000',
            'items'            => 'required|array|min:1',
            'items.*.barang_id'=> 'required|exists:barang,id',
            'items.*.jumlah'   => 'required|integer|min:1',
            'items.*.catatan'  => 'nullable|string|max:500',
        ], [
            'items.required'            => 'Minimal 1 barang harus dimasukkan ke dalam permohonan amprah.',
            'items.min'                 => 'Minimal 1 barang harus dimasukkan ke dalam permohonan amprah.',
            'items.*.barang_id.required'=> 'Barang wajib dipilih.',
            'items.*.jumlah.min'        => 'Jumlah barang minimal 1 unit.',
        ]);
    }

    /**
     * Batalkan dokumen amprah yang masih berstatus DIAJUKAN atau DISETUJUI_KARU.
     * POST /v1/distribusi/amprah/{id}/batal
     */
    public function batalAmprah(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->jsonError('Unauthenticated.', 401);
        }

        $amprah = PermintaanDistribusi::find($id);

        if (! $amprah) {
            return $this->jsonError('Data amprah tidak ditemukan.', 404);
        }

        if ($message = $this->cancelDenial($user, $amprah)) {
            return $this->jsonError($message, 403);
        }

        if (! $this->canRequesterAct($user, $amprah->unit_id)) {
            return $this->jsonError('Anda tidak memiliki wewenang untuk membatalkan amprah ini.', 403);
        }

        $canBatal = in_array($amprah->status, StatusPermintaanDistribusi::dapatDibatalkan(), true)
            && ! PengirimanBarang::where('permintaan_distribusi_id', $amprah->id)->where('status', '!=', 'DIBATALKAN')->exists();

        if (! $canBatal) {
            return $this->jsonError('Permintaan amprah ini sudah diproses dan tidak dapat dibatalkan.', 422);
        }

        $catatanAwal = $amprah->catatan ? $amprah->catatan . "\n" : '';
        $amprah->update([
            'status'  => StatusPermintaanDistribusi::Ditolak->value,
            'catatan' => $catatanAwal . '[Dibatalkan oleh pemohon]',
        ]);

        return $this->jsonSuccess([
            'kode'   => $amprah->kode,
            'status' => $amprah->status,
        ], "Permintaan amprah {$amprah->kode} berhasil dibatalkan.");
    }

    /**
     * Tutup sisa hutang komoditas amprah ruangan yang berstatus DIKIRIM_SEBAGIAN.
     * POST /v1/distribusi/amprah/{id}/tutup-sisa
     */
    public function tutupSisa(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->jsonError('Unauthenticated.', 401);
        }

        return DB::transaction(function () use ($user, $id) {
            $amprah = PermintaanDistribusi::lockForUpdate()->find($id);

            if (! $amprah) {
                return $this->jsonError('Data amprah tidak ditemukan.', 404);
            }

            if (! $this->canRequesterAct($user, $amprah->unit_id)) {
                return $this->jsonError('Anda tidak memiliki hak akses untuk menutup sisa permintaan dari unit ini.', 403);
            }

            if ($amprah->status !== StatusPermintaanDistribusi::DikirimSebagian->value) {
                return $this->jsonError('Hanya permintaan dengan status DIKIRIM_SEBAGIAN yang dapat ditutup sisa hutangnya.', 422);
            }

            $catatanAwal = $amprah->catatan ? $amprah->catatan . "\n" : '';
            $amprah->update([
                'status'  => StatusPermintaanDistribusi::Selesai->value,
                'catatan' => $catatanAwal . '[Sisa hutang ditutup oleh pemohon pada ' . now()->format('d/m/Y H:i') . ']',
            ]);

            return $this->jsonSuccess([
                'kode'   => $amprah->kode,
                'status' => $amprah->status,
            ], "Sisa hutang amprah {$amprah->kode} berhasil ditutup.");
        });
    }

    private array $stokTersediaCache = [];

    /**
     * Operator global (super_admin / admin_logistik) melewati pembatasan gudang & unit.
     */
    private function isGlobalOperator(?User $user): bool
    {
        return PickingScope::isGlobalOperator($user);
    }

    /**
     * Pembaca global read-only: auditor/manajemen (ADR 0001).
     */
    private function isGlobalReader(?User $user): bool
    {
        return (bool) $user?->hasRole(['auditor', 'manajemen']);
    }

    /**
     * Ambil daftar ID unit yang berada dalam wewenang pengguna saat ini.
     */
    private function ownUnitIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return Unit::where('kepala_unit_id', $user->id)->pluck('id')
            ->push($user->unit_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ambil daftar ID gudang yang berada dalam wewenang pengguna saat ini.
     */
    private function ownGudangIds(?User $user): array
    {
        return PickingScope::ownGudangIds($user);
    }

    /**
     * Scope baca: unit milik pengguna, lalu gudang miliknya; tanpa penempatan = pembaca global via permission view*.
     * Mereplikasi logika AuthorizesDistribusiScope::applyReadScope.
     */
    private function applyReadScope(Builder $query, ?User $user): Builder
    {
        if ($this->isGlobalOperator($user) || $this->isGlobalReader($user)) {
            return $query;
        }

        $unitIds = $this->ownUnitIds($user);
        $gudangIds = $this->ownGudangIds($user);

        if ($unitIds) {
            return $query->whereIn('unit_id', $unitIds);
        }

        if ($gudangIds) {
            return $query->whereIn('gudang_id', $gudangIds);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Periksa hak akses baca data berdasarkan cakupan unit atau gudang pengguna.
     * Mereplikasi logika AuthorizesDistribusiScope::canReadScoped.
     */
    private function canReadScoped(?User $user, ?int $unitId, ?int $gudangId): bool
    {
        if ($this->isGlobalOperator($user) || $this->isGlobalReader($user)) {
            return true;
        }

        $unitIds = $this->ownUnitIds($user);
        $gudangIds = $this->ownGudangIds($user);

        if ($unitIds) {
            return $unitId && in_array((int) $unitId, $unitIds, true);
        }

        if ($gudangIds) {
            return $gudangId && in_array((int) $gudangId, $gudangIds, true);
        }

        return false;
    }

    /**
     * Pembatalan/penutupan amprah oleh pemohon: permission pengajuan amprah dan unit milik sendiri / global operator.
     * Mereplikasi logika PermintaanBarangIndex::canRequesterAct.
     */
    private function canRequesterAct(?User $user, ?int $unitId): bool
    {
        if (! $user) {
            return false;
        }

        return $user->canAny(['create_layanan_unit::permintaan::barang', 'update_layanan_unit::permintaan::barang'])
            && ($this->isGlobalOperator($user) || ($user->unit_id && (int) $user->unit_id === (int) $unitId));
    }

    /**
     * Dapatkan jumlah stok barang yang saat ini tersedia di gudang.
     * Mereplikasi formula DistribusiApprovalIndex::getStokTersediaGudang.
     */
    private function getStokTersediaGudang(int $barangId, ?int $gudangId, ?int $excludePermintaanId = null): int
    {
        $cacheKey = "{$barangId}_{$gudangId}_{$excludePermintaanId}";
        if (isset($this->stokTersediaCache[$cacheKey])) {
            return $this->stokTersediaCache[$cacheKey];
        }

        $stokQuery = StokBatch::where('barang_id', $barangId)
            ->where('status', 'AKTIF')
            ->where('jumlah', '>', 0)
            ->where(function ($q) {
                $q->whereNull('tanggal_kedaluwarsa')
                    ->orWhere('tanggal_kedaluwarsa', '>=', now()->toDateString());
            });

        if ($gudangId) {
            $stokQuery->where('gudang_id', $gudangId);
        }

        $stokFisik = (int) $stokQuery->sum('jumlah');

        $stokCommitted = (int) PermintaanDistribusiDetail::where('barang_id', $barangId)
            ->whereHas('permintaanDistribusi', function ($q) use ($gudangId, $excludePermintaanId) {
                $q->where('status', StatusPermintaanDistribusi::DisetujuiGudang->value)
                    ->when($gudangId, fn ($sub) => $sub->where('gudang_id', $gudangId))
                    ->when($excludePermintaanId, fn ($sub) => $sub->where('id', '!=', $excludePermintaanId));
            })
            ->sum('jumlah_disetujui');

        return $this->stokTersediaCache[$cacheKey] = max(0, $stokFisik - $stokCommitted);
    }

    /**
     * Periksa apakah permohonan amprah dapat dibatalkan oleh pengguna saat ini.
     * Web rule: status DIAJUKAN/DISETUJUI_KARU, tidak ada SPPB aktif, dan requester diizinkan.
     */
    private function canCancel(?User $user, PermintaanDistribusi $amprah): bool
    {
        if (! in_array($amprah->status, StatusPermintaanDistribusi::dapatDibatalkan(), true)) {
            return false;
        }

        if ($this->cancelDenial($user, $amprah) !== null) {
            return false;
        }

        if (! $this->canRequesterAct($user, $amprah->unit_id)) {
            return false;
        }

        if ($amprah->relationLoaded('pengiriman')) {
            return ! $amprah->pengiriman->contains(fn ($p) => $p->status !== 'DIBATALKAN');
        }

        return ! PengirimanBarang::where('permintaan_distribusi_id', $amprah->id)
            ->where('status', '!=', 'DIBATALKAN')
            ->exists();
    }

    /**
     * Alasan penolakan pembatalan berdasarkan kepemilikan dokumen; null jika lolos.
     * Hanya pembuat dokumen atau super_admin yang boleh membatalkan; staf tidak boleh
     * membatalkan setelah disetujui Kepala Ruangan.
     */
    private function cancelDenial(?User $user, PermintaanDistribusi $amprah): ?string
    {
        if (! $user || $user->hasRole('super_admin')) {
            return null;
        }

        if ($user->hasRole('petugas_unit') && $amprah->status === StatusPermintaanDistribusi::DisetujuiKaru->value) {
            return 'Permintaan amprah ini telah disetujui oleh Kepala Ruangan dan tidak dapat dibatalkan oleh staf.';
        }

        if ((int) $amprah->diajukan_oleh !== (int) $user->id) {
            return $user->hasRole('kepala_unit')
                ? 'Kepala Ruangan tidak memiliki wewenang membatalkan permohonan staf unit. Gunakan aksi Tolak pada menu persetujuan.'
                : 'Anda hanya dapat membatalkan permohonan amprah yang Anda ajukan sendiri.';
        }

        return null;
    }

    /**
     * Periksa apakah sisa hutang amprah dapat ditutup oleh pengguna saat ini.
     * Web rule: status DIKIRIM_SEBAGIAN dan requester diizinkan.
     */
    private function canTutupSisa(?User $user, PermintaanDistribusi $amprah): bool
    {
        return $amprah->status === StatusPermintaanDistribusi::DikirimSebagian->value
            && $this->canRequesterAct($user, $amprah->unit_id);
    }
}
