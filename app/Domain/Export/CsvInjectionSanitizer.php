<?php

namespace App\Domain\Export;

/**
 * Neutralizacion de formulas CSV (brief §6.2, API.md §3.2).
 *
 * =============================== EL ATAQUE ===============================
 * Un CSV no ejecuta nada por si mismo. El problema es Excel, LibreOffice y
 * Google Sheets: al abrir el fichero, interpretan como FORMULA cualquier celda
 * que empiece por `=`, `+`, `-` o `@`. Un participante que escriba
 *
 *     =SUM(A1:A9)*cmd|'/c calc'!A1
 *
 * en un campo de texto libre consigue que la hoja de calculo de quien exporte
 * intente ejecutar un comando. El texto se guardo sin peligro; el peligro
 * aparece en la herramienta de destino.
 *
 * Por eso la neutralizacion va EN EL PUNTO DE EXPORTACION y no en la entrada:
 * sanear al guardar destruiria el texto real del participante, y ademas no
 * protegeria los datos que ya estuvieran almacenados.
 * =========================================================================
 *
 * Se aplica a TODA celda, no solo al texto libre. Una etiqueta de opcion o un
 * enunciado de pregunta los escribe el administrador, pero un administrador
 * tambien puede equivocarse, y una encuesta duplicada puede arrastrar texto de
 * cualquier procedencia. Es mas barato neutralizar todo que mantener una lista
 * de que campos son "de confianza".
 */
class CsvInjectionSanitizer
{
    /**
     * Prefijos peligrosos. TAB (0x09) y CR (0x0D) estan incluidos porque
     * algunas hojas de calculo los recortan al abrir y dejan al descubierto el
     * caracter siguiente, que puede ser un `=`.
     */
    public const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Devuelve el valor seguro para una celda.
     *
     * La neutralizacion es un APOSTROFO ANTEPUESTO: es la convencion que las
     * hojas de calculo entienden como "esto es texto literal", de modo que el
     * dato sigue siendo legible para una persona. No se borra ni se modifica
     * el contenido: un numero negativo como `-5` se exporta como `'-5`, que se
     * lee igual de bien y ya no es una formula.
     */
    public function sanitize(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(\DATE_ATOM);
        }

        $text = (string) $value;

        if ($text === '') {
            return '';
        }

        /*
         * Los caracteres de control se eliminan ANTES de mirar el prefijo. Si
         * no, un `\0=cmd` o un salto de linea inicial dejaria el `=` fuera de
         * la comprobacion y la neutralizacion se podria esquivar. Se conservan
         * el tabulador y los saltos de linea legitimos dentro del texto: un
         * comentario de varias lineas es un dato valido, y el entrecomillado
         * de RFC 4180 ya los contiene sin romper el fichero.
         */
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        if ($text === '') {
            return '';
        }

        if ($this->isDangerous($text)) {
            return "'" . $text;
        }

        return $text;
    }

    public function isDangerous(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return in_array(mb_substr($text, 0, 1), self::DANGEROUS_PREFIXES, true);
    }

    /**
     * Una fila completa, ya entrecomillada segun RFC 4180.
     *
     * No se usa `fputcsv` porque escribe en un recurso y aqui hace falta
     * devolver la cadena para un `StreamedResponse` por trozos. El
     * entrecomillado es incondicional: siempre comillas, comillas internas
     * duplicadas. Una celda que contenga `;` o un salto de linea no puede
     * romper la estructura del fichero.
     *
     * @param  array<int, mixed>  $cells
     */
    public function row(array $cells): string
    {
        $escaped = array_map(function (mixed $cell): string {
            $value = $this->sanitize($cell);

            return '"' . str_replace('"', '""', $value) . '"';
        }, $cells);

        // CRLF: lo que RFC 4180 exige y lo que Excel espera.
        return implode(',', $escaped) . "\r\n";
    }
}
