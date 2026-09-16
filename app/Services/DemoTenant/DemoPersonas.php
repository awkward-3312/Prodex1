<?php

namespace App\Services\DemoTenant;

/**
 * Fixed, curated demo data per business "persona". Names are deliberately
 * real-sounding (Honduras/Central America commercial context) and fully
 * fictitious — no real company, person, phone, email, RTN, or card number.
 *
 * Idempotency contract for the entities defined here:
 *  - categories/products carry a deterministic `code` (has a DB column) —
 *    the seeder looks up by `code` before inserting.
 *  - brands/units have no `code` column in this schema, so idempotency for
 *    them is the curated list itself: the seeder looks up by exact `name`
 *    (brand) or `name`+`ShortName` (unit) before inserting. Because this
 *    file is a fixed list (not randomly generated per run), "does a row
 *    with this exact name already exist" is a reliable existence check.
 *
 * Two personas keep prueba02 and pruebapago from being clones:
 *  - DISTRIBUTION: wholesale/distribution trade.
 *  - RETAIL: retail + services.
 */
class DemoPersonas
{
    public const DISTRIBUTION = 'distribucion';
    public const RETAIL = 'retail';

    /** Map a known demo domain to its persona. Unknown domains fall back by hash (used only for local dry-run testing against demo01/etc). */
    public static function forDomain(string $domain, string $fallbackSeed): string
    {
        return match ($domain) {
            'prueba02' => self::DISTRIBUTION,
            'pruebapago' => self::RETAIL,
            default => (crc32($fallbackSeed) % 2 === 0) ? self::DISTRIBUTION : self::RETAIL,
        };
    }

    public static function all(): array
    {
        return [
            self::DISTRIBUTION => self::distribution(),
            self::RETAIL => self::retail(),
        ];
    }

    public static function get(string $persona): array
    {
        return self::all()[$persona];
    }

