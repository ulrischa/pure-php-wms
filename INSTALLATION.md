# Installation, administration and deployment guide

This guide assumes the project is **Pure PHP WMS** and its supported protocol is **WMS 1.3.0**. Start with the [README](README.md) for example map requests, terminology and symbology. For all JSON fields see [CONFIGURATION.md](docs/CONFIGURATION.md).

## 1. Choose a deployment model

**Option A — recommended: `public/` is the web DocumentRoot.**

Place the application at a private filesystem path such as `/home/account/pure-php-wms/`, and configure the Apache vhost / subdomain DocumentRoot as `/home/account/pure-php-wms/public/`. The entry URL becomes `https://maps.example.org/wms.php`. The sibling `config/`, `data/`, `sources/`, `resources/`, `vendor/` and `cache/` directories are outside the public document root.

**Option B — shared hosting with FTP and a fixed webroot.**

Upload the *entire* distribution to `public_html/wms/` and use `https://example.org/wms/public/wms.php`. Include both the root `.htaccess` and `public/.htaccess`. The root file denies access to the project tree; `public/.htaccess` explicitly permits the public endpoint. Verify that Apache actually honors these directives. If it does not, **do not** use this layout; switch to Option A.

Neither option needs a persistent PHP worker, Node.js, GDAL or MapServer.

## 2. Verify PHP and acquire the files

You need Apache 2.4, **64-bit PHP 8.2+**, and the extensions `gd` **with FreeType**, `pdo_sqlite` **with RTree**, `dom`, `xmlwriter`, `mbstring` and `iconv`. A reasonable starting point is `memory_limit=256M` and `max_execution_time >= 20` seconds for HTTP maps. Adjust based on actual hosting tests.

The **distribution ZIP**, when provided, includes `vendor/` and prepared synthetic demo datasets. It can be uploaded with FTP and run without Composer on the host. A raw **Git checkout does not necessarily include `vendor/`**; prepare dependencies on your own computer with:

```sh
composer install --no-dev --prefer-dist
```

For PHP CLI on Windows you can run the full path to `php.exe` if it is not on PATH. Run in the project root:

```sh
php bin/console.php doctor
php bin/console.php validate
php bin/console.php list
```

The `doctor` command checks prerequisites and displays whether `cache/` and `data/` are writable. `validate` checks the configured service/style JSON and locally available source paths. Do not use it as a substitute for real HTTP/Apache tests.

## 3. Set the public URL

Edit `config/server.json`:

```json
{
  "base_url": "https://example.org/wms/public/wms.php",
  "default_service": "demo",
  "max_side": 2048,
  "max_pixels": 2097152,
  "max_features": 5000,
  "max_vertices": 250000,
  "max_seconds": 10,
  "max_parallel": 3,
  "cache_slots": 64,
  "cache_ttl": 3600,
  "cors_origins": ["*"]
}
```

Replace `base_url` with the **actual external URL of `wms.php` without `?` parameters**. It is published in GetCapabilities; it is not inferred from a client-supplied Host header. For private services, CORS configuration alone is not sufficient: add Apache-level access control.

## 4. Deploy and verify security

Upload the application tree. With Option B, check that the FTP program did not hide dotfiles.

Normal HTTP requests must be able to **read** code, configuration and `data/*.sqlite`; only `cache/` needs to be **writable** by the PHP user. `data/` only needs write access where CLI imports are performed. Avoid `chmod 777` on shared hosting. Directory owner/group strategy depends on the provider.

Verify these URLs in a browser or HTTP client:

```text
https://example.org/wms/public/wms.php?MAP=demo&SERVICE=WMS&VERSION=1.3.0&REQUEST=GetCapabilities
https://example.org/wms/public/wms.php?MAP=demo&SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&LAYERS=land,routes,places&STYLES=&CRS=CRS:84&BBOX=11.28,48.18,11.39,48.245&WIDTH=1000&HEIGHT=700&FORMAT=image/png&TRANSPARENT=TRUE
```

Capabilities should be XML; GetMap should be a PNG. With Option B, `https://example.org/wms/config/server.json` and `https://example.org/wms/data/places.sqlite` **must return 403 or 404**. Never accept a 200 response that exposes either file. If `Options -Indexes` in `public/.htaccess` causes HTTP 500 because the host prohibits the directive, it may be removed **only if directory listing is already disabled by Apache**. Do not remove access restrictions to work around a hosting error.

## 5. Connect a WMS client

In QGIS choose **Data Source Manager → WMS/WMTS → New**. Paste this connection URL:

