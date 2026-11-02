// Prueba en un Chromium de verdad (headless) desde Node 22, sin dependencias: protocolo de DevTools por WebSocket.
// Herramienta de desarrollo (no es del núcleo, no va en core.json).
//
//   node tools/cdp.mjs <url inicial> <pasos.mjs> [captura.png]
//
// pasos.mjs exporta una lista de [nombre, paso]; el paso es una expresión JS que se evalúa en la página, o una
// función async ({ go, evalJs, sleep }) => resultado. Se imprime el resultado de cada uno. El perfil y la captura
// van en ~/cdp-tmp (Chromium es un snap y no ve /tmp).
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
ws.onmessage = e => { const m = JSON.parse(e.data); if (m.id && pending[m.id]) { pending[m.id](m); delete pending[m.id]; } };
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending[i] = r; ws.send(JSON.stringify({ id: i, method, params })); });
const evalJs = async expr => {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  return r.result?.exceptionDetails ? 'EXC: ' + (r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text) : r.result?.result?.value;
};
const go = async u => { await send('Page.navigate', { url: u }); await sleep(1500); };

await send('Page.enable');
await send('Runtime.enable');
await go(url);
for (const [name, step] of steps) {
  const out = typeof step === 'string' ? await evalJs(step) : await step({ go, evalJs, sleep, send });
  console.log(name.padEnd(30), JSON.stringify(out));
}
if (shot) {
  const r = await send('Page.captureScreenshot', { format: 'png' });
  writeFileSync(shot, Buffer.from(r.result.data, 'base64'));
}
ws.close();
chrome.kill();