    private static function distribution(): array
    {
        return [
            'label' => 'Distribución mayorista',
            'company_name' => 'Distribuidora Comayagua',
            'currency_context' => 'HNL — Honduras',
            'units' => [
                ['name' => 'Unidad', 'short' => 'UND'],
                ['name' => 'Caja', 'short' => 'CJA'],
                ['name' => 'Docena', 'short' => 'DOC'],
                ['name' => 'Paquete', 'short' => 'PAQ'],
                ['name' => 'Libra', 'short' => 'LB'],
                ['name' => 'Galón', 'short' => 'GAL'],
                ['name' => 'Litro', 'short' => 'LT'],
                ['name' => 'Quintal', 'short' => 'QQ'],
                ['name' => 'Fardo', 'short' => 'FRD'],
                ['name' => 'Bulto', 'short' => 'BLT'],
            ],
            'brands' => [
                'Molinos Copán', 'AguaZul', 'Lácteos San Isidro', 'Café Cordillera',
                'Industrias El Progreso', 'Distribuidora Sula', 'TropiSnacks',
                'Ferretodo Industrial', 'Bodega Central', 'Grano de Oro',
            ],
            'categories' => [
                'Abarrotes' => ['Arroz Extra Grano Largo 1lb', 'Frijol Rojo Seco 1lb', 'Azúcar Blanca 2lb', 'Sal Refinada 1lb', 'Aceite Vegetal 1L', 'Harina de Maíz 2lb', 'Avena en Hojuelas 500g', 'Pasta Espagueti 400g', 'Consomé de Pollo 250g', 'Café Molido Tostado 500g'],
                'Bebidas' => ['Agua Purificada 1L', 'Refresco de Cola 2L', 'Jugo de Naranja 1L', 'Bebida Isotónica 600ml', 'Té Helado 500ml', 'Agua Mineral con Gas 500ml', 'Bebida Energética 355ml', 'Horchata Envasada 1L', 'Refresco de Uva 2L', 'Café Listo para Beber 250ml'],
                'Limpieza y Aseo' => ['Detergente en Polvo 1kg', 'Jabón de Lavar Barra 300g', 'Cloro Desinfectante 1L', 'Desinfectante Multiusos 900ml', 'Papel Higiénico 4 Rollos', 'Servilletas de Papel 200un', 'Escoba de Cerdas Plásticas', 'Trapeador de Microfibra', 'Guantes de Limpieza Talla M', 'Bolsas de Basura 20un'],
                'Ferretería' => ['Cinta Métrica 5m', 'Martillo de Uña 16oz', 'Juego de Destornilladores 6pz', 'Cinta Aislante Eléctrica', 'Candado de Seguridad 40mm', 'Alambre Galvanizado 1kg', 'Clavos de Acero 2" 1lb', 'Silicona Transparente 280ml', 'Cinta Métrica Láser', 'Guantes de Cuero para Trabajo'],
                'Papelería y Oficina' => ['Resma de Papel Bond Carta', 'Cuaderno Universitario 100h', 'Lapicero Tinta Negra 12un', 'Marcador Permanente 4un', 'Folder Tamaño Carta 50un', 'Grapadora Metálica Estándar', 'Calculadora de Escritorio', 'Cinta Adhesiva Transparente', 'Tijera Escolar 8"', 'Post-it Notas Adhesivas'],
                'Electrodomésticos' => ['Licuadora de Vaso 1.5L', 'Plancha de Vapor', 'Ventilador de Pedestal 18"', 'Cafetera Eléctrica 12 Tazas', 'Tostadora de 2 Rebanadas', 'Sanduchera Eléctrica', 'Olla Arrocera 10 Tazas', 'Extractor de Jugos', 'Batidora de Mano', 'Horno Tostador 20L'],
                'Cuidado Personal' => ['Jabón de Baño en Barra 90g', 'Shampoo Familiar 750ml', 'Pasta Dental 100ml', 'Desodorante en Barra', 'Papel Higiénico Suave 4un', 'Crema Corporal Hidratante 400ml', 'Enjuague Bucal 500ml', 'Rastrillo Desechable 4un', 'Toallas Húmedas 40un', 'Jabón Líquido para Manos 500ml'],
                'Snacks y Confitería' => ['Papas Fritas Clásicas 150g', 'Galletas de Vainilla 200g', 'Chocolate con Leche 100g', 'Maní Salado 200g', 'Palomitas de Maíz Naturales', 'Barra de Granola 40g', 'Bombones Surtidos 250g', 'Tortillas de Maíz 500g', 'Chicharrón de Cerdo 150g', 'Caramelos Surtidos 300g'],
                'Lácteos y Refrigerados' => ['Leche Entera 1L', 'Queso Fresco 500g', 'Mantequilla con Sal 200g', 'Yogurt Natural 1L', 'Crema Agria 400ml', 'Queso Crema 200g', 'Huevos Frescos 12un', 'Requesón 500g', 'Leche Deslactosada 1L', 'Yogurt Bebible Fresa 200ml'],
                'Repuestos y Accesorios' => ['Filtro de Aceite Universal', 'Bujía de Encendido Estándar', 'Bombillo LED de Faro', 'Fusibles Automotrices 10un', 'Aceite de Motor 20W-50 1qt', 'Limpiador de Parabrisas', 'Correa de Distribución', 'Cable de Batería 1m', 'Pastillas de Freno Delanteras', 'Ambientador para Auto'],
            ],
            'providers' => [
                'Comercial Copán S. de R.L.', 'Importadora Sula', 'Distribuidora El Progreso',
                'Mayorista Comayagua', 'Grupo Industrial Cortés', 'Almacenes La Ceiba',
                'Suplidora Danlí', 'Comercializadora Choluteca', 'Grupo Olancho Import',
                'Bodegas Siguatepeque',
            ],
            'clients' => [
                'Pulpería La Esquina', 'Minimarket Santa Rosa', 'Abarrotería El Trébol',
                'Tienda Don Chepe', 'Colmado Buenaventura', 'Super Ahorro Comayagua',
                'Mercadito San José', 'Bodega Los Pinos', 'Distribuidora Rivera Hnos.',
                'Comercial Flores',
            ],
        ];
    }

