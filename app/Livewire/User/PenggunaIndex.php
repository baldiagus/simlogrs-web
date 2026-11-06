<?php

namespace App\Livewire\User;

use App\Livewire\Concerns\HasConfirmationModal;
use App\Livewire\Concerns\HasPortalLayout;
use App\Livewire\Concerns\HasTableFilters;
use App\Livewire\Concerns\HasToast;
use App\Livewire\User\Concerns\HasAksiPengguna;
use App\Models\Master\Gudang;
use App\Models\Master\Unit;
use App\Models\User;
use App\Services\User\PenggunaService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\AkunPegawaiBaruMail;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

class PenggunaIndex extends Component
{
    use HasPortalLayout, HasToast, HasTableFilters, HasConfirmationModal, WithPagination;
    use HasAksiPengguna;

    #[Url(history: true)]
    public string $filterRole = '';

    #[Url(history: true)]
    public string $filterStatus = '';
    /**
     * Reset seluruh parameter filter dan pencarian ke kondisi default.
     */
    public function resetFilters(): void
    {
        $this->search = '';
        $this->filterRole = '';
        $this->filterStatus = '';
        $this->resetPage();
    }

    // Modal Form State
    public bool $showModal = false;
    public ?int $editingId = null;

    // Modal Kredensial Sementara (One-Time Display)
    public bool $showResetSuccessModal = false;
    #[Locked]
    public array $tempPasswordData = [];

    public string $nama = '';
    public string $nip = '';
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $no_telepon = '';
    public string $penugasan_tipe = 'none'; // 'none', 'unit', 'gudang'
    public ?int $unit_id = null;
    public ?int $gudang_id = null;
    public string $selectedRole = 'petugas_unit';
    public string $status = 'AKTIF';

    /**
     * Lifecycle hook boot Livewire untuk inisialisasi awal dan validasi otorisasi.
     */
    public function boot(): void
    {
        $this->authorizeBaca();
    }

    /**
     * Verifikasi hak akses pengguna untuk melihat data.
     */
    protected function authorizeBaca(): void
    {
        abort_unless(
            auth()->user()?->canAny(['view_any_user::pengguna', 'view_user::pengguna']),
            403,
            'Akses ditolak: Anda tidak memiliki wewenang mengelola Akun Pengguna.'
        );
    }

    /**
     * Inisialisasi komponen dan persiapan data awal.
     */
    public function mount(): void
    {
        $this->authorizeBaca();
    }

    /**
     * Reset unit atau gudang ketika tipe penugasan diubah.
     */
    public function updatedPenugasanTipe(string $value): void
    {
        if ($value === 'unit') {
            $this->gudang_id = null;
        } elseif ($value === 'gudang') {
            $this->unit_id = null;
        } else {
            $this->unit_id = null;
            $this->gudang_id = null;
        }
    }

    /**
     * Buka modal formulir penambahan data baru setelah validasi wewenang.
     */
    public function openCreate(): void
    {
        abort_unless(
            auth()->user()?->can('create_user::pengguna'),
            403,
            'Akses ditolak: Anda tidak memiliki wewenang menambah akun pengguna.'
        );

        $this->reset([
            'editingId',
            'nama',
            'nip',
            'username',
            'email',
            'password',
            'password_confirmation',
            'no_telepon',
            'penugasan_tipe',
            'unit_id',
            'gudang_id',
        ]);
        // Kata sandi sementara dibuat otomatis; dikirim ke email pegawai setelah akun tersimpan.
        $this->password = Str::password(10, true, true, false);
        $this->selectedRole = 'petugas_unit';
        $this->status = 'AKTIF';
        $this->resetValidation();
        $this->showModal = true;
    }

