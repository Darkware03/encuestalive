#!/usr/bin/env node
/**
 * build-tokens.mjs — EncuestaLive
 *
 * Deriva `resources/css/tokens.css` (bloque @theme de Tailwind 4) desde
 * `/software-factory/deliverables/encuestalive/design-tokens.json`.
 *
 * Objetivo: que NADIE escriba un hex a mano en el CSS ni en un Blade.
 * Si UX cambia un token, se vuelve a ejecutar este script y Tailwind regenera
 * las utilidades. El diseño y el código no se pueden desincronizar.
 *
 * D-03: el modo oscuro queda FUERA del MVP. Los tokens `*-dark` / `color.chart.dark`
 * existen en el JSON como trabajo preparado y este generador los OMITE
 * deliberadamente (se listan al final como "omitidos", no se pierden).
 *
 * Uso:  node tools/build-tokens.mjs [--check]
 *       --check  no escribe; falla con código 1 si el CSS está desactualizado.
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT = resolve(__dirname, '..');

const TOKENS_IN = process.env.ENCUESTALIVE_TOKENS
    ?? '/software-factory/deliverables/encuestalive/design-tokens.json';
const CSS_OUT = resolve(PROJECT, 'resources/css/tokens.css');

if (!existsSync(TOKENS_IN)) {
    console.error(`[tokens] No encuentro ${TOKENS_IN}`);
    process.exit(1);
}

const T = JSON.parse(readFileSync(TOKENS_IN, 'utf8'));

const omitted = [];
const lines = [];
const out = (s = '') => lines.push(s);

/** ¿Es un token de modo oscuro? (D-03) */
const isDark = (key) => /(^|[-.])dark($|[-.])/i.test(key);

// ---------------------------------------------------------------- cabecera
out('/*');
out(' * tokens.css — GENERADO AUTOMÁTICAMENTE. NO EDITAR A MANO.');
out(' *');
out(` * Fuente:   ${TOKENS_IN}`);
out(` * Versión:  ${T.$meta?.version ?? 'n/d'}  ·  ${T.$meta?.phase ?? ''}`);
out(' * Generador: tools/build-tokens.mjs   (node tools/build-tokens.mjs)');
out(' *');
out(' * Contraste: 56/56 pares verificados WCAG 2.2 AA en UX_UI_SPEC.md §5.2.');
out(' * Cambiar un hex aquí INVALIDA esa verificación: cámbialo en design-tokens.json');
out(' * y recalcula el contraste antes.');
out(' *');
out(' * RESTRICCIONES DE USO (UX_UI_SPEC §5.1, $meta.notes del JSON):');
for (const n of T.$meta?.notes ?? []) out(` *   - ${n}`);
out(' */');
out();
out('@theme {');

// ---------------------------------------------------------------- colores
out('  /* ---------- color ---------- */');

const emitColorScale = (prefix, scale) => {
    for (const [k, v] of Object.entries(scale)) {
        if (k.startsWith('$')) continue;
        const name = `${prefix}-${k}`;
        if (isDark(name) || isDark(k)) { omitted.push(`--color-${name}`); continue; }
        const value = typeof v === 'string' ? v : v.value;
        if (!value) continue;
        const comment = typeof v === 'object' && v.comment ? `  /* ${v.comment} */` : '';
        out(`  --color-${name}: ${value};${comment}`);
    }
};

const C = T.color;
emitColorScale('brand', C.brand);
out();
emitColorScale('ink', C.ink);
out();
for (const [sem, scale] of Object.entries(C.semantic)) emitColorScale(sem, scale);
out();
out('  /* Series de gráfico. Regla vinculante: nunca codificar información SOLO por color. */');
emitColorScale('chart', C.chart.light);
if (C.chart.dark) omitted.push('--color-chart-*(dark)');
out();
out('  /* Superficies (modo claro; las *-dark quedan fuera del MVP por D-03) */');
emitColorScale('surface', C.surface);
out();
out('  /* Bordes. border-subtle es DECORATIVO: nunca único límite de un control. */');
emitColorScale('border', C.border);
out();
out('  /* Estados de control */');
emitColorScale('state', C.state);
out();
out('  /* Estado de conexión en tiempo real (siempre con forma + texto, WCAG 1.4.1) */');
emitColorScale('connection', C.connection);
out();
out('  /* Badges de estado de encuesta: pares bg/fg verificados en §6.7 */');
for (const [status, pair] of Object.entries(C.surveyStatus)) {
    out(`  --color-status-${status}-bg: ${pair.bg};`);
    out(`  --color-status-${status}-fg: ${pair.fg};${pair.comment ? `  /* ${pair.comment} */` : ''}`);
}

// ---------------------------------------------------------------- tipografía
out();
out('  /* ---------- tipografía ---------- */');
const ff = T.typography.fontFamily;
out(`  --font-sans: ${ff.sans.value};`);
out(`  --font-mono: ${ff.mono.value};`);
out();
for (const [k, s] of Object.entries(T.typography.scale)) {
    if (k.startsWith('$')) continue;
    out(`  --text-${k}: ${s.fontSize};${s.comment ? `  /* ${s.comment} */` : ''}`);
    if (s.lineHeight) out(`  --text-${k}--line-height: ${s.lineHeight};`);
    if (s.letterSpacing && s.letterSpacing !== '0') out(`  --text-${k}--letter-spacing: ${s.letterSpacing};`);
}
out();
for (const [k, w] of Object.entries(T.typography.fontWeight)) {
    out(`  --font-weight-${k}: ${w.value};`);
}

