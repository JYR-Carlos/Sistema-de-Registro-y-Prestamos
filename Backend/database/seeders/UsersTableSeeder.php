<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        $idPersona = DB::table('persona')->where('Email', 'superadmin@admin.com')->value('idPersona');
        $idRolAdmin = DB::table('rol')->where('Nombre', 'ADMIN')->value('idRol');

        $idUser = DB::table('users')->insertGetId([
            'idPersona' => $idPersona,
            'estadoSancion' => 'Activo',
            'Contrasena' => Hash::make('admin1234'),
            'Email' => 'superadmin@admin.com',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'idUser');

        DB::table('rol_user')->insert([
            'idRol' => $idRolAdmin,
            'idUser' => $idUser,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
