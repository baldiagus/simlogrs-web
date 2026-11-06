<?php

namespace Tests\Feature\Ui;

use App\Livewire\Aset\PengembalianPeminjamanIndex;
use App\Livewire\Pengadaan\MasterRekananIndex;
use App\Livewire\User\PenggunaIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TablePerPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::where('email', 'superadmin@simlogrs.local')->firstOrFail());
    }

    public function test_pengguna_index_honors_per_page(): void
    {
        $c = Livewire::test(PenggunaIndex::class)->set('perPage', 25);

        $this->assertSame(25, $c->viewData('users')->perPage());
    }

    public function test_master_rekanan_index_honors_per_page(): void
    {
        $c = Livewire::test(MasterRekananIndex::class)->set('perPage', 25);

        $this->assertSame(25, $c->viewData('items')->perPage());
    }

    public function test_pengembalian_peminjaman_riwayat_honors_per_page(): void
    {
        $c = Livewire::test(PengembalianPeminjamanIndex::class)->set('perPage', 25);

        $this->assertSame(25, $c->viewData('riwayatPengembalian')->perPage());
        $this->assertSame(25, $c->viewData('daftarPeminjaman')->perPage());
    }

    public function test_per_page_outside_whitelist_resets_to_default(): void
    {
        $c = Livewire::test(PenggunaIndex::class)->set('perPage', 100000);

        $this->assertSame(15, $c->get('perPage'));
        $this->assertSame(15, $c->viewData('users')->perPage());
    }
}
