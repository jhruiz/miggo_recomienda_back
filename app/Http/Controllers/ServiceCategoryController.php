<?php

namespace App\Http\Controllers;

use App\Http\Responses\HasApiResponses;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;

/**
 * Controlador publico del catalogo de categorias de servicio
 * (/api/v1/service-categories) - tarea 18.1 (Requisitos 4.9, 13.7).
 *
 * Expone el catalogo completo (sin paginar) para que el frontend pueble los
 * selectores de categoria. Es de solo lectura: el catalogo lo gestiona el
 * seeder ServiceCategorySeeder, no hay endpoints de escritura.
 */
class ServiceCategoryController extends Controller
{
    use HasApiResponses;

    /**
     * GET /api/v1/service-categories  (publico)
     *
     * Devuelve todas las categorias de servicio ordenadas por nombre, cada una
     * con id, name y slug.
     */
    public function index(): JsonResponse
    {
        $categories = ServiceCategory::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (ServiceCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ])
            ->all();

        return $this->ok(
            data: ['categories' => $categories],
            message: 'Catalogo de categorias de servicio.',
        );
    }
}