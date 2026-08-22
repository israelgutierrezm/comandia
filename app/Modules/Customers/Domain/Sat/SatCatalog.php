<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Sat;

/**
 * Los catálogos oficiales del SAT que un CFDI necesita (ADR-005, CFDI 4.0).
 *
 * ## Por qué un catálogo en código y no una tabla
 *
 * Como el catálogo de permisos y el de configuración: es una lista cerrada, nacional —la misma para todos los
 * negocios— y estable. Una tabla exigiría `tenant_id` (y no lo tiene: no es dato de un negocio), o una tabla global con
 * su excepción al aislamiento, más un seeder. En código es versionado, sin migración, y la validación es real: ADR-005
 * prohíbe texto libre, y validar contra estas listas es exactamente eso.
 *
 * ## Lo que v1 valida y lo que no
 *
 * Valida la **pertenencia** al catálogo y la compatibilidad **régimen ↔ tipo de persona** (que cada régimen declara).
 * La matriz fina **régimen ↔ uso CFDI** del SAT —qué usos admite cada régimen— se documenta como deuda y se cierra al
 * construir el timbrado: es una madriguera de reglas que cambian, y un uso incompatible se descubre al facturar, con el
 * mismo criterio con que el RFC se valida por forma y no por su dígito verificador.
 */
final class SatCatalog
{
    /**
     * `c_RegimenFiscal`: code => [descripción, aplica a persona física, aplica a moral].
     *
     * @return array<string, array{description: string, fisica: bool, moral: bool}>
     */
    public static function taxRegimes(): array
    {
        return [
            '601' => ['description' => 'General de Ley Personas Morales', 'fisica' => false, 'moral' => true],
            '603' => ['description' => 'Personas Morales con Fines no Lucrativos', 'fisica' => false, 'moral' => true],
            '605' => ['description' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios', 'fisica' => true, 'moral' => false],
            '606' => ['description' => 'Arrendamiento', 'fisica' => true, 'moral' => false],
            '607' => ['description' => 'Régimen de Enajenación o Adquisición de Bienes', 'fisica' => true, 'moral' => false],
            '608' => ['description' => 'Demás ingresos', 'fisica' => true, 'moral' => false],
            '610' => ['description' => 'Residentes en el Extranjero sin Establecimiento Permanente en México', 'fisica' => true, 'moral' => true],
            '611' => ['description' => 'Ingresos por Dividendos (socios y accionistas)', 'fisica' => true, 'moral' => false],
            '612' => ['description' => 'Personas Físicas con Actividades Empresariales y Profesionales', 'fisica' => true, 'moral' => false],
            '614' => ['description' => 'Ingresos por intereses', 'fisica' => true, 'moral' => false],
            '615' => ['description' => 'Régimen de los ingresos por obtención de premios', 'fisica' => true, 'moral' => false],
            '616' => ['description' => 'Sin obligaciones fiscales', 'fisica' => true, 'moral' => false],
            '620' => ['description' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos', 'fisica' => false, 'moral' => true],
            '621' => ['description' => 'Incorporación Fiscal', 'fisica' => true, 'moral' => false],
            '622' => ['description' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', 'fisica' => false, 'moral' => true],
            '623' => ['description' => 'Opcional para Grupos de Sociedades', 'fisica' => false, 'moral' => true],
            '624' => ['description' => 'Coordinados', 'fisica' => false, 'moral' => true],
            '625' => ['description' => 'Actividades Empresariales con ingresos a través de Plataformas Tecnológicas', 'fisica' => true, 'moral' => false],
            '626' => ['description' => 'Régimen Simplificado de Confianza', 'fisica' => true, 'moral' => true],
        ];
    }

    /**
     * `c_UsoCFDI`: code => descripción.
     *
     * @return array<string, string>
     */
    public static function cfdiUses(): array
    {
        return [
            'G01' => 'Adquisición de mercancías',
            'G02' => 'Devoluciones, descuentos o bonificaciones',
            'G03' => 'Gastos en general',
            'I01' => 'Construcciones',
            'I02' => 'Mobiliario y equipo de oficina por inversiones',
            'I03' => 'Equipo de transporte',
            'I04' => 'Equipo de cómputo y accesorios',
            'I05' => 'Dados, troqueles, moldes, matrices y herramental',
            'I06' => 'Comunicaciones telefónicas',
            'I07' => 'Comunicaciones satelitales',
            'I08' => 'Otra maquinaria y equipo',
            'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
            'D02' => 'Gastos médicos por incapacidad o discapacidad',
            'D03' => 'Gastos funerales',
            'D04' => 'Donativos',
            'D05' => 'Intereses reales por créditos hipotecarios (casa habitación)',
            'D06' => 'Aportaciones voluntarias al SAR',
            'D07' => 'Primas por seguros de gastos médicos',
            'D08' => 'Gastos de transportación escolar obligatoria',
            'D09' => 'Depósitos en cuentas para el ahorro / planes de pensiones',
            'D10' => 'Pagos por servicios educativos (colegiaturas)',
            'S01' => 'Sin efectos fiscales',
            'CP01' => 'Pagos',
            'CN01' => 'Nómina',
        ];
    }

    public static function isTaxRegime(string $code): bool
    {
        return isset(self::taxRegimes()[$code]);
    }

    public static function isCfdiUse(string $code): bool
    {
        return isset(self::cfdiUses()[$code]);
    }

    /**
     * ¿El régimen es válido para ese tipo de persona (física/moral)?
     */
    public static function regimeAllowsPerson(string $code, string $personType): bool
    {
        $regime = self::taxRegimes()[$code] ?? null;

        if ($regime === null) {
            return false;
        }

        return $personType === 'moral' ? $regime['moral'] : $regime['fisica'];
    }

    /**
     * El tipo de persona que implica un RFC por su longitud: 12 = moral, 13 = física (D200/D266, mismo criterio que
     * proveedores).
     */
    public static function personTypeForRfc(string $rfc): string
    {
        return mb_strlen(trim($rfc)) === 12 ? 'moral' : 'fisica';
    }
}
