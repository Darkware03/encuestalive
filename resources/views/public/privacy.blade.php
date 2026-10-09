{{--
    Aviso de privacidad. Enlazado desde los tres layouts.

    El texto dice la verdad incomoda que ARCHITECTURE.md §7.3 obliga a
    declarar: la participacion es anonima en el sentido de que NO SE PIDE
    IDENTIDAD, pero no se promete anonimato absoluto mientras existan
    registros tecnicos de seguridad.

    Prometer "anonimato total" seria mas bonito y seria falso.
--}}
<x-layouts::participant title="Como tratamos los datos">
    <h1 class="text-2xl font-bold text-ink-900">Como tratamos los datos</h1>

    <div class="mt-6 flex flex-col gap-6 text-base text-ink-800">

        <section>
            <h2 class="text-lg font-semibold text-ink-900">Al responder una encuesta</h2>
            <p class="mt-2">
                No se pide cuenta, ni nombre, ni correo, ni telefono. No hay registro
                ni inicio de sesion para participar.
            </p>
            <p class="mt-2">
                Se guarda un identificador aleatorio de sesion en una cookie tecnica.
                Sirve para dos cosas y solo dos: saber cuanta gente esta conectada y
                evitar que un envio se duplique por un fallo de red. Ese identificador
                es <strong>distinto en cada encuesta</strong>, de modo que no permite
                seguir a nadie entre una encuesta y otra.
            </p>
            <p class="mt-2">
                Las respuestas <strong>no se guardan junto a ninguna direccion IP</strong>.
                Las tablas de participacion no tienen columna para ello.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-ink-900">Registros tecnicos</h2>
            <p class="mt-2">
                Como cualquier servicio en internet, se guardan registros de seguridad
                para investigar abusos: intentos de acceso fallidos y peticiones
                excesivas. Esos registros incluyen direcciones IP, viven separados de
                las respuestas y se borran a los
                {{ config('encuestalive.retention.security_log_days') }} dias.
            </p>
            <p class="mt-2">
                <strong>Lo honesto:</strong> la participacion es anonima en el sentido
                de que no se pide identidad, pero no se promete anonimato absoluto
                mientras existan registros tecnicos. Quien tuviera acceso a la vez a
                los registros de seguridad y a las respuestas podria, por coincidencia
                de horas, aproximar correspondencias. Para dificultarlo, la hora de los
                registros de seguridad se guarda redondeada al minuto.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-ink-900">Cuanto tiempo se conserva</h2>
            <ul class="mt-2 list-disc pl-5">
                <li>Respuestas y resultados agregados: mientras exista la encuesta.</li>
                <li>
                    Datos de sesion de participacion:
                    {{ config('encuestalive.retention.session_days') }} dias despues de
                    cerrar la encuesta. Las respuestas sobreviven, desvinculadas.
                </li>
                <li>
                    Registros de seguridad:
                    {{ config('encuestalive.retention.security_log_days') }} dias.
                </li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-ink-900">Lo que no se hace</h2>
            <ul class="mt-2 list-disc pl-5">
                <li>No hay seguimiento publicitario ni perfiles personales.</li>
                <li>No se usa huella de navegador.</li>
                <li>No se comparten datos con terceros.</li>
                <li>No se puede editar una respuesta ya enviada, ni por ti ni por nadie.</li>
            </ul>
        </section>
    </div>
</x-layouts::participant>
