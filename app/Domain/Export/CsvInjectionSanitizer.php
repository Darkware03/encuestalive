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
     * Caracteres que convierten una celda en formula.
     *
     * TAB y CR YA NO FIGURAN AQUI, y no es un descuido: ahora se recorta TODO
     * el espacio en blanco inicial antes de mirar el prefijo, asi que TAB, CR
     * y LF se tratan por la via general en vez de por una lista de casos
     * particulares. Ver la nota de F-03 mas abajo.
     */
    public const DANGEROUS_PREFIXES = ['=', '+', '-', '@'];

    /**
     * F-03 (ALTO, Fase 7) — EL SANEADOR ERA EVADIBLE.
     *
     * ==================================================================
     *  QUE FALLO
     * ==================================================================
     * La version anterior comparaba el PRIMER caracter contra una lista de
     * prefijos peligrosos que incluia TAB y CR "porque algunas hojas de
     * calculo los recortan al abrir y dejan al descubierto el caracter
     * siguiente". El razonamiento era correcto. El problema es que ese mismo
     * razonamiento se aplica identicamente a otros espacios en blanco que NO
     * estaban en la lista y que tampoco eliminaba la limpieza de caracteres
     * de control:
     *
     *     "\n=1+1"        LF: fuera del rango de control limpiado y sin listar
     *     " =1+1"         espacio: no es caracter de control
     *     "\u{00A0}=1+1"  NBSP: fuera del rango ASCII del patron
     *
     * El auditor los probo los tres y los tres escapaban: la formula llegaba
     * desnuda a la celda. Un participante que escribiera un salto de linea
     * seguido de `=cmd|'/c calc'!A1` en un campo de texto libre obtenia una
     * celda NO neutralizada en el CSV que abre el administrador.
     *
     * ==================================================================
     *  LA CORRECCION: LISTA NEGRA -> NORMALIZACION PREVIA
     * ==================================================================
     * Mantener una lista de "espacios peligrosos" es perder la carrera: hay
     * decenas de caracteres de espacio en Unicode (U+2000..U+200A, U+202F,
     * U+205F, U+3000, el BOM U+FEFF, los de ancho cero...). Asi que se
     * invierte la logica:
     *
     *   1. Se RECORTA todo el espacio en blanco inicial, en su sentido
     *      Unicode completo, para obtener un "testigo".
     *   2. Se decide sobre el PRIMER CARACTER SIGNIFICATIVO del testigo.
     *   3. Si es peligroso, se antepone el apostrofo AL TEXTO ORIGINAL, que
     *      no se modifica: el dato del participante se conserva intacto.
     *
     * El testigo es solo para decidir; nunca se exporta.
     *
     * `\s` con el modificador /u ya cubre los espacios Unicode, pero se anaden
     * NBSP y BOM de forma explicita porque son los dos que mas se usan para
     * esto y porque conviene que se lean en el codigo.
     *
     * NOTA DE ALCANCE, HONESTA: que Excel o LibreOffice recorten de verdad
     * estos prefijos al importar NO se ha podido verificar aqui (no hay hoja
     * de calculo instalada en este host). Es el mismo supuesto, documentado,
     * sobre el que el codigo original ya justificaba incluir TAB y CR. Se
     * corrige por prudencia: el coste de neutralizar de mas es un apostrofo;
     * el de neutralizar de menos, una ejecucion de comando en la maquina de
     * quien exporta.
     */
    private const LEADING_WHITESPACE = '/^[\s\x{00A0}\x{FEFF}\x{180E}\x{200B}-\x{200D}\x{2060}]+/u';

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
         * F-04 (BAJO, Fase 7): UTF-8 invalido ya no vacia la celda.
         *
         * `preg_replace` con /u devuelve null ante una cadena que no sea UTF-8
         * valido, y la version anterior lo absorbia con `?? ''`: TODA la celda
         * se convertia en cadena vacia y una respuesta legitima desaparecia
         * del CSV sin aviso. Peor aun, la cadena invalida se saltaba tambien
         * la comprobacion de prefijo.
         *
         * Ahora se descartan solo los BYTES invalidos y se conserva el resto
         * del texto, que ademas vuelve a pasar por la neutralizacion.
         */
        $text = $this->ensureValidUtf8($text);

        if ($text === '') {
            return '';
        }

        /*
         * Los caracteres de control se eliminan ANTES de mirar el prefijo. Si
         * no, un `\0=cmd` dejaria el `=` fuera de la comprobacion. Se
         * conservan el tabulador y los saltos de linea legitimos DENTRO del
         * texto: un comentario de varias lineas es un dato valido, y el
         * entrecomillado de RFC 4180 ya los contiene sin romper el fichero.
         */
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        if ($text === '') {
            return '';
        }

        if ($this->isDangerous($text)) {
            // El apostrofo va delante del texto ORIGINAL, con sus espacios y
            // saltos de linea: no se altera el dato del participante.
            return "'" . $text;
        }

        return $text;
    }

    /**
     * Primer caracter SIGNIFICATIVO, ignorando todo el espacio inicial.
     *
     * Es el corazon de la correccion de F-03: lo que decide no es el primer
     * byte, sino el primer caracter que una hoja de calculo vera despues de
     * recortar el espacio de cortesia.
     */
    public function isDangerous(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $probe = preg_replace(self::LEADING_WHITESPACE, '', $this->ensureValidUtf8($text));

        // Si el patron falla por cualquier motivo, se decide sobre el texto
        // tal cual: fallar hacia el lado seguro, nunca hacia el permisivo.
        $probe ??= $text;

        if ($probe === '') {
            return false;   // solo espacios: no hay formula que neutralizar
        }

        return in_array(mb_substr($probe, 0, 1), self::DANGEROUS_PREFIXES, true);
    }

    /**
     * Descarta los bytes que no forman UTF-8 valido, conservando el resto.
     *
     * `mb_convert_encoding` de UTF-8 a UTF-8 sustituye cada secuencia invalida
     * por el caracter de reemplazo; despues se retira ese caracter para no
     * ensuciar la celda. La alternativa anterior (devolver '') perdia el dato
     * entero.
     */
    private function ensureValidUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }

        return str_replace("\u{FFFD}", '', (string) $clean);
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
