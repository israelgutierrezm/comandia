<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

use Stringable;

/**
 * Nombre de una persona, en las tres partes del registro civil mexicano (D76).
 *
 * Expone exactamente **dos** formas, y sólo dos, para que nadie invente una
 * tercera a medio proyecto:
 *
 *   - {@see self::short()} — nombre y apellido paterno. Es lo que cabe en una
 *     comanda, un ticket y un botón táctil del POS.
 *   - {@see self::full()} — nombre y ambos apellidos. Administración, nómina,
 *     auditoría y reportes.
 *
 * El apellido materno es nullable porque las personas extranjeras no lo tienen
 * (ESPECIFICACIÓN_MAESTRA §4.1).
 */
final readonly class PersonName implements Stringable
{
    public function __construct(
        public string $firstName,
        public string $paternalSurname,
        public ?string $maternalSurname = null,
    ) {}

    /**
     * Para comandas, tickets y superficies táctiles.
     */
    public function short(): string
    {
        return trim("{$this->firstName} {$this->paternalSurname}");
    }

    /**
     * Para administración, nómina y auditoría.
     */
    public function full(): string
    {
        return trim("{$this->firstName} {$this->paternalSurname} {$this->maternalSurname}");
    }

    /**
     * Iniciales para avatares y etiquetas compactas del POS.
     */
    public function initials(): string
    {
        return mb_strtoupper(
            mb_substr($this->firstName, 0, 1).mb_substr($this->paternalSurname, 0, 1)
        );
    }

    public function __toString(): string
    {
        return $this->full();
    }
}
