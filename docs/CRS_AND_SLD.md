# Coordinate systems and SLD styling

## Source CRS and on-the-fly reprojection

Each entry in `config/sources.json` has its own explicit `crs`. Different layers in one service can originate in different coordinate systems. Import transforms their geometry into longitude/latitude (EPSG:4326) in the prepared SQLite database. `GetMap` transforms the selected geometry into the requested `CRS` while rendering. You do **not** import one database per output CRS. `GetFeatureInfo` interprets its pixel position using the same requested CRS and style. Its **GeoJSON response geometry remains longitude/latitude**, regardless of the map CRS; text responses contain attributes only.

The built-in registry includes EPSG:4326, CRS:84, EPSG:3857 and EPSG:25832/25833. The shipped `config/crs.json` additionally enables **EPSG:3035** (ETRS89 / LAEA Europe). `GetCapabilities` advertises every registered output CRS, alongside layer geographic bounds and available named styles. Layer `BoundingBox` metadata is expressed in EPSG:4326, with its WMS axis order. A separate projected bounding box is not required to request another advertised CRS.

To add a CRS, add an entry to **`config/crs.json`**, preserving existing entries. For example, WGS 84 / UTM zone 32N:

```json
{
  "EPSG:32632": {
    "proj4": "+proj=utm +zone=32 +datum=WGS84 +units=m +no_defs",
    "axis_order": "xy",
    "meters_per_unit": 1,
    "bounds": [100000, 0, 900000, 10000000]
  }
}
```

This is the complete file for **one additional CRS**. Retain the existing EPSG:3035 entry if it is still needed. Built-in definitions cannot be overridden. Up to 64 additional definitions are allowed.

| Field | Meaning |
|---|---|
| `proj4` | Explicit local definition supported by the pinned `proj4php` library. Obtain the correct definition for your dataset's datum and projection. No automatic EPSG downloads, `+init`, grids or axis overrides. |
| `axis_order` | **WMS 1.3 BBOX axis order**: `xy` for easting/northing, `yx` for northing/easting. This does not change source-file coordinate order. |
| `meters_per_unit` | Coordinate-unit conversion used for scale denominators: 1 for metres, 0.3048 for international feet; geographic degrees use approximately 111319.49. |
| `bounds` | Allowed request extent **always in internal x,y order**: `[minx,miny,maxx,maxy]`. Choose a regional domain within the projection's useful area; this is a deployment restriction, not an EPSG area-of-use lookup. |

Then set a source's `crs` to the new code if appropriate, run `php bin/console.php validate`, and import that source. When changing the definition of a **source CRS**, reimport affected sources; an output-only definition change does not require reimport. CRS definitions participate in map cache keys.

Source coordinates are always x,y, including Shapefiles. `.prj` is not automatically interpreted. A wrong source CRS creates wrong geometry even if the output CRS is correct. Validate a known reference point and a real dataset before publishing a new definition. Projection algorithms and datum transformations depend on `proj4php`; registering a code does not guarantee survey-grade accuracy. Grid-based datum shifts, polar processing, antimeridian wrapping and arbitrary global projection extents are outside this server's regional profile. Line segments are transformed at their stored vertices: densify long segments in a GIS if curvature matters at your map scale.

### WMS axis order examples

- `CRS:84`: `BBOX=11.28,48.18,11.39,48.245` (longitude,latitude).
- `EPSG:4326`: `BBOX=48.18,11.28,48.245,11.39` (latitude,longitude).
- `EPSG:3035`: `BBOX=2790000,4420000,2800000,4430000` (northing,easting). This corresponds to internal XY bounds `[4420000,2790000,4430000,2800000]`.

For example:

```text
wms.php?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&MAP=demo&LAYERS=places&STYLES=places_sld&CRS=EPSG:3035&BBOX=2790000,4420000,2800000,4430000&WIDTH=512&HEIGHT=512&FORMAT=image/png
```

Unknown CRS codes return `InvalidCRS`; out-of-domain or reversed BBOX values are rejected. Unsupported codes never cause a network request.

## Publish a local SLD style

The server supports a **bounded vector subset of SLD 1.0.0 and SE symbolizers in SLD 1.1.0**, using PHP's standard DOM extension. This is not full SLD-WMS conformance. Native JSON style files remain supported; those JSON files are an application-specific format, while `.sld` files use OGC XML syntax.

1. Put the XML in `resources/places.sld`. The shipped example shows categories, an `ElseFilter`, point symbols and a separate label pass.
2. Create `config/styles/places_sld.json`:

   ```json
   {
     "sld_file": "places.sld",
     "sld_layer": "places",
     "sld_style": "places_sld"
   }
   ```

   `sld_layer` selects the XML `NamedLayer/Name`; `sld_style` selects its `UserStyle/Name`. If omitted, `sld_style` selects the sole style or the unique `IsDefault` style. This wrapper uses SLD styling only; do not add native JSON symbolizer overrides.
