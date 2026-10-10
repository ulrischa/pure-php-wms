# Overview data, caching, diagnostics and style services

## Large map extents without raising resource limits

Map and feature-info requests skip completely scale-hidden styles **before opening their dataset**. Attribute-dependent rules remain conservative: a layer is skipped only when none of its possible symbolizers can apply at that scale. Query limits still apply to visible layers; the server never silently truncates a map.

For dense or complex datasets, prepare simplified overview files in a GIS, register each as a normal source, and import them. Select them by scale in the **service layer**, for example:

```json
{
  "title": "Administrative areas",
  "dataset": "areas_detail",
  "overviews": [
    {"min_scale": 100000, "dataset": "areas_medium"},
    {"min_scale": 1000000, "dataset": "areas_small"}
  ],
  "styles": ["areas"],
  "queryable": true,
  "query_fields": ["name", "code"]
}
```

Insert this object under the appropriate layer name in the service's `layers` object. Register all three datasets in `config/sources.json` and run `php bin/console.php import --all`. Below 1:100,000 the detailed source is used; at 1:100,000 the medium source is used; at 1:1,000,000 the small source is used. The highest applicable `min_scale` wins, regardless of configuration order. At most eight distinct positive thresholds are accepted.

Overview files must have compatible geometry types and attribute names for styles and `query_fields`. Map, context legend and feature-info requests use the **same scale-selected dataset**. Feature IDs and geometries may therefore change between overview levels; do not treat response IDs as globally stable identifiers. GetCapabilities still describes the detailed source extent, so overview data should describe the same geographic coverage. A missing selected overview is an error, not an automatic fallback to expensive detailed data.

Automatic topology-changing simplification is deliberately not performed. Prepare and inspect shared polygon borders and holes in a GIS. The existing feature, vertex, image and execution limits remain in force.

## Cache and diagnostics

Additional `config/server.json` settings:

```json
{
  "cache_slots": 256,
  "cache_ttl": 3600,
  "cache_max_bytes": 67108864,
  "log_requests": false
}
```

Merge these fields into your complete server configuration. `cache_slots` now means **maximum cached image files**, not hash buckets. Files are named by the complete cache key, avoiding bucket collisions. The shipped defaults allow 256 images and **64 MiB of completed cache entries**, whichever limit is reached first. `cache_max_bytes` accepts 1 MiB–1 GiB. Older configurations without the new fields default to 64 MiB and logging disabled.

Writes remove expired entries and then evict least recently accessed entries as needed. Access time has filesystem/second-level granularity, so this is approximate LRU. Maintenance is serialized; when maintenance is busy, an already rendered response may be served without caching. Temporary writes and lock files are outside the completed-entry quota; allow additional disk space for one temporary image. Cleanup racing with reads is supported.

Identical uncached GetMap requests cannot render concurrently: a short metadata lock reserves a bounded request slot for the **exact** map key. A duplicate receives HTTP 503 with `Retry-After: 5`, rather than holding a PHP worker waiting. Different keys do not conflict merely because their hashes share a prefix. The existing global render limit applies as well. Cache hits work without acquiring render slots. Locks are local-filesystem locks, not distributed coordination.

Successful responses include:

- `X-WMS-Cache`: `hit`, `miss`, or `none` for operations without a map-cache lookup.
- `Server-Timing`: total WMS handling duration in milliseconds.
- `X-Request-ID`: the existing diagnostic reference.

Set `log_requests` to `true` to write one JSON record per handled HTTP request to the PHP error log. Records include status, duration, PHP peak memory, operation, cache state and available rendering counters; failures include their WMS code/message. Whole query strings, SLD documents and feature attributes are not logged. Configure log rotation with your host. PHP peak memory does not necessarily account for all native GD allocations.

Run:

```sh
php bin/console.php doctor
php bin/console.php doctor --json
php bin/console.php doctor --json --deep
```

Doctor checks PHP/extensions/FreeType, SQLite RTree, CRS initialization, cache writes/locking/rename, service/style configuration and all referenced detail/overview databases. `--deep` additionally runs SQLite `quick_check` and checks feature/index row counts. It reports cache occupancy and exits nonzero on failures. This is a **CLI administration command**, not a public endpoint. CLI success does not establish Apache access restrictions or identical web-PHP settings.

## GetStyles

```text
wms.php?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetStyles&MAP=demo&LAYERS=land,routes,places&SLD_VERSION=1.0.0
```

Returns `application/vnd.ogc.sld+xml`: an SLD **1.0.0** StyledLayerDescriptor containing all published styles for the selected layers, including native JSON styles converted to SLD. The first published style is marked default. GetCapabilities exposes this URL in each style's standard `StyleSheetURL` element. GetStyles is an extension, not an additional mandatory WMS-core operation.

Limits: 1–16 requested layers, at most 1 MiB XML output. Unknown layers and unsupported SLD_VERSION values are rejected. Service/layer publication restrictions still apply. The endpoint reads configuration and does not need dataset files.

**Export boundaries:** this exports styling, not a self-contained GIS project. Local PNG and TTF names remain resource references; another server needs those files copied and its resource references/font mapping adjusted. Resources are not made publicly downloadable. Native JSON label-priority/repeat-distance options have no export mapping in this SLD subset. Native JSON equality compares string representations strictly, while untyped OGC comparisons may compare numeric-looking values numerically: values such as `"01"`, `"1.0"` and `1` can classify differently after conversion. For interoperable numeric categories, author the published style directly in SLD and use consistently typed data. Label placement defaults also vary among renderers. Do not assume pixel-identical rendering by another server.