    /**
     * Buka modal formulir penyuntingan data terpilih setelah validasi wewenang.
     */
    public function openEdit(int $id): void
    {
        abort_unless(
            auth()->user()?->can('update_user::pengguna'),
            403,
            'Akses ditolak: Anda tidak memiliki wewenang mengubah akun pengguna.'
        );

        $user = User::with(['roles', 'unit', 'gudang'])->findOrFail($id);

        if ($user->hasRole('super_admin') && ! auth()->user()?->hasRole('super_admin')) {
            abort(403, 'Akun dengan peran super_admin hanya dapat dikelola oleh pengguna dengan peran super_admin.');
        }

        $this->editingId = $user->id;
        $this->nama = $user->nama ?? '';
        $this->nip = $user->nip ?? '';
        $this->username = $user->username ?? '';
        $this->email = $user->email ?? '';
        $this->password = '';
        $this->password_confirmation = '';
        $this->no_telepon = $user->no_telepon ?? '';
        $this->unit_id = $user->unit_id;
        $this->gudang_id = $user->gudang_id;
        $this->status = $user->status ?? 'AKTIF';

        if ($user->unit_id) {
            $this->penugasan_tipe = 'unit';
        } elseif ($user->gudang_id) {
            $this->penugasan_tipe = 'gudang';
        } else {
            $this->penugasan_tipe = 'none';
        }

        $primaryRole = $user->roles->first();
        $this->selectedRole = $primaryRole ? $primaryRole->name : 'petugas_unit';

        $this->resetValidation();
        $this->showModal = true;
    }

    /**
     * Validasi isian formulir dan simpan data ke database.
     */
    public function save(): void
    {
        // Mode menentukan permission: create tidak boleh mengubah akun, update tidak boleh membuat akun.
        abort_unless(
            $this->editingId
                ? (auth()->user()?->can('update_user::pengguna') || auth()->user()?->hasRole('super_admin'))
                : (auth()->user()?->can('create_user::pengguna') || auth()->user()?->hasRole('super_admin')),
            403,
            'Anda tidak memiliki izin menyimpan data pengguna.'
        );

        $rules = [
            'nama'         => 'required|string|max:255',
            'nip'          => 'nullable|string|max:30',
            'username'     => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('pengguna', 'username')->ignore($this->editingId)],
            'email'        => ['required', 'email', 'max:255', Rule::unique('pengguna', 'email')->ignore($this->editingId)],
            'selectedRole' => 'required|exists:roles,name',
            'no_telepon'   => 'nullable|string|max:50',
            'unit_id'      => 'nullable|exists:unit,id',
            'gudang_id'    => 'nullable|exists:gudang,id',
            'status'       => 'required|in:AKTIF,NONAKTIF',
        ];

        // Create: kata sandi dibuat sistem (tanpa konfirmasi). Edit: opsional, dengan konfirmasi.
        $rules['password'] = $this->editingId
            ? ['nullable', 'string', 'confirmed', Password::min(8)->letters()->numbers()]
            : ['required', 'string', Password::min(8)->letters()->numbers()];

        $messages = [
            'nama.required'         => 'Nama lengkap pengguna wajib diisi.',
            'username.required'     => 'Username wajib diisi.',
            'username.alpha_dash'   => 'Username hanya boleh berisi huruf, angka, garis bawah, dan tanda hubung.',
            'username.unique'       => 'Username sudah digunakan oleh akun lain.',
            'email.required'        => 'Alamat email dinas wajib diisi.',
            'email.email'           => 'Format alamat email tidak valid.',
            'email.unique'          => 'Alamat email sudah terdaftar di sistem.',
            'selectedRole.required' => 'Peran hak akses (Role) dinas wajib dipilih.',
            'password.required'     => 'Kata sandi akun baru wajib diisi.',
            'password.confirmed'    => 'Konfirmasi kata sandi tidak cocok.',
            'status.required'       => 'Status akun pengguna wajib ditentukan.',
        ];

        $this->validate($rules, $messages);

        if ($this->selectedRole === 'super_admin' && ! auth()->user()?->hasRole('super_admin')) {
            abort(403, 'Hanya pengguna dengan peran super_admin yang dapat menetapkan peran super_admin.');
        }

        $payload = [
            'nama'       => $this->nama,
            'nip'        => $this->nip ?: null,
            'username'   => strtolower($this->username),
            'email'      => strtolower($this->email),
            'no_telepon' => $this->no_telepon ?: null,
            'unit_id'    => $this->penugasan_tipe === 'unit' ? $this->unit_id : null,
            'gudang_id'  => $this->penugasan_tipe === 'gudang' ? $this->gudang_id : null,
            'status'     => $this->status,
        ];

