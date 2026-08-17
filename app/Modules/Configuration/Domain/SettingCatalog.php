<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Domain\Enums\SettingScope;
use App\Modules\Configuration\Domain\Enums\SettingType;
use App\Modules\Configuration\Domain\Exceptions\UnknownSettingKeyException;

/**
 * Catálogo cerrado de llaves de configuración — LA fuente de verdad de los defaults
 * de sistema (ARQUITECTURA_MAESTRA §5).
 *
 * Los defaults viven aquí, en código, y no en base: un tenant que no configura nada
 * obtiene un restaurante funcional (ESPECIFICACIÓN_MAESTRA §1) sin que nadie tenga
 * que sembrarle filas.
 *
 * Aquí viven los toggles del principio de configuración dual (D20). Cada iteración
 * agrega los suyos y **cada toggle justifica su caso de uso**: una llave sin caso de
 * uso escrito es una decisión que nadie tomó, ofrecida al usuario como si la hubiera
 * pedido.
 */
final class SettingCatalog
{
    /**
     * @var array<string, SettingDefinition>|null
     */
    private static ?array $definitions = null;

    /**
     * @throws UnknownSettingKeyException
     */
    public static function get(string $key): SettingDefinition
    {
        $definitions = self::all();

        if (! isset($definitions[$key])) {
            throw UnknownSettingKeyException::make($key);
        }

        return $definitions[$key];
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    /**
     * Llaves de un módulo, para la pantalla de configuración.
     *
     * @return array<string, SettingDefinition>
     */
    public static function forModule(string $module): array
    {
        return array_filter(self::all(), fn (SettingDefinition $d): bool => $d->module === $module);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private static function build(): array
    {
        $definitions = [
            // ---------------------------------------------------------------
            // Localización — preparadas sin UI de cambio (D52)
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'locale',
                type: SettingType::Enum,
                default: 'es_MX',
                maxScope: SettingScope::Tenant,
                module: 'Configuration',
                allowed: ['es_MX'],
                description: 'Idioma y formato regional. México exclusivamente en v1.',
            ),
            new SettingDefinition(
                key: 'currency',
                type: SettingType::Enum,
                default: 'MXN',
                maxScope: SettingScope::Tenant,
                module: 'Configuration',
                allowed: ['MXN'],
                description: 'Moneda. Sin multi-moneda en v1.',
            ),

            // ---------------------------------------------------------------
            // Impuestos — override por sucursal exigido por §6.1
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'tax.vat_rate',
                type: SettingType::Decimal,
                default: 16.00,
                maxScope: SettingScope::Branch,
                module: 'Configuration',
                description: 'Tasa de IVA aplicable. Los precios son IVA incluido; el desglose se calcula.',
            ),

            // ---------------------------------------------------------------
            // Seguridad (§10.2, D54, D55)
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'security.password_min_length',
                type: SettingType::Int,
                default: 10,
                maxScope: SettingScope::Tenant,
                module: 'Identity',
                description: 'Longitud mínima de contraseña.',
            ),
            new SettingDefinition(
                key: 'security.require_two_factor_for_admin_roles',
                type: SettingType::Bool,
                default: false,
                maxScope: SettingScope::Tenant,
                module: 'Identity',
                description: 'Exigir 2FA a los roles marcados como administrativos.',
            ),
            new SettingDefinition(
                key: 'security.pin_max_attempts',
                type: SettingType::Int,
                default: 5,
                maxScope: SettingScope::Tenant,
                module: 'Identity',
                description: 'Intentos de PIN antes de bloquear la membresía.',
            ),
            new SettingDefinition(
                key: 'security.pin_lock_minutes',
                type: SettingType::Int,
                default: 15,
                maxScope: SettingScope::Tenant,
                module: 'Identity',
                description: 'Minutos de bloqueo del PIN tras agotar los intentos.',
            ),
            new SettingDefinition(
                key: 'security.terminal_session_minutes',
                type: SettingType::Int,
                default: 480,
                maxScope: SettingScope::Branch,
                module: 'Identity',
                // Por sucursal porque un turno de bar y uno de cafetería no duran igual.
                description: 'Expiración de la sesión de una terminal, en minutos.',
            ),

            // ---------------------------------------------------------------
            // Inventarios (D11) — la topología degrada hacia lo simple
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'inventory.warehouse_mode',
                type: SettingType::Enum,
                default: 'branch_default',
                maxScope: SettingScope::Branch,
                module: 'Inventory',
                allowed: ['branch_default', 'per_area'],
                description: 'Si el consumo se descuenta del almacén de la sucursal o del de cada área.',
            ),

            // ---------------------------------------------------------------
            // POS (§6.3)
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'pos.blind_precount',
                type: SettingType::Bool,
                // Recomendado por §6.3: el cajero declara sin ver lo esperado, que es
                // lo que hace útil la diferencia de corte como señal.
                default: true,
                maxScope: SettingScope::Branch,
                module: 'Pos',
                description: 'Precorte ciego: el cajero declara sin ver el monto esperado.',
            ),
            new SettingDefinition(
                key: 'pos.lock_items_on_bill_request',
                type: SettingType::Bool,
                default: false,
                maxScope: SettingScope::Branch,
                module: 'Pos',
                description: 'Bloquear la captura de items cuando se solicita la cuenta.',
            ),
            new SettingDefinition(
                key: 'pos.takeout_payment_timing',
                type: SettingType::Enum,
                default: 'on_order',
                maxScope: SettingScope::Branch,
                module: 'Pos',
                allowed: ['on_order', 'on_pickup'],
                description: 'Cuándo se cobra un pedido para llevar: al ordenar o al recoger.',
            ),

            // ---------------------------------------------------------------
            // Precios y costeo (D15) — el sistema sugiere, el humano decide
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'pricing.rounding_mode',
                type: SettingType::Enum,
                default: 'none',
                maxScope: SettingScope::Tenant,
                module: 'Costing',
                allowed: ['none', 'integer', 'multiple_5', 'multiple_10'],
                description: 'Redondeo aplicado al precio sugerido.',
            ),
            new SettingDefinition(
                key: 'pricing.default_markup_percent',
                type: SettingType::Decimal,
                default: 200.00,
                maxScope: SettingScope::Tenant,
                module: 'Costing',
                // MARKUP sobre costo, no margen. El glosario normativo (§7) prohíbe
                // usarlos como sinónimos, y el nombre de la llave lo respeta.
                description: 'Markup sobre costo por defecto para el precio sugerido.',
            ),

            // ---------------------------------------------------------------
            // E-commerce (D51) — módulo activable
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'ecommerce.auto_accept_orders',
                type: SettingType::Bool,
                default: false,
                maxScope: SettingScope::Branch,
                module: 'Ecommerce',
                description: 'Aceptar automáticamente los pedidos pagados, sin bandeja.',
            ),
        ];

        $indexed = [];

        foreach ($definitions as $definition) {
            $indexed[$definition->key] = $definition;
        }

        return $indexed;
    }

    /**
     * Sólo para pruebas: olvida el catálogo memoizado.
     */
    public static function flush(): void
    {
        self::$definitions = null;
    }
}
