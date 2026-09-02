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
            // La apariencia dejó de ser un ajuste suelto (acento + barra): ahora es un TEMA completo —paleta entera con
            // nombre— que cada persona elige y personaliza (estilo Acadion). Vive en sus propias tablas
            // (`themes`/`theme_tokens`/`membership_theme_overrides`) y lo resuelve `ThemeResolver`, no el catálogo de
            // ajustes. Por eso `appearance.accent`/`appearance.sidebar` ya no están aquí.

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
                allowedLabels: [
                    'branch_default' => 'Un almacén por sucursal',
                    'per_area' => 'Un almacén por área de preparación',
                ],
            ),

            new SettingDefinition(
                key: 'inventory.waste_authorization_threshold',
                type: SettingType::Decimal,
                // Cero significa «toda merma se autoriza», que NO es un valor razonable por omisión: haría que
                // cada vaso roto exigiera el PIN de un gerente y la gente acabaría no registrando mermas — el
                // peor resultado posible, porque el inventario se descuadra igual y sin rastro.
                //
                // 500 pesos es una merma que en una fonda ya duele y no ocurre a diario. El negocio lo ajusta.
                default: 500.00,
                maxScope: SettingScope::Branch,
                module: 'Inventory',
                // Por sucursal porque el volumen de un bar y de una fonda no se parecen, y un umbral que sirve
                // en uno vuelve el otro impracticable.
                description: 'Monto de merma que exige autorización de un superior con PIN.',
            ),

            new SettingDefinition(
                key: 'inventory.count_authorization_threshold',
                type: SettingType::Decimal,
                // Un orden de magnitud por encima del umbral de mermas, y no es arbitrario: una merma es un evento
                // (un vaso, una caja) y un conteo es el descuadre acumulado de semanas en un almacén entero. Con el
                // mismo umbral que las mermas, TODO cierre pediría el PIN del propietario y el control se
                // volvería un trámite que se firma sin leer — que es peor que no tenerlo.
                //
                // Se mide en valor ABSOLUTO: un conteo con veinte mil de sobrante y veinte mil de faltante tiene
                // neto cero y reescribe cuarenta mil pesos de inventario. El neto dejaría pasar exactamente el
                // caso que más urge revisar.
                default: 5000.00,
                maxScope: SettingScope::Branch,
                module: 'Inventory',
                description: 'Diferencia total de un conteo que exige autorización del propietario con PIN.',
            ),

            // Los dos pasos omitibles de la transferencia (D25). Apagados por omisión, y no es pereza: por
            // omisión el flujo es solicitar → enviar → recibir, tres pasos con un hecho físico detrás cada uno.
            //
            // Con los cinco activos desde el primer día, el caso común —una sucursal le presta un costal de arroz
            // a otra— exige cinco peticiones y probablemente dos personas, y lo previsible es que la gente deje de
            // usar transferencias y registre entradas y salidas manuales: se pierde el documento que las
            // relaciona, que es lo único que la transferencia aporta sobre dos movimientos sueltos.
            //
            // Ámbito de NEGOCIO y no de sucursal: una transferencia tiene dos extremos, y si cada sucursal pudiera
            // exigir pasos distintos no habría forma de saber cuál flujo aplica.
            new SettingDefinition(
                key: 'inventory.transfers_require_authorization',
                type: SettingType::Bool,
                default: false,
                maxScope: SettingScope::Tenant,
                module: 'Inventory',
                description: 'Una transferencia necesita autorización antes de poder enviarse.',
            ),

            new SettingDefinition(
                key: 'inventory.transfers_require_preparation',
                type: SettingType::Bool,
                default: false,
                maxScope: SettingScope::Tenant,
                module: 'Inventory',
                description: 'Una transferencia necesita registrarse como preparada antes de enviarse.',
            ),

            // ---------------------------------------------------------------
            // Compras (§6.2)
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'purchasing.vat_is_creditable',
                type: SettingType::Bool,
                // Verdadero por omisión: es lo correcto para un negocio que acredita IVA, que es el perfil de la
                // mayoría de los que emiten factura. Con IVA acreditable el impuesto se recupera contra el IVA
                // cobrado, así que sumarlo al costo lo inflaría un 16 % y hundiría todos los márgenes.
                //
                // En falso —RESICO, régimen simplificado, o quien compra sin factura en la central de abastos— el
                // impuesto pagado SÍ es dinero que no vuelve, y entonces sí es costo.
                //
                // El DOCUMENTO no cambia con este ajuste: la recepción guarda siempre la verdad de la factura
                // —precio sin IVA, tasa, impuesto— y lo único que decide esto es qué costo se manda a Costing. Por
                // eso el criterio se congela en cada recepción: sin eso, cambiar el ajuste volvería inexplicable el
                // costo de las recepciones viejas.
                //
                // RIESGO CON FECHA: cambiar este ajuste NO recalcula los costos ya capturados —el historial de
                // costos es inmutable (§7)— así que el historial quedaría con dos criterios mezclados. Si el
                // negocio cambia de régimen, lo correcto es capturar costos nuevos, no esperar que los viejos se
                // corrijan solos.
                default: true,
                maxScope: SettingScope::Tenant,
                module: 'Purchasing',
                description: 'El IVA de las compras es acreditable, así que no forma parte del costo.',
            ),

            // ---------------------------------------------------------------
            // POS (§6.3)
            // ---------------------------------------------------------------
            // El precorte ciego NO es un ajuste: es ciego por PERMISOS (D289). Declarar (`pos.sessions.precount`) y ver
            // el corte con el esperado (`finance.cuts.view`) son permisos distintos, y ésa es toda la mecánica. Una
            // sucursal que no quiere precorte ciego le da `finance.cuts.view` a sus cajeros. Tener además una llave
            // `pos.blind_precount` era un control muerto —nadie lo leía— que sólo podía contradecir al permiso; se quitó
            // al cablear los ajustes (punto 5), consistente con lo que D289 ya rechazaba: un corte que a veces muestra
            // el esperado y a veces no.
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
                // `on_pickup` por omisión: preserva el mostrador de siempre —se prepara y se cobra al recoger—. `on_order`
                // es el opt-in más estricto (pagar antes de que salga a cocina), que la sucursal enciende a sabiendas.
                // Gobierna el momento de COMANDAR, no la entrega: la entrega nunca depende del pago (D269).
                default: 'on_pickup',
                maxScope: SettingScope::Branch,
                module: 'Pos',
                allowed: ['on_order', 'on_pickup'],
                description: 'Cuándo se cobra un pedido para llevar. «Al ordenar» exige pagarlo antes de mandarlo a cocina; '
                    .'«al recoger» lo prepara sin pago previo.',
                allowedLabels: [
                    'on_order' => 'Al ordenar',
                    'on_pickup' => 'Al recoger',
                ],
            ),

            // ---------------------------------------------------------------
            // Salón (§6.4)
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'floor.use_cleaning_state',
                type: SettingType::Bool,
                // Apagado por omisión, y no es indecisión: en una fonda de comida corrida el mesero limpia y sienta a
                // los siguientes en el mismo movimiento, así que un estado intermedio obligatorio sería un toque de
                // más por mesa y por servicio. En un restaurante con encargado de piso es justo la señal que
                // necesita, y lo enciende.
                default: false,
                maxScope: SettingScope::Branch,
                module: 'Floor',
                description: 'Al pagarse todas las cuentas, la mesa pasa a «por limpiar» en lugar de quedar libre.',
            ),

            // ---------------------------------------------------------------
            // Promociones (§6.3, D315)
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'promotions.allow_stacking',
                type: SettingType::Bool,
                // Apagado por omisión: la regla de §6.3 es «no acumulables, mejor gana». La excepción configurable existe
                // para el negocio que sí quiere apilar —una promoción de categoría MÁS una de temporada— y la enciende a
                // sabiendas. Encendido, sólo se acumulan las promociones marcadas como acumulables entre sí; una no
                // acumulable sigue compitiendo por «la mejor». Es un toggle del sistema jerárquico (D20), nunca una
                // columna suelta.
                default: false,
                maxScope: SettingScope::Branch,
                module: 'Promotions',
                description: 'Permite que varias promociones acumulables se apliquen juntas en lugar de que gane sólo la mejor.',
            ),

            // ---------------------------------------------------------------
            // Finanzas (§6.5)
            // ---------------------------------------------------------------
            new SettingDefinition(
                key: 'finance.expense_authorization_threshold',
                type: SettingType::Decimal,
                // Mismo razonamiento que el umbral de mermas: cero significaría «todo gasto pide PIN», y el resultado
                // sería que el cajero deja de registrar los garrafones para no ir a buscar al gerente. El dinero sale
                // igual y el arqueo se descuadra sin rastro — exactamente lo que este registro existe para evitar.
                //
                // 1000 pesos es un gasto que en una fonda ya es una decisión y no una compra de rutina. Y es por
                // SUCURSAL porque el gasto corriente de un bar y de una fonda no se parecen.
                default: 1000.00,
                maxScope: SettingScope::Branch,
                module: 'Finance',
                description: 'Monto de gasto que exige autorización de un superior con PIN.',
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
                allowedLabels: [
                    'none' => 'Sin redondeo',
                    'integer' => 'Al peso',
                    'multiple_5' => 'A múltiplos de $5',
                    'multiple_10' => 'A múltiplos de $10',
                ],
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
            new SettingDefinition(
                key: 'pricing.stale_price_tolerance_percent',
                type: SettingType::Decimal,
                default: 5.00,
                maxScope: SettingScope::Tenant,
                module: 'Costing',
                // Caso de uso, como exige D20: el semáforo de "precio desactualizado" de D15 necesita
                // un umbral. Sin él, el redondeo que el propio tenant configuró marcaría en rojo el
                // 100 % del catálogo el primer día — y un semáforo que siempre está en rojo no lo mira
                // nadie, con lo que se pierde justo la señal que D15 quería dar.
                description: 'Desviación permitida entre el precio final y el sugerido antes de marcarlo como desactualizado.',
            ),

            // E-commerce (D51): la aceptación automática de pedidos NO vive aquí. Es un dato por TIENDA
            // —`stores.auto_accept_orders`, con su propia UI—, y lo lee el procesador de pagos. Tenerlo además como
            // llave de catálogo por sucursal era una segunda fuente muerta (nadie la leía) que sólo podía
            // desincronizarse de la verdadera. Se quitó al cablear los ajustes (punto 5).
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
