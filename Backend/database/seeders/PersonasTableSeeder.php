<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PersonasTableSeeder extends Seeder
{
    public function run(): void
    {
        $personas = [
            [
                'Nombre' => 'Super',
                'apellido1' => 'Admin',
                'apellido2' => null,
                'Rut' => '11.111.111-1',
                'Email' => 'superadmin@admin.com',
                'celular' => null,
                'telefono' => null,
            ],
            [
                'Nombre' => 'Super',
                'apellido1' => 'Admin',
                'apellido2' => null,
                'Rut' => '00000000-0',
                'Email' => 'superadmin@utamed.edu.ec',
                'celular' => null,
                'telefono' => null,
            ],
            [
                'Nombre' => 'Rodrigo',
                'apellido1' => 'Admin',
                'apellido2' => null,
                'Rut' => '12345678-9',
                'Email' => 'rodrigo@admin.com',
                'celular' => null,
                'telefono' => null,
            ],
        ];

        foreach ($personas as $persona) {
            // Se busca por Email, no por Rut: el SSO de UTAmed puede haber creado
            // ya esta persona con un Rut escrito distinto (con puntos, etc.).
            if (!DB::table('persona')->where('Email', $persona['Email'])->exists()) {
                DB::table('persona')->insert($persona + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