```text
https://example.org/wms/public/wms.php?MAP=demo
```

Click Connect; select the advertised vector-based WMS layers; use PNG and one of the supported CRSs. QGIS consumes *rendered images*, not editable original geometry. The Identify tool can request feature information for layers marked `queryable`. If a connection fails, first load the GetCapabilities URL explicitly and check the returned XML or error.

For WMS 1.3.0, **EPSG:4326 BBOX order is latitude,longitude** whereas **CRS:84 BBOX order is longitude,latitude**. Original GeoJSON coordinates are still always read as x,y (longitude,latitude).

## 6. Import your own sources on a computer with PHP CLI

**Shapefile:** place same-basename files under `sources/`:

```text
sources/protected_areas.shp
sources/protected_areas.shx
sources/protected_areas.dbf
sources/protected_areas.prj
sources/protected_areas.cpg
```

The geometry/attributes come from SHP/DBF. Include the other companion files for completeness, but explicitly configure the CRS and DBF encoding: the importer does **not** parse `.prj`/`.cpg` automatically. Convert unsupported DBF field types (memo/binary) in a GIS. SHP and DBF records are streamed in lockstep. The SHP source-file limit is **256 MiB**; each record is bounded before decoding. Features above 20,000 vertices must still be simplified or split.

**GeoJSON:** create an EPSG:4326 GeoJSON **FeatureCollection** in `sources/`. The importer streams features but individual large geometries are still bounded. For source config use `"file": "areas.geojson", "crs": "EPSG:4326"`.

**GeoPackage:** select a supported **simple-feature vector table** by its exact `table` name. Its configured EPSG value must agree with GPKG metadata. Raster tiles, curved geometry and arbitrary SQL views are not supported.

Edit the **top-level object** in `config/sources.json`; do not overwrite unrelated existing entries:

```json
{
  "protected_areas": {
    "file": "protected_areas.shp",
    "crs": "EPSG:25832",
    "encoding": "Windows-1252"
  },
  "monitoring_points": {
    "file": "stations.geojson",
    "crs": "EPSG:4326"
  },
  "administrative_areas": {
    "file": "areas.gpkg",
    "table": "areas",
    "crs": "EPSG:25832"
  }
}
```

The example represents **three sources**; merge it with the bundled demo entries if those should remain available. Names must begin with lowercase a–z, followed only by lowercase letters, digits, `_` or `-`, maximum 64 characters.

From the project root on the import machine:

```sh
php bin/console.php validate
php bin/console.php import protected_areas
php bin/console.php import monitoring_points
php bin/console.php import administrative_areas
php bin/console.php list
```

Alternatively `php bin/console.php import --all` imports every configured source. For heavy local imports only, you may temporarily increase CLI memory using `php -d memory_limit=1G bin/console.php import protected_areas`; the 256 MiB source-file limit and per-feature complexity limits still apply. Increasing memory is not a substitute for simplifying an oversized individual feature. The result for each source is a **different** file `data/NAME.sqlite` with geometries, attributes and RTree, normalized to EPSG:4326. Source files are unchanged. Failed imports do not replace the previously published dataset.

## 7. Create reusable symbols and styles

Create `config/styles/protected_area.json`:

```json
{
  "title": "Protected areas",
  "legend_geometry": "polygon",
  "fill": "#75b76b70",
  "stroke": "#2b6933",
  "stroke_width": 2,
  "label_field": "NAME",
  "font_size": 12,
  "label_color": "#173d20",
  "halo": 2
}
```

`NAME` must be the actual case-sensitive attribute name; for no labels remove `label_field`.

For point markers, set `"legend_geometry": "point"`, `"symbol": "triangle"`, `"size": 18`, `"fill": "#d34836"`. Supported shapes: `circle`, `square`, `triangle`, `diamond`, `cross`. An icon can replace the shape: put `warning.png` in `resources/` and set `"icon": "warning.png"`. It must be PNG, no larger than 512 × 512 pixels and 1 MiB. The `size` field controls its rendered width and height. For another TTF font place it in `resources/` and set `"font": "MyFont.ttf"`. The bundled `DejaVuSans.ttf` is the default.

Use `rules` for a simple legend with multiple attribute categories; the first matching `field` / `equals` rule replaces only named base properties:

```json
{
  "title": "Monitoring stations",
  "symbol": "circle",
  "size": 12,
  "fill": "#367ab8",
  "rules": [
    {
      "field": "STATUS",
      "equals": "critical",
      "symbolizer": {"symbol": "triangle", "fill": "#d34836", "size": 18}
    }
  ]
}
```

