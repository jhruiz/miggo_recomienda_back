<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\Department;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Seed the location catalog for Colombia: one country, its 32 departments
     * plus Bogotá D.C. (33 top-level divisions), and the principal
     * cities/municipios for each department.
     *
     * Uses firstOrCreate keyed by natural keys (country iso_code, department
     * name + country, city name + department) so the seeder is safe to re-run
     * without duplicating rows.
     */
    public function run(): void
    {
        $country = Country::firstOrCreate(
            ['iso_code' => 'CO'],
            ['name' => 'Colombia']
        );

        foreach ($this->departments() as $departmentName => $cities) {
            $department = Department::firstOrCreate(
                [
                    'country_id' => $country->id,
                    'name' => $departmentName,
                ]
            );

            foreach ($cities as $cityName) {
                City::firstOrCreate(
                    [
                        'department_id' => $department->id,
                        'name' => $cityName,
                    ]
                );
            }
        }
    }

    /**
     * Real Colombian departments (plus Bogotá D.C.) mapped to a representative
     * subset of their principal cities/municipios. Capitals are included for
     * every department; names use correct Spanish orthography.
     *
     * @return array<string, array<int, string>>
     */
    private function departments(): array
    {
        return [
            'Amazonas' => [
                'Leticia', 'Puerto Nariño',
            ],
            'Antioquia' => [
                'Medellín', 'Bello', 'Itagüí', 'Envigado', 'Apartadó',
                'Rionegro', 'Turbo', 'Caucasia', 'Sabaneta', 'La Estrella',
                'Copacabana', 'Girardota', 'Caldas', 'Marinilla',
            ],
            'Arauca' => [
                'Arauca', 'Saravena', 'Tame', 'Arauquita', 'Fortul',
            ],
            'Atlántico' => [
                'Barranquilla', 'Soledad', 'Malambo', 'Sabanalarga',
                'Puerto Colombia', 'Galapa', 'Baranoa',
            ],
            'Bogotá D.C.' => [
                'Bogotá',
            ],
            'Bolívar' => [
                'Cartagena', 'Magangué', 'Turbaco', 'El Carmen de Bolívar',
                'Arjona', 'María la Baja', 'Mompós',
            ],
            'Boyacá' => [
                'Tunja', 'Sogamoso', 'Duitama', 'Chiquinquirá', 'Puerto Boyacá',
                'Paipa', 'Villa de Leyva',
            ],
            'Caldas' => [
                'Manizales', 'La Dorada', 'Chinchiná', 'Villamaría', 'Riosucio',
                'Anserma', 'Supía',
            ],
            'Caquetá' => [
                'Florencia', 'San Vicente del Caguán', 'Puerto Rico',
                'El Doncello', 'La Montañita',
            ],
            'Casanare' => [
                'Yopal', 'Aguazul', 'Villanueva', 'Tauramena', 'Paz de Ariporo',
            ],
            'Cauca' => [
                'Popayán', 'Santander de Quilichao', 'Puerto Tejada', 'Patía',
                'Guapi', 'Piendamó',
            ],
            'Cesar' => [
                'Valledupar', 'Aguachica', 'Agustín Codazzi', 'Bosconia',
                'La Jagua de Ibirico', 'El Copey',
            ],
            'Chocó' => [
                'Quibdó', 'Istmina', 'Tadó', 'Condoto', 'Riosucio', 'Acandí',
            ],
            'Córdoba' => [
                'Montería', 'Cereté', 'Lorica', 'Sahagún', 'Montelíbano',
                'Planeta Rica', 'Tierralta',
            ],
            'Cundinamarca' => [
                'Soacha', 'Facatativá', 'Zipaquirá', 'Chía', 'Girardot',
                'Fusagasugá', 'Mosquera', 'Madrid', 'Funza', 'Cajicá',
                'Villeta', 'Ubaté',
            ],
            'Guainía' => [
                'Inírida',
            ],
            'Guaviare' => [
                'San José del Guaviare', 'Calamar', 'El Retorno', 'Miraflores',
            ],
            'Huila' => [
                'Neiva', 'Pitalito', 'Garzón', 'La Plata', 'Campoalegre',
                'Gigante',
            ],
            'La Guajira' => [
                'Riohacha', 'Maicao', 'Uribia', 'Fonseca', 'San Juan del Cesar',
                'Villanueva',
            ],
            'Magdalena' => [
                'Santa Marta', 'Ciénaga', 'Fundación', 'El Banco', 'Plato',
                'Zona Bananera',
            ],
            'Meta' => [
                'Villavicencio', 'Acacías', 'Granada', 'Puerto López',
                'Cumaral', 'San Martín',
            ],
            'Nariño' => [
                'Pasto', 'Tumaco', 'Ipiales', 'Túquerres', 'La Unión',
                'Samaniego', 'Barbacoas',
            ],
            'Norte de Santander' => [
                'Cúcuta', 'Ocaña', 'Villa del Rosario', 'Los Patios', 'Pamplona',
                'Tibú', 'El Zulia',
            ],
            'Putumayo' => [
                'Mocoa', 'Puerto Asís', 'Orito', 'Valle del Guamuez',
                'Villagarzón',
            ],
            'Quindío' => [
                'Armenia', 'Calarcá', 'La Tebaida', 'Montenegro', 'Quimbaya',
                'Circasia', 'Filandia',
            ],
            'Risaralda' => [
                'Pereira', 'Dosquebradas', 'Santa Rosa de Cabal', 'La Virginia',
                'Marsella', 'Belén de Umbría',
            ],
            'San Andrés y Providencia' => [
                'San Andrés', 'Providencia',
            ],
            'Santander' => [
                'Bucaramanga', 'Floridablanca', 'Girón', 'Piedecuesta',
                'Barrancabermeja', 'San Gil', 'Socorro', 'Málaga',
            ],
            'Sucre' => [
                'Sincelejo', 'Corozal', 'Sampués', 'San Marcos', 'Tolú',
                'San Onofre',
            ],
            'Tolima' => [
                'Ibagué', 'Espinal', 'Melgar', 'Honda', 'Líbano', 'Chaparral',
                'Flandes', 'Mariquita',
            ],
            'Valle del Cauca' => [
                'Cali', 'Buenaventura', 'Palmira', 'Tuluá', 'Cartago',
                'Buga', 'Jamundí', 'Yumbo', 'Candelaria', 'Florida',
            ],
            'Vaupés' => [
                'Mitú',
            ],
            'Vichada' => [
                'Puerto Carreño', 'La Primavera', 'Cumaribo', 'Santa Rosalía',
            ],
        ];
    }
}
