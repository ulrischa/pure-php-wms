# Performance and production operation

## Scope of this review

The October 2026 follow-up replaces the Shapefile importer’s whole-file geometry retention with bounded record streaming, and addresses concrete request-path and cache problems. It does not add GDAL, MapServer, a database server, a background worker or another Composer dependency.

This remains a regional vector WMS with configurable request budgets. Production suitability depends on the actual datasets, request mix and Apache/PHP hosting configuration. These tests are not an OGC certificate or a shared-hosting capacity guarantee.

## What changed and why

| Finding | Change |
|---|---|
| The importer retained all SHP records; memory grew with the dataset | Stream SHP and DBF in lockstep; decode one bounded record using the existing phpMyAdmin library |
| Large record fields could allocate excessive arrays before publication limits | Check binary record length (1 MiB), points (20,000), parts (1,000), dimensions and part indices before geometry decoding |
| A missing unselected database broke otherwise valid maps and legends | Maps open selected layer datasets only; legends need no database; Capabilities still describes every layer and therefore requires all advertised datasets |
| FeatureInfo scanned the map envelope, failing above the visible-feature budget even for a local click | Query the spatial index around the clicked pixel, conservatively covering the maximum supported symbol radius, then apply exact geometry distance |
| FeatureInfo retained many expanded PHP geometry arrays | Retain encoded results, at most 100 per layer and 8 MiB aggregate feature payload; text output omits retained geometry |
| Mercator endpoint normalization could turn an edge query into a world-wide scan | Keep projection-envelope samples just inside the world endpoints; regression checks cover east and west edges |
| Cache cleanup could race with file existence checks | Open cache files first, then inspect the same open file; missing/corrupt entries are misses |
| Cache-write failure discarded already rendered output | Log write failure and serve the uncached map; concurrency lock storage remains mandatory |

The file-size limit is now **256 MiB for SHP**, matching the existing source-file limit. This is an intentional administrative resource limit, not a format limitation. The per-feature publication limits (20,000 vertices / 1 MiB geometry JSON), per-dataset limits (1,000,000 features / 5,000,000 vertices), trusted-input requirement, explicit source CRS/encoding and one-SQLite-file-per-source architecture remain in place. No database migration or reimport of existing valid datasets is required.

## Reproducible measurements

Environment: Linux, 64-bit PHP 8.3.31, GD/FreeType, PDO_SQLite/RTree, dependencies from `composer.lock`. Timings are single synthetic runs and fluctuate; do not interpret small differences as a guaranteed speedup.

| Workload | Previous implementation | Updated implementation |
|---|---:|---:|
| Import 64 line features, 65,536 vertices, 1,052,260-byte SHP | 0.206 s; 28 MiB reported PHP peak | 0.209 s; 4 MiB reported PHP peak |
| Import 2,048 lines, 2,097,152 vertices, 33,669,220-byte SHP | Rejected by the 16 MiB guard | About 6.0 s; 8 MiB reported PHP peak; 38.3 MiB process RSS |
| Render 3,000 points, 1,000 × 700 pixels, cold cache | 66 ms | 60 ms |
| Same point map, application-cache hit | 4.1 ms | 3.7 ms |

The import improvement is **bounded memory**, not a claimed dramatic CPU speedup. Geometry complexity still costs CPU. PHP's memory counter excludes some native-library allocations, so process RSS is included for the large import. Local import can need more resources with other geometry structures or projections.

Run from the project directory:

```sh
php -d memory_limit=128M tests/run.php
python tests/http_smoke.py php
php -d memory_limit=128M tests/streaming_benchmark.php 64
php -d memory_limit=128M tests/streaming_benchmark.php 2048
php tests/benchmark.php
python tests/concurrency.py php
```

