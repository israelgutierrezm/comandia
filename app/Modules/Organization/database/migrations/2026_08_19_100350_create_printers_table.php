<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `printers` — a dónde se imprime (§9.1 del diseño de la Iteración 4).
 *
 * ## El hueco que cierra
 *
 * Las áreas de preparación y las terminales existían desde la Iteración 1 y **no tenían a dónde imprimir**. Sin esto,
 * «ruteo por área» no tiene destino: se puede decir que una comanda es de la barra y no se puede decir por qué
 * impresora sale. Y el **cajón de dinero** tampoco se puede abrir, porque en un punto de venta el cajón se abre
 * mandando una secuencia a la impresora de tickets, no por un cable propio.
 *
 * No venía de ninguna iteración pendiente: era una omisión, encontrada al recorrer §6.3 para decidir el alcance (D235).
 *
 * ## Por qué una tabla y no una cadena en el área
 *
 * Con dos a cinco impresoras por sucursal, guardar el destino como texto en cada área lo repetiría —cambiar una IP
 * obligaría a editar todas— y no habría dónde poner `supports_cash_drawer`, que es una propiedad **de la impresora** y
 * no del área. Una impresora es infraestructura física de la sucursal, igual que la terminal y el almacén, y va donde
 * van ellas: en `Organization`.
 *
 * ## `target` es una cadena a propósito
 *
 * Una impresora de red se identifica por IP y puerto, una USB por su nombre de dispositivo y una compartida de Windows
 * por su ruta UNC. Son tres formas que no comparten estructura, y modelarlas en columnas separadas dejaría dos vacías
 * en cada fila. El **agente** local es quien sabe interpretar `target` según `connection` — este sistema no habla con la
 * impresora, sólo le dice al agente cuál es.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT y no CASCADE: una sucursal no se borra (D80, se da de baja), así que un CASCADE aquí no
            // protegería de nada real y sí ocultaría un borrado accidental de infraestructura.
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->char('code', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 60);

            // Cómo llegar a ella. El agente lo interpreta; el servidor sólo lo transporta.
            $table->enum('connection', ['network', 'usb', 'windows_share']);
            $table->string('target', 120);

            // 58 y 80 mm son los dos anchos de rollo térmico del mercado. Se guarda en milímetros y no como enum
            // porque el ancho es lo que decide cuántos caracteres caben por renglón, y eso es un cálculo del formato
            // del ticket — un enum obligaría a traducirlo a número en cada uso.
            $table->unsignedSmallInteger('paper_width')->default(80);

            // Sólo algunas impresoras de tickets llevan el conector del cajón. Sin esta bandera, la interfaz ofrecería
            // «abrir cajón» en una impresora de cocina y el trabajo fallaría sin que nadie entendiera por qué.
            $table->boolean('supports_cash_drawer')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'printers_ulid_unique');

            // El código es único dentro de la SUCURSAL, no del negocio: dos sucursales pueden tener su «COCINA», y
            // prohibirlo sería una regla inventada. Es la misma decisión que en terminales y almacenes.
            $table->unique(['tenant_id', 'branch_id', 'code'], 'printers_tenant_branch_code_unique');

            // La consulta real es «las impresoras activas de esta sucursal», que es lo que necesita el formulario de un
            // área y lo que consulta el agente al arrancar. Empieza por `tenant_id` como toda tabla transaccional.
            $table->index(['tenant_id', 'branch_id', 'status'], 'printers_tenant_branch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
