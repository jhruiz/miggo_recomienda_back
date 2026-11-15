<?php

namespace App\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\Department;
use Illuminate\Support\Collection;

/**
 * Servicio de ubicaciones (Gestor_Ubicaciones del dominio) - tarea 19.3
 * (Requisitos 15.1, 15.2, 15.4, 15.5, 15.6, 15.7).
 *
 * Encapsula las consultas Eloquent del catalogo geografico en cascada
 * (pais -> departamento -> ciudad). Es de solo lectura: los datos los pobla el
 * seeder de ubicaciones (tarea 19.2), no hay endpoints de escritura.
 *
 * Todas las listas se devuelven ordenadas por nombre. Cuando el id padre no
 * corresponde a un registro existente, el servicio devuelve una coleccion
 * vacia (el controlador traduce eso al mensaje "No se encontraron resultados."
 * con HTTP 200, nunca un error; Requisito 15.7).
 */
class LocationService
{
    /**
     * Todos los paises ordenados por nombre (id, name, iso_code).
     *
     * @return Collection<int, array{id: int, name: string, iso_code: ?string}>
     */
    public function listCountries(): Collection
    {
        return Country::query()
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn (Country $country): array => [
                'id' => $country->id,
                'name' => $country->name,
                'iso_code' => $country->iso_code,
            ])
            ->values();
    }

    /**
     * Departamentos del pais indicado, ordenados por nombre
     * (id, country_id, name).
     *
     * Si $countryId es null o no corresponde a un pais existente, devuelve una
     * coleccion vacia (Requisito 15.7).
     *
     * @return Collection<int, array{id: int, country_id: int, name: string}>
     */
    public function departmentsForCountry(?int $countryId): Collection
    {
        if ($countryId === null || ! Country::query()->whereKey($countryId)->exists()) {
            return collect();
        }

        return Department::query()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'country_id', 'name'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'country_id' => $department->country_id,
                'name' => $department->name,
            ])
            ->values();
    }

    /**
     * Ciudades del departamento indicado, ordenadas por nombre
     * (id, department_id, name).
     *
     * Si $departmentId es null o no corresponde a un departamento existente,
     * devuelve una coleccion vacia (Requisito 15.7).
     *
     * @return Collection<int, array{id: int, department_id: int, name: string}>
     */
    public function citiesForDepartment(?int $departmentId): Collection
    {
        if ($departmentId === null || ! Department::query()->whereKey($departmentId)->exists()) {
            return collect();
        }

        return City::query()
            ->where('department_id', $departmentId)
            ->orderBy('name')
            ->get(['id', 'department_id', 'name'])
            ->map(fn (City $city): array => [
                'id' => $city->id,
                'department_id' => $city->department_id,
                'name' => $city->name,
            ])
            ->values();
    }
}
