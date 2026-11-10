<?php

namespace Tests\Feature\Distribusi;

use App\Enums\Distribusi\StatusPermintaanDistribusi;
use App\Models\Distribusi\PengirimanBarang;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Distribusi\PermintaanDistribusiDetail;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\LokasiGudang;
use App\Models\Master\Unit;
use App\Models\Persediaan\StokBatch;
use App\Models\User;
use App\Support\Generator\CodeGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DistribusiScopeApiTest extends TestCase
{
    use DatabaseTransactions;

    private const PERM_PICKING = 'create_gudang_stok::picking::distribusi';
    private const PERM_AMPRAH  = 'create_layanan_unit::permintaan::barang';

    protected Gudang $gudang1;
    protected Gudang $gudang2;
    protected Unit $unit;
    protected Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $gudangs = Gudang::where('status', 'AKTIF')->orderBy('id')->take(2)->get();
        if ($gudangs->count() < 2) {
            $this->markTestSkipped('Butuh minimal dua gudang AKTIF pada database.');
        }

        [$this->gudang1, $this->gudang2] = [$gudangs[0], $gudangs[1]];
        $this->unit = Unit::where('status', 'AKTIF')->firstOrFail();
        $this->barang = Barang::where('status', 'AKTIF')->firstOrFail();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function makeUser(array $permissions = [], array $roles = [], ?int $unitId = null, ?int $gudangId = null): User
    {
        $suffix = uniqid();
        $user = User::create([
            'nama'      => 'Uji Distribusi API ' . $suffix,
            'username'  => 'uji_dist_' . $suffix,
            'email'     => "uji_dist_{$suffix}@simlog.test",
            'password'  => bcrypt('password'),
            'unit_id'   => $unitId,
            'gudang_id' => $gudangId,
            'status'    => 'AKTIF',
        ]);

        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        if ($roles) {
            $user->assignRole($roles);
        }

        return $user->fresh();
    }

    private function createPermintaanSiapPicking(?int $gudangId, int $unitId, int $userId): PermintaanDistribusi
    {
        $permintaan = PermintaanDistribusi::create([
            'kode'               => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id'            => $unitId,
            'gudang_id'          => $gudangId,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi'            => 'REGULER',
            'status'             => StatusPermintaanDistribusi::DisetujuiGudang->value,
            'diajukan_oleh'      => $userId,
            'disetujui_oleh'     => $userId,
        ]);

        PermintaanDistribusiDetail::create([
            'permintaan_distribusi_id' => $permintaan->id,
            'barang_id'                => $this->barang->id,
            'jumlah'                   => 5,
            'jumlah_disetujui'         => 5,
        ]);

        return $permintaan;
    }

    private function ensureStock(int $gudangId, int $qty = 10): StokBatch
    {
        $lokasi = LokasiGudang::firstOrCreate(
            ['kode' => 'RAK-TEST-' . $gudangId, 'gudang_id' => $gudangId],
            ['nama' => 'Rak Test ' . $gudangId, 'status' => 'AKTIF']
        );

        return StokBatch::create([
            'barang_id'           => $this->barang->id,
            'gudang_id'           => $gudangId,
            'lokasi_gudang_id'    => $lokasi->id,
            'nomor_batch'         => 'BATCH-TEST-' . uniqid(),
            'tanggal_kedaluwarsa' => now()->addYear()->toDateString(),
            'jumlah'              => $qty,
            'harga_satuan'        => 10000,
            'status'              => 'AKTIF',
        ]);
    }

    private function amprahPayload(array $extra = []): array
    {
        return array_merge([
            'gudang_id' => $this->gudang1->id,
            'urgensi'   => 'REGULER',
            'catatan'   => 'Uji amprah API',
            'items'     => [
                ['barang_id' => $this->barang->id, 'jumlah' => 10],
            ],
        ], $extra);
    }

    public function test_petugas_gudang_of_gudang_1_posting_picking_for_permintaan_of_gudang_2_returns_403_and_no_sppb(): void
    {
        $petugas1 = $this->makeUser([self::PERM_PICKING], ['petugas_gudang'], null, $this->gudang1->id);
        $creator = $this->makeUser([], [], $this->unit->id);
        $permintaanGudang2 = $this->createPermintaanSiapPicking($this->gudang2->id, $this->unit->id, $creator->id);
        $this->ensureStock($this->gudang2->id, 10);

        $response = $this->actingAs($petugas1)
            ->postJson('/api/v1/distribusi/picking', [
                'permintaan_id' => $permintaanGudang2->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('pengiriman_barang', [
            'permintaan_distribusi_id' => $permintaanGudang2->id,
        ]);
    }

    public function test_same_user_for_own_gudang_returns_201(): void
    {
        $petugas1 = $this->makeUser([self::PERM_PICKING], ['petugas_gudang'], null, $this->gudang1->id);
        $creator = $this->makeUser([], [], $this->unit->id);
        $permintaanGudang1 = $this->createPermintaanSiapPicking($this->gudang1->id, $this->unit->id, $creator->id);
        $this->ensureStock($this->gudang1->id, 10);

        $response = $this->actingAs($petugas1)
            ->postJson('/api/v1/distribusi/picking', [
                'permintaan_id' => $permintaanGudang1->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pengiriman_barang', [
            'permintaan_distribusi_id' => $permintaanGudang1->id,
            'gudang_id'                => $this->gudang1->id,
        ]);
    }

    public function test_super_admin_any_gudang_returns_201(): void
    {
        $admin = $this->makeUser([self::PERM_PICKING], ['super_admin']);
        $creator = $this->makeUser([], [], $this->unit->id);
        $permintaanGudang2 = $this->createPermintaanSiapPicking($this->gudang2->id, $this->unit->id, $creator->id);
        $this->ensureStock($this->gudang2->id, 10);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/distribusi/picking', [
                'permintaan_id' => $permintaanGudang2->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pengiriman_barang', [
            'permintaan_distribusi_id' => $permintaanGudang2->id,
            'gudang_id'                => $this->gudang2->id,
        ]);
    }

    public function test_user_with_neither_unit_nor_gudang_posting_amprah_returns_403_and_no_permintaan_row(): void
    {
        $unplacedUser = $this->makeUser([self::PERM_AMPRAH], [], null, null);

        $response = $this->actingAs($unplacedUser)
            ->postJson('/api/v1/distribusi/amprah', $this->amprahPayload());

        $response->assertStatus(403);
        $this->assertDatabaseMissing('permintaan_distribusi', [
            'diajukan_oleh' => $unplacedUser->id,
        ]);
    }

    public function test_user_with_unit_and_stock_short_item_returns_201_backorder_allowed(): void
    {
        $userUnit = $this->makeUser([self::PERM_AMPRAH], ['petugas_unit'], $this->unit->id, null);

        // Minta jumlah besar yang melebihi saldo stok fisik gudang (backorder)
        $response = $this->actingAs($userUnit)
            ->postJson('/api/v1/distribusi/amprah', $this->amprahPayload([
                'items' => [
                    ['barang_id' => $this->barang->id, 'jumlah' => 999999],
                ],
            ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('permintaan_distribusi', [
            'diajukan_oleh' => $userUnit->id,
            'unit_id'       => $this->unit->id,
        ]);
    }

    public function test_client_sent_gudang_id_is_ignored_for_non_global_user(): void
    {
        $petugas1 = $this->makeUser([self::PERM_PICKING], ['petugas_gudang'], null, $this->gudang1->id);
        $creator = $this->makeUser([], [], $this->unit->id);
        $permintaanGudang1 = $this->createPermintaanSiapPicking($this->gudang1->id, $this->unit->id, $creator->id);
        $this->ensureStock($this->gudang1->id, 10);

        // Petugas gudang 1 mencoba mengirimkan gudang_id = gudang 2
        $response = $this->actingAs($petugas1)
            ->postJson('/api/v1/distribusi/picking', [
                'permintaan_id' => $permintaanGudang1->id,
                'gudang_id'     => $this->gudang2->id,
            ]);

        $response->assertStatus(201);
        // SPPB harus tetap diterbitkan untuk gudang 1 (gudang 2 diabaikan)
        $this->assertDatabaseHas('pengiriman_barang', [
            'permintaan_distribusi_id' => $permintaanGudang1->id,
            'gudang_id'                => $this->gudang1->id,
        ]);
    }

    public function test_user_without_gudang_cannot_pick(): void
    {
        $userTanpaGudang = $this->makeUser([self::PERM_PICKING], [], null, null);
        $creator = $this->makeUser([], [], $this->unit->id);
        $permintaan = $this->createPermintaanSiapPicking($this->gudang1->id, $this->unit->id, $creator->id);

        $response = $this->actingAs($userTanpaGudang)
            ->postJson('/api/v1/distribusi/picking', [
                'permintaan_id' => $permintaan->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('pengiriman_barang', [
            'permintaan_distribusi_id' => $permintaan->id,
        ]);
    }

    public function test_scan_batch_rejects_cross_warehouse_picking_for_non_global_user(): void
    {
        $petugas1 = $this->makeUser([self::PERM_PICKING], [], null, $this->gudang1->id);
        $creator = $this->makeUser([], [], $this->unit->id);
        $permintaanGudang2 = $this->createPermintaanSiapPicking($this->gudang2->id, $this->unit->id, $creator->id);
        $this->ensureStock($this->gudang2->id, 10);

        $response = $this->actingAs($petugas1)
            ->postJson('/api/v1/gudang/scan-batch', [
                'permintaan_id' => $permintaanGudang2->id,
                'gudang_id'     => $this->gudang2->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('pengiriman_barang', [
            'permintaan_distribusi_id' => $permintaanGudang2->id,
        ]);
    }
}
