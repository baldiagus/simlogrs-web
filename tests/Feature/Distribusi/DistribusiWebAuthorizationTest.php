<?php

namespace Tests\Feature\Distribusi;

use App\Livewire\Distribusi\ApprovalPermintaanIndex;
use App\Livewire\Distribusi\DistribusiApprovalIndex;
use App\Livewire\Distribusi\PenerimaanBarangIndex;
use App\Livewire\Distribusi\PermintaanBarangIndex;
use App\Livewire\Distribusi\PickingDistribusiIndex;
use App\Livewire\Distribusi\StokRuanganIndex;
use App\Models\Aset\Aset;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Distribusi\PengirimanBarang;
use App\Models\Distribusi\PermintaanDistribusi;
use App\Models\Distribusi\StokRuangan;
use App\Models\Master\Unit;
use App\Models\User;
use App\Support\Generator\CodeGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Otorisasi web modul distribusi sesuai ADR 0001.
 */
class DistribusiWebAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected Unit $unitA;
    protected Unit $unitB;
    protected Gudang $gudangA;
    protected Gudang $gudangB;
    protected Barang $barang;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->unitA, $this->unitB] = Unit::where('status', 'AKTIF')->orderBy('id')->take(2)->get()->all();
        [$this->gudangA, $this->gudangB] = Gudang::where('status', 'AKTIF')->orderBy('id')->take(2)->get()->all();
        $this->barang = Barang::where('status', 'AKTIF')->first();
    }

    protected function makeUser(array $attrs = [], array $permissions = [], ?string $role = null): User
    {
        $suffix = uniqid();
        $user = User::create(array_merge([
            'nama' => 'Uji Otorisasi Distribusi ' . $suffix,
            'username' => 'uji_dist_' . $suffix,
            'email' => 'uji_dist_' . $suffix . '@simlog.test',
            'password' => bcrypt('password'),
            'status' => 'AKTIF',
        ], $attrs));

        if ($role) {
            $user->assignRole($role);
        }
        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    protected function makePermintaan(Unit $unit, Gudang $gudang, string $status, ?int $diajukanOleh = null): PermintaanDistribusi
    {
        return PermintaanDistribusi::create([
            'kode' => CodeGenerator::generate('REQ', PermintaanDistribusi::class, 'kode', 'Ym', 3),
            'unit_id' => $unit->id,
            'gudang_id' => $gudang->id,
            'tanggal_permintaan' => now()->toDateString(),
            'urgensi' => 'REGULER',
            'status' => $status,
            'diajukan_oleh' => $diajukanOleh ?? User::where('email', 'superadmin@simlogrs.local')->value('id'),
        ]);
    }

    // ---------- Guard halaman & page_*Dashboard ----------

    public function test_page_and_dashboard_permissions_alone_cannot_open_distribusi_pages(): void
    {
        $user = $this->makeUser(['unit_id' => $this->unitA->id], [
            'page_Dashboard',
            'page_AdminLogistikDashboard',
            'page_PermintaanBarangIndex',
            'page_ApprovalPermintaanIndex',
            'page_DistribusiApprovalIndex',
            'page_PickingDistribusiIndex',
            'page_PenerimaanBarangIndex',
            'page_StokRuanganIndex',
        ]);
        $this->actingAs($user);

        foreach ([
            PermintaanBarangIndex::class,
            ApprovalPermintaanIndex::class,
            DistribusiApprovalIndex::class,
            PickingDistribusiIndex::class,
            PenerimaanBarangIndex::class,
            StokRuanganIndex::class,
        ] as $component) {
            Livewire::test($component)->assertForbidden();
        }
    }

    public function test_view_permission_opens_page_scoped_to_own_unit_even_with_dashboard_permission(): void
    {
        $own = $this->makePermintaan($this->unitA, $this->gudangA, 'DIAJUKAN');
        $other = $this->makePermintaan($this->unitB, $this->gudangA, 'DIAJUKAN');

        $user = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::permintaan::barang',
            'page_Dashboard',
            'page_AdminLogistikDashboard',
        ]);
        $this->actingAs($user);

        Livewire::test(PermintaanBarangIndex::class)
            ->assertOk()
            ->assertSee($own->kode)
            ->assertDontSee($other->kode)
            ->call('showDetail', $other->id)
            ->assertForbidden();
    }

    public function test_amprah_tabs_follow_page_guard_permissions(): void
    {
        $user = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::permintaan::barang',
            'page_ApprovalPermintaanIndex',
            'page_DistribusiApprovalIndex',
        ]);
        $this->actingAs($user);
        $this->blade('<x-tabs.distribusi-amprah active="permintaan" />')
            ->assertDontSee('Otorisasi Karu')
            ->assertDontSee('Otorisasi Logistik');

        $user->givePermissionTo('view_any_layanan_unit::approval::karu');
        $this->blade('<x-tabs.distribusi-amprah active="permintaan" />')
            ->assertSee('Otorisasi Karu')
            ->assertDontSee('Otorisasi Logistik');
    }

    // ---------- Permintaan (amprah) ----------

    public function test_non_global_requester_can_only_submit_for_own_unit(): void
    {
        $user = $this->makeUser(['unit_id' => $this->unitA->id], [
            'create_layanan_unit::permintaan::barang',
            'view_any_layanan_unit::permintaan::barang',
        ]);
        $this->actingAs($user);

        Livewire::test(PermintaanBarangIndex::class)
            ->call('create')
            ->set('unit_id', $this->unitB->id)
            ->set('gudang_id', $this->gudangA->id)
            ->set('items', [['barang_id' => $this->barang->id, 'jumlah' => 2, 'catatan' => '']])
            ->call('save')
            ->assertHasNoErrors();

        $created = PermintaanDistribusi::where('diajukan_oleh', $user->id)->latest('id')->first();
        $this->assertNotNull($created);
        $this->assertSame((int) $this->unitA->id, (int) $created->unit_id);
        // Tanpa role kepala_unit, permohonan tetap melalui Meja 1
        $this->assertSame('DIAJUKAN', $created->status);
    }

    public function test_cancel_requires_permission_and_own_unit(): void
    {
        $requester = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::permintaan::barang',
            'create_layanan_unit::permintaan::barang',
        ]);
        $own = $this->makePermintaan($this->unitA, $this->gudangA, 'DIAJUKAN', $requester->id);
        $other = $this->makePermintaan($this->unitB, $this->gudangA, 'DIAJUKAN');

        $viewer = $this->makeUser(['unit_id' => $this->unitA->id], ['view_any_layanan_unit::permintaan::barang']);
        $this->actingAs($viewer);
        Livewire::test(PermintaanBarangIndex::class)->call('batal', $own->id)->assertForbidden();

        $this->actingAs($requester);
        Livewire::test(PermintaanBarangIndex::class)->call('batal', $other->id)->assertForbidden();
        Livewire::test(PermintaanBarangIndex::class)->call('batal', $own->id)->assertOk();

        $this->assertSame('DITOLAK', $own->fresh()->status);
        $this->assertSame('DIAJUKAN', $other->fresh()->status);
    }
    public function test_petugas_unit_cannot_cancel_approved_amprah_on_web(): void
    {
        $petugas = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::permintaan::barang',
            'create_layanan_unit::permintaan::barang',
        ], 'petugas_unit');

        $approved = $this->makePermintaan($this->unitA, $this->gudangA, 'DISETUJUI_KARU', $petugas->id);

        $this->actingAs($petugas);
        Livewire::test(PermintaanBarangIndex::class)
            ->call('batal', $approved->id)
            ->assertForbidden();

        $this->assertSame('DISETUJUI_KARU', $approved->fresh()->status);
    }

    public function test_petugas_unit_cannot_cancel_karu_amprah_on_web(): void
    {
        $karu = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::permintaan::barang',
            'create_layanan_unit::permintaan::barang',
        ], 'kepala_unit');

        $petugas = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::permintaan::barang',
            'create_layanan_unit::permintaan::barang',
        ], 'petugas_unit');

        $karuAmprah = $this->makePermintaan($this->unitA, $this->gudangA, 'DIAJUKAN', $karu->id);

        $this->actingAs($petugas);
        Livewire::test(PermintaanBarangIndex::class)
            ->call('batal', $karuAmprah->id)
            ->assertForbidden();

        $this->assertSame('DIAJUKAN', $karuAmprah->fresh()->status);
    }

    // ---------- Otorisasi Karu (Meja 1) ----------

    public function test_karu_approval_permission_without_kepala_unit_role_is_denied(): void
    {
        $permintaan = $this->makePermintaan($this->unitA, $this->gudangA, 'DIAJUKAN');

        $user = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::approval::karu',
            'update_layanan_unit::approval::karu',
        ], 'petugas_unit');
        $this->actingAs($user);

        Livewire::test(ApprovalPermintaanIndex::class)->call('approveKaru', $permintaan->id)->assertForbidden();
        $this->assertSame('DIAJUKAN', $permintaan->fresh()->status);
    }

    public function test_kepala_gudang_role_cannot_approve_meja_1(): void
    {
        $permintaan = $this->makePermintaan($this->unitA, $this->gudangA, 'DIAJUKAN');

        $user = $this->makeUser(['gudang_id' => $this->gudangA->id], [], 'kepala_gudang');
        $this->actingAs($user);

        Livewire::test(ApprovalPermintaanIndex::class)->call('approveKaru', $permintaan->id)->assertForbidden();
        $this->assertSame('DIAJUKAN', $permintaan->fresh()->status);
    }

    public function test_kepala_unit_approves_own_unit_but_not_other_unit(): void
    {
        $own = $this->makePermintaan($this->unitA, $this->gudangA, 'DIAJUKAN');
        $other = $this->makePermintaan($this->unitB, $this->gudangA, 'DIAJUKAN');

        $karu = $this->makeUser(['unit_id' => $this->unitA->id], [], 'kepala_unit');
        $this->actingAs($karu);

        Livewire::test(ApprovalPermintaanIndex::class)
            ->assertSee($own->kode)
            ->assertDontSee($other->kode)
            ->call('approveKaru', $other->id)
            ->assertForbidden();

        Livewire::test(ApprovalPermintaanIndex::class)->call('approveKaru', $own->id)->assertOk();

        $this->assertSame('DISETUJUI_KARU', $own->fresh()->status);
        $this->assertSame('DIAJUKAN', $other->fresh()->status);
    }

    // ---------- Otorisasi Gudang (Meja 2) ----------

    public function test_gudang_approval_permission_without_kepala_gudang_role_is_denied(): void
    {
        $permintaan = $this->makePermintaan($this->unitA, $this->gudangA, 'DISETUJUI_KARU');

        $user = $this->makeUser(['gudang_id' => $this->gudangA->id], [
            'view_any_gudang_stok::distribusi::approval',
            'update_gudang_stok::distribusi::approval',
        ], 'petugas_gudang');
        $this->actingAs($user);

        Livewire::test(DistribusiApprovalIndex::class)->call('rejectGudang', $permintaan->id)->assertForbidden();
        Livewire::test(DistribusiApprovalIndex::class)->call('openReview', $permintaan->id)->assertForbidden();
        $this->assertSame('DISETUJUI_KARU', $permintaan->fresh()->status);
    }

    public function test_kepala_gudang_processes_own_gudang_but_not_other_gudang(): void
    {
        $own = $this->makePermintaan($this->unitA, $this->gudangA, 'DISETUJUI_KARU');
        $other = $this->makePermintaan($this->unitA, $this->gudangB, 'DISETUJUI_KARU');

        $kagud = $this->makeUser(['gudang_id' => $this->gudangA->id], [], 'kepala_gudang');
        $this->actingAs($kagud);

        Livewire::test(DistribusiApprovalIndex::class)
            ->assertSee($own->kode)
            ->assertDontSee($other->kode)
            ->call('rejectGudang', $other->id)
            ->assertForbidden();

        Livewire::test(DistribusiApprovalIndex::class)->call('openDetail', $other->id)->assertForbidden();
        Livewire::test(DistribusiApprovalIndex::class)->call('rejectGudang', $own->id)->assertOk();

        $this->assertSame('DITOLAK', $own->fresh()->status);
        $this->assertSame('DISETUJUI_KARU', $other->fresh()->status);
    }

    // ---------- Picking FEFO ----------

    public function test_picking_requires_create_permission_and_own_gudang(): void
    {
        $own = $this->makePermintaan($this->unitA, $this->gudangA, 'DISETUJUI_GUDANG');
        $other = $this->makePermintaan($this->unitA, $this->gudangB, 'DISETUJUI_GUDANG');

        // Halaman/dashboard tanpa permission create picking tidak boleh mengeksekusi
        $viewer = $this->makeUser(['gudang_id' => $this->gudangA->id], [
            'view_any_gudang_stok::picking::distribusi',
            'page_GudangDashboard',
            'page_PickingDistribusiIndex',
        ]);
        $this->actingAs($viewer);
        Livewire::test(PickingDistribusiIndex::class)->call('confirmPicking', $own->id)->assertForbidden();

        $picker = $this->makeUser(['gudang_id' => $this->gudangA->id], [
            'view_any_gudang_stok::picking::distribusi',
            'create_gudang_stok::picking::distribusi',
        ]);
        $this->actingAs($picker);

        Livewire::test(PickingDistribusiIndex::class)
            ->assertSee($own->kode)
            ->assertDontSee($other->kode)
            ->call('executePicking', $other->id)
            ->assertForbidden();

        Livewire::test(PickingDistribusiIndex::class)->call('showPermintaanDetail', $other->id)->assertForbidden();
        Livewire::test(PickingDistribusiIndex::class)->call('confirmPicking', $own->id)->assertOk();
    }

    public function test_picking_cannot_skip_gudang_approval_stage(): void
    {
        $permintaan = $this->makePermintaan($this->unitA, $this->gudangA, 'DISETUJUI_KARU');

        $picker = $this->makeUser(['gudang_id' => $this->gudangA->id], [
            'view_any_gudang_stok::picking::distribusi',
            'create_gudang_stok::picking::distribusi',
        ]);
        $this->actingAs($picker);

        Livewire::test(PickingDistribusiIndex::class)->call('executePicking', $permintaan->id)->assertOk();

        $this->assertSame('DISETUJUI_KARU', $permintaan->fresh()->status);
        $this->assertFalse(PengirimanBarang::where('permintaan_distribusi_id', $permintaan->id)->exists());
    }

    // ---------- Penerimaan di ruangan ----------

    public function test_receipt_requires_update_permission_and_own_unit(): void
    {
        $permintaanA = $this->makePermintaan($this->unitA, $this->gudangA, 'SEDANG_DIKIRIM');
        $permintaanB = $this->makePermintaan($this->unitB, $this->gudangA, 'SEDANG_DIKIRIM');
        $makeSppb = fn (PermintaanDistribusi $p) => PengirimanBarang::create([
            'kode' => CodeGenerator::generate('SPPB', PengirimanBarang::class, 'kode', 'Ymd', 4),
            'permintaan_distribusi_id' => $p->id,
            'gudang_id' => $p->gudang_id,
            'unit_id' => $p->unit_id,
            'tanggal_kirim' => now()->toDateString(),
            'status' => 'DIKIRIM',
            'dikirim_oleh' => User::where('email', 'superadmin@simlogrs.local')->value('id'),
        ]);
        $own = $makeSppb($permintaanA);
        $other = $makeSppb($permintaanB);

        $viewer = $this->makeUser(['unit_id' => $this->unitA->id], ['view_any_layanan_unit::penerimaan::barang']);
        $this->actingAs($viewer);
        Livewire::test(PenerimaanBarangIndex::class)->call('confirmTerima', $own->id)->assertForbidden();

        $receiver = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::penerimaan::barang',
            'update_layanan_unit::penerimaan::barang',
        ]);
        $this->actingAs($receiver);

        Livewire::test(PenerimaanBarangIndex::class)
            ->assertSee($own->kode)
            ->assertDontSee($other->kode)
            ->call('confirmTerima', $other->id)
            ->assertForbidden();

        Livewire::test(PenerimaanBarangIndex::class)->call('showDetail', $other->id)->assertForbidden();
        Livewire::test(PenerimaanBarangIndex::class)->call('confirmTerima', $own->id)->assertOk();
    }

    // ---------- Persediaan ruangan ----------

    public function test_stok_ruangan_role_alone_cannot_change_asset_status(): void
    {
        $aset = Aset::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudangA->id,
            'kode_aset' => 'AST-RBAC-' . uniqid(),
            'nomor_seri' => 'SN-RBAC-1',
            'tanggal_perolehan' => now()->toDateString(),
            'status' => 'TERSEDIA',
            'kondisi' => 'BAIK',
        ]);

        // Role petugas_gudang tanpa permission update persediaan ruangan tidak boleh mengubah status aset.
        $user = $this->makeUser(['gudang_id' => $this->gudangA->id], [], 'petugas_gudang');
        $this->actingAs($user);

        Livewire::test(StokRuanganIndex::class)->assertOk()
            ->call('openUbahStatusAset', $aset->id)->assertForbidden();
    }

    public function test_lapor_servis_requires_permission_and_own_facility(): void
    {
        $aset = Aset::create([
            'barang_id' => $this->barang->id,
            'gudang_id' => $this->gudangA->id,
            'kode_aset' => 'AST-RBAC-' . uniqid(),
            'nomor_seri' => 'SN-RBAC-2',
            'tanggal_perolehan' => now()->toDateString(),
            'status' => 'TERSEDIA',
            'kondisi' => 'BAIK',
        ]);

        // Hanya boleh melihat halaman (komponen termuat), tanpa permission lapor servis: ditolak.
        $tanpaIzin = $this->makeUser(['gudang_id' => $this->gudangA->id], ['view_any_layanan_unit::stok::ruangan']);
        $this->actingAs($tanpaIzin);
        Livewire::test(StokRuanganIndex::class)->call('openLaporServis', $aset->id)->assertForbidden();

        // Permission ada tetapi aset milik gudang lain: ditolak.
        $gudangLain = $this->makeUser(['gudang_id' => $this->gudangB->id], ['view_any_layanan_unit::stok::ruangan', 'create_layanan_unit::lapor::servis']);
        $this->actingAs($gudangLain);
        Livewire::test(StokRuanganIndex::class)->call('openLaporServis', $aset->id)->assertForbidden();

        // Permission ada dan aset di gudang sendiri: lolos.
        $pemilik = $this->makeUser(['gudang_id' => $this->gudangA->id], ['view_any_layanan_unit::stok::ruangan', 'create_layanan_unit::lapor::servis']);
        $this->actingAs($pemilik);
        Livewire::test(StokRuanganIndex::class)->call('openLaporServis', $aset->id)->assertOk();
    }

    public function test_stok_ruangan_consumption_requires_permission_and_own_unit(): void
    {
        $own = StokRuangan::updateOrCreate(['unit_id' => $this->unitA->id, 'barang_id' => $this->barang->id], ['jumlah' => 10]);
        $other = StokRuangan::updateOrCreate(['unit_id' => $this->unitB->id, 'barang_id' => $this->barang->id], ['jumlah' => 10]);

        $viewer = $this->makeUser(['unit_id' => $this->unitA->id], ['view_any_layanan_unit::stok::ruangan']);
        $this->actingAs($viewer);
        Livewire::test(StokRuanganIndex::class)->call('openCatatPemakaian', $own->id)->assertForbidden();

        $editor = $this->makeUser(['unit_id' => $this->unitA->id], [
            'view_any_layanan_unit::stok::ruangan',
            'update_layanan_unit::stok::ruangan',
        ]);
        $this->actingAs($editor);

        Livewire::test(StokRuanganIndex::class)->call('openCatatPemakaian', $other->id)->assertForbidden();
        Livewire::test(StokRuanganIndex::class)
            ->call('openCatatPemakaian', $own->id)
            ->assertSet('showPakaiModal', true)
            ->set('jumlah_pakai', 2)
            ->set('keterangan', 'Uji otorisasi pemakaian')
            ->call('simpanPemakaian');

        $this->assertSame(8, (int) $own->fresh()->jumlah);
        $this->assertSame(10, (int) $other->fresh()->jumlah);
    }

    public function test_global_reader_with_unit_placement_can_still_read_all_units(): void
    {
        $reqA = $this->makePermintaan($this->unitA, $this->gudangA, 'DIAJUKAN');
        $reqB = $this->makePermintaan($this->unitB, $this->gudangA, 'DIAJUKAN');

        $auditor = $this->makeUser(['unit_id' => $this->unitA->id, 'gudang_id' => $this->gudangA->id], [
            'view_any_layanan_unit::permintaan::barang',
        ], 'auditor');

        $this->actingAs($auditor);
        Livewire::test(PermintaanBarangIndex::class)
            ->assertOk()
            ->assertSee($reqA->kode)
            ->assertSee($reqB->kode)
            ->call('showDetail', $reqB->id)
            ->assertOk();
    }
}
