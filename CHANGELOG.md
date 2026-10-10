# Pure PHP WMS — Changelog

## 1.0.0-rc.1 — 2026-10-09

- WMS 1.3.0 GetCapabilities, PNG GetMap and JSON/plain-text GetFeatureInfo.
- Configuration-based services, reusable styles and explicit attribute allowlists.
- ESRI Shapefile/DBF, streamed GeoJSON and simple-feature GeoPackage imports.
- Atomic SQLite dataset publishing with RTree spatial indexing.
- GD rendering with polygon holes, local PNG icons and FreeType text labels.
- Bounded caching, rendering slots, request validation and isolated error responses.
- Synthetic format fixtures, OGC schema validation and HTTP smoke tests.

### OGC reference review — 2026-10-09

- Corrected WMS parameter requirements, handling of extension parameters and exception codes.
- Corrected GetFeatureInfo per-layer feature counts, invalid-count defaulting, nearest-hit ordering and exact MIME types.
- Added schema hints and combined root-layer geographic bounds to GetCapabilities.
- Included reference XML/XSD assets and recorded 105 functional and 20 HTTP checks.

### English documentation and examples — 2026-10-09

- Expanded README with step-by-step installation, data import, QGIS usage, creation of services and layers, symbology, custom icons, attribute rules, legends and safe deployment.
- Translated installation, configuration, validation and OGC-reference guides to English; renamed the guides to English filenames.
- Updated example service/style metadata to English without changing their published identifiers or datasets.

Recorded validation scope and remaining production checks are documented in [VALIDATION.md](docs/VALIDATION.md).

## Performance and production hardening — 2026-10-10

- Stream SHP records using the existing phpMyAdmin geometry decoder instead of retaining an entire Shapefile; remove the 16 MiB SHP cap in favor of the existing 256 MiB source limit.
- Validate record boundaries, type consistency, point/part counts and optional Z/M storage before decoding. Preserve null/deleted-row alignment and atomic publication.
- Open only selected map datasets; render legends independently of dataset availability.
- Narrow FeatureInfo spatial selection to the clicked region and bound retained results to 8 MiB.
- Keep Mercator edge queries local; preserve deeply nested attributes in text responses.
- Tolerate concurrent cache cleanup and serve rendered maps even if an optional cache write fails.
- Add large-import and concurrent HTTP regression tools and document measured production limits.