Rules are literal scalar comparisons, **not** SQL or an expression language. See the full [symbology reference](docs/CONFIGURATION.md) for every supported field, transparency, scale denominators, labels and limitations.

## 8. Create a layer inside a new service

Create `config/services/environment.json`:

```json
{
  "title": "Environmental maps",
  "abstract": "Published local datasets",
  "layers": {
    "protected_areas": {
      "title": "Protected areas",
      "dataset": "protected_areas",
      "styles": ["protected_area"],
      "queryable": true,
      "query_fields": ["NAME", "TYPE"]
    },
    "stations": {
      "title": "Monitoring stations",
      "dataset": "monitoring_points",
      "styles": ["monitoring_stations"],
      "queryable": true,
      "query_fields": ["NAME", "STATUS"]
    }
  }
}
```

This two-layer example assumes you **also created** `config/styles/monitoring_stations.json` from the rule example above. The strings `protected_areas` and `stations` are public **layer IDs**, while `protected_areas` and `monitoring_points` in `dataset` refer to **source IDs** in `sources.json`. Each layer's `styles` list contains existing style **file basenames**. Use `"styles": ["protected_area", "protected_area_contrast"]` to advertise alternatives; the first becomes default. For another service, create another file in `config/services/` and select it with `MAP=other_service`.

The `query_fields` list must be present, even for nonqueryable layers, and controls which **properties** are returned by `GetFeatureInfo`; geometry is returned too. For a nonqueryable layer, use `"queryable": false, "query_fields": []`. Never publish confidential attributes, labels or geometries inadvertently.

Validate locally:

```sh
php bin/console.php validate
```

Then deploy `config/sources.json`, the service and style JSON files, new fonts/icons (if any), and all required `data/*.sqlite`. **No source upload is needed** if local import is complete. New WMS URL:

```text
https://example.org/wms/public/wms.php?MAP=environment
```

## 9. Safe updates, caching and operations

For a data refresh, update the local source, re-run `import NAME`, then **do not overwrite** a live SQLite file byte by byte over FTP. Upload it as `NAME.sqlite.upload` and atomically rename it after completion **only if the server supports atomic replacement**. Otherwise publish under a new dataset ID, then switch the referencing service configuration. Apply the same care when uploading JSON files in production.

Each published dataset has a revision used in map cache keys. Style and icon/font content changes are also reflected in those keys. **After changing application code**, run `php bin/console.php clear-cache` on the server or carefully remove only old `cache/map-*.bin` files while preserving active lock files. If cache space is tight, lower `cache_slots`, `cache_ttl` or `max_pixels`.

CLI commands:

| Command | Function |
|---|---|
| `doctor` | Check PHP extensions, FreeType, SQLite RTree, directory permissions |
| `validate` | Check configured service/style/source relationships |
| `import NAME` | Import a named source |
| `import --all` | Import all sources |
| `list` | Show dataset revision, extent and feature counts |
| `clear-cache` | Remove map cache files |

All commands use `php bin/console.php COMMAND` in the project root. There is intentionally **no HTTP upload endpoint**, no browser-based administrative interface and no CLI daemon.

## 10. Common problems

- **WMS exception / HTTP 400:** confirm parameter syntax, `MAP`, layer names, style IDs, BBOX/CRS order, dimensions, format and version 1.3.0.
- **HTTP 503 or missing dataset:** check `data/NAME.sqlite`, file permissions and the PHP error log (use the response reference ID).
- **HTTP 503 "Rendering budget exceeded":** zoom in; simplify or split complex geometries; reduce image resolution or active layers.
- **HTTP 503 "Server busy":** maximum concurrent render slots are occupied; inspect workload and hosting process limits.
- **Empty image:** confirm spatial extent, layer drawing order, EPSG:4326 axis order and style `min_scale`/`max_scale`.
- **Mojibake/incorrect accented text:** correct Shapefile DBF `encoding`, then import again.
- **HTTP 500 on Apache:** inspect Apache/PHP logs, verify .htaccess permissions; some hosts prohibit `Options -Indexes`.
- **Config or SQLite downloadable by URL:** halt deployment and fix access restrictions immediately.

See [VALIDATION.md](docs/VALIDATION.md) for scope, budgets, tests and remaining production checks.

For concurrency, monitoring, upgrade steps and measured resource use, see [Production operation](docs/PRODUCTION.md).

## Additional coordinate systems and SLD

See [CRS and SLD](docs/CRS_AND_SLD.md) for adding source/output CRS definitions, WMS axis order and publishing standard XML styles. DOM is required; no additional native GIS extension is needed.
