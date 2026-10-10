# Review against supplied OGC references

**Review date: 9 October 2026.** Reviewed against **OGC 06-042 (WMS 1.3.0)**, the supplied capabilities/exception XML and XSD files and `ExtendedSecurityCapabilities.xsd`.

Unmodified supplied XML/XSD reference copies are in `tests/references/`; `manifest.json` records SHA-256 checksums of all supplied reference assets, including the PDF. **The original PDF is not bundled.** Only the working XSD copies under `tests/schemas/` use local paths to imported XLink/XML schemas for offline validation.

This is an implementation-focused review with selected regression tests, **not** the complete OGC Abstract Test Suite/CITE procedure or a certification.

## Corrected discrepancies and evidence

| Standard section / topic | Issue before correction | Implemented correction or test |
|---|---|---|
| Tables 3, 8, 9; §7.2.3.3 | SERVICE was required for every operation | SERVICE required for GetCapabilities only; map and feature-info requests without SERVICE tested |
| §6.8.1, §6.9.5, Annex C.3.5 | Unknown extensions and TIME/ELEVATION were rejected | Ignored, never interpreted or remotely fetched; parameter names may include valid punctuation |
| §7.2.4.1 | Missing schemaLocation on capabilities root | Added namespace/schema hint while retaining XSD validity |
| §7.2.4.6.8 | Grouping root layer had no bounding box | Added union geographical extent of published layers |
| §7.3.3.6 | Minimum BBOX size of 1e-9 rejected positive extents | Accept any strictly positive width and height; regression for tiny positive extent |
| §7.4.3.6 | FEATURE_COUNT was global and invalid numbers were rejected | Per-layer count; invalid/nonpositive value defaults to 1; local limit of 100 per layer |
| §7.4.3.7 | Generic I/J error response | InvalidPoint for invalid, missing or out-of-bounds pixel coordinates |
| §7.4.4 | Returned hits followed database insertion order | Sorted by proximity to selected pixel; tests cover nearby points, lines and polygon holes |
| §6.10, §7.4.3.5 | INFO_FORMAT response included charset suffix | Returns exact MIME type, including actual HTTP response header; content is UTF-8 |
| §6.5, §6.8.1 | Some integer spellings were needlessly excluded; CRS case was normalized | Signed/zero-prefixed decimal forms accepted where relevant; requested CRS string is validated as required |
| Annex E | Error XML was only checked for existence | Exception responses validated with supplied exception XSD; supplied XML samples validated against corresponding schemas |

At that point **105 PHP functional checks** passed (also with a 128 MiB limit for the fixtures) and **20 HTTP smoke checks** passed using PHP's development server. These test counts are historical results, **not** a guarantee for larger datasets or production Apache.

## Supported and optional protocol choices

- Advertising only WMS **1.3.0** is permitted. GetCapabilities negotiates another syntactically valid requested version to the sole supported version (§6.2.4).
- PNG may be the only GetMap image format. Additional JPEG and raster formats are optional.
- Without a server update sequence, GetCapabilities can return its current metadata when UPDATESEQUENCE is present (Table 4).
- EPSG:4326 uses latitude/longitude **in WMS 1.3.0 BBOX**, while CRS:84 uses longitude/latitude; the regression suite checks the equivalent maps.
- The supplied ExtendedSecurityCapabilities schema concerns an optional later OGC security extension. It does **not** mandate login, roles or security metadata for a basic public WMS.
- GetLegendGraphic is an intentionally limited extension. The bounded SLD vector profile is documented separately; full SLD-WMS conformance, time/elevation dimensions and in-image exceptions are **not** advertised. A server is required to support the formats/capabilities it actually advertises.

## Remaining recommendations and release gates

1. **Out-of-range geographic BBOX (§7.3.3.6):** a BBOX outside the renderer's numerically supported projection area still raises an XML exception instead of returning a blank map. The standard uses **should** for this behavior. Robust clipping/global handling would require additional projection testing. An extent that is within supported coordinates but outside the data's footprint already produces an empty image.
2. **Metadata enrichment:** optional contact details, keywords and extra projected bounding boxes could improve interoperability with catalogs. Geographic extent and offered CRS values are available.
3. **Formal conformance:** run the full OGC CITE/TEAM Engine suite against a deployed service; individual schema and regression tests cannot substitute for it.
4. **Real hosting checks:** Apache private-path protection, QGIS interoperability, real vector data and concurrency/hosting load all require verification on the target environment.

Full GeoTIFF support, global/antimeridian and polar handling, and arbitrary projection systems are deliberately outside this release's scope and are not required merely because these reference schemas were supplied.
