<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\RegisterTemuanCapaIndex;
use App\Models\Audit\TemuanAudit;
use App\Models\Master\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CapaAksesTest extends TestCase
{
    use DatabaseTransactions;

    private User $kepalaIgd;
    private User $kepalaIcu;
    private User $auditor;
    private User $superAdmin;
    private TemuanAudit $temuanIgd;
    private TemuanAudit $temuanIcu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kepalaIgd = User::where('username', 'kepalaunit')->firstOrFail();
        $this->kepalaIcu = User::where('username', 'karu_icu')->firstOrFail();
        $this->auditor = User::where('username', 'auditor')->firstOrFail();
        $this->superAdmin = User::where('username', 'superadmin')->firstOrFail();

        $this->temuanIgd = $this->buatTemuan('TST-CAPA-IGD', Unit::where('kode', 'IGD')->value('id'));
        $this->temuanIcu = $this->buatTemuan('TST-CAPA-ICU', Unit::where('kode', 'ICU')->value('id'));
    }

    private function buatTemuan(string $kode, int $unitId): TemuanAudit
    {
        return TemuanAudit::create([
            'kode'           => $kode,
            'tanggal_audit'  => now()->toDateString(),
            'objek_audit'    => "Objek {$kode}",
            'ringkasan'      => "Ringkasan temuan {$kode}",
            'tingkat_risiko' => 'Moderat',
            'due_date_capa'  => now()->addDays(14)->toDateString(),
            'status_capa'    => 'In Progress',
            'auditor_id'     => $this->auditor->id,
            'unit_id'        => $unitId,
        ]);
    }

    public function test_api_kepala_igd_hanya_melihat_temuan_igd_dengan_field_id(): void
    {
        Sanctum::actingAs($this->kepalaIgd);

        $res = $this->getJson('/api/v1/audit/temuan-capa')->assertOk();
        $items = collect($res->json('data.items'));

        $this->assertTrue($items->contains('kode', 'TST-CAPA-IGD'));
        $this->assertFalse($items->contains('kode', 'TST-CAPA-ICU'));

        $igd = $items->firstWhere('kode', 'TST-CAPA-IGD');
        $this->assertSame($this->temuanIgd->unit_id, $igd['unit_id']);
        $this->assertArrayHasKey('gudang_id', $igd);
        $this->assertNull($igd['gudang_id']);
    }

    public function test_livewire_kepala_igd_hanya_melihat_temuan_igd(): void
    {
        Livewire::actingAs($this->kepalaIgd)
            ->test(RegisterTemuanCapaIndex::class)
            ->assertSee('TST-CAPA-IGD')
            ->assertDontSee('TST-CAPA-ICU');
    }

    public function test_livewire_kepala_icu_tidak_melihat_temuan_igd(): void
    {
        Livewire::actingAs($this->kepalaIcu)
            ->test(RegisterTemuanCapaIndex::class)
            ->assertSee('TST-CAPA-ICU')
            ->assertDontSee('TST-CAPA-IGD');
    }

    public function test_livewire_auditor_melihat_semua_temuan(): void
    {
        Livewire::actingAs($this->auditor)
            ->test(RegisterTemuanCapaIndex::class)
            ->assertSee('TST-CAPA-IGD')
            ->assertSee('TST-CAPA-ICU');
    }

    public function test_otorisasi_tindak_lanjut_livewire(): void
    {
        $komponen = fn (User $u) => Livewire::actingAs($u)->test(RegisterTemuanCapaIndex::class)->instance();

        $kIgd = $komponen($this->kepalaIgd);
        $this->assertTrue($kIgd->canUserManageTindakLanjut($this->kepalaIgd, $this->temuanIgd));
        $this->assertFalse($kIgd->canUserManageTindakLanjut($this->kepalaIgd, $this->temuanIcu));

        $kAud = $komponen($this->auditor);
        $this->assertFalse($kAud->canUserManageTindakLanjut($this->auditor, $this->temuanIgd));

        $kSa = $komponen($this->superAdmin);
        $this->assertTrue($kSa->canUserManageTindakLanjut($this->superAdmin, $this->temuanIgd));
    }

    public function test_kepala_igd_tidak_bisa_evaluasi(): void
    {
        $this->assertFalse($this->kepalaIgd->can('update_audit_manajemen::temuan::capa'));

        Livewire::actingAs($this->kepalaIgd)
            ->test(RegisterTemuanCapaIndex::class)
            ->assertViewHas('canEvaluate', false)
            ->call('openEvaluasiClosing', $this->temuanIgd->id)
            ->assertForbidden();
    }

    public function test_kepala_igd_bisa_buka_tindak_lanjut_unit_sendiri_tapi_bukan_unit_lain(): void
    {
        Livewire::actingAs($this->kepalaIgd)
            ->test(RegisterTemuanCapaIndex::class)
            ->call('openTindakLanjut', $this->temuanIgd->id)
            ->assertSet('showTindakLanjutModal', true);

        Livewire::actingAs($this->kepalaIgd)
            ->test(RegisterTemuanCapaIndex::class)
            ->call('openTindakLanjut', $this->temuanIcu->id)
            ->assertForbidden();
    }

    public function test_upload_bukti_menolak_unit_lain_dan_menerima_unit_sendiri(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->kepalaIgd);

        $berkas = fn () => ['berkas' => [UploadedFile::fake()->create('bukti.pdf', 50, 'application/pdf')]];

        $this->postJson("/api/v1/audit/temuan-capa/{$this->temuanIcu->id}/bukti", $berkas())
            ->assertForbidden();

        $res = $this->postJson("/api/v1/audit/temuan-capa/{$this->temuanIgd->id}/bukti", $berkas())
            ->assertOk()
            ->assertJsonPath('sukses', true);

        $this->assertCount(1, $res->json('data.bukti_perbaikan'));
        $this->assertCount(1, $this->temuanIgd->fresh()->bukti_perbaikan);
    }

    public function test_upload_bukti_ditolak_bila_temuan_closed(): void
    {
        Storage::fake('public');
        $this->temuanIgd->update(['status_capa' => 'Closed']);
        Sanctum::actingAs($this->kepalaIgd);

        $this->postJson("/api/v1/audit/temuan-capa/{$this->temuanIgd->id}/bukti", [
            'berkas' => [UploadedFile::fake()->create('bukti.pdf', 50, 'application/pdf')],
        ])->assertForbidden();
    }

    public function test_tindak_lanjut_api_dan_livewire_ditolak_bila_temuan_closed(): void
    {
        $this->temuanIgd->update(['status_capa' => 'Closed']);

        Sanctum::actingAs($this->kepalaIgd);
        $this->postJson("/api/v1/audit/temuan-capa/{$this->temuanIgd->id}/tindak-lanjut", [
            'analisis_penyebab'  => 'Analisis penyebab minimal sepuluh karakter',
            'tindakan_perbaikan' => 'Tindakan perbaikan minimal sepuluh karakter',
        ])->assertForbidden();

        Livewire::actingAs($this->kepalaIgd)
            ->test(RegisterTemuanCapaIndex::class)
            ->set('selectedTemuanId', $this->temuanIgd->id)
            ->set('analisis_penyebab', 'Analisis penyebab minimal sepuluh karakter')
            ->set('tindakan_perbaikan', 'Tindakan perbaikan minimal sepuluh karakter')
            ->call('saveTindakLanjut')
            ->assertForbidden();

        $this->assertSame('Closed', $this->temuanIgd->fresh()->status_capa);
    }

    public function test_register_temuan_api_menolak_pengguna_tanpa_permission_audit_capa(): void
    {
        $userTanpaPermission = User::create([
            'nama'     => 'User Tanpa Capa',
            'username' => 'no_capa_' . uniqid(),
            'email'    => 'no_capa_' . uniqid() . '@simlog.test',
            'password' => bcrypt('password'),
            'unit_id'  => $this->igd->id,
            'status'   => 'AKTIF',
        ]);

        Sanctum::actingAs($userTanpaPermission);
        $this->getJson('/api/v1/audit/temuan-capa')->assertForbidden();
    }
}