        if (! empty($this->password)) {
            $payload['password'] = Hash::make($this->password);
        }

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);

            if ($user->hasRole('super_admin') && ! auth()->user()?->hasRole('super_admin')) {
                abort(403, 'Akun dengan peran super_admin hanya dapat dikelola oleh pengguna dengan peran super_admin.');
            }

            // Anti self-promotion: pengguna tidak boleh mengubah peran dirinya sendiri.
            if (auth()->id() === $user->id && ! $user->hasRole($this->selectedRole)) {
                abort(403, 'Anda tidak dapat mengubah peran akun Anda sendiri.');
            }

            // Proteksi anti self-deactivation
            if (auth()->id() === $user->id && $this->status === 'NONAKTIF') {
                $this->toast('Anda tidak dapat menonaktifkan akun Anda sendiri yang sedang aktif.', 'danger');
                return;
            }

            app(PenggunaService::class)->updatePengguna($user, $payload, $this->selectedRole, auth()->user());
            $this->toast("Data pegawai {$user->nama} berhasil diperbarui.", 'success');
        } else {
            $passwordSementara = $this->password;
            $user = app(PenggunaService::class)->createPengguna(
                array_merge($payload, ['wajib_ganti_password' => true]),
                $this->selectedRole,
                auth()->user()
            );

            if ($this->kirimKredensial($user, $passwordSementara)) {
                $this->toast("Akun pegawai {$user->nama} berhasil dibuat. Kredensial dikirim ke {$user->email}.", 'success');
            } else {
                $this->toast("Akun pegawai {$user->nama} berhasil dibuat, tetapi email kredensial gagal dikirim ke {$user->email}. Gunakan menu Reset Kata Sandi.", 'warning');
            }

            $this->reset(['password', 'password_confirmation']);
        }

        $this->showModal = false;
    }

    /**
     * Kirim informasi akun dan kata sandi sementara ke email pegawai.
     * Kegagalan pengiriman tidak membatalkan akun yang sudah tersimpan.
     */
    protected function kirimKredensial(User $user, string $passwordSementara): bool
    {
        try {
            Mail::to($user->email)->send(new AkunPegawaiBaruMail($user, $passwordSementara));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Email kredensial akun baru gagal dikirim.', [
                'pengguna_id' => $user->id,
                'galat'       => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Render tampilan blade komponen Livewire.
     */
    public function render()
    {
        $this->authorizeBaca();

        $query = User::with(['roles', 'unit', 'gudang']);

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('nama', 'ilike', "%{$this->search}%")
                  ->orWhere('nip', 'ilike', "%{$this->search}%")
                  ->orWhere('username', 'ilike', "%{$this->search}%")
                  ->orWhere('email', 'ilike', "%{$this->search}%");
            });
        }

        if (! empty($this->filterStatus)) {
            $query->where('status', $this->filterStatus);
        }

        if (! empty($this->filterRole)) {
            $query->whereHas('roles', fn ($r) => $r->where('name', $this->filterRole));
        }

        $users = $query->orderBy('nama')->paginate($this->perPage);

        $daftarUnit = Unit::where('status', 'AKTIF')->orderBy('nama')->get();
        $daftarGudang = Gudang::where('status', 'AKTIF')->orderBy('nama')->get();
        $daftarRoles = Role::orderBy('name')->get();
        $assignableRoles = Role::when(! auth()->user()?->hasRole('super_admin'), fn ($q) => $q->where('name', '!=', 'super_admin'))
            ->orderBy('name')
            ->get();

        $totalPengguna = User::count();
        $penggunaAktif = User::where('status', 'AKTIF')->count();
        $penggunaNonaktif = User::where('status', 'NONAKTIF')->count();

        return view('livewire.user.pengguna-index', [
            'users'            => $users,
            'daftarUnit'       => $daftarUnit,
            'daftarGudang'     => $daftarGudang,
            'daftarRoles'      => $daftarRoles,
            'assignableRoles'  => $assignableRoles,
            'totalPengguna'    => $totalPengguna,
            'penggunaAktif'    => $penggunaAktif,
            'penggunaNonaktif' => $penggunaNonaktif,
        ])->layout('layouts.portal', [
            'title'     => 'Manajemen Akun Pengguna',
            'portalKey' => $this->getPortalName(),
        ]);
    }
}
