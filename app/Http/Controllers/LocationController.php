<?php

namespace App\Http\Controllers;

use App\Http\Responses\HasApiResponses;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador publico del catalogo de ubicaciones en cascada
 * (/api/v1/countries, /api/v1/departments, /api/v1/cities) - tarea 19.3
 * (Requisitos 15.1, 15.2, 15.4, 15.5, 15.6, 15.7).
 *
 * Mismos criterios que ServiceCategoryController: endpoints publicos, de solo
 * lectura y SIN paginar. El frontend los consume para poblar en cascada los
 * selectores de pais -> departamento -> ciudad.
 *
 * Comportamiento ante id padre inexistente o ausente: NO es un error. Se
 * devuelve una lista vacia junto al mensaje "No se encontraron resultados."
 * con HTTP 200 (Requisito 15.7). El id de filtro se valida de forma suave:
 * si llega pero no es un entero valido, se trata como ausente (lista vacia),
 * evitando respuestas 500.
 */
class LocationController extends Controller
{
    use HasApiResponses;

    /** Mensaje estandar cuando un filtro en cascada no arroja resultados. */
    private const NO_RESULTS = 'No se encontraron resultados.';

    public function __construct(private readonly LocationService $locations)
    {
    }

    /**
     * GET /api/v1/countries  (publico)
     *
     * Devuelve todos los paises ordenados por nombre (id, name, iso_code).
     */
    public function countries(): JsonResponse
    {
        $countries = $this->locations->listCountries()->all();

        return $this->ok(
            data: ['countries' => $countries],
            message: 'Catalogo de paises.',
        );
    }

    /**
     * GET /api/v1/departments?country_id={id}  (publico)
     *
     * Devuelve los departamentos del pais indicado, ordenados por nombre
     * (id, country_id, name). Si country_id falta, no es entero o no existe,
     * responde lista vacia + "No se encontraron resultados." con HTTP 200.
     */
    public function departments(Request $request): JsonResponse
    {
        $countryId = $this->integerQueryParam($request, 'country_id');

        $departments = $this->locations->departmentsForCountry($countryId)->all();

        if ($departments === []) {
            return $this->ok(
                data: ['departments' => []],
                message: self::NO_RESULTS,
            );
        }

        return $this->ok(
            data: ['departments' => $departments],
            message: 'Departamentos del pais.',
        );
    }

    /**
     * GET /api/v1/cities?department_id={id}  (publico)
     *
     * Devuelve las ciudades del departamento indicado, ordenadas por nombre
     * (id, department_id, name). Si department_id falta, no es entero o no
     * existe, responde lista vacia + "No se encontraron resultados." con HTTP 200.
     */
    public function cities(Request $request): JsonResponse
    {
        $departmentId = $this->integerQueryParam($request, 'department_id');

        $cities = $this->locations->citiesForDepartment($departmentId)->all();

        if ($cities === []) {
            return $this->ok(
                data: ['cities' => []],
                message: self::NO_RESULTS,
            );
        }

        return $this->ok(
            data: ['cities' => $cities],
            message: 'Ciudades del departamento.',
        );
    }

    /**
     * Lee un parametro de consulta como entero positivo. Devuelve null cuando
     * esta ausente, vacio o no es un entero valido (p. ej. "abc" o "1.5"),
     * para que el servicio lo trate como "sin resultados" en lugar de fallar.
     */
    private function integerQueryParam(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        if (! ctype_digit((string) $value)) {
            return null;
        }

        return (int) $value;
    }
}
