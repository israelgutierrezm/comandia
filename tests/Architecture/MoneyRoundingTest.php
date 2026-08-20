<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * CANDADO: no se reduce la escala de un decimal con `bcmath` a secas, porque TRUNCA.
 *
 * ## El defecto
 *
 * `bcmath` trunca en lugar de redondear. Eso está escrito en el encabezado de `Decimal` desde la Iteración 2, y aun así
 * lo volví a cometer en el paso 7 de ésta, extrayendo el IVA de un precio IVA incluido (D30):
 *
 *     $base = bcdiv($total, $divisor, 6);   // 45.00 ÷ 1.16 = 38.793103
 *     return bcsub($total, $base, 2);       // 6.206897 → '6.20' TRUNCADO, no '6.21'
 *
 * Un centavo por renglón, siempre hacia abajo, en el impuesto que el ticket desglosa. Nada falla: sale un número
 * plausible que no cuadra con el precio que el cliente pagó, y el desglose del corte tampoco.
 *
 * ## Por qué el candado ve este defecto y no todos
 *
 * `bcadd($a, $b, 2)` entre dos importes de dos decimales es EXACTO: no hay nada que truncar. El truncamiento aparece
 * sólo cuando un operando trae más decimales que la escala destino, y ahí el proyecto ya usaba
 * `Decimal::round(bcmul(..., 6), 2)` en todos lados menos en mi línea.
 *
 * Saber si un operando trae más decimales exige, en general, seguir el dato. Lo que sí es decidible —y es justo la forma
 * del defecto— es la CASCADA dentro de una misma función: una variable calculada con escala alta y usada después con
 * escala 2. Eso se lee del texto. Se comprueban dos cosas:
 *
 *   1. Una variable asignada de un `bc*` con escala > 2 no puede aparecer luego en un `bc*` con escala ≤ 2, salvo
 *      envuelta en `Decimal::round(...)`.
 *   2. `bcdiv` con escala 1 o 2 está prohibido. Una división trunca su residuo por definición, y a escala de dinero lo
 *      hace en silencio: el dígito que decidía el redondeo se pierde y redondear después ya no lo recupera (por eso
 *      existe `Decimal::divide`).
 *
 * ## Escala 0 sí se permite, y no es una excepción de conveniencia
 *
 * `bcdiv($amount, $multiple, 0)` en `RoundingMode::ceilToMultiple` es el primer paso de un techo a múltiplos: el
 * cociente entero. Ahí truncar **es** la operación pedida, no un descuido, y se lee como tal en la línea. La frontera
 * está en la intención declarada por la escala: 0 dice «quiero la parte entera»; 1 o 2 dice «quiero dinero», y ahí
 * truncar nunca es lo que se quiso.
 *
 * No cubre el caso de un operando que llega con decimales desde la base o desde un parámetro. Ése queda en la revisión
 * humana, y este candado no lo disimula: cierra la forma que ya se cometió dos veces.
 */
