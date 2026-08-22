<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Export;

use Dompdf\Dompdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Convierte el resultado de un reporte —columnas + filas, tal como lo devuelve el motor— en un archivo (Tanda B).
 *
 * CSV y Excel salen por openspout (streaming, no carga todo en memoria); el PDF por dompdf (una tabla HTML). El dinero y
 * los porcentajes ya vienen calculados y redondeados del servidor (D134): aquí sólo se formatean para presentar.
 *
 * No decide alcance ni permisos —eso ya lo aplicó el motor al producir el resultado—: sólo escribe lo que recibe.
 */
final class ReportFileWriter
{
    /**
     * Escribe el resultado en `$absolutePath` con el formato dado y devuelve el número de filas de datos.
     *
     * @param  array{columns: array{dimensions: list<array{key: string, label: string}>, measures: list<array{key: string, label: string, format: string}>}, rows: list<array<string, mixed>>}  $result
     */
    public function write(array $result, string $format, string $label, string $absolutePath): int
    {
        $dimensions = $result['columns']['dimensions'];
        $measures = $result['columns']['measures'];
        $rows = $result['rows'];

        $headers = array_merge(
            array_map(static fn (array $d): string => $d['label'], $dimensions),
            array_map(static fn (array $m): string => $m['label'], $measures),
        );

        match ($format) {
            'csv' => $this->writeSpreadsheet(new CsvWriter(), $headers, $dimensions, $measures, $rows, $absolutePath),
            'xlsx' => $this->writeSpreadsheet(new XlsxWriter(), $headers, $dimensions, $measures, $rows, $absolutePath),
            'pdf' => $this->writePdf($label, $headers, $dimensions, $measures, $rows, $absolutePath),
        };

        return count($rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array{key: string, label: string}>  $dimensions
     * @param  list<array{key: string, label: string, format: string}>  $measures
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeSpreadsheet(CsvWriter|XlsxWriter $writer, array $headers, array $dimensions, array $measures, array $rows, string $path): void
    {
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($this->cells($row, $dimensions, $measures, plain: true)));
        }

        $writer->close();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array{key: string, label: string}>  $dimensions
     * @param  list<array{key: string, label: string, format: string}>  $measures
     * @param  list<array<string, mixed>>  $rows
     */
    private function writePdf(string $label, array $headers, array $dimensions, array $measures, array $rows, string $path): void
    {
        $th = implode('', array_map(static fn (string $h): string => '<th>'.e($h).'</th>', $headers));

        $tr = '';
        foreach ($rows as $row) {
            $cells = $this->cells($row, $dimensions, $measures, plain: false);
            $tr .= '<tr>'.implode('', array_map(static fn ($c): string => '<td>'.e((string) $c).'</td>', $cells)).'</tr>';
        }

        $html = <<<HTML
            <html><head><meta charset="utf-8"><style>
                body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
                h1 { font-size: 16px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
            </style></head><body>
                <h1>{$label}</h1>
                <table><thead><tr>{$th}</tr></thead><tbody>{$tr}</tbody></table>
            </body></html>
            HTML;

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        file_put_contents($path, (string) $dompdf->output());
    }

    /**
     * Las celdas de una fila, en el orden dimensiones→medidas. En PDF las medidas se formatean ($ / %); en hoja de
     * cálculo se dejan crudas para que la celda sea un número usable.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array{key: string, label: string}>  $dimensions
     * @param  list<array{key: string, label: string, format: string}>  $measures
     * @return list<mixed>
     */
    private function cells(array $row, array $dimensions, array $measures, bool $plain): array
    {
        $cells = [];

        foreach ($dimensions as $d) {
            $cells[] = $row[$d['key']] ?? '';
        }

        foreach ($measures as $m) {
            $value = $row[$m['key']] ?? null;
            $cells[] = $plain ? $value : $this->format($value, $m['format']);
        }

        return $cells;
    }

    private function format(mixed $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'money' => '$'.$value,
            'percent' => $value.'%',
            default => (string) $value,
        };
    }
}
