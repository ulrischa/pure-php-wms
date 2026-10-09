# Pure PHP WMS — Changelog

## 1.0.0-rc.1 — 2026-10-09

- WMS 1.3.0 GetCapabilities, PNG GetMap and JSON/text GetFeatureInfo.
- Configuration-based services, reusable styles and explicit attribute allowlists.
- SHP/DBF, streamed GeoJSON and simple-feature GeoPackage imports.
- Atomic SQLite dataset publishing with an RTree index.
- GD rendering with polygon holes, local PNG symbols and FreeType labels.
- Bounded caching, render slots, request validation and error isolation.
- Synthetic format fixtures, OGC schema validation and HTTP smoke tests.

Known scope and deployment validation requirements are documented in README.md and docs/VALIDIERUNG.md.

## OGC-Referenzprüfung – 2026-10-09

- WMS-Parameterpflichten, Erweiterungsparameter und Fehlercodes korrigiert.
- GetFeatureInfo: Trefferzahl pro Layer, ungültige Zahlen mit Standardwert, nächste Treffer zuerst und exakte MIME-Typen.
- Capabilities um Schemahinweis und gemeinsame Layer-Ausdehnung ergänzt.
- Hochgeladene Referenzschemas und Beispiele aufgenommen; 105 Funktions- und 20 HTTP-Prüfungen bestanden.