// ---------------------------------------------------------------- radios
out();
out('  /* ---------- radios ---------- */');
for (const [k, v] of Object.entries(T.radius)) {
    if (k.startsWith('$')) continue;
    out(`  --radius-${k}: ${v.value};${v.comment ? `  /* ${v.comment} */` : ''}`);
}

// ---------------------------------------------------------------- sombras
out();
out('  /* ---------- sombras (máx. 3 niveles; la elevación por defecto es un borde 1px) ---------- */');
for (const [k, v] of Object.entries(T.shadow)) {
    if (k.startsWith('$')) continue;
    if (isDark(k)) { omitted.push(`--shadow-${k}`); continue; }
    if (k === 'focus' || k === 'focus-error') {
        // No son sombras de utilidad: alimentan el patrón de foco de §6.0.
        out(`  --ring-shadow-${k}: ${v.value};`);
        continue;
    }
    out(`  --shadow-${k}: ${v.value};${v.comment ? `  /* ${v.comment} */` : ''}`);
}

// ---------------------------------------------------------------- breakpoints
out();
out('  /* ---------- breakpoints (mobile-first real: el base es 360px, sin prefijo) ---------- */');
for (const [k, v] of Object.entries(T.breakpoint)) {
    if (k.startsWith('$')) continue;
    if (k === 'xs') { out(`  /* xs ${v.value}: ${v.comment} */`); continue; }
    out(`  --breakpoint-${k}: ${v.value};`);
}

// ---------------------------------------------------------------- contenedores
out();
out('  /* ---------- contenedores (max-w-*) ---------- */');
for (const [k, v] of Object.entries(T.grid.container)) {
    out(`  --container-${k}: ${v.value};${v.comment ? `  /* ${v.comment} */` : ''}`);
}

// ---------------------------------------------------------------- movimiento
out();
out('  /* ---------- movimiento ---------- */');
for (const [k, v] of Object.entries(T.motion.easing)) out(`  --ease-${k}: ${v.value};`);
out(`  --default-transition-duration: ${T.motion.duration.fast.value};`);
out(`  --default-transition-timing-function: ${T.motion.easing.standard.value};`);

out('}');

// -------------------------------------------- tokens sin namespace Tailwind
out();
out('/*');
out(' * Tokens sin namespace propio en Tailwind 4. Se exponen como custom properties');
out(' * y se consumen desde las clases de componente o con sintaxis arbitraria.');
out(' */');
out(':root {');
out('  /* duraciones de movimiento */');
for (const [k, v] of Object.entries(T.motion.duration)) {
    out(`  --duration-${k}: ${v.value};${v.comment ? `  /* ${v.comment} */` : ''}`);
}
out();
out('  /* objetivos táctiles — el estándar del proyecto es 44px, 48px en el participante */');
for (const [k, v] of Object.entries(T.sizing.touchTarget)) {
    out(`  --touch-${k}: ${v.value};${v.comment ? `  /* ${v.comment} */` : ''}`);
}
out();
out('  /* alturas de control */');
for (const [k, v] of Object.entries(T.sizing.control)) out(`  --control-${k}: ${v.value};`);
out();
out('  /* iconografía */');
for (const [k, v] of Object.entries(T.sizing.icon)) out(`  --icon-${k}: ${v};`);
out();
out('  /* canalones de rejilla */');
for (const [k, v] of Object.entries(T.grid.gutter)) out(`  --gutter-${k}: ${v};`);
out();
out('  /* z-index — escala cerrada, prohibido inventar valores sueltos */');
for (const [k, v] of Object.entries(T.zIndex)) out(`  --z-${k}: ${v};`);
out();
out('  /* anillos de foco (§6.0 · WCAG 2.2 2.4.13) */');
out(`  --ring-focus: ${T.shadow.focus.value};`);
out(`  --ring-focus-error: ${T.shadow['focus-error'].value};`);
out();
out('  /* tipografía numérica en vivo: tabular-nums obligatorio */');
out(`  --numeric-features: ${T.typography.fontFamily.numeric.fontFeatureSettings};`);
out('}');
out();

const css = lines.join('\n');

if (process.argv.includes('--check')) {
    const current = existsSync(CSS_OUT) ? readFileSync(CSS_OUT, 'utf8') : '';
    if (current !== css) {
        console.error('[tokens] DESACTUALIZADO: resources/css/tokens.css no coincide con design-tokens.json');
        console.error('[tokens] Ejecuta: node tools/build-tokens.mjs');
        process.exit(1);
    }
    console.log('[tokens] OK: tokens.css está sincronizado con design-tokens.json');
    process.exit(0);
}

writeFileSync(CSS_OUT, css, 'utf8');

const varCount = (css.match(/^\s{2}--/gm) ?? []).length;
console.log(`[tokens] Escrito ${CSS_OUT}`);
console.log(`[tokens] ${varCount} custom properties derivadas de design-tokens.json v${T.$meta?.version}`);
console.log(`[tokens] Omitidos por D-03 (modo oscuro fuera del MVP): ${omitted.length} tokens`);
for (const o of omitted) console.log(`[tokens]   · ${o}`);
