<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'RRHH Dosys', 'email' => 'admin@dosys.local', 'profile' => 'rrhh'],
            ['name' => 'Supervisor Demo', 'email' => 'supervisor@dosys.local', 'profile' => 'supervisor'],
            ['name' => 'Jonathan Eduardo Garcia Trujillo', 'email' => 'jonathan.garcia@dosys.local', 'profile' => 'supervisor'],
            ['name' => 'Edwin Rodriguez Lanza', 'email' => 'edwin.rodriguez@dosys.local', 'profile' => 'supervisor'],
            ['name' => 'Sandro Kluivert Aplicano Flores', 'email' => 'sandro.aplicano@dosys.local', 'profile' => 'supervisor'],
            ['name' => 'Jasmin Rocio Sanchez Valeriano', 'email' => 'jasmin.sanchez@dosys.local', 'profile' => 'supervisor'],
            ['name' => 'Victor Ariel Vasquez Nolasco', 'email' => 'victor.vasquez@dosys.local', 'profile' => 'supervisor'],
            ['name' => 'Rocio Rodas Oyuela', 'email' => 'rocio.rodas@dosys.local', 'profile' => 'rrhh'],
            ['name' => 'Leonardo Rodas Oyuela', 'email' => 'leonardo.rodas@dosys.local', 'profile' => 'rrhh'],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(['email' => $user['email']], [
                'name' => $user['name'],
                'password' => Hash::make('password'),
                'profile' => $user['profile'],
                'active' => true,
            ]);
        }
    }
}
