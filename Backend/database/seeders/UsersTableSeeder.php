<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        $idRolAdmin = DB::table('rol')->where('Nombre', 'ADMIN')->value('idRol');

        $usuarios = [
            ['personaEmail' => 'superadmin@admin.com', 'userEmail' => 'superadmin@admin.com', 'password' => 'admin1234'],
            ['personaEmail' => 'superadmin@utamed.edu.ec', 'userEmail' => 'superadmin@utamed.edu.ec', 'password' => 'superadmin'],
            ['personaEmail' => 'rodrigo@admin.com', 'userEmail' => 'rodrigo@admin.com', 'password' => 'rodrigo1234'],
        ];

        foreach ($usuarios as $usuario) {
            $idUser = DB::table('users')->where('Email', $usuario['userEmail'])->value('idUser');

            if ($idUser) {
                // Ya existe (p. ej. la creó el SSO con una contraseña aleatoria que
                // nadie conoce): se le fuerza la contraseña conocida de este seeder
                // para que el login por email/password funcione en dev.
                DB::table('users')->where('idUser', $idUser)->update([
                    'Contrasena' => Hash::make($usuario['password']),
                    'updated_at' => now(),
                ]);
            } else {
                $idPersona = DB::table('persona')->where('Email', $usuario['personaEmail'])->value('idPersona');

                $idUser = DB::table('users')->insertGetId([
                    'idPersona' => $idPersona,
                    'estadoSancion' => 'Activo',
                    'Contrasena' => Hash::make($usuario['password']),
                    'Email' => $usuario['userEmail'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'idUser');
            }

            if (!DB::table('rol_user')->where(['idRol' => $idRolAdmin, 'idUser' => $idUser])->exists()) {
                DB::table('rol_user')->insert([
                    'idRol' => $idRolAdmin,
                    'idUser' => $idUser,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
