<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Alta de tenant desde la consola.
 *
 * Es el "alta asistida por super admin desde el día 1" (D6) mientras no exista el panel. Sin
 * esto, dar de alta un negocio exige Tinker, y una operación que sólo se puede hacer con Tinker
 * es una operación que nadie documenta y que cada quien hace distinto.
 *
 * La contraseña se pide de forma interactiva y **no** se acepta como argumento: los argumentos
 * quedan en el historial del shell y en los logs de auditoría del sistema operativo.
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'comandia:tenant:create
        {--name= : Nombre comercial del negocio}
        {--email= : Correo del propietario}
        {--first-name= : Nombre del propietario}
        {--paternal-surname= : Apellido paterno del propietario}
        {--maternal-surname= : Apellido materno del propietario}
        {--slug= : Slug para las superficies públicas (se genera si se omite)}
        {--timezone=America/Mexico_City : Zona horaria de la sucursal}
        {--branch-name=Matriz : Nombre de la primera sucursal}
        {--branch-code=MTZ : Código de la primera sucursal (entra en los folios)}';

    protected $description = 'Da de alta un negocio con su propietario, su primera sucursal y su almacén.';

    public function handle(ProvisionTenant $provision): int
    {
        $name = $this->option('name') ?? text('Nombre comercial del negocio', required: true);
        $email = $this->option('email') ?? text('Correo del propietario', required: true);
        $firstName = $this->option('first-name') ?? text('Nombre del propietario', required: true);
        $paternal = $this->option('paternal-surname') ?? text('Apellido paterno', required: true);
        $maternal = $this->option('maternal-surname') ?? text('Apellido materno (opcional)');

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'first_name' => $firstName,
                'paternal_surname' => $paternal,
                'timezone' => $this->option('timezone'),
                'branch_code' => $this->option('branch-code'),
            ],
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:150'],
                'first_name' => ['required', 'string', 'max:60'],
                'paternal_surname' => ['required', 'string', 'max:60'],
                // Validada de verdad: una zona horaria inválida rompería el cálculo de "el día"
                // en los cortes, y el error aparecería semanas después en un reporte.
                'timezone' => ['required', 'timezone'],
                'branch_code' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-]+$/'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // Nunca como argumento: quedaría en el historial del shell.
        $plainPassword = password('Contraseña del propietario', required: true);

        try {
            $result = $provision->provision(
                businessName: (string) $name,
                ownerEmail: (string) $email,
                ownerFirstName: (string) $firstName,
                ownerPaternalSurname: (string) $paternal,
                plainPassword: $plainPassword,
                ownerMaternalSurname: $maternal === '' ? null : $maternal,
                slug: $this->option('slug'),
                timezone: (string) $this->option('timezone'),
                branchName: (string) $this->option('branch-name'),
                branchCode: (string) $this->option('branch-code'),
            );
        } catch (Throwable $e) {
            // Todo va en una transacción, así que no queda un tenant a medias.
            $this->components->error('No se pudo dar de alta el negocio: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Negocio dado de alta.');

        $this->table(
            ['Concepto', 'Valor'],
            [
                ['Negocio', $result['tenant']->name],
                ['Identificador público', $result['tenant']->ulid],
                ['Slug', $result['tenant']->slug],
                ['Estado', $result['tenant']->status->label()],
                ['Propietario', $result['owner']->email],
                ['Código de empleado', $result['membership']->employee_code],
                ['Sucursal', $result['branch']->name.' ('.$result['branch']->code.')'],
                ['Zona horaria', $result['branch']->timezone],
            ],
        );

        $this->newLine();
        $this->components->warn(
            'El negocio nace en «Pendiente de activación»: el propietario ya puede entrar y '
            .'configurar. Actívalo cuando corresponda comercialmente.'
        );
        $this->components->warn(
            'El propietario NO tiene PIN de terminal todavía. Sin PIN no puede autorizar '
            .'acciones sensibles del POS (ADR-008).'
        );

        return self::SUCCESS;
    }
}
