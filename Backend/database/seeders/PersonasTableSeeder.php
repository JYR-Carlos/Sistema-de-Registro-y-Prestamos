<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PersonasTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('persona')->insert([
            'Nombre' => 'Super',
            'apellido1' => 'Admin',
            'apellido2' => null,
            'Rut' => '11.111.111-1',
            'Email' => 'superadmin@admin.com',
            'celular' => null,
            'telefono' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
