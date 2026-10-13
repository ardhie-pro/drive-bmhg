const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/js/app.js'), 'utf8');
const context = { AbortController, AbortSignal, Response, DOMException, URL, Event, setTimeout,
  document: { addEventListener() {} }, window: { location: {} } };
vm.createContext(context);
vm.runInContext(source + '\nthis.App = DriveApp;', context);
const app = Object.create(context.App.prototype);
app.thumbnailQueue = [];
app.thumbnailRequests = new Map();
app.thumbnailUrls = new Set();
const pending = [];
app.fetchWithRetry = (url, options) => new Promise((resolve, reject) => {
  pending.push(() => resolve(new Response('image')));
  options.signal.addEventListener('abort', () => reject(new DOMException('cancelled', 'AbortError')));
});
function img() { return { isConnected: true, removeAttribute() {}, dispatchEvent() {} }; }
async function tick() { await new Promise(resolve => setTimeout(resolve, 0)); }
(async () => {
  for (let i = 0; i < 10; i++) app.enqueueThumbnail(img(), 'thumb-' + i);
  assert.equal(app.thumbnailRequests.size, 4);
  assert.equal(pending.length, 4);
  for (let i = 0; i < 10; i++) { pending[i](); await tick(); assert.ok(app.thumbnailRequests.size <= 4); }
  assert.equal(app.thumbnailRequests.size, 0);
  for (let i = 0; i < 6; i++) app.enqueueThumbnail(img(), 'cancel-' + i);
  app.cancelThumbnails();
  await tick();
  assert.equal(app.thumbnailQueue.length, 0);
  assert.equal(app.thumbnailRequests.size, 0);
  let stopped = 0;
  app.el = { previewContainer: { querySelector(selector) { if (selector === 'iframe') return null; return { pause() { stopped++; }, removeAttribute() {}, querySelectorAll() { return []; }, load() { stopped++; } }; } } };
  app.previewController = new AbortController();
  const signal = app.previewController.signal;
  app.stopPreview();
  assert.equal(signal.aborted, true);
  assert.equal(stopped, 2);
  // A body which is still being downloaded must also respond to preview cancellation.
  const cancel = new AbortController();
  let receivedHeaders;
  const headersReady = new Promise(resolve => { receivedHeaders = resolve; });
  context.fetch = async (url, options) => ({ status:200, ok:true, headers:new Headers(),
    arrayBuffer() { receivedHeaders(); return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('cancelled', 'AbortError')))); } });
  const bodyRequest = context.App.prototype.fetchWithRetry.call(app, 'image', { signal:cancel.signal });
  await headersReady;
  cancel.abort();
  await assert.rejects(bodyRequest, { name:'AbortError' });
  console.log('PASS: four-request thumbnail cap, queue cancellation, media release, cancellation during response body');
})().catch(error => { console.error(error); process.exitCode = 1; });