    private static function retail(): array
    {
        return [
            'label' => 'Retail y servicios',
            'company_name' => 'Retail Express San Pedro',
            'currency_context' => 'HNL — Honduras',
            'units' => [
                ['name' => 'Unidad', 'short' => 'UND'],
                ['name' => 'Par', 'short' => 'PAR'],
                ['name' => 'Set', 'short' => 'SET'],
                ['name' => 'Caja', 'short' => 'CJA'],
                ['name' => 'Paquete', 'short' => 'PAQ'],
                ['name' => 'Rollo', 'short' => 'ROL'],
                ['name' => 'Kit', 'short' => 'KIT'],
                ['name' => 'Bolsa', 'short' => 'BOL'],
                ['name' => 'Frasco', 'short' => 'FRS'],
                ['name' => 'Tubo', 'short' => 'TBO'],
            ],
            'brands' => [
                'AuroraTech', 'CasaViva', 'UrbanStyle', 'PlayZone Kids', 'BellezaPura',
                'PetLife', 'SportMax', 'OfficePro', 'ModaCentro', 'HogarNova',
            ],
            'categories' => [
                'Electrónica' => ['Audífonos Bluetooth Inalámbricos', 'Parlante Portátil Recargable', 'Cargador Rápido USB-C 20W', 'Cable USB-C 1m', 'Power Bank 10000mAh', 'Mouse Óptico Inalámbrico', 'Teclado Compacto USB', 'Smartwatch Deportivo', 'Base Cargadora Inalámbrica', 'Adaptador Multipuertos USB'],
                'Hogar y Decoración' => ['Set de Toallas 3 Piezas', 'Cortina Blackout 140x220', 'Almohada Viscoelástica Estándar', 'Juego de Sábanas Queen', 'Espejo Decorativo de Pared', 'Portavelas de Cerámica', 'Cesto Organizador de Tela', 'Reloj de Pared Moderno', 'Cojín Decorativo 45x45', 'Marco de Fotos Doble'],
                'Moda y Accesorios' => ['Camisa Casual Manga Larga', 'Blusa Dama Corte Moderno', 'Gorra Ajustable Bordada', 'Bufanda Tejida Unisex', 'Cinturón de Cuero Sintético', 'Billetera Slim para Caballero', 'Bolso Tote de Dama', 'Lentes de Sol Polarizados', 'Reloj de Pulsera Clásico', 'Mochila Urbana Impermeable'],
                'Tecnología' => ['Funda Protectora para Celular', 'Vidrio Templado Universal', 'Soporte para Laptop Ajustable', 'Hub USB 4 Puertos', 'Webcam HD 1080p', 'Micrófono USB de Escritorio', 'Trípode Flexible para Celular', 'Memoria USB 32GB', 'Disco Duro Externo 1TB', 'Router Wi-Fi Doble Banda'],
                'Cuidado Personal' => ['Crema Facial Hidratante 50ml', 'Set de Brochas de Maquillaje', 'Perfume de Cuerpo 100ml', 'Esmalte de Uñas Larga Duración', 'Kit de Manicure Portátil', 'Secadora de Cabello Profesional', 'Plancha para Cabello Cerámica', 'Bálsamo Labial Hidratante', 'Cepillo de Cabello Antiestático', 'Espejo de Aumento con Luz LED'],
                'Juguetería' => ['Set de Bloques de Construcción', 'Muñeca Articulada con Accesorios', 'Auto a Fricción Escala Grande', 'Rompecabezas 500 Piezas', 'Peluche Suave Mediano', 'Set de Plastilina 12 Colores', 'Pizarra Mágica para Dibujar', 'Balón de Fútbol Infantil', 'Cocina de Juguete Miniatura', 'Set de Té de Juguete'],
                'Deportes' => ['Botella Deportiva 1L', 'Banda de Resistencia Elástica', 'Cuerda para Saltar Ajustable', 'Guantes de Entrenamiento', 'Yoga Mat Antideslizante', 'Mancuernas de Neopreno 2kg', 'Rodillera de Compresión', 'Silbato de Árbitro', 'Bolso Deportivo Impermeable', 'Toalla de Microfibra Deportiva'],
                'Papelería' => ['Agenda Ejecutiva Anual', 'Set de Lápices de Colores 24un', 'Cuaderno Argollado Profesional', 'Bolígrafo de Gel Set 6un', 'Organizador de Escritorio', 'Mochila Escolar Ergonómica', 'Estuche Portalápices', 'Regla y Escuadra Set', 'Marcadores Fluorescentes 6un', 'Calculadora Científica'],
                'Mascotas' => ['Alimento para Perro Adulto 2kg', 'Alimento para Gato Adulto 1kg', 'Correa Ajustable para Perro', 'Cama Acolchada para Mascota', 'Juguete Mordedor de Goma', 'Shampoo para Mascotas 500ml', 'Comedero Doble de Acero', 'Arena Sanitaria para Gato 4kg', 'Transportadora Plegable', 'Cepillo Deshedding para Mascota'],
                'Belleza' => ['Set de Skincare Facial 3 Pasos', 'Máscara de Pestañas Volumen', 'Base de Maquillaje Líquida', 'Paleta de Sombras 12 Tonos', 'Labial Mate Larga Duración', 'Removedor de Maquillaje 200ml', 'Kit de Pinceles Profesional', 'Bronzer en Polvo Compacto', 'Aceite Capilar Reparador', 'Set de Uñas Postizas'],
            ],
            'providers' => [
                'Importadora San Pedro Retail', 'Suministros Urbanos SPS', 'Comercial Tegucigalpa Retail',
                'Distribuidora Moda Centro', 'Proveedora Tech Honduras', 'Grupo Belleza Integral',
                'Importadora Pet House', 'Suplidora Deportiva Ceibeña', 'Almacén Hogar Express',
                'Comercializadora Roatán Retail',
            ],
            'clients' => [
                'Boutique María Elena', 'Boutique Reyna', 'Tienda Estilo Urbano',
                'Farmacia y Belleza Vida', 'Pet Shop Los Amigos', 'Deportes Cumbre',
                'Papelería Escolar Futuro', 'Electrónica Express SPS', 'Casa & Hogar Deco',
                'Accesorios Moderna',
            ],
        ];
    }
}
