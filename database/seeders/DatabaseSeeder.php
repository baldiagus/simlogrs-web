<?php

namespace Database\Seeders;

use Database\Seeders\Dummy\AuditDummySeeder;
use Database\Seeders\Dummy\DistribusiDummySeeder;
use Database\Seeders\Dummy\IpsrsDummySeeder;
use Database\Seeders\Dummy\PeminjamanAsetDummySeeder;
use Database\Seeders\Dummy\PengadaanDummySeeder;
use Database\Seeders\Dummy\PersediaanDummySeeder;
use Database\Seeders\Master\AnggaranPengadaanSeeder;
use Database\Seeders\Master\GudangSeeder;
use Database\Seeders\Master\MasterDataSeeder;
use Database\Seeders\Master\UnitSeeder;
use Database\Seeders\Rbac\PenggunaSeeder;
use Database\Seeders\Rbac\PeranSeeder;
use Database\Seeders\Rbac\ShieldRolePermissionSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Menjalankan seluruh pipeline seeder resmi secara berurutan.
     */
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            GudangSeeder::class,
            PeranSeeder::class,
            ShieldRolePermissionSeeder::class,
            PenggunaSeeder::class,
            // Data dummy dinonaktifkan (hanya akun & struktur dasar yang aktif):
            AnggaranPengadaanSeeder::class,
            MasterDataSeeder::class,
            PengadaanDummySeeder::class,
            DistribusiDummySeeder::class,
            PeminjamanAsetDummySeeder::class,
            IpsrsDummySeeder::class,
            PersediaanDummySeeder::class,
            AuditDummySeeder::class,
        ]);
    }
}