3. Add `"places_sld"` to the published layer's `styles` list in `config/services/demo.json`. The first entry is the default. The demo already includes this alternative style.
4. Run `php bin/console.php validate`. Request `LAYERS=places&STYLES=places_sld` in a complete GetMap request. GetCapabilities lists it and provides a legend URL. No dataset reimport is needed for a style change.

The same style selection works in GetFeatureInfo. Rules that hide a feature exclude it from feature-info results. Queryable layers and the configured `query_fields` allowlist still apply. Hit testing uses feature geometry and symbol tolerance, not the exact opaque pixels of icons or labels.

### Request-supplied styles

- **`SLD_BODY`**: URL-encoded XML in a GET request, with `LAYERS` and `STYLES` still supplied. An empty `STYLES=` selects the sole/default SLD style; otherwise names select SLD user styles. Every requested layer must have a matching `NamedLayer`. GET query strings remain limited to **8192 encoded bytes**, so use a local file for larger documents. XML POST and persistent style uploads are not implemented.
- **`SLD`**: an exact URL registered by the administrator in `config/sld.json`, mapped to a local `.sld` resource. The server resolves this mapping locally and **never downloads the URL**. The shipped example maps `https://example.org/styles/places.sld` to `resources/places.sld`; replace that identifier for your deployment. Unregistered URLs are rejected.
- Supply at most one of `SLD` and `SLD_BODY`. `NamedStyle` inside request XML may reference only an already published style for that layer. Local configured SLD files must contain a `UserStyle`.
- GetLegendGraphic accepts the same SLD parameters plus `LAYER` and optional `STYLE`. It shows one row per rule, including scale-dependent rules; it does not filter the legend by current map scale.

Example with a registered URL:

```sh
curl --get 'https://maps.example.org/wms.php' \
  --data-urlencode 'SERVICE=WMS' --data-urlencode 'VERSION=1.3.0' \
  --data-urlencode 'REQUEST=GetMap' --data-urlencode 'MAP=demo' \
  --data-urlencode 'LAYERS=places' --data-urlencode 'STYLES=' \
  --data-urlencode 'SLD=https://example.org/styles/places.sld' \
  --data-urlencode 'CRS=CRS:84' --data-urlencode 'BBOX=11.28,48.18,11.39,48.245' \
  --data-urlencode 'WIDTH=512' --data-urlencode 'HEIGHT=512' \
  --data-urlencode 'FORMAT=image/png' --output places.png
```

### Supported styling profile

| Element | Supported behavior |
|---|---|
| `NamedLayer`, `UserStyle`, `FeatureTypeStyle`, `Rule` | Up to 32 rules total, 8 symbolizers per rule. Each FeatureTypeStyle is a separate painter's pass over the layer. All matching regular rules apply; ElseFilter rules apply when no regular rule matches at the current scale. Labels are placed after geometry. |
| `MinScaleDenominator`, `MaxScaleDenominator` | Inclusive minimum, exclusive maximum. Scale uses the WMS/SE 0.28 mm reference pixel. |
| `PointSymbolizer` | `Graphic/Mark`: circle, square, triangle, diamond, cross; size 1–128 px. Fill, stroke and graphic opacity. `ExternalGraphic` accepts an existing local PNG filename in `xlink:href`, with `Format=image/png`; no remote URLs. |
| `LineSymbolizer`, `PolygonSymbolizer` | Solid fill/stroke, opacity and stroke widths 0–16 px. Polygon holes remain transparent. Line symbolizers may outline polygons. |
| `TextSymbolizer` | One `ogc:PropertyName` label; local TTF font, font size 8–64 px, fill, halo radius 0–4 px. `DejaVu Sans` maps to the bundled font. Font style/weight must be `normal`; use a specific TTF filename for another face. Automatic collision-aware placement, without custom LabelPlacement. |
| `CssParameter`, `SvgParameter` | Literal `#RRGGBB` colors and numeric values; fill/stroke opacity 0–1. Widths, sizes and halo radii round to pixels. |
| OGC filters | Property/literal comparisons: equal, not equal, less/greater, inclusive variants, PropertyIsNull, And, Or, Not. Comparisons are case-sensitive; numeric operands compare numerically. Logical nesting is limited to 8. |

Unsupported elements raise a WMS exception. This includes UserLayer/remote data, spatial filters, PropertyIsLike, functions/arithmetic expressions, dashed strokes, graphic fills/strokes, custom placement/rotation and vendor options. QGIS-exported styles may contain these constructs: simplify them to this profile instead of assuming every export works. XML DTDs and entities are forbidden, document size is limited to 128 KiB and element count to 2048. Fonts and icons must stay inside `resources/`. The cache key includes compiled styles and font/icon content hashes.

References: [OGC SLD 1.0 schema](https://schemas.opengis.net/sld/1.0.0/StyledLayerDescriptor.xsd), [SLD 1.1 schema](https://schemas.opengis.net/sld/1.1.0/StyledLayerDescriptor.xsd), [SE 1.1 schema](https://schemas.opengis.net/se/1.1.0/Symbolizer.xsd), [proj4php](https://github.com/proj4php/proj4php).
