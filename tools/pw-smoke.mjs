import { chromium } from '@playwright/test';
try {
  const b = await chromium.launch({ args: ['--no-sandbox','--disable-dev-shm-usage'] });
  const p = await b.newPage({ viewport: { width: 1280, height: 720 } });
  await p.setContent('<h1 style="font:700 48px system-ui;padding:60px">EncuestaLive · toolchain OK</h1>');
  await p.screenshot({ path: '/tmp/pw-smoke.png' });
  console.log('CHROMIUM LANZA OK');
  await b.close();
} catch (e) { console.log('CHROMIUM FALLA ->', String(e.message).split('\n').slice(0,8).join(' | ')); }
