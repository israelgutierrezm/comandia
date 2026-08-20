<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de prueba del agente de impresión (§9.4).
 *
 * ## Por qué existe, y por qué NO es el agente real
 *
 * El ejecutable del agente no entra en esta iteración: ni instalador, ni descubrimiento de impresoras, ni servicio de
 * Windows. Lo que sí entra es el **contrato**, porque es lo que las dos implementaciones previstas —el puente Flutter de
 * v1 y el agente de escritorio— tienen que compartir, y cambiarlo después significa actualizar software instalado en
 * computadoras de cocina.
 *
 * Un contrato escrito y no ejercitado es un contrato que no existe. Este comando lo consume de punta a punta: reclama
 * en lote, escribe el papel a un archivo y reporta el resultado. Si algo del contrato es incómodo o falta, se descubre
 * aquí y no dentro de seis meses en una cocina.
 *
 * ## Imprime a ARCHIVO
 *
 * Un `.txt` por trabajo, en `storage/app/print-agent/`. No hay hardware en la máquina de desarrollo y `ENTORNO_LOCAL.md`
 * §7 ya lo dice: la impresión ESC/POS real se verifica con impresora. Lo que sí se verifica aquí es todo lo demás —el
 * reclamo exclusivo, la idempotencia del reporte, la forma del payload— que es donde están los errores caros.
 *
 * ## El formateo de aquí es de MENTIRA, a propósito
 *
 * Pinta el payload en texto plano de una manera legible, y no pretende ser el rendido final: el ancho de papel, los
 * cortes y el juego de caracteres son trabajo del agente real. Meterlos aquí daría la ilusión de que el rendido ya está
 * resuelto.
 */
final class PrintAgentClientCommand extends Command
{
    protected $signature = 'comandia:print:agent
        {--token= : El token del agente}
        {--url=http://localhost:8000 : La base de la API}
        {--once : Una sola pasada en lugar de sondear}
        {--interval=3 : Segundos entre sondeos}
        {--fail= : Simula un fallo con este motivo, para ejercitar el camino de error}';

    protected $description = 'Cliente de prueba del agente de impresión: reclama trabajos, los escribe a archivo y reporta.';

    public function handle(): int
    {
        $token = (string) ($this->option('token') ?? '');

        if ($token === '') {
            $this->components->error('Falta --token. Se obtiene al dar de alta un agente: POST /api/v1/print-agents.');

            return self::FAILURE;
        }

        $base = rtrim((string) $this->option('url'), '/').'/api/v1/print-agent';
        $destino = storage_path('app/print-agent');

        File::ensureDirectoryExists($destino);

        $this->components->info('Agente de prueba en marcha. Los papeles salen en '.$destino);

        do {
            $procesados = $this->pasada($base, $token, $destino);

            if (! $this->option('once')) {
                sleep(max(1, (int) $this->option('interval')));
            }
        } while (! $this->option('once'));

        $this->components->info(sprintf('Listo. %d trabajo(s) en la última pasada.', $procesados));

        return self::SUCCESS;
    }

    /**
     * Una pasada: reclamar, escribir, reportar.
     */
    private function pasada(string $base, string $token, string $destino): int
    {
        $respuesta = Http::withToken($token)->acceptJson()->get($base.'/jobs/next');

        if ($respuesta->status() === 401) {
            $this->components->error('El token del agente no es válido o el agente está desactivado.');

            return 0;
        }

        if (! $respuesta->successful()) {
            $this->components->warn('No se pudo reclamar: HTTP '.$respuesta->status());

            return 0;
        }

        $trabajos = $respuesta->json('data') ?? [];

        foreach ($trabajos as $trabajo) {
            $this->procesar($base, $token, $destino, $trabajo);
        }

        return count($trabajos);
    }

    /**
     * @param  array<string, mixed>  $trabajo
     */
    private function procesar(string $base, string $token, string $destino, array $trabajo): void
    {
        $ulid = (string) $trabajo['ulid'];

        // El camino de error, a petición. Existe porque un contrato en el que sólo se ha probado el éxito no está
        // probado: lo que se rompe en una cocina es el papel que se acabó, no el que salió bien.
        if ($this->option('fail') !== null) {
            Http::withToken($token)->acceptJson()
                ->post($base."/jobs/{$ulid}/failed", ['error' => (string) $this->option('fail')]);

            $this->components->warn("Reportado como fallido: {$ulid}");

            return;
        }

        File::put(
            $destino.DIRECTORY_SEPARATOR.$ulid.'.txt',
            $this->pintar($trabajo),
        );

        $confirmacion = Http::withToken($token)->acceptJson()->post($base."/jobs/{$ulid}/printed");

        $this->components->twoColumnDetail(
            $ulid.'  '.($trabajo['kind_label'] ?? ''),
            $confirmacion->successful() ? '<fg=green>impreso</>' : '<fg=red>HTTP '.$confirmacion->status().'</>',
        );
    }

    /**
     * @param  array<string, mixed>  $trabajo
     */
    private function pintar(array $trabajo): string
    {
        $payload = $trabajo['payload'] ?? [];

        if (($payload['kind'] ?? null) === 'drawer_open') {
            return sprintf(
                "*** ABRIR CAJÓN ***\nMotivo: %s\nAutorizó (membresía): %s\n",
                $payload['reason'] ?? '',
                $payload['actor_membership_id'] ?? '',
            );
        }

        $lineas = [
            str_repeat('=', 32),
            mb_strtoupper((string) ($payload['kind_label'] ?? 'TICKET')),
            (string) ($payload['area'] ?? ''),
            str_repeat('-', 32),
            (string) ($payload['account']['display_name'] ?? ''),
            'Orden: '.($payload['order_sequence'] ?? '-'),
            (string) ($payload['issued_at'] ?? ''),
            str_repeat('-', 32),
        ];

        foreach ($payload['items'] ?? [] as $renglon) {
            $lineas[] = sprintf('%s x %s', $renglon['quantity'] ?? '', $renglon['name'] ?? '');

            foreach ($renglon['modifiers'] ?? [] as $modificador) {
                $lineas[] = sprintf('    + %s x%s', $modificador['name'] ?? '', $modificador['quantity'] ?? 1);
            }
        }

        $lineas[] = str_repeat('=', 32);

        return implode(PHP_EOL, array_filter($lineas, fn (string $l): bool => trim($l) !== '')).PHP_EOL;
    }
}
