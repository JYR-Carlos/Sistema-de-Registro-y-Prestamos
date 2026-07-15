<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ---- Datos base para arrancar el sistema ----
            RolesTableSeeder::class,
            PersonasTableSeeder::class, // Solo crea la persona del superadmin
            UsersTableSeeder::class,    // Solo crea el usuario superadmin (superadmin@admin.com)
            CategoriasSeeder::class,
            TipoEquiposSeeder::class,
            AsignaturaSeeder::class,
            BloqueSeeder::class,
            EquiposTableSeeder::class,
            EquiposRelacionadosSeeder::class,

            // Catálogo de tipos de falla para auditoría de equipos
            TiposFallaSeeder::class,

            // ---- Seeders de datos de demostración (NO usar en producción) ----
            // Generan préstamos, sanciones y docentes de ejemplo atados a usuarios
            // ficticios que ya no se crean por defecto. Solo ejecutar manualmente
            // en un entorno de desarrollo con usuarios de prueba ya creados.
            // DocenteSeeder::class,
            // AsignaturaDocenteSeeder::class,
            // PrestamoSeeder::class,
            // ObservacionSeeder::class,
            // SancionSeeder::class,
            // UserSancionSeeder::class,
            // BloquePrestamosSeeder::class,
            // ReportesDataSeeder::class,
        ]);
    }
}
