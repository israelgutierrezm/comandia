<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Marketplace;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * El canal existe en el catálogo pero su adaptador real aún no está implementado (ADR-015, Fase 3): se
 * conoce el contrato, pero cablear DiDi/Uber/Rappi depende de su convenio y credenciales. 503 (no 500):
 * es un "todavía no", no un error del cliente ni una falla del servidor.
 */
final class MarketplaceChannelNotWired extends ServiceUnavailableHttpException
{
    public function __construct(string $channelLabel)
    {
        parent::__construct(
            retryAfter: null,
            message: "El canal {$channelLabel} aún no está cableado (ADR-015, Fase 3).",
        );
    }
}
