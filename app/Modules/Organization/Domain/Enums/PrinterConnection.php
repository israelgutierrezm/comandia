<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

/**
 * Cómo llega el agente a la impresora.
 *
 * Los tres casos reales de un negocio de alimentos, y no hay un cuarto en v1:
 *
 *   - **Red**: la impresora tiene IP propia. Es lo normal en cocina, porque el cable de datos llega más lejos que un
 *     USB y no depende de que una computadora concreta esté encendida.
 *   - **USB**: colgada de la computadora donde corre el agente. Es lo normal en la caja.
 *   - **Compartida de Windows**: la instaló otra máquina de la red y se llega por su ruta UNC. Aparece en instalaciones
 *     que ya tenían la impresora funcionando antes de este sistema, y no admitirla obligaría a recablear para migrar.
 *
 * El servidor **no habla con la impresora**: guarda esto para que el agente sepa cómo interpretar `target`. Por eso el
 * enum vive en `Organization` —es una propiedad del hardware de la sucursal— y no en `Printing`, que es quien manda los
 * trabajos.
 */
enum PrinterConnection: string
{
    case Network = 'network';
    case Usb = 'usb';
    case WindowsShare = 'windows_share';

    public function label(): string
    {
        return match ($this) {
            self::Network => 'Red (IP)',
            self::Usb => 'USB',
            self::WindowsShare => 'Compartida de Windows',
        };
    }

    /**
     * Qué se espera en `target` para esta conexión.
     *
     * Vive aquí y no en la interfaz por la lección de D139: si el cliente escribe sus propias etiquetas, acaban
     * diciendo algo distinto de lo que el servidor valida. La pantalla lo pide a la API y lo muestra tal cual.
     */
    public function targetHint(): string
    {
        return match ($this) {
            self::Network => 'IP y puerto, por ejemplo 192.168.1.50:9100',
            self::Usb => 'Nombre del dispositivo tal como lo ve la computadora del agente',
            self::WindowsShare => 'Ruta compartida, por ejemplo \\\\CAJA-01\\TICKETS',
        };
    }
}
