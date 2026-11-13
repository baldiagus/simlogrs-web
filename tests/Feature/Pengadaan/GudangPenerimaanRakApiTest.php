<?php

namespace Tests\Feature\Pengadaan;

use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\KategoriBarang;
use App\Models\Master\LokasiGudang;
use App\Models\Master\Pemasok;
use App\Models\Master\SatuanBarang;
use App\Models\Pengadaan\PesananPembelian;
use App\Models\Pengadaan\PesananPembelianDetail;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GudangPenerimaanRakApiTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = '/api/v1/gudang/penerimaan-po';
    private const PERMISSION = 'create_gudang_stok::penerimaan::po';

    private Gudang $gudangA;
    private Gudang $gudangB;
    private LokasiGudang $rakA;
    private LokasiGudang $rakA2;
    private LokasiGudang $rakB;
    private LokasiGudang $rakNonaktif;
    private Barang $barang;
    private Barang $barang2;
    private PesananPembelian $po;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->gudangA = Gudang::create(['kode' => 'GD-RCV-A', 'nama' => 'Gudang Penerimaan A', 'status' => 'AKTIF']);
        $this->gudangB = Gudang::create(['kode' => 'GD-RCV-B', 'nama' => 'Gudang Penerimaan B', 'status' => 'AKTIF']);
        $this->rakA = $this->makeRack($this->gudangA, 'A1');
        $this->rakA2 = $this->makeRack($this->gudangA, 'A2');
        $this->rakB = $this->makeRack($this->gudangB, 'B1');
        $this->rakNonaktif = $this->makeRack($this->gudangA, 'OFF', 'NONAKTIF');
        $this->petugas = $this->makeUser($this->gudangA->id);
        $kategori = KategoriBarang::create(['kode' => 'KAT-RCV-RAK', 'nama' => 'Kategori Penerimaan Rak', 'status' => 'AKTIF']);
        $satuan = SatuanBarang::create(['kode' => 'SAT-RCV-RAK', 'nama' => 'Satuan Penerimaan Rak', 'status' => 'AKTIF']);
        foreach (['barang', 'barang2'] as $index => $property) {
            $this->{$property} = Barang::create([
                'kode' => 'BRG-RCV-RAK-' . $index,
                'nama' => 'Barang Penerimaan Rak ' . $index,
                'kategori_id' => $kategori->id,
                'satuan_id' => $satuan->id,
                'tipe' => 'HABIS_PAKAI',
                'status' => 'AKTIF',
                'harga_beli_terakhir' => 250,
            ]);
        }
        $pemasok = Pemasok::create(['kode' => 'VND-RCV-RAK', 'nama' => 'Vendor Penerimaan Rak', 'status' => 'AKTIF']);
        $this->po = PesananPembelian::create([
            'kode' => 'PO-RCV-RAK-TEST',
            'pemasok_id' => $pemasok->id,
            'tanggal_po' => now()->toDateString(),
            'status' => 'DIKIRIM_VENDOR',
            'total_biaya' => 20000,
            'syarat_pembayaran' => 'TEMPO_30_HARI',
            'dibuat_oleh' => $this->petugas->id,
        ]);
        foreach ([$this->barang, $this->barang2] as $barang) {
            PesananPembelianDetail::create([
                'pesanan_pembelian_id' => $this->po->id,
                'barang_id' => $barang->id,
                'jumlah_dipesan' => 10,
                'jumlah_diterima' => 0,
                'harga_satuan' => 1000,
                'subtotal' => 10000,
            ]);
        }
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function makeRack(Gudang $gudang, string $kode, string $status = 'AKTIF'): LokasiGudang
    {
        return LokasiGudang::create(['gudang_id' => $gudang->id, 'kode' => $kode, 'nama' => 'Rak ' . $kode, 'status' => $status]);
    }

    private function makeUser(?int $gudangId, ?string $role = null, bool $permission = true): User
    {
        $suffix = uniqid();
        $user = User::create([
            'nama' => 'Penerima Rak Test',
            'username' => 'rcv_rak_' . $suffix,
            'email' => "rcv_rak_{$suffix}@simlog.test",
            'password' => 'password',
            'gudang_id' => $gudangId,
            'status' => 'AKTIF',
            'wajib_ganti_password' => false,
        ]);
        if ($role !== null) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        if ($permission) {
            $user->givePermissionTo(Permission::findOrCreate(self::PERMISSION, 'web'));
        }

        return $user->fresh();
    }

    private function payload(): array
    {
        return [
            'pesanan_pembelian_id' => $this->po->id,
            'nomor_surat_jalan' => 'SJ-RCV-RAK',
            'catatan' => 'Penerimaan fixture',
            'items' => [[
                'barang_id' => $this->barang->id,
                'lokasi_gudang_id' => $this->rakA->id,
                'nomor_batch' => 'BATCH-RCV-RAK',
                'tanggal_kedaluwarsa' => '2030-01-01',
                'jumlah_diterima' => 3,
                'jumlah_rusak' => 0,
            ]],
        ];
    }

    private function businessState(): array
    {
        $state = [];
        foreach (['penerimaan_barang', 'penerimaan_barang_detail', 'stok_batch', 'pergerakan_stok'] as $table) {
            $state[$table] = DB::table($table)->count();
        }
        $state['po'] = $this->po->fresh()->getAttributes();
        $state['detail'] = $this->po->detail()->orderBy('id')->get()->toArray();
        $state['harga'] = Barang::whereIn('id', [$this->barang->id, $this->barang2->id])->orderBy('id')->pluck('harga_beli_terakhir')->all();

        return $state;
    }

    public static function assignedAccounts(): array
    {
        return [
            'petugas legacy payload' => [null, false],
            'petugas explicit target' => [null, true],
            'assigned super admin' => ['super_admin', true],
            'assigned admin logistik' => ['admin_logistik', false],
        ];
    }

    #[DataProvider('assignedAccounts')]
    public function test_assigned_accounts_receive_only_into_their_warehouse(?string $role, bool $explicit): void
    {
        $user = $role ? $this->makeUser($this->gudangA->id, $role, false) : $this->petugas;
        $payload = $this->payload();
        if ($explicit) {
            $payload['gudang_id'] = $this->gudangA->id;
        }
        $response = $this->actingAs($user)->postJson(self::ENDPOINT, $payload)->assertCreated();
        $this->assertDatabaseHas('penerimaan_barang', ['id' => $response->json('data.id'), 'gudang_id' => $this->gudangA->id]);
        $this->assertDatabaseHas('stok_batch', [
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudangA->id,
            'lokasi_gudang_id' => $this->rakA->id,
            'nomor_batch' => 'BATCH-RCV-RAK',
            'jumlah' => 3,
        ]);
    }

    public static function globalRoles(): array
    {
        return [['super_admin'], ['admin_logistik']];
    }

    #[DataProvider('globalRoles')]
    public function test_unassigned_global_admin_receives_into_explicit_active_target(string $role): void
    {
        $payload = $this->payload();
        $payload['gudang_id'] = $this->gudangB->id;
        $payload['items'][0]['lokasi_gudang_id'] = $this->rakB->id;
        $response = $this->actingAs($this->makeUser(null, $role, false))->postJson(self::ENDPOINT, $payload)->assertCreated();
        $this->assertDatabaseHas('penerimaan_barang', ['id' => $response->json('data.id'), 'gudang_id' => $this->gudangB->id]);
        $this->assertDatabaseHas('stok_batch', ['barang_id' => $this->barang->id, 'gudang_id' => $this->gudangB->id, 'lokasi_gudang_id' => $this->rakB->id]);
    }

    public static function invalidAllocations(): array
    {
        return [
            'foreign rack' => ['foreign_rack', 422],
            'mixed racks' => ['mixed_racks', 422],
            'inactive rack' => ['inactive_rack', 422],
            'missing rack' => ['missing_rack', 422],
            'unknown rack' => ['unknown_rack', 422],
            'inactive assignment' => ['inactive_assignment', 422],
            'inactive admin target' => ['inactive_target', 422],
            'unknown admin target' => ['unknown_target', 422],
            'noninteger target' => ['invalid_target', 422],
            'fractional target' => ['fractional_target', 422],
            'null target' => ['null_target', 422],
            'admin target omitted' => ['missing_target', 422],
            'super admin target omitted' => ['missing_super_target', 422],
            'assignment mismatch' => ['mismatch', 403],
            'assigned admin mismatch' => ['admin_mismatch', 403],
            'unassigned permission holder' => ['unassigned', 403],
            'view permission is not global role' => ['view_only_global', 403],
            'no receive permission' => ['denied', 403],
            'unshipped PO' => ['invalid_po_status', 422],
            'unknown PO' => ['unknown_po', 422],
            'quantity exceeds remaining' => ['excess_quantity', 422],
        ];
    }

    #[DataProvider('invalidAllocations')]
    public function test_invalid_receipts_have_no_business_effects(string $case, int $status): void
    {
        $user = $this->petugas;
        $payload = $this->payload();
        switch ($case) {
            case 'foreign_rack':
                $payload['items'][0]['lokasi_gudang_id'] = $this->rakB->id;
                break;
            case 'mixed_racks':
                $payload['items'][] = array_replace($payload['items'][0], ['barang_id' => $this->barang2->id, 'lokasi_gudang_id' => $this->rakB->id]);
                break;
            case 'inactive_rack':
                $payload['items'][0]['lokasi_gudang_id'] = $this->rakNonaktif->id;
                break;
            case 'missing_rack':
                unset($payload['items'][0]['lokasi_gudang_id']);
                break;
            case 'unknown_rack':
                $payload['items'][0]['lokasi_gudang_id'] = LokasiGudang::max('id') + 1;
                break;
            case 'inactive_assignment':
                $this->gudangA->update(['status' => 'NONAKTIF']);
                break;
            case 'mismatch':
                $payload['gudang_id'] = $this->gudangB->id;
                break;
            case 'admin_mismatch':
                $user = $this->makeUser($this->gudangA->id, 'super_admin', false);
                $payload['gudang_id'] = $this->gudangB->id;
                break;
            case 'unassigned':
            case 'view_only_global':
                $user = $this->makeUser(null);
                $payload['gudang_id'] = $this->gudangA->id;
                if ($case === 'view_only_global') {
                    $user->givePermissionTo(Permission::findOrCreate('view_master::lokasi_gudang', 'web'));
                }
                break;
            case 'denied':
                $user = $this->makeUser($this->gudangA->id, null, false);
                break;
            case 'invalid_po_status':
                $this->po->update(['status' => 'DITERBITKAN']);
                break;
            case 'unknown_po':
                $payload['pesanan_pembelian_id'] = PesananPembelian::max('id') + 1;
                break;
            case 'excess_quantity':
                $payload['items'][0]['jumlah_diterima'] = 11;
                break;
            default:
                $user = $this->makeUser(null, $case === 'missing_super_target' ? 'super_admin' : 'admin_logistik', false);
                if (! in_array($case, ['missing_target', 'missing_super_target'], true)) {
                    $payload['gudang_id'] = match ($case) {
                        'unknown_target' => Gudang::max('id') + 1,
                        'invalid_target' => 'not-an-id',
                        'fractional_target' => 1.5,
                        'null_target' => null,
                        default => $this->gudangA->id,
                    };
                }
                if ($case === 'inactive_target') {
                    $this->gudangA->update(['status' => 'NONAKTIF']);
                }
        }
        $before = $this->businessState();
        $this->actingAs($user)->postJson(self::ENDPOINT, $payload)->assertStatus($status)->assertJsonPath('sukses', false);
        $this->assertSame($before, $this->businessState());
    }

    public function test_partial_receipt_accepts_multiple_active_racks_in_the_same_warehouse(): void
    {
        $payload = $this->payload();
        $payload['gudang_id'] = $this->gudangA->id;
        $payload['items'][] = array_replace($payload['items'][0], [
            'barang_id' => $this->barang2->id,
            'lokasi_gudang_id' => $this->rakA2->id,
            'nomor_batch' => 'BATCH-RCV-RAK-2',
            'jumlah_diterima' => 4,
        ]);
        $response = $this->actingAs($this->petugas)->postJson(self::ENDPOINT, $payload)->assertCreated();
        $this->assertDatabaseHas('penerimaan_barang', ['id' => $response->json('data.id'), 'gudang_id' => $this->gudangA->id, 'status' => 'SEBAGIAN']);
        foreach ([[$this->barang, $this->rakA, 3], [$this->barang2, $this->rakA2, 4]] as [$barang, $rak, $jumlah]) {
            $this->assertDatabaseHas('penerimaan_barang_detail', ['penerimaan_barang_id' => $response->json('data.id'), 'barang_id' => $barang->id, 'lokasi_gudang_id' => $rak->id, 'jumlah_diterima' => $jumlah]);
            $this->assertDatabaseHas('stok_batch', ['barang_id' => $barang->id, 'gudang_id' => $this->gudangA->id, 'lokasi_gudang_id' => $rak->id, 'jumlah' => $jumlah]);
            $this->assertDatabaseHas('pergerakan_stok', ['referensi_id' => $response->json('data.id'), 'referensi_jenis' => 'PENERIMAAN_PO', 'barang_id' => $barang->id, 'gudang_id' => $this->gudangA->id, 'lokasi_gudang_id' => $rak->id, 'jumlah' => $jumlah]);
            $this->assertDatabaseHas('pesanan_pembelian_detail', ['pesanan_pembelian_id' => $this->po->id, 'barang_id' => $barang->id, 'jumlah_diterima' => $jumlah]);
        }
        $this->assertSame('DITERIMA_SEBAGIAN', $this->po->fresh()->status);
    }

    public static function masterChanges(): array
    {
        return [['rack_moved'], ['rack_inactive'], ['rack_deleted'], ['warehouse_inactive']];
    }

    #[DataProvider('masterChanges')]
    public function test_master_validity_is_rechecked_after_locking_po_before_any_business_writes(string $change): void
    {
        $before = $this->businessState();
        $changed = false;
        // Change real master data after preflight, when the receipt transaction locks its PO.
        DB::listen(function (QueryExecuted $query) use ($change, &$changed): void {
            if ($changed || ! str_contains($query->sql, 'pesanan_pembelian') || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }
            $changed = true;
            match ($change) {
                'rack_moved' => $this->rakA->update(['gudang_id' => $this->gudangB->id]),
                'rack_inactive' => $this->rakA->update(['status' => 'NONAKTIF']),
                'rack_deleted' => $this->rakA->delete(),
                'warehouse_inactive' => $this->gudangA->update(['status' => 'NONAKTIF']),
            };
        });

        $this->actingAs($this->petugas)->postJson(self::ENDPOINT, $this->payload())->assertStatus(422);
        $this->assertTrue($changed, 'The master change must happen inside the receipt transaction.');
        $this->assertSame($before, $this->businessState());
    }
}