The streaming benchmark generates temporary SHP/DBF files using PHP, imports them, verifies spatial selection and row alignment, then verifies that a malformed subsequent import leaves the published database unchanged. It cleans up its temporary files. No external GIS library or fixture download is needed. Its optional second argument is a baseline checkout path with its own installed dependencies; older versions may fail the new corruption checks or reject the larger file.

The concurrency test requires **Linux/POSIX** and Python 3. It creates an isolated temporary installation, starts PHP's development server with multiple workers, and never deletes the project's normal cache. This is a concurrency regression test, not a production web server recommendation.

## Concurrency results

The test passed **9 assertions**:

- Cached maps remain available while all three render slots are locked.
- An uncached request gets HTTP 503 with `Retry-After: 5` when all render slots are occupied.
- Releasing those slots restores normal rendering.
- Eight clients issued 80 requests while a separate thread repeatedly deleted map-cache files.
- In the recorded run, 51 requests returned byte-identical PNGs and 29 returned the expected `Server busy` response. No unexpected HTTP status, PHP warning or fatal error occurred.
- Recorded median latency was about 50 ms and p95 about 92 ms, including admission-control responses. These are not successful-request throughput claims.
- The service returned a valid map after the workload ended.

The render limit protects the host by rejecting excess work. It does **not** queue requests, prevent all duplicate concurrent renders, or provide a distributed lock across multiple hosts. Tune `max_parallel` to available PHP worker memory/CPU; do not simply maximize it. Clients should honor the retry indication.

## Hosting deployment and monitoring

1. Use the supported PHP extensions from [INSTALLATION.md](../INSTALLATION.md), 64-bit PHP and a maintained PHP release. Enable OPcache through your hosting settings when available; invalidate/restart it after deployment if timestamp validation is disabled.
2. Prefer `public/` as the DocumentRoot. For fixed-webroot FTP deployment, verify that both `.htaccess` files are uploaded and private configuration, database, source and dependency files return 403/404.
3. Keep configuration and published databases read-only for web requests. `cache/` must permit file creation, rename and file locking by the PHP worker. Local filesystem storage is the tested model; network filesystem lock/rename semantics must be verified separately.
4. Begin with the supplied request limits and `memory_limit=256M`. Account for simultaneous PHP workers, GD/native allocations, OS memory and other applications. `max_seconds` is cooperative; configure an appropriate PHP/server execution timeout as well.
5. Watch HTTP 503 frequency, response latency, PHP error logs, memory and disk usage. `X-Request-ID` ties internal failures to logged reference numbers. Cache-write failures are logged even when the client receives a successful map.
6. Keep enough disk space for the current SQLite file, a new import/upload and its temporary journal. The cache limits file count, not a strict total byte quota. Clear old map-cache files with `php bin/console.php clear-cache`; never delete active `worker-*.lock` files.
7. Import trusted sources locally when SSH is unavailable, then publish the completed database by an atomic replacement if supported. Never stream an FTP upload directly over an active `.sqlite` file. Preserve the previous working dataset for rollback.
8. Deploy all changed PHP files consistently, then clear the map cache after code upgrades. The renderer cache-version key was incremented for this change. Data revisions, styles and resource content hashes continue to invalidate cached maps.

## Remaining release checks

Apache, QGIS desktop, an OGC CITE/TEAM Engine run, actual provider-specific filesystem permissions and representative customer datasets have **not** been exercised here. Before calling a particular installation production-ready, validate those items and run a realistic sustained workload on that host. Complex global maps, polar/antimeridian geometries, raster data and topology repair remain out of scope.

## Implementation references

- [ESRI Shapefile Technical Description](https://www.esri.com/content/dam/esrisites/sitecore-archive/Files/Pdfs/library/whitepapers/pdfs/shapefile.pdf): file and record headers, XY/Z/M layouts and optional measure arrays.
- The installed `phpmyadmin/shapefile` 4.0.1 source: public `ShapeRecord::loadFromFile()` and `ShapeFile::readSHP()` APIs used by the bounded input adapter. No private library fields are accessed or modified.
