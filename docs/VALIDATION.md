# Validation, performance and known limitations

**Recorded implementation version: 1.0.0-rc.1.** The project is a release candidate. Its implementation and supplied fixtures have been tested, but it is **not OGC certified** and the exact target Apache hosting environment and real production datasets still need verification.

## Recorded tests

Recorded environment: Linux, **PHP CLI 8.3.31**, GD/FreeType, PDO_SQLite with RTree, dependencies pinned in `composer.lock`. The functional suite also passed at PHP `memory_limit=128M` **for supplied synthetic fixtures**. Depending on build, GD memory may not be fully reflected in PHP's reported memory usage; this is **not** a general 128 MiB requirement guarantee.

| Test | Recorded result |
|---|---|
| `php tests/run.php` | **140 checks passed** |
| `python tests/http_smoke.py php` | **20 HTTP checks passed** |
| `python tests/concurrency.py php` (Linux) | **9 checks passed; 80 requests with 8 clients** |
| PHP syntax checking | Source and tests free of syntax errors in the recorded run |
| `composer validate --strict` | Valid manifest and lock file |
| Composer security advisory check at install/update | No reported known advisories for pinned packages at that time |
| GetCapabilities vs official OGC WMS 1.3.0 XSD | XML schema valid |
| PNG visual check | Polygon fills and holes, underlying layers, lines, category markers, accented labels |
| Regression verification | Style-rule matching and geometry size handling corrections covered by tests |

Functional tests cover axis order for EPSG:4326 versus CRS:84, Web Mercator / UTM reference coordinates, image dimensions and transparency, polygon holes, feature-info property allowlisting and empty-hole hit rejection, Shapefile/DBF including Windows-1252 text, GeoPackage and WKB Z/M inputs, bounded cache/worker slots, failed import safety, oversized geometry rejections, duplicate and malformed query rejection, ETag/304, CORS and MIME headers.

**How to rerun locally:**

```sh
composer install --no-dev --prefer-dist
php bin/console.php doctor
php bin/console.php validate
php bin/console.php import --all
php tests/run.php
python tests/http_smoke.py php
php -d memory_limit=256M tests/benchmark.php
```

The Python HTTP smoke test uses a **PHP development server**, not Apache; Python 3 is only needed for that optional test. `composer test` runs the PHP functional suite.

## Small synthetic benchmark (recorded)

```sh
php -d memory_limit=256M tests/benchmark.php
```

| Measurement | Recorded result |
|---|---:|
| Source | 3000 synthetic points, no labels |
| Image dimensions | 1000 × 700 |
| Import | approximately 73 ms |
| Uncached map | approximately 60 ms |
| Cached map | approximately 4 ms |
| Reported peak PHP memory | 16 MiB |
| PNG output size | 13,605 bytes |

This is only a functional scale check, not an Apache/shared-hosting capacity forecast. It does not benchmark parallel HTTP users, huge polygons, complex labels, projections or real environmental datasets.

## Production acceptance checklist

1. **Apache/filesystem security:** verify the site's real DocumentRoot or both `.htaccess` files; directly fetch likely private paths and require 403/404. Apache itself was not available for the recorded development tests.
2. **Real data:** import representative Shapefile, GeoJSON or GeoPackage data and compare coordinates, polygon holes, linework, labels and exposed fields in QGIS.
3. **Client interoperability:** test the service URL in real QGIS and any production browser WMS library. Verify GetMap, GetFeatureInfo and intended style choices.
4. **Performance:** test typical and maximum expected map extents at hosting memory, wall-time and concurrency limits; simplify large geometry where needed.
5. **Compliance:** if formal standards conformance is required, run the full **OGC CITE/TEAM Engine** procedure and interoperability cases. Passing schema validation alone is not conformance certification.
6. **Operational practice:** test updates while clients are requesting maps, disk cache growth, cache cleanup and recovery from interrupted FTP transfers.

## Explicit implementation boundaries

- WMS 1.3.0 only, HTTP GET, PNG GetMap; no JPEG.
- CRS restricted to documented values; global or arbitrary CRS transformations are outside scope.
- Geographic processing limited to ±180° longitude and ±85.05112878° latitude, no antimeridian crossing; UTM requests use regular supported zone extents.
- No GeoTIFF or other raster layers, GeoPackage raster tiles, curve geometries or SQL views.
- No SLD, remote symbology, arbitrary filters, time/elevation dimensions, WFS or WMTS. Unknown extension parameters are ignored.
- A published feature has at most 20,000 vertices and 1 MiB geometry JSON. No automatic geometry simplification.
- The input dataset must be structurally and topologically appropriate. Import validates structure and safety bounds but cannot repair self-intersections.
- Local imports are trusted administration tasks; stream processing and parser limits do not guarantee resistance to every malicious input.
- Geodesic edge interpolation is not implemented. WGS84/ETRS89 differences are approximated for map display, not survey-grade coordinates.
- Label placement avoids collisions only within each PNG; independently requested WMS tiles may have inconsistent labels.
- `max_seconds` is a cooperative processing budget, not a hard CPU-kill switch. Host PHP process and server-level limits still matter.

## References

- [OGC WMS standard](https://www.ogc.org/standards/wms/)
- [WMS 1.3.0 Capabilities XSD](https://schemas.opengis.net/wms/1.3.0/capabilities_1_3_0.xsd)
- [PHP GD](https://www.php.net/manual/en/book.image.php)
- [phpMyAdmin Shapefile](https://github.com/phpmyadmin/shapefile)
- [Proj4php](https://github.com/proj4php/proj4php)
- [JSON Machine](https://github.com/halaxa/json-machine)

Official reference XSDs are included in `tests/schemas/` for repeatable offline checks (schema import paths were adjusted locally while definitions/license notices were retained). Additional documents and reference material supplied for the review are described in [OGC_REFERENCE_REVIEW.md](OGC_REFERENCE_REVIEW.md).

## Streaming and production-hardening follow-up

See [PRODUCTION.md](PRODUCTION.md) for the 32 MiB SHP import, before/after memory comparison, concurrent cache deletion, render admission/recovery checks and remaining hosting-specific validation. These checks add streaming Z/M/null/deleted-record handling, malformed lengths/types, local FeatureInfo selection from a 6,000-feature map, bounded result payloads, nested property output and Mercator edge envelopes.
