<script setup>
/**
 * Las líneas de un desglose de costo, anidadas.
 *
 * Es recursivo porque el costeo lo es: una torta contiene salsa, y la salsa contiene jitomate. Una
 * tabla plana mostraría el costo de la salsa como un número dado, que es exactamente lo que el
 * desglose existe para no hacer — el usuario tiene que poder bajar hasta el insumo que se compra.
 *
 * El componente se importa a sí mismo por su nombre de archivo; en un `<script setup>` de un archivo
 * llamado `CostBreakdownLines.vue`, Vue resuelve la autorreferencia sin necesidad de declararla.
 */
const props = defineProps({
    lines: { type: Array, required: true },
    depth: { type: Number, default: 0 },
});
</script>

<template>
    <template v-for="(line, index) in props.lines" :key="`${props.depth}-${index}-${line.component_ulid}`">
        <tr :class="{ 'row--nested': props.depth > 0 }">
            <td>
                <span :style="{ paddingLeft: `${props.depth * 1.1}rem` }">
                    <span v-if="props.depth > 0" class="branch">└</span>
                    {{ line.component_name }}
                    <span v-if="line.is_producible" class="badge badge--off">producible</span>
                </span>
            </td>

            <td class="num">{{ line.quantity }} {{ line.unit_code }}</td>

            <!--
                La cantidad convertida a la unidad base del componente. Es la que multiplica el costo, y
                verla evita la pregunta más frecuente al revisar un costeo: «capturé 0.2 kg, ¿por qué
                dice 200?».
            -->
            <td class="num muted">= {{ line.quantity_in_base_unit }} {{ line.base_unit_code }}</td>

            <td class="num">
                <template v-if="line.component_unit_cost !== null">
                    ${{ line.component_unit_cost }}
                </template>
                <span v-else class="missing">sin costo</span>
            </td>

            <!-- El rendimiento se muestra sólo cuando NO es 100 %: es el número que explica por qué la
                 línea no cuesta cantidad × costo, y repetir «100 %» en veinte filas lo volvería invisible. -->
            <td class="num">
                <span v-if="line.yield_percent !== '100.00'" class="yield">{{ line.yield_percent }} %</span>
                <span v-else class="muted">—</span>
            </td>

            <td class="num">
                <template v-if="line.line_cost !== null">
                    <strong>${{ line.line_cost }}</strong>
                </template>
                <span v-else class="missing">—</span>
            </td>
        </tr>

        <!--
            Los renglones anidados son la receta de una TANDA COMPLETA del componente, no la porción que
            entra aquí: por eso «900 g de jitomate» aparece debajo de una línea de 120 ml, y sus importes
            son mayores que el de su propia línea padre. Sin decirlo, el desglose parece no cuadrar —y es
            lo primero que alguien nota al revisarlo—. Lo encontró el navegador.
        -->
        <template v-if="line.sub_lines?.length">
            <tr class="caption">
                <td :colspan="6">
                    <span :style="{ paddingLeft: `${(props.depth + 1) * 1.1}rem` }">
                        Así se costea una tanda completa de <strong>{{ line.component_name }}</strong>: de
                        aquí sale su costo de ${{ line.component_unit_cost }} por
                        {{ line.base_unit_code }}, que es el que multiplica la línea de arriba.
                    </span>
                </td>
            </tr>

            <CostBreakdownLines :lines="line.sub_lines" :depth="props.depth + 1" />
        </template>
    </template>
</template>

<style scoped>
.num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.caption {
    background: #fcfcfb;
}

.caption td {
    font-size: 0.75rem;
    opacity: 0.7;
    padding-top: 0.4rem;
    padding-bottom: 0.1rem;
}

.row--nested {
    background: #fcfcfb;
    font-size: 0.95em;
}

.branch {
    opacity: 0.3;
    margin-right: 0.2rem;
}

.muted {
    opacity: 0.45;
}

.missing {
    color: #b45309;
}

.yield {
    color: #92400e;
}

.badge {
    display: inline-block;
    padding: 0.05rem 0.3rem;
    border-radius: 0.25rem;
    font-size: 0.65rem;
    background: #f5f5f4;
    color: #78716c;
    margin-left: 0.25rem;
}
</style>
