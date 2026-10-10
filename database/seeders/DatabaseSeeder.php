<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Provisiona el usuario administrador desde variables de entorno (Art. IX, D-10 & T-17)
     */
    public function run(): void
    {
        $adminUser = strtolower(trim((string) env('ADMIN_USERNAME', 'admin')));
        $adminPass = (string) env('ADMIN_PASSWORD', 'admin1234');

        if (!empty($adminUser) && !empty($adminPass)) {
            $user = User::where('username', $adminUser)->first();
            if (!$user) {
                User::create([
                    'id' => (string) Str::uuid(),
                    'username' => $adminUser,
                    'password_hash' => password_hash($adminPass, PASSWORD_BCRYPT),
                    'role' => 'admin',
                ]);
            } else {
                $user->password_hash = password_hash($adminPass, PASSWORD_BCRYPT);
                $user->role = 'admin';
                $user->save();
            }
        }
    }
}
