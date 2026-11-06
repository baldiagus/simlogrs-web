<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;
use Livewire\WithPagination;

trait HasTableFilters
{
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    public int $perPage = 15;

    #[Url(history: true)]
    public string $sortBy = 'id';

    #[Url(history: true)]
    public string $sortDirection = 'desc';
    /**
     * Inisialisasi konfigurasi filter tabel, pencarian, dan pengurutan bawaan.
     */
    public function mountHasTableFilters(): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $this->sortBy)) {
            $this->sortBy = 'id';
        }
        $this->sortDirection = strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Terapkan pengurutan baris data tabel berdasarkan kolom yang dipilih.
     */
    public function sort(string $column): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            $column = 'id';
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    /**
     * Hook saat kolom sorting diubah untuk memperbarui tampilan tabel.
     */
    public function updatedSortBy(): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $this->sortBy)) {
            $this->sortBy = 'id';
        }
    }

    /**
     * Hook saat arah pengurutan sorting (asc/desc) diubah.
     */
    public function updatedSortDirection(): void
    {
        $this->sortDirection = strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Mengembalikan nama kolom pengurutan yang tervalidasi terhadap whitelist kolom aman.
     */
    public function getValidatedSortColumn(array $allowedColumns = []): string
    {
        $defaultAllowed = [
            'id', 'kode', 'nama', 'status', 'dibuat_pada', 'created_at', 'updated_at',
            'tanggal', 'tanggal_po', 'tanggal_kirim', 'tanggal_permintaan', 'tanggal_opname',
            'harga_beli', 'harga_estimasi', 'pagu', 'realisasi', 'serapan', 'total_biaya'
        ];
        $whitelist = array_merge($defaultAllowed, $allowedColumns);

        return in_array($this->sortBy, $whitelist, true) ? $this->sortBy : 'id';
    }

    /**
     * Memvalidasi arah pengurutan (hanya 'asc' atau 'desc').
     */
    public function getValidatedSortDirection(): string
    {
        return strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';
    }
    /**
     * Hook saat kata kunci pencarian diubah untuk mereset paginasi tabel.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Hook saat jumlah baris per halaman diubah untuk mereset paginasi tabel.
     */
    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 15, 25, 50], true)) {
            $this->perPage = 15;
        }

        $this->resetPage();
    }
}
