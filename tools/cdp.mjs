// Prueba en un Chromium de verdad (headless) desde Node 22, sin dependencias: protocolo de DevTools por WebSocket.
// Herramienta de desarrollo (no es del núcleo, no va en core.json). Copia de afi-claude (skills/entorno-local/scripts/cdp.mjs).
//
//   node tools/cdp.mjs <url inicial> <pasos.mjs> [captura.png]
//
// pasos.mjs exporta una lista de [nombre, paso]; el paso es una expresión JS que se evalúa en la página, o una función
// async (h) => resultado, con estos ayudantes en h:
//
//   go(url)                         abrir una dirección (espera a que cargue)
//   evalJs(expr)                    evaluar JS en la página (con await); devuelve el valor o «EXC: …»
//   sleep(ms)
//   shot(nombre, { full })          captura en ~/cdp-tmp/<nombre>.png (full: la página entera, no solo la pantalla)
//   click(selector o texto)         pulsar: un selector CSS, o el texto visible de un botón, enlace, etiqueta o summary
//   fill(selector, valor)           escribir en un campo (y avisar con input y change, como al teclear)
//   files(selector, [rutas])        poner ficheros en un <input type=file> (rutas dentro de $HOME: Chromium es un snap)
//   waitFor(selector o expr, ms)    esperar hasta que exista (o sea verdad); devuelve true o false (por defecto 8 s)
//   text(selector)                  el texto visible del elemento (o '' si no está)
//   login({ user, pass })           entrar: rellena usuario o correo (si lo hay) y contraseña y envía el formulario
//   errors()                        errores de JavaScript y de la consola desde el principio (y los vacía)
//   send(método, params)            una orden de DevTools a mano
//
// Se imprime el resultado de cada paso. El perfil y las capturas van en ~/cdp-tmp (Chromium es un snap y no ve /tmp).
// Pantalla de móvil: CDP_SIZE=390,844 (por defecto, 1280×1600).
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const [, , url, stepsFile, shot] = process.argv;
const steps = (await import(resolve(stepsFile))).default;
const home = process.env.HOME + '/cdp-tmp';
mkdirSync(home, { recursive: true });
const chrome = spawn('chromium', ['--headless=new', '--disable-gpu', '--remote-debugging-port=9333', '--window-size=1280,1600',
  '--user-data-dir=' + home + '/profile', 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));

let targets = [];
for (let i = 0; i < 50 && !targets.length; i++) {
  try { targets = (await (await fetch('http://127.0.0.1:9333/json/list')).json()).filter(t => t.type === 'page'); } catch {}
  if (!targets.length) await sleep(200);
}
const ws = new WebSocket(targets[0].webSocketDebuggerUrl);
await new Promise(r => (ws.onopen = r));
let id = 0;
const pending = {};
const jsErrors = [];
ws.onmessage = e => {
  const m = JSON.parse(e.data);
  if (m.id && pending[m.id]) { pending[m.id](m); delete pending[m.id]; return; }
  if (m.method === 'Runtime.exceptionThrown') jsErrors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') jsErrors.push(m.params.args.map(a => a.value ?? a.description ?? '').join(' '));
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending[i] = r; ws.send(JSON.stringify({ id: i, method, params })); });
const evalJs = async expr => {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  return r.result?.exceptionDetails ? 'EXC: ' + (r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text) : r.result?.result?.value;
};
const go = async u => { await send('Page.navigate', { url: u }); await sleep(1500); };

await send('Page.enable');
await send('Runtime.enable');
await send('DOM.enable');
if (process.env.CDP_SIZE) {
  const [width, height] = process.env.CDP_SIZE.split(',').map(Number);
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 2, mobile: width < 800 });
}
const capture = async (file, opts = {}) => {
  const params = { format: 'png' };
  if (opts.full) {
    const m = await send('Page.getLayoutMetrics');
    const size = m.result.cssContentSize || m.result.contentSize;
    Object.assign(params, { captureBeyondViewport: true, clip: { x: 0, y: 0, width: size.width, height: Math.min(size.height, 16000), scale: 1 } });
  }
  const r = await send('Page.captureScreenshot', params);
  writeFileSync(file, Buffer.from(r.result.data, 'base64'));
  return file;
};

// Un elemento por selector CSS o por su texto visible (botones, enlaces, etiquetas, summary, opciones de menú)
const FIND = `(q) => {
  try { const el = document.querySelector(q); if (el) return el; } catch (e) {}
  const norm = s => (s || '').replace(/\\s+/g, ' ').trim().toLowerCase();
  const all = [...document.querySelectorAll('button, a, [role=button], label, summary, input[type=submit], input[type=button], li, [onclick]')]
    .filter(el => el.offsetParent !== null || el.getClientRects().length);
  return all.find(el => norm(el.innerText || el.value) === norm(q)) || all.find(el => norm(el.innerText || el.value).includes(norm(q))) || null;
}`;
const helpers = {
  go, evalJs, sleep, send,
  shot: (n, opts) => capture(home + '/' + n + '.png', opts),
  click: async q => {
    const r = await evalJs(`(() => { const el = (${FIND})(${JSON.stringify(q)}); if (!el) return 'no encontrado: ' + ${JSON.stringify(q)};
      el.scrollIntoView({ block: 'center' }); el.click(); return true; })()`);
    await sleep(300);
    return r;
  },
  fill: (sel, value) => evalJs(`(() => { const el = document.querySelector(${JSON.stringify(sel)}); if (!el) return 'no encontrado: ' + ${JSON.stringify(sel)};
    const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : el instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(String(value))});
    el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`),
  files: async (sel, paths) => {
    const doc = await send('DOM.getDocument', { depth: -1, pierce: true });
    const node = await send('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: sel });
    if (!node.result?.nodeId) return 'no encontrado: ' + sel;
    await send('DOM.setFileInputFiles', { nodeId: node.result.nodeId, files: paths.map(p => resolve(p)) });
    return true;
  },
  waitFor: async (q, ms = 8000) => {
    const expr = /^[.#\[a-z]/i.test(q) && !/[()=!<>]/.test(q) ? `!!document.querySelector(${JSON.stringify(q)})` : q;
    for (let t = 0; t < ms; t += 200) { if ((await evalJs(expr)) === true) return true; await sleep(200); }
    return false;
  },
  text: sel => evalJs(`(document.querySelector(${JSON.stringify(sel)}) || {}).innerText || ''`),
  login: async ({ user = '', pass = '' } = {}) => {
    const r = await evalJs(`(() => {
      const p = document.querySelector('input[type=password]'); if (!p) return 'sin formulario de acceso';
      const u = document.querySelector('input[name=username], input[name=login], input[name=email], input[type=email]');
      const set = (el, v) => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, v); el.dispatchEvent(new Event('input', { bubbles: true })); };
      if (u) set(u, ${JSON.stringify(user)});
      set(p, ${JSON.stringify(pass)});
      const f = p.form; if (f && f.requestSubmit) f.requestSubmit(); else if (f) f.submit();
      return true; })()`);
    await sleep(1800);
    return r;
  },
  errors: () => jsErrors.splice(0),
};

await go(url);
for (const [name, step] of steps) {
  const out = typeof step === 'string' ? await evalJs(step) : await step(helpers);
  console.log(name.padEnd(30), JSON.stringify(out));
}
if (shot) await capture(shot);
ws.close();
chrome.kill();
