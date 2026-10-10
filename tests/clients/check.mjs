import {spawn} from 'node:child_process';
import assert from 'node:assert/strict';
import {JSDOM} from 'jsdom';
import TileWMS from 'ol/source/TileWMS.js';
import WMSCapabilities from 'ol/format/WMSCapabilities.js';
import {get as getProjection, fromLonLat, transform} from 'ol/proj.js';

const repo = new URL('../../', import.meta.url).pathname;
const base = 'http://127.0.0.1:18082/wms.php?MAP=demo';
const dom = new JSDOM('<!doctype html><html><body><div id="map" style="width:512px;height:512px"></div></body></html>', {url:'http://localhost/'});
globalThis.window=dom.window; globalThis.document=dom.window.document; Object.defineProperty(globalThis, 'navigator', {value:dom.window.navigator, configurable:true}); globalThis.DOMParser=dom.window.DOMParser; globalThis.Node=dom.window.Node;
const php = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:18082', '-t', 'public'], {cwd:repo, stdio:['ignore','ignore','pipe']});
let stderr = '';
php.stderr.on('data', chunk => { stderr += chunk.toString(); });
process.on('exit', () => php.kill());
async function request(url, expect = 'image/png') {
  const response = await fetch(url);
  const bytes = new Uint8Array(await response.arrayBuffer());
  const mime = response.headers.get('content-type');
  const good = response.status === 200 && mime?.startsWith(expect) && (expect !== 'image/png' || bytes.slice(0,8).join() === '137,80,78,71,13,10,26,10');
  console.log(`${good ? 'PASS':'FAIL'} ${response.status} ${mime} ${bytes.length}B ${new URL(url).searchParams.get('REQUEST') || new URL(url).searchParams.get('request')} ${url}`);
  if (!good) console.log(Buffer.from(bytes).toString('utf8').slice(0,700));
  assert(good);
  return Buffer.from(bytes).toString('utf8');
}
try {
  for (let attempt=0;;attempt++) {
    try { await fetch(base + '&SERVICE=WMS&REQUEST=GetCapabilities'); break; }
    catch(e) { if (attempt === 30) throw new Error(stderr || String(e)); await new Promise(r=>setTimeout(r,100)); }
  }

  const capsXml = await request(base+'&SERVICE=WMS&REQUEST=GetCapabilities&VERSION=1.3.0', 'text/xml');
  const capabilities = new WMSCapabilities().read(capsXml);
  assert.equal(capabilities.version, '1.3.0');
  assert.deepEqual(capabilities.Capability.Layer.Layer.map(l=>l.Name), ['land','routes','places']);
  console.log('OpenLayers WMSCapabilities parsed:', capabilities.version, capabilities.Capability.Layer.Layer.map(l=>l.Name));

  const coordinate = fromLonLat([11.333,48.223]);
  const projection = getProjection('EPSG:3857');
  const source = new TileWMS({url:base, params:{LAYERS:'land,routes,places', VERSION:'1.3.0', FORMAT:'image/png'}});
  const grid = source.getTileGridForProjection(projection);
  const tileCoord = grid.getTileCoordForCoordAndZ(coordinate, 11);
  const tileUrl = source.tileUrlFunction(tileCoord, 1, projection);
  await request(tileUrl);
  const fiUrl = source.getFeatureInfoUrl(coordinate, grid.getResolution(11), projection, {INFO_FORMAT:'application/json', QUERY_LAYERS:'places', FEATURE_COUNT:5});
  const jsonInfo = JSON.parse(await request(fiUrl, 'application/json'));
  assert.equal(jsonInfo.features?.[0]?.properties?.name, 'Olching');
  assert.equal(jsonInfo.features?.[0]?.properties?.internal_note, undefined);

  const projection4326 = getProjection('EPSG:4326');
  const coordinate4326 = transform(coordinate, projection, projection4326);
  const source4326 = new TileWMS({url:base, projection:projection4326, params:{LAYERS:'land,places', VERSION:'1.3.0', FORMAT:'image/png'}});
  const grid4326 = source4326.getTileGridForProjection(projection4326);
  const tileCoord4326 = grid4326.getTileCoordForCoordAndZ(coordinate4326, 10);
  const tileUrl4326 = source4326.tileUrlFunction(tileCoord4326, 1, projection4326);
  await request(tileUrl4326);
  const fiUrl4326 = source4326.getFeatureInfoUrl(coordinate4326, grid4326.getResolution(10), projection4326, {INFO_FORMAT:'text/plain', QUERY_LAYERS:'places'});
  const textInfo = await request(fiUrl4326, 'text/plain');
  assert(textInfo.includes('Olching'));

  const L = (await import('leaflet')).default;
  const map = L.map('map', {center:[48.22,11.32], zoom:11, zoomControl:false});
  const leafletLayer = L.tileLayer.wms(base, {layers:'land,routes,places', format:'image/png', transparent:true, version:'1.3.0'}).addTo(map);
  const leafletCoord = map.project(map.getCenter(), 11).divideBy(256).floor();
  const leafletUrl = leafletLayer.getTileUrl(Object.assign(leafletCoord, {z:11}));
  await request(leafletUrl);
  // Leaflet itself does not provide a GetFeatureInfo helper.
  leafletLayer.remove(); map.remove();

  const map4326 = L.map('map', {center:[48.22,11.32], zoom:10, crs:L.CRS.EPSG4326, zoomControl:false});
  const leafletLayer4326 = L.tileLayer.wms(base, {layers:'land,places', format:'image/png', transparent:true, version:'1.3.0'}).addTo(map4326);
  const leafletCoord4326 = map4326.project(map4326.getCenter(), 10).divideBy(256).floor();
  await request(leafletLayer4326.getTileUrl(Object.assign(leafletCoord4326, {z:10})));
  leafletLayer4326.remove(); map4326.remove();
  console.log('Compatibility checks complete.');
} finally { php.kill(); }
