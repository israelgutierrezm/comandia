<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Finder\Finder;

/**
 * Escáner textual del código fuente para los candados estructurales.
 *
 * Es textual a propósito: un análisis semántico sería más preciso pero también más
 * frágil y mucho más lento, y lo que estas reglas necesitan es un candado que nadie
 * pueda desactivar sin darse cuenta. Su limitación está declarada donde importa —no
 * distingue `$rol->givePermissionTo()` de `$usuario->givePermissionTo()`— y en esos
 * casos el complemento es una aserción de base de datos en tiempo de ejecución.
 */
final class SourceScanner
{
    /**
     * Archivos de `app/` que contienen alguno de los patrones, excluyendo los
     * autorizados.
     *
     * @param  list<string>  $patterns  fragmentos literales a buscar
     * @param  list<string>  $allowedPaths  rutas relativas —archivo o carpeta— permitidas
     * @return list<string> rutas relativas al raíz del proyecto, con `/` como separador
     */
    public static function findUsages(array $patterns, array $allowedPaths): array
    {
        $offenders = [];

        foreach (self::phpFiles() as $relative => $contents) {
            if (self::isAllowed($relative, $allowedPaths)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = $relative;

                    break;
                }
            }
        }

        sort($offenders);

        return $offenders;
    }

    /**
     * @return array<string, string> ruta relativa => código sin comentarios
     */
    private static function phpFiles(): array
    {
        $files = [];
        $root = base_path();

        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
            $relative = str_replace('\\', '/', substr((string) $file->getRealPath(), strlen($root) + 1));

            $files[$relative] = self::normalize(
                self::stripComments(
                    (string) file_get_contents((string) $file->getRealPath())
                )
            );
        }

        return $files;
    }

    /**
     * Normaliza el código para que los patrones no dependan del formato.
     *
     * Descubierto por la meta-verificación de `AuthorizationDisciplineTest`: el patrón
     * `roles()->whereHas('permissions'` no coincidía porque la llamada real está partida en
     * dos líneas por el formateador. **Un candado que se evade pulsando Enter no es un
     * candado**, y peor aún, se queda verde mientras deja de proteger.
     *
     * Se colapsa el espacio en blanco y se quitan los espacios alrededor de `->` y `::`,
     * que es donde el formateador corta las cadenas de llamadas.
     */
    public static function normalize(string $code): string
    {
        $collapsed = (string) preg_replace('/\s+/', ' ', $code);

        return (string) preg_replace('/\s*(->|::)\s*/', '$1', $collapsed);
    }

    /**
     * Quita comentarios antes de buscar.
     *
     * Sin esto, los candados delatan la documentación: un docblock que explica *por qué*
     * está prohibido `$user->can()` contiene literalmente `->can(` y hace fallar el
     * test. La alternativa —contorsionar los comentarios para no escribir lo prohibido—
     * empeoraría justo lo que hace útil a este proyecto, que es explicar el motivo de
     * cada regla.
     *
     * Las cadenas de texto SÍ se conservan: un `Gate::allows` construido dinámicamente
     * a partir de una cadena sigue siendo una violación, y es de las que más conviene
     * cazar.
     */
    private static function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
                // Se sustituye por un salto de línea para no pegar tokens vecinos.
                $out .= "\n";

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * @param  list<string>  $allowedPaths
     */
    private static function isAllowed(string $relative, array $allowedPaths): bool
    {
        foreach ($allowedPaths as $allowed) {
            $allowed = str_replace('\\', '/', $allowed);

            if ($relative === $allowed || str_starts_with($relative, rtrim($allowed, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
