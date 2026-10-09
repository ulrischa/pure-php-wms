# Validierung und Betriebsgrenzen

Stand des Pakets: **1.0.0-rc.1**. Die Bezeichnung Release Candidate ist bewusst gewählt: Der Code und die mitgelieferten Formate wurden geprüft, das konkrete Apache-Webhosting und echte Fachdatensätze noch nicht. Eine OGC-Zertifizierung wird nicht behauptet.

## Ausgeführte Prüfungen

Umgebung: Linux, PHP CLI **8.3.31**, GD mit FreeType, PDO_SQLite/RTree und die in `composer.lock` fixierten Pakete. Die Kernprüfungen bestehen auch mit **128 MiB PHP-memory_limit** für die mitgelieferten Fixtures. GD-Speicher ist je nach Build nicht vollständig in PHP-Speicherstatistiken enthalten; dies ist keine 128-MiB-Zusage für beliebige Daten.

| Prüfung | Ergebnis |
|---|---|
| `php tests/run.php` | 105 Prüfungen bestanden |
| `python tests/http_smoke.py php` | 20 HTTP-Prüfungen bestanden |
| PHP-Syntaxprüfung | Anwendung und Tests ohne Syntaxfehler |
| `composer validate --strict` | Manifest und Lockdatei gültig |
| Composer Advisory-Prüfung beim Installieren/Aktualisieren | Keine bekannten Sicherheitsmeldungen für die fixierten Pakete gemeldet |
| GetCapabilities gegen offizielles OGC-WMS-1.3.0-XSD | Gültig |
| PNG-Sichtprüfung | Flächen, Polygonloch, Linien, klassifizierte Punkte und Umlaute dargestellt |
| Zusätzliche Codeprüfung | Gefundene Regelvergleichs- und Geometriespeicherprobleme behoben und durch Regressionstests abgedeckt |

Die Funktionsprüfungen umfassen unter anderem:

- WMS-Achsenreihenfolge und identische Darstellung mit EPSG:4326/CRS:84;
- Mercator- und UTM-Koordinaten gegen Referenzwerte;
- PNG-Größe, transparente und deckende Hintergründe;
- Polygonlöcher, Inseln und Erhalt darunterliegender Layer;
- Punktabfrage, Attributfreigabe und Abfrage im Polygonloch;
- native Shapefile-/DBF-Fixtures einschließlich Windows-1252-Umlauten;
- GeoPackage sowie ISO-WKB-Z-/M-Dimensionen;
- Cache, belegte Renderplätze, Pfadbegrenzung und Arbeitsbudgets;
- Erhalt des veröffentlichten Datensatzes bei fehlerhaften Importen;
- Zurückweisung übergroßer Einzelgeometrien vor Veröffentlichung und vor öffentlichem JSON-Decoding;
- Ablehnung ungültiger/mehrdeutiger HTTP-Parameter, ETag/304, CORS und MIME-Header.

## Kleiner Leistungstest

Reproduzierbar mit:

```sh
php -d memory_limit=256M tests/benchmark.php
```

Gemessener Einzelaufruf in dieser Umgebung, synthetische Punkte ohne Labels:

| Merkmal | Messwert |
|---|---:|
| Features | 3000 Punkte |
| Bild | 1000×700 Pixel |
| Import | ca. 73 ms |
| Karte ohne Cache | ca. 60 ms |
| Karte mit Cache | ca. 4 ms |
| Von PHP gemeldeter Spitzenspeicher | 16 MiB |
| PNG | 13.605 Bytes |

Keine Lastmessung mit parallelen HTTP-Anfragen, keine Aussage über große Polygone, Texte, Reprojektionen oder ein bestimmtes Shared Hosting. Die Zahlen sind eine Funktions-/Größenkontrolle, keine Kapazitätsplanung.

## Vor einer Produktivfreigabe auf dem Zielsystem

1. Tatsächliche Apache-Konfiguration und Schutz der privaten Verzeichnisse anhand `INSTALLATION.md` prüfen. Apache war in der Entwicklungsumgebung nicht verfügbar; HTTP wurde mit dem PHP-Testserver geprüft.
2. Repräsentative eigene Daten importieren und fachlich mit QGIS vergleichen: Positionen, Polygonlöcher, Labels, gewählte Projektion und freigegebene Attribute.
3. QGIS-Verbindung, GetFeatureInfo und die tatsächlich eingesetzte Webkartenbibliothek gegen die Zieladresse testen. Ein QGIS-Desktop stand in dieser Umgebung nicht zur Verfügung.
4. Übliche Kartenausschnitte und den größten vorgesehenen Ausschnitt unter den echten Hosting-Limits prüfen. Zu große Daten vorab vereinfachen oder aufteilen.
5. Bei einer Anforderung formaler OGC-Konformität einen vollständigen CITE-/TEAM-Engine-Test und die erforderlichen Interoperabilitätstests durchführen. XSD-Validität allein ersetzt diese nicht.

## Explizit eingeschränkter Umfang

- Ausschließlich WMS 1.3.0, HTTP GET und PNG-Karten; keine JPEG-Ausgabe.
- Quell- und Ziel-CRS nur aus der dokumentierten Liste.
- Geografischer Datenbereich ±180° Länge und ±85,05112878° Breite, keine antimeridianüberschreitenden Geometrien. UTM-Anfragen auf den üblichen Zonenbereich begrenzt.
- Keine Rasterdienste, GeoTIFFs, GeoPackage-Rasterkacheln, Kurven, SQL-Ansichten oder beliebige DBF-Erweiterungen.
- SLD-/Filter-/Remote-URL-/Zeit-/Höhenparameter werden ignoriert; keine Unterstützung dieser Erweiterungen.
- Veröffentlichte Einzelgeometrien maximal 20.000 Stützpunkte und 1 MiB Geometrie-JSON; keine automatische Vereinfachung.
- Geometrien müssen fachlich/topologisch gültig sein; der Import prüft Struktur und Grenzen, aber repariert keine Selbstüberschneidungen.
- Importe sind eine lokale, vertrauenswürdige Administrationsaufgabe. Parserlimits und Streamverarbeitung bedeuten keinen vollständigen Schutz vor jedem pathologischen oder manipulierten Eingangsdokument.
- Geometriekanten werden nicht automatisch geodätisch verdichtet; WGS84/ETRS89-Transformationen ohne epochengenaue Vermessungskorrektur.
- Beschriftungskollisionen werden innerhalb einer einzelnen Karte vermieden; keine globale kachelübergreifende Labelplanung.

## Standards und Bibliotheksquellen

- [OGC Web Map Service](https://www.ogc.org/standards/wms/)
- [OGC WMS 1.3.0 Capabilities-XSD](https://schemas.opengis.net/wms/1.3.0/capabilities_1_3_0.xsd)
- [phpMyAdmin Shapefile](https://github.com/phpmyadmin/shapefile)
- [Proj4php](https://github.com/proj4php/proj4php)
- [JSON Machine](https://github.com/halaxa/json-machine)
- [PHP GD](https://www.php.net/manual/en/book.image.php)

Die offiziellen XSDs liegen für wiederholbare Offline-Prüfungen unter `tests/schemas/`. Die Schema-Importpfade sind lokal umgeschrieben; Definitionen und Lizenzhinweise bleiben erhalten.

Die Prüfung der hochgeladenen OGC-Referenzen und offene Empfehlungen sind in [OGC_REFERENZPRUEFUNG.md](OGC_REFERENZPRUEFUNG.md) dokumentiert.