it('no se reduce la escala de un decimal con bcmath a secas', function () {
    $hallazgos = [];

    $archivos = Finder::create()->files()->in(app_path())->name('*.php')
        // El propio ayudante es quien tiene permiso: `Decimal::round` trunca a propósito, después de sumar medio dígito.
        ->notPath('Shared/Domain/Support/Decimal.php');

    foreach ($archivos as $file) {
        $relativa = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        $lineas = preg_split('/\R/', $file->getContents()) ?: [];

        /** @var array<string, int> $altaEscala nombre de variable => escala con la que se calculó */
        $altaEscala = [];

        foreach ($lineas as $numero => $linea) {
            // Los comentarios hablan de `bcdiv` y de escalas constantemente —este candado incluido— y no ejecutan nada.
            $codigo = preg_replace('#(//|\*|/\*).*$#', '', $linea) ?? '';

            if (trim($codigo) === '') {
                continue;
            }

            // Una variable con escala alta pertenece a la función donde se calculó. Sin reiniciar, un `$base` de escala
            // 6 en un método contaminaría un `$base` de dos decimales en el siguiente.
            if (preg_match('/\bfunction\s/', $codigo) === 1) {
                $altaEscala = [];
            }

            $llamadas = llamadasBcDe($codigo);

            foreach ($llamadas as $llamada) {
                // Regla 2: dividir a escala de dinero trunca el residuo en silencio.
                if ($llamada['funcion'] === 'div' && in_array($llamada['escala'], [1, 2], true)) {
                    $hallazgos[] = sprintf(
                        '%s:%d — `bcdiv` con escala %d trunca el residuo; usa `Decimal::divide`.',
                        $relativa,
                        $numero + 1,
                        $llamada['escala'],
                    );
                }

                // Regla 1, segunda mitad: ¿esta llamada baja a escala ≤ 2 usando una variable de escala alta?
                if ($llamada['escala'] === null || $llamada['escala'] > 2) {
                    continue;
                }

                foreach (array_keys($altaEscala) as $variable) {
                    if (! str_contains($llamada['operandos'], '$'.$variable)) {
                        continue;
                    }

                    // `Decimal::round(bcsub($total, $base, 6), 2)` es la forma correcta; ahí el `bc*` interno ya no
                    // lleva escala 2. Si aun así la línea envuelve todo en `Decimal::round`, se acepta.
                    if (str_contains($codigo, 'Decimal::round')) {
                        continue;
                    }

                    $hallazgos[] = sprintf(
                        '%s:%d — `$%s` se calculó con escala %d y aquí baja a escala %d truncando; envuelve en `Decimal::round`.',
                        $relativa,
                        $numero + 1,
                        $variable,
                        $altaEscala[$variable],
                        $llamada['escala'],
                    );
                }
            }

            // Regla 1, primera mitad: registrar las variables de escala alta. Va DESPUÉS de comprobar, porque
            // `$x = bcsub($x, $y, 6)` reasigna y no debe delatarse a sí misma.
            if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:Decimal::round\s*\(\s*)?bc(?:add|sub|mul|div)\s*\(/', $codigo, $m) === 1) {
                $escala = $llamadas[0]['escala'] ?? null;

                // Envuelto en `Decimal::round`, lo que la variable acaba teniendo es la escala del redondeo, no la del
                // `bc*` interno. Ésa es precisamente la forma correcta, y la variable queda limpia.
                if ($escala !== null && $escala > 2 && ! str_contains($codigo, 'Decimal::round')) {
                    $altaEscala[$m[1]] = $escala;
                } else {
                    unset($altaEscala[$m[1]]);
                }
            }
        }
    }

    expect($hallazgos)->toBe([], "Truncamiento de dinero:\n\n".implode("\n", $hallazgos));
});

/**
 * Las llamadas a `bcadd|bcsub|bcmul|bcdiv` de una línea, con su lista de argumentos y su escala.
 *
 * ## Por qué no una regex, que era mi primer intento
 *
 * `/bcdiv\s*\([^;]*,\s*([0-2])\s*\)/` parece bastar y no basta: `[^;]*` cruza los paréntesis de cierre. En
 * `Decimal::round(bcmul($a, bcdiv($b, '100', 6), 6), 2)` el patrón arrancaba en `bcdiv(` y hacía coincidir el `, 2)`
 * final —la escala del redondeo de afuera— acusando de truncar a la línea que hace lo correcto.
 *
 * Los paréntesis no se equilibran con expresiones regulares, así que se recorren los caracteres contando profundidad.
 * Son quince líneas y quitan la clase entera de falso positivo.
 *
 * @return list<array{funcion: string, operandos: string, escala: int|null}>
 */
function llamadasBcDe(string $codigo): array
{
    $encontradas = [];

    if (preg_match_all('/\bbc(add|sub|mul|div)\s*\(/', $codigo, $inicios, PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

    foreach ($inicios[0] as $indice => [$texto, $offset]) {
        $profundidad = 1;
        $cursor = $offset + strlen($texto);
        $largo = strlen($codigo);

        while ($cursor < $largo && $profundidad > 0) {
            $profundidad += match ($codigo[$cursor]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            $cursor++;
        }

        // Sin cerrar en esta línea: la llamada sigue en la siguiente y no se puede leer su escala aquí.
        if ($profundidad !== 0) {
            continue;
        }

        $inicioOperandos = $offset + strlen($texto);
        $operandos = substr($codigo, $inicioOperandos, $cursor - 1 - $inicioOperandos);

        // El último argumento de primer nivel es la escala.
        $escala = null;
        $profundidad = 0;
        $ultimaComa = null;

        foreach (str_split($operandos) as $posicion => $caracter) {
            $profundidad += match ($caracter) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };

            if ($caracter === ',' && $profundidad === 0) {
                $ultimaComa = $posicion;
            }
        }

        if ($ultimaComa !== null) {
            $candidata = trim(substr($operandos, $ultimaComa + 1));
            $escala = preg_match('/^\d+$/', $candidata) === 1 ? (int) $candidata : null;
        }

        $encontradas[] = [
            'funcion' => $inicios[1][$indice][0],
            'operandos' => $operandos,
            'escala' => $escala,
        ];
    }

    return $encontradas;
}
