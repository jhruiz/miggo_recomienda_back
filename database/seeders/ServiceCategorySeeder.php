<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Seed example service categories for the services marketplace.
     */
    public function run(): void
    {
        $categories = [
            'Plomería',
            'Electricidad',
            'Carpintería',
            'Pintura',
            'Albañilería',
            'Jardinería',
            'Limpieza del hogar',
            'Cerrajería',
            'Climatización y refrigeración',
            'Mudanzas y fletes',
            'Reparación de electrodomésticos',
            'Diseño gráfico',
            'Desarrollo web',
            'Fotografía y video',
            'Clases particulares',
            'Belleza y estética',
            'Mecánica automotriz',
            'Gasfitería',
        ];

        foreach ($categories as $name) {
            ServiceCategory::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}
