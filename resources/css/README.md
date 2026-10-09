# Sistema de diseño — EncuestaLive (Fase 5a)

Cómo están conectados el diseño y el código, y qué NO se debe tocar a mano.

## 1. Los tokens no se copian: se derivan

```
/software-factory/deliverables/encuestalive/design-tokens.json   ← fuente única
                 │
                 │  node tools/build-tokens.mjs
                 ▼
resources/css/tokens.css        ← @theme de Tailwind 4 · GENERADO · no editar
                 │
                 │  @import en resources/css/app.css
                 ▼
Utilidades Tailwind: bg-brand-700, text-ink-900, bg-status-active-bg, …
```

`tokens.css` **está generado**. Si editas un hex ahí, lo perderás en la siguiente
ejecución y, lo que es peor, romperás la verificación de contraste: los 56 pares
de `UX_UI_SPEC.md` §5.2 se calcularon sobre los valores del JSON.

Para cambiar un color:

1. cámbialo en `design-tokens.json`,
2. recalcula el contraste de los pares afectados,
3. `node tools/build-tokens.mjs`,
4. `npm run build`.

Para comprobar en CI que nadie los ha desincronizado:

```bash
node tools/build-tokens.mjs --check   # sale con código 1 si difieren
```

163 custom properties se derivan automáticamente. 8 tokens de modo oscuro se
**omiten a propósito**: D-03 deja el modo oscuro fuera del MVP. No se pierden,
siguen en el JSON como trabajo preparado.

### Tokens con restricción de uso

| Token | Ratio | Regla |
|---|---|---|
| `ink-400` `#94A1B4` | 2,62:1 | **Decorativo.** Prohibido como texto o icono con significado |
| `border-subtle` `#DDE3EC` | 1,29:1 | **Divisor decorativo.** Nunca como único límite de un control |

El límite de un control interactivo usa siempre `border-strong` `#78849A` (3,77:1).

## 2. Tipografía: Inter autoalojada, sin CDN en runtime

`UX_UI_SPEC.md` §5.3 eligió **Inter** (SIL OFL) en lugar de Museo Sans, que es
comercial y de licencia no verificable para este proyecto.

**Qué se hizo exactamente:**

- Los `.woff2` se descargaron **una sola vez, en tiempo de desarrollo**, desde
  `fonts.bunny.net` (espejo de Google Fonts que sirve los binarios originales
  del proyecto Inter) y viven versionados en `resources/css/fonts/`.
- `resources/css/fonts.css` declara los `@font-face` propios. Vite procesa los
  `url()` y emite los binarios con hash a `public/build/assets/`.
- **En tiempo de ejecución no hay ninguna petición a una CDN externa**: ni
  Google Fonts, ni Bunny, ni `preconnect`. Verificado en la captura con
  Playwright: 0 peticiones fallidas y `document.fonts.check('16px Inter') === true`.
- Subconjuntos: `latin` + `latin-ext`. `latin-ext` es obligatorio para el
  español (á é í ó ú ñ ü ¿ ¡). Se omiten cyrillic, greek y vietnamese: ~180 KB
  de descarga inútil para el participante en el 4G de un evento.
- Pesos 400/500/600/700, exactamente los declarados en el JSON.
- `font-display: swap`: el texto es legible desde el primer frame. Nadie espera
  a una fuente para poder leer una pregunta.

Se **retiró** del `vite.config.js` el plugin `fonts` con `bunny('Instrument Sans')`
que traía el esqueleto de Laravel: esa familia no es la del producto y además
descargaba en cada build.

## 3. Qué hay en cada fichero

| Fichero | Contenido |
|---|---|
| `tokens.css` | **GENERADO.** `@theme` + custom properties sin namespace Tailwind |
| `fonts.css` | `@font-face` de Inter, autoalojada |
| `app.css` | Imports, capa base (foco, 16px en inputs, reduced-motion), utilidades y las pocas clases de componente que las utilidades no pueden expresar |
| `fonts/*.woff2` | Binarios de Inter, versionados |

En `app.css` solo hay CSS propio cuando una utilidad Tailwind no basta:
animación del skeleton, congelado de ancho del botón en carga, scrim del modal,
radio/checkbox nativos con `appearance-none`, y el anillo de foco que debe
rodear la fila completa de una opción (`:has()`).

Todo lo demás es Tailwind en los `.blade.php` de `resources/views/components/`.

## 4. Reglas que no son negociables

- **Foco:** patrón único con `:focus-visible` (nunca `:focus`). 2px + 2px de
  separación. Prohibido `outline: none` sin sustituto.
- **16px mínimo** en `<input>`, `<textarea>` y `<select>`: por debajo, iOS
  Safari hace zoom al enfocar y descoloca el formulario.
- **`tabular-nums`** (`class="tabular"`) en todo número que cambie en vivo.
- **`prefers-reduced-motion: reduce`** anula todas las transiciones. Obligatorio.
- **Nunca scroll horizontal.** Las tablas se transforman en tarjetas bajo `md`.
- **El color nunca es el único portador de significado.** Estados con forma +
  texto, series de gráfico con etiqueta y valor.
- **Sin `user-scalable=no` ni `maximum-scale`.**

## 5. Verificación visual

```bash
npm run build
php tools/render-gallery.php        # → storage/app/screenshots/gallery.html
node tools/capture-gallery.mjs      # capturas + comprobaciones automáticas
node tools/capture-sections.mjs     # una captura por sección (revisables)
```

`capture-gallery.mjs` no solo fotografía: comprueba errores de consola,
peticiones fallidas, si Inter está realmente aplicada, si hay scroll horizontal
a 320/390/768/1280 y los tamaños reales de los objetivos táctiles. Deja el
resultado en `storage/app/screenshots/capture-report.json`.
