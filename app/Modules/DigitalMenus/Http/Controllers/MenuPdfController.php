<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Http\Controllers;

use App\Modules\DigitalMenus\Application\AssembleMenu;
use App\Modules\DigitalMenus\Infrastructure\Models\DigitalMenu;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Dompdf\Dompdf;
use Illuminate\Http\Response;

/**
 * Genera el PDF de un menú (Iteración 8, Tanda A). Autenticado (lado admin), gateado por `module:DigitalMenus` y
 * `digital_menus.pdf.generate`. Reusa {@see AssembleMenu} y una plantilla Blade tematizada por los campos `theme_*`.
 *
 * v1: menú de texto tematizado (sin fotos en el PDF —dompdf y las imágenes de disco son frágiles—; deuda declarada). El
 * editor libre de plantillas es evolución (§6.8).
 */
final class MenuPdfController
{
    use AssertsBranchScope;

    public function __construct(private readonly AssembleMenu $assembler) {}

    public function __invoke(DigitalMenu $digitalMenu): Response
    {
        $this->assertBranchInScope((int) $digitalMenu->branch_id);

        $data = $this->assembler->forMenu($digitalMenu->load('branch'));

        $html = view('menu.pdf', ['menu' => $data])->render();

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $fileName = 'menu-'.$digitalMenu->slug.'.pdf';

        return new Response((string) $dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