## GetLegendGraphic controls

| Parameter | Behavior |
|---|---|
| `LAYER` | Required published layer name. |
| `STYLE` | Published style or request-SLD style name; defaults to the layer's first published style. |
| `FORMAT` | `image/png`. |
| `RULE` | Select one rule by its SLD Name. Native JSON uses `default`, then `rule_1`, `rule_2`, etc. |
| `SCALE` | Positive scale denominator. Minimum is inclusive, maximum exclusive. Without SCALE or BBOX, all rules are shown. |
| `BBOX`, `CRS` | Enable content-based selection using the normal WMS 1.3 axis order. Features are spatially queried, projected and checked against the viewport; polygon holes and marker/stroke padding are considered. |
| `SRCWIDTH`, `SRCHEIGHT` | Map-context dimensions, defaults 256×256. Used to derive map scale from BBOX and CRS. Set these to the map's real dimensions. An explicit SCALE overrides the rule-selection scale. |
| `WIDTH` | Legend output width, 64–1024 pixels; default 260. |
| `HEIGHT` | **Per-row height**, 16–128 pixels; default 40. Total height is this value times the selected rule count. |
| `SLD`, `SLD_BODY` | Same registered-local/inline request styling as GetMap. |

These dimension/context conventions are this server's documented extension profile; vendor conventions are not identical across WMS products. Use SRCWIDTH/SRCHEIGHT for the map and WIDTH/HEIGHT for the legend. Do not pass a client's map dimensions as legend row sizes.

Example, rules present around Olching at the context scale:

```text
wms.php?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetLegendGraphic&LAYER=places&STYLE=places_sld&FORMAT=image/png&CRS=CRS:84&BBOX=11.332,48.222,11.334,48.224&SRCWIDTH=512&SRCHEIGHT=512&WIDTH=300&HEIGHT=40
```

Rules apply with the same first-match (JSON), multi-rule/group (SLD), ElseFilter and scale logic as rendering. Text-only rules show a text sample. If no rule qualifies, the result is one transparent row. Unknown RULE names are errors. Context legends consume a render slot and the normal feature/geometry/time budget; plain legends require no data queries. Image limits still apply.

This is a **feature/symbol context legend**, not pixel analysis of the final composited map. Occlusion by other layers, exact icon alpha masks and final label collision decisions are not evaluated. It may include a class whose objects are hidden behind another layer. Rules with label text are retained conservatively for candidates inside the padded map query, including displaced labels; their final glyph placement is not evaluated. Prepared overview data is selected using the effective legend scale.

## Additional styling

- `stroke_dasharray`: native JSON array of 1–16 lengths, each 1–128 pixels; SLD `stroke-dasharray` supports the same range. Odd-length arrays repeat twice. Supported on lines and polygon outlines.
- SLD `PropertyIsLike`: required distinct single-byte `wildCard`, `singleChar`, `escapeChar`; case-sensitive matching, maximum 256-byte pattern.
- SLD `LabelPlacement/PointPlacement`: AnchorPoint, Displacement and Rotation. Anchor values are 0–1, displacements ±256 px, rotation ±360 degrees. Rotation is converted from SLD clockwise to GD counterclockwise coordinates.
- SLD empty `LinePlacement`: text is straight and aligned with the segment at the line anchor. Curved per-character text, repeated line labels and nonzero perpendicular offsets are not implemented.
- Native JSON `label_anchor: [0.5,0.5]`, `label_offset: [0,0]`, `label_angle: 30` (counterclockwise), `label_follow_line: true`.
- Native JSON `label_priority_field`: numeric attribute used to place higher-priority labels first. `label_min_distance`: 0–2048 px minimum distance between accepted anchors with identical text. Existing collision avoidance remains active.

SLD now permits up to 64 rules per selected UserStyle, accommodating export of native default plus class rules. Each rule still has at most eight symbolizers; XML remains bounded by 128 KiB and 2048 elements. A large multi-layer GetStyles response may therefore need splitting before it is submitted as a request style. The GET query remains limited to 8192 encoded bytes.

## Optional service metadata

A service accepts `keywords` (up to 32 strings) and `contact` with `person`, `organization`, `email`. Layers accept `keywords`, text `attribution`, and `metadata_url` pointing to an HTTP(S) ISO 19115 XML metadata record. These are emitted as the corresponding WMS capability metadata. Layer groups are not yet implemented.

## Verification and remaining scope

Client interoperability tests are in `tests/clients/`:

```sh
cd tests/clients
npm ci
PHP_BINARY=/absolute/path/to/php node check.mjs
```

Node dependencies are **test-only**, not part of PHP deployment. The tests use real OpenLayers and Leaflet URL generation plus OpenLayers capability parsing, then send HTTP requests to PHP. They cover EPSG:3857/4326, maps, feature info and property restrictions. They do not render a browser screenshot. Leaflet must explicitly use `version: '1.3.0'` and `format: 'image/png'`.

Before a production launch, still run QGIS desktop, Apache on the actual host, OGC CITE/TEAM Engine and sustained representative-data load tests. Those tools were unavailable in the recorded environment. Remaining optional improvements include bounded metatiling for tile-edge labels, antialiased rendering, advanced line joins/caps/curved labels, layer groups, broader EPSG validation and more SLD constructs. WMS 1.1.1, POST, raster, WFS, WMTS, time/elevation and a web administration UI remain outside the implemented scope.
