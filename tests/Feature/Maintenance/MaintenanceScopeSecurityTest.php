<?php

namespace Tests\Feature\Maintenance;

use App\Models\Aset\Aset;
use App\Models\Aset\PermintaanPeminjaman;
use App\Models\Aset\PermintaanPeminjamanDetail;
use App\Models\Ipsrs\PermintaanMaintenance;
use App\Models\Master\Barang;
use App\Models\Master\Gudang;
use App\Models\Master\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaintenanceScopeSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected Unit $unitIgd;
    protected Unit $unitOther;
    protected User $karuIgd;
    protected User $karuOther;
    protected User $globalAdmin;
    protected User $kepalaTeknisi;
    protected Aset $asetIgd;
    protected Aset $asetOther;
    protected PermintaanMaintenance $tiketIgd;
    protected PermintaanMaintenance $tiketOther;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unitIgd = Unit::where('kode', 'IGD')->first() ?: Unit::find(1);
        $this->unitOther = Unit::where('id', '!=', $this->unitIgd->id)->first();

        $barang = Barang::where('status', 'AKTIF')->first();

        $this->karuIgd = User::create([
            'nama'     => 'Karu IGD Tester',
            'username' => 'karu_igd_' . uniqid(),
            'email'    => 'karu_igd_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => $this->unitIgd->id,
            'status'   => 'AKTIF',
        ]);
        $this->karuIgd->assignRole('kepala_unit');

        $this->karuOther = User::create([
            'nama'     => 'Karu Other Tester',
            'username' => 'karu_other_' . uniqid(),
            'email'    => 'karu_other_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => $this->unitOther->id,
            'status'   => 'AKTIF',
        ]);
        $this->karuOther->assignRole('kepala_unit');

        $this->globalAdmin = User::create([
            'nama'     => 'Super Admin Tester',
            'username' => 'super_admin_' . uniqid(),
            'email'    => 'super_admin_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'status'   => 'AKTIF',
        ]);
        $this->globalAdmin->assignRole('super_admin');

        $this->kepalaTeknisi = User::create([
            'nama'     => 'Kepala Teknisi Tester',
            'username' => 'katek_' . uniqid(),
            'email'    => 'katek_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => $this->unitOther->id,
            'status'   => 'AKTIF',
        ]);
        $this->kepalaTeknisi->assignRole('kepala_teknisi');

        $this->asetIgd = Aset::create([
            'barang_id'         => $barang->id,
            'unit_id'           => $this->unitIgd->id,
            'kode_aset'         => 'AST-IGD-' . uniqid(),
            'nomor_seri'        => 'SN-IGD-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'DIGUNAKAN',
        ]);

        $this->tiketIgd = PermintaanMaintenance::create([
            'kode'              => 'MNT-IGD-' . uniqid(),
            'aset_id'           => $this->asetIgd->id,
            'unit_id'           => $this->unitIgd->id,
            'dilaporkan_oleh'   => $this->karuIgd->id,
            'deskripsi_masalah' => 'Kerusakan sarana IGD',
            'prioritas'         => 'SEDANG',
            'status'            => 'DILAPORKAN',
            'dibuat_pada'       => now(),
        ]);

        $this->asetOther = Aset::create([
            'barang_id'         => $barang->id,
            'unit_id'           => $this->unitOther->id,
            'kode_aset'         => 'AST-OTH-' . uniqid(),
            'nomor_seri'        => 'SN-OTH-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'DIGUNAKAN',
        ]);

        $this->tiketOther = PermintaanMaintenance::create([
            'kode'              => 'MNT-OTH-' . uniqid(),
            'aset_id'           => $this->asetOther->id,
            'unit_id'           => $this->unitOther->id,
            'dilaporkan_oleh'   => $this->karuOther->id,
            'deskripsi_masalah' => 'Kerusakan sarana unit lain',
            'prioritas'         => 'SEDANG',
            'status'            => 'DILAPORKAN',
            'dibuat_pada'       => now(),
        ]);
    }

    public function test_kepala_unit_only_sees_tickets_of_own_unit_in_get_maintenance_tiket(): void
    {
        $response = $this->actingAs($this->karuIgd)->getJson('/api/v1/maintenance/tiket');

        $response->assertOk();
        $response->assertJsonPath('sukses', true);

        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $this->tiketIgd->id));
        $this->assertFalse($data->contains('id', $this->tiketOther->id));
    }

    public function test_kepala_unit_receives_403_for_ticket_of_another_unit(): void
    {
        $response = $this->actingAs($this->karuIgd)->getJson("/api/v1/maintenance/tiket/{$this->tiketOther->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('sukses', false);
        $response->assertJsonPath('pesan', 'Akses ditolak: Anda tidak memiliki wewenang melihat tiket pemeliharaan unit lain.');
    }

    public function test_kepala_unit_receives_200_for_ticket_of_own_unit(): void
    {
        $response = $this->actingAs($this->karuIgd)->getJson("/api/v1/maintenance/tiket/{$this->tiketIgd->id}");

        $response->assertOk();
        $response->assertJsonPath('sukses', true);
        $response->assertJsonPath('data.id', $this->tiketIgd->id);
    }

    public function test_global_admin_receives_200_for_ticket_of_any_unit(): void
    {
        $responseIgd = $this->actingAs($this->globalAdmin)->getJson("/api/v1/maintenance/tiket/{$this->tiketIgd->id}");
        $responseIgd->assertOk();
        $responseIgd->assertJsonPath('data.id', $this->tiketIgd->id);

        $responseOther = $this->actingAs($this->globalAdmin)->getJson("/api/v1/maintenance/tiket/{$this->tiketOther->id}");
        $responseOther->assertOk();
        $responseOther->assertJsonPath('data.id', $this->tiketOther->id);
    }

    public function test_kepala_teknisi_receives_200_for_ticket_of_any_unit(): void
    {
        $responseIgd = $this->actingAs($this->kepalaTeknisi)->getJson("/api/v1/maintenance/tiket/{$this->tiketIgd->id}");
        $responseIgd->assertOk();
        $responseIgd->assertJsonPath('data.id', $this->tiketIgd->id);

        $responseOther = $this->actingAs($this->kepalaTeknisi)->getJson("/api/v1/maintenance/tiket/{$this->tiketOther->id}");
        $responseOther->assertOk();
        $responseOther->assertJsonPath('data.id', $this->tiketOther->id);
    }

    public function test_formatted_ticket_output_includes_is_aset_pinjaman(): void
    {
        $barang = Barang::where('status', 'AKTIF')->first();

        $asetPinjam = Aset::create([
            'barang_id'         => $barang->id,
            'unit_id'           => $this->unitIgd->id,
            'kode_aset'         => 'AST-PINJ-' . uniqid(),
            'nomor_seri'        => 'SN-PINJ-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'DIPINJAM',
        ]);

        $pinjaman = PermintaanPeminjaman::create([
            'kode'                    => 'PINJ-TEST-' . uniqid(),
            'tipe_penyedia'           => 'RUANGAN',
            'unit_penyedia_id'        => $this->unitOther->id,
            'unit_id'                 => $this->unitIgd->id,
            'diajukan_oleh'           => $this->karuIgd->id,
            'tanggal_pinjam'          => now()->toDateString(),
            'tanggal_kembali_rencana' => now()->addDays(7)->toDateString(),
            'status'                  => 'DIPINJAM',
        ]);

        PermintaanPeminjamanDetail::create([
            'permintaan_peminjaman_id' => $pinjaman->id,
            'aset_id'                  => $asetPinjam->id,
        ]);

        $tiketPinjam = PermintaanMaintenance::create([
            'kode'              => 'MNT-PINJ-' . uniqid(),
            'aset_id'           => $asetPinjam->id,
            'unit_id'           => $this->unitIgd->id,
            'dilaporkan_oleh'   => $this->karuIgd->id,
            'deskripsi_masalah' => 'Kerusakan sarana pinjaman',
            'prioritas'         => 'SEDANG',
            'status'            => 'DILAPORKAN',
            'dibuat_pada'       => now(),
        ]);

        $resIndex = $this->actingAs($this->karuIgd)->getJson('/api/v1/maintenance/tiket');
        $resIndex->assertOk();

        $itemIndex = collect($resIndex->json('data'))->firstWhere('id', $tiketPinjam->id);
        $this->assertNotNull($itemIndex);
        $this->assertTrue($itemIndex['is_aset_pinjaman']);
        $this->assertSame($this->unitOther->nama, $itemIndex['nama_penyedia_asal']);

        $resDetail = $this->actingAs($this->karuIgd)->getJson("/api/v1/maintenance/tiket/{$tiketPinjam->id}");
        $resDetail->assertOk();
        $resDetail->assertJsonPath('data.is_aset_pinjaman', true);
        $resDetail->assertJsonPath('data.nama_penyedia_asal', $this->unitOther->nama);
    }

    public function test_warehouse_user_sees_tickets_reported_by_self_or_for_warehouse_asset(): void
    {
        $gudang = Gudang::first();
        $userGudang = User::create([
            'nama'      => 'Karu Gudang Tester',
            'username'  => 'karu_gudang_' . uniqid(),
            'email'     => 'karu_gudang_' . uniqid() . '@simlog.test',
            'password'  => bcrypt('password'),
            'gudang_id' => $gudang->id,
            'unit_id'   => null,
            'status'    => 'AKTIF',
        ]);
        $userGudang->assignRole('kepala_gudang');

        $barang = Barang::where('status', 'AKTIF')->first();
        $asetGudang = Aset::create([
            'barang_id'         => $barang->id,
            'gudang_id'         => $gudang->id,
            'kode_aset'         => 'AST-GDG-' . uniqid(),
            'nomor_seri'        => 'SN-GDG-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'TERSEDIA',
        ]);

        $tiketGudang = PermintaanMaintenance::create([
            'kode'              => 'MNT-GDG-' . uniqid(),
            'aset_id'           => $asetGudang->id,
            'unit_id'           => null,
            'dilaporkan_oleh'   => $this->karuOther->id,
            'deskripsi_masalah' => 'Kerusakan sarana gudang',
            'prioritas'         => 'SEDANG',
            'status'            => 'DILAPORKAN',
            'dibuat_pada'       => now(),
        ]);

        $res = $this->actingAs($userGudang)->getJson('/api/v1/maintenance/tiket');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($tiketGudang->id, $ids);
        $this->assertNotContains($this->tiketIgd->id, $ids);
    }

    public function test_user_without_unit_and_gudang_sees_empty_result(): void
    {
        $plainUser = User::create([
            'nama'     => 'Plain Tester',
            'username' => 'plain_' . uniqid(),
            'email'    => 'plain_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => null,
            'status'   => 'AKTIF',
        ]);
        $plainUser->givePermissionTo('view_layanan_unit::lapor::servis');

        $res = $this->actingAs($plainUser)->getJson('/api/v1/maintenance/tiket');
        $res->assertOk();
        $this->assertEmpty($res->json('data'));
    }

    public function test_auditor_without_unit_sees_all_tickets(): void
    {
        $auditor = User::create([
            'nama'     => 'Auditor Tester',
            'username' => 'auditor_' . uniqid(),
            'email'    => 'auditor_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => null,
            'status'   => 'AKTIF',
        ]);
        $auditor->assignRole('auditor');

        $res = $this->actingAs($auditor)->getJson('/api/v1/maintenance/tiket');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($this->tiketIgd->id, $ids);
        $this->assertContains($this->tiketOther->id, $ids);
    }

    public function test_manajemen_without_unit_sees_all_tickets(): void
    {
        $manajemen = User::create([
            'nama'     => 'Manajemen Tester',
            'username' => 'manajemen_' . uniqid(),
            'email'    => 'manajemen_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => null,
            'status'   => 'AKTIF',
        ]);
        $manajemen->assignRole('manajemen');

        $res = $this->actingAs($manajemen)->getJson('/api/v1/maintenance/tiket');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($this->tiketIgd->id, $ids);
        $this->assertContains($this->tiketOther->id, $ids);
    }
    public function test_aset_unit_tersedia_for_room_user_includes_own_and_borrowed_assets_but_excludes_other_units(): void
    {
        $barang = Barang::where('status', 'AKTIF')->first();

        $asetOwn = Aset::create([
            'barang_id'         => $barang->id,
            'unit_id'           => $this->unitIgd->id,
            'kode_aset'         => 'AST-OWN-' . uniqid(),
            'nomor_seri'        => 'SN-OWN-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'TERSEDIA',
        ]);

        $asetPinjam = Aset::create([
            'barang_id'         => $barang->id,
            'unit_id'           => $this->unitIgd->id,
            'kode_aset'         => 'AST-PINJ-' . uniqid(),
            'nomor_seri'        => 'SN-PINJ-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'DIPINJAM',
        ]);

        $pinjaman = PermintaanPeminjaman::create([
            'kode'                    => 'PINJ-TEST-' . uniqid(),
            'tipe_penyedia'           => 'RUANGAN',
            'unit_penyedia_id'        => $this->unitOther->id,
            'unit_id'                 => $this->unitIgd->id,
            'diajukan_oleh'           => $this->karuIgd->id,
            'tanggal_pinjam'          => now()->toDateString(),
            'tanggal_kembali_rencana' => now()->addDays(7)->toDateString(),
            'status'                  => 'DIPINJAM',
        ]);

        PermintaanPeminjamanDetail::create([
            'permintaan_peminjaman_id' => $pinjaman->id,
            'aset_id'                  => $asetPinjam->id,
        ]);

        $asetOther = Aset::create([
            'barang_id'         => $barang->id,
            'unit_id'           => $this->unitOther->id,
            'kode_aset'         => 'AST-OTH-AVAIL-' . uniqid(),
            'nomor_seri'        => 'SN-OTH-AVAIL-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'TERSEDIA',
        ]);

        $res = $this->actingAs($this->karuIgd)->getJson('/api/v1/maintenance/aset-unit');
        $res->assertOk();

        $data = $res->json('data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($asetOwn->id, $ids);
        $this->assertContains($asetPinjam->id, $ids);
        $this->assertNotContains($asetOther->id, $ids);

        $ownItem = collect($data)->firstWhere('id', $asetOwn->id);
        $this->assertFalse($ownItem['is_pinjaman']);
        $this->assertNull($ownItem['asal_penyedia']);

        $pinjamItem = collect($data)->firstWhere('id', $asetPinjam->id);
        $this->assertTrue($pinjamItem['is_pinjaman']);
        $this->assertSame($this->unitOther->nama, $pinjamItem['asal_penyedia']);
    }

    public function test_aset_unit_tersedia_for_warehouse_user_returns_warehouse_assets_only(): void
    {
        $gudang = Gudang::first();
        $userGudang = User::create([
            'nama'      => 'Karu Gudang Tester ' . uniqid(),
            'username'  => 'karu_gudang_' . uniqid(),
            'email'     => 'karu_gudang_' . uniqid() . '@simlog.test',
            'password'  => bcrypt('password'),
            'gudang_id' => $gudang->id,
            'unit_id'   => null,
            'status'    => 'AKTIF',
        ]);
        $userGudang->assignRole('kepala_gudang');

        $barang = Barang::where('status', 'AKTIF')->first();
        $asetGudang = Aset::create([
            'barang_id'         => $barang->id,
            'gudang_id'         => $gudang->id,
            'kode_aset'         => 'AST-GDG-' . uniqid(),
            'nomor_seri'        => 'SN-GDG-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'TERSEDIA',
        ]);

        $res = $this->actingAs($userGudang)->getJson('/api/v1/maintenance/aset-unit');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($asetGudang->id, $ids);
        $this->assertNotContains($this->asetIgd->id, $ids);
        $this->assertNotContains($this->asetOther->id, $ids);
    }

    public function test_aset_unit_tersedia_for_user_without_unit_and_gudang_returns_empty(): void
    {
        $plainUser = User::create([
            'nama'     => 'Plain Tester ' . uniqid(),
            'username' => 'plain_' . uniqid(),
            'email'    => 'plain_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => null,
            'gudang_id'=> null,
            'status'   => 'AKTIF',
        ]);

        $res = $this->actingAs($plainUser)->getJson('/api/v1/maintenance/aset-unit');
        $res->assertOk();
        $this->assertEmpty($res->json('data'));
    }

    public function test_lapor_cepat_allowed_for_own_unit_asset(): void
    {
        $res = $this->actingAs($this->karuIgd)->postJson('/api/v1/maintenance/lapor-cepat', [
            'aset_id'           => $this->asetIgd->id,
            'deskripsi_masalah' => 'Kerusakan fisik sarana milik unit IGD sendiri',
        ]);

        $res->assertStatus(201);
        $tiketId = $res->json('data.id');
        $this->assertNotNull($tiketId);
        $this->assertDatabaseHas('permintaan_maintenance', [
            'id'      => $tiketId,
            'aset_id' => $this->asetIgd->id,
            'unit_id' => $this->unitIgd->id,
        ]);
    }

    public function test_lapor_cepat_allowed_for_actively_borrowed_asset_option_a(): void
    {
        $barang = Barang::where('status', 'AKTIF')->first();

        $asetPinjam = Aset::create([
            'barang_id'         => $barang->id,
            'unit_id'           => $this->unitIgd->id,
            'kode_aset'         => 'AST-PINJ-LAPOR-' . uniqid(),
            'nomor_seri'        => 'SN-PINJ-LAPOR-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'DIPINJAM',
        ]);

        $pinjaman = PermintaanPeminjaman::create([
            'kode'                    => 'PINJ-TEST-' . uniqid(),
            'tipe_penyedia'           => 'RUANGAN',
            'unit_penyedia_id'        => $this->unitOther->id,
            'unit_id'                 => $this->unitIgd->id,
            'diajukan_oleh'           => $this->karuIgd->id,
            'tanggal_pinjam'          => now()->toDateString(),
            'tanggal_kembali_rencana' => now()->addDays(7)->toDateString(),
            'status'                  => 'DIPINJAM',
        ]);

        PermintaanPeminjamanDetail::create([
            'permintaan_peminjaman_id' => $pinjaman->id,
            'aset_id'                  => $asetPinjam->id,
        ]);

        // Client passes other unit_id, but it must be ignored and forced to user's unit_id
        $res = $this->actingAs($this->karuIgd)->postJson('/api/v1/maintenance/lapor-cepat', [
            'aset_id'           => $asetPinjam->id,
            'unit_id'           => $this->unitOther->id,
            'deskripsi_masalah' => 'Kerusakan fisik sarana pinjaman aktif IGD',
        ]);

        $res->assertStatus(201);
        $tiketId = $res->json('data.id');
        $this->assertNotNull($tiketId);
        $this->assertDatabaseHas('permintaan_maintenance', [
            'id'      => $tiketId,
            'aset_id' => $asetPinjam->id,
            'unit_id' => $this->unitIgd->id,
        ]);
    }

    public function test_lapor_cepat_denied_403_for_asset_of_another_unit_not_borrowed(): void
    {
        $res = $this->actingAs($this->karuIgd)->postJson('/api/v1/maintenance/lapor-cepat', [
            'aset_id'           => $this->asetOther->id,
            'deskripsi_masalah' => 'Mencoba lapor kerusakan sarana unit lain',
        ]);

        $res->assertStatus(403);
        $res->assertJsonFragment([
            'pesan' => 'Anda hanya berhak melaporkan kerusakan untuk aset milik unit Anda atau aset yang sedang aktif dipinjam oleh unit Anda.',
        ]);
    }

    public function test_lapor_cepat_denied_403_for_warehouse_asset_by_room_user(): void
    {
        $gudang = Gudang::first();
        $barang = Barang::where('status', 'AKTIF')->first();

        $asetGudang = Aset::create([
            'barang_id'         => $barang->id,
            'gudang_id'         => $gudang->id,
            'kode_aset'         => 'AST-GDG-DENY-' . uniqid(),
            'nomor_seri'        => 'SN-GDG-DENY-' . uniqid(),
            'tanggal_perolehan' => now()->toDateString(),
            'kondisi'           => 'BAIK',
            'status'            => 'TERSEDIA',
        ]);

        $res = $this->actingAs($this->karuIgd)->postJson('/api/v1/maintenance/lapor-cepat', [
            'aset_id'           => $asetGudang->id,
            'deskripsi_masalah' => 'Mencoba lapor kerusakan sarana gudang oleh user unit',
        ]);

        $res->assertStatus(403);
        $res->assertJsonFragment([
            'pesan' => 'Anda hanya berhak melaporkan kerusakan untuk aset milik unit Anda atau aset yang sedang aktif dipinjam oleh unit Anda.',
        ]);
    }

    public function test_spk_list_scopes_regular_technician_to_own_spk_even_with_view_any_permission(): void
    {
        $tek1 = User::create([
            'nama'     => 'Teknisi 1 SPK',
            'username' => 'tek1_spk_' . uniqid(),
            'email'    => 'tek1_spk_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'status'   => 'AKTIF',
        ]);
        $tek1->assignRole('teknisi');
        $tek1->givePermissionTo('view_any_pemeliharaan::spk::perbaikan');

        $tek2 = User::create([
            'nama'     => 'Teknisi 2 SPK',
            'username' => 'tek2_spk_' . uniqid(),
            'email'    => 'tek2_spk_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'status'   => 'AKTIF',
        ]);
        $tek2->assignRole('teknisi');

        \App\Models\Ipsrs\PenugasanMaintenance::create([
            'kode' => 'SPK-TEST-TEK1-' . uniqid(),
            'permintaan_maintenance_id' => $this->tiketIgd->id,
            'teknisi_id' => $tek1->id,
            'ditugaskan_oleh' => $this->globalAdmin->id,
            'tanggal_penugasan' => now()->toDateString(),
            'target_selesai' => now()->addDays(2)->toDateString(),
            'status' => 'DITUGASKAN',
        ]);
        $spkTek2 = \App\Models\Ipsrs\PenugasanMaintenance::create([
            'kode' => 'SPK-TEST-TEK2-' . uniqid(),
            'permintaan_maintenance_id' => $this->tiketOther->id,
            'teknisi_id' => $tek2->id,
            'ditugaskan_oleh' => $this->globalAdmin->id,
            'tanggal_penugasan' => now()->toDateString(),
            'target_selesai' => now()->addDays(2)->toDateString(),
            'status' => 'DITUGASKAN',
        ]);

        $res = $this->actingAs($tek1)->getJson('/api/v1/maintenance/spk')->assertOk();
        $kodes = collect($res->json('data.items'))->pluck('kode_spk')->all();
        $this->assertNotContains($spkTek2->kode, $kodes);
    }
}
