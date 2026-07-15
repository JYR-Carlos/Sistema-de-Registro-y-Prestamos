<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Categoria;

class CategoriasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $categorias = [
            [
                'nombre' => 'Audiovisual',
                'descripcion' => 'Equipos relacionados con producción audiovisual.',
            ],
            [
                'nombre' => 'Fotografía',
                'descripcion' => 'Equipos para captura de imágenes fotográficas.',
            ],
            [
                'nombre' => 'Equipo Computacional',
                'descripcion' => 'Computadores, notebooks y periféricos.',
            ],
            [
                'nombre' => 'Iluminación',
                'descripcion' => 'Luces, reflectores y accesorios de iluminación.',
            ],
        ];

        foreach ($categorias as $categoria) {
            Categoria::firstOrCreate(
                ['nombre' => $categoria['nombre']],
                $categoria
            );
        }
    }
}
