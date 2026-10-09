# Konfigurationsreferenz

Alle Konfigurationsdateien sind UTF-8-JSON ohne Kommentare. Namen für Dienste, Datensätze und Stile: Kleinbuchstabe am Anfang, danach Kleinbuchstaben, Ziffern, Unterstrich oder Bindestrich; maximal 64 Zeichen. Groß-/Kleinschreibung ist bei Layernamen und Attributen relevant.

## Dateien und Zuständigkeiten

| Datei/Ordner | Inhalt |
|---|---|
| `config/server.json` | Veröffentlichte URL, Standarddienst und Betriebsgrenzen |
| `config/sources.json` | Namen der Datenquellen, Quelldateien, CRS und Importoptionen |
| `config/services/NAME.json` | Ein WMS-Dienst mit seinen sichtbaren Layern |
| `config/styles/NAME.json` | Ein wiederverwendbarer Stil |
| `sources/` | Lokale Originaldateien für den Import |
| `data/NAME.sqlite` | Vorbereiteter Datensatz; nicht manuell bearbeiten |
| `resources/` | Lokale TTF-Schriften und PNG-Symbole |
| `cache/` | Begrenzter Kartencache und feste Render-Sperrdateien |

## Server

| Schlüssel | Bedeutung / Standard |
|---|---|
| `base_url` | Vollständige öffentliche URL zu `wms.php`, ohne Querystring |
| `cors_origins` | Liste erlaubter Browser-Ursprünge; `["*"]` für öffentliche Dienste, `[]` für keinen Cross-Origin-Zugriff |
| `default_service` | Dienst bei fehlendem `MAP`, standardmäßig `demo` |
| `max_side` | Höchstens 2048 Pixel je Bildkante; erlaubte Einstellung 64–4096 |
| `max_pixels` | Höchstens 2.097.152 Pixel je Anfrage; erlaubte Einstellung bis 4.194.304 |
| `max_features` | Höchstens 5000 verarbeitete sichtbare Features je Kartenanfrage |
| `max_vertices` | Höchstens 250.000 transformierte Stützpunkte je Anfrage |
| `max_seconds` | Kooperatives Renderzeitbudget von 10 Sekunden |
| `max_parallel` | Höchstens 3 gleichzeitig zugelassene Renderjobs je Installation |
| `cache_slots` | 64 feste Cacheplätze; `0` deaktiviert den Kartencache |
| `cache_ttl` | Ein Cacheeintrag ist höchstens 3600 Sekunden gültig; `0` deaktiviert ihn |

Die Grenzwerte sind keine Leistungszusage. Zusätzlich existiert ein festes Arbeitsbudget für Polygon-Scanlines. Das Zeitbudget wird während der Verarbeitung geprüft, ersetzt aber kein externes hartes Prozesslimit. Ein einzelner Bibliotheks- oder GD-Aufruf kann erst danach abgebrochen werden. In Apache/PHP ein angemessenes `max_execution_time` verwenden.

Die Cacheplatzanzahl begrenzt die **Dateianzahl**, nicht eine frei konfigurierbare Bytequote. Bei 2.097.152 Pixeln und RGBA können 64 ungünstig komprimierbare Bilder in der Größenordnung von 512 MiB liegen. Kleinere `cache_slots` oder `max_pixels` wählen, wenn der Webspace knapp ist. Temporäre Dateien nach einem Prozessabbruch können vereinzelt liegen bleiben; Dateien `render-*` aus alten, sicher beendeten Prozessen dürfen gelöscht werden. Aktive `worker-*.lock`-Dateien nicht austauschen oder löschen.

## Datenquellen

| Schlüssel | Bedeutung |
|---|---|
| `file` | Relativer Dateiname innerhalb `sources/`; keine URL |
| `crs` | Expliziter Quell-CRS: `EPSG:4326`, `CRS:84`, `EPSG:3857`, `EPSG:25832`, `EPSG:25833` |
| `encoding` | Nur DBF: beispielsweise `UTF-8`, `Windows-1252`, `CP850`; Standard UTF-8 |
| `table` | Nur GeoPackage: exakter Name der einfachen Vektortabelle |

Alle Geometriekoordinaten in den Quelldateien werden als **x,y** gelesen, bei geografischen CRS also Länge,Breite. Die vertauschte EPSG:4326-Achsenreihenfolge betrifft ausschließlich WMS-BBOX-Anfragen und Capabilities, nicht die Quelldaten.

Veröffentlichungsgrenzen je Feature: **20.000 Stützpunkte und 1 MiB Geometrie-JSON**. Größere Einzelobjekte im GIS vereinfachen oder aufteilen. Die folgenden Parsergrenzen sind zusätzliche Sicherungen und keine Zusage, dass entsprechende Einzelgeometrien veröffentlicht werden können.

Importgrenzen: 256 MiB Quelldatei, bei SHP zusätzlich 16 MiB und Speicherprüfung; 1.000.000 Features, 5.000.000 Stützpunkte insgesamt, höchstens 1.000.000 Stützpunkte je GeoJSON-/WKB-Geometrie, höchstens 64 KiB Attribute je Feature. SHP-Geometrien besitzen strengere Grenzen: 250.000 Punkte und 1000 Teile je Record. Große oder ungültige Geometrien im GIS aufbereiten. Die Grenzen verhindern nicht jede Speicherüberlastung durch pathologische Eingaben; Importe sind ausschließlich eine vertrauenswürdige Administrationsfunktion.

## Dienst

| Schlüssel | Bedeutung |
|---|---|
| `title` | Öffentlicher Diensttitel |
| `abstract` | Optionale Beschreibung |
| `fees` | Optionaler Gebührenhinweis; Standard `none` |
| `access_constraints` | Optionaler Zugriffshinweis; Standard `none`, keine technische Zugriffskontrolle |
| `layers` | Objekt mit Layernamen als Schlüsseln |

Ein Layer:

| Schlüssel | Bedeutung |
|---|---|
| `title` | Öffentlicher Layertitel |
| `abstract` | Optionale Beschreibung |
| `dataset` | Name aus `sources.json`, entspricht `data/NAME.sqlite` |
| `styles` | Nichtleere Liste erlaubter Stile; erster Eintrag ist Standard |
| `queryable` | `true` oder `false`; Standard `true` |
| `query_fields` | Explizite Liste der durch GetFeatureInfo sichtbaren Attributfelder |

Der Client bestimmt über `LAYERS` die Zeichenreihenfolge. Die Reihenfolge in der Dienstkonfiguration bestimmt die Darstellung der Layerliste in den Capabilities.

## Stil

| Schlüssel | Bedeutung / Standard |
|---|---|
| `title` | Anzeigename in Capabilities und Legende |
| `legend_geometry` | `point`, `line` oder `polygon`; Standard `point` |
| `fill` | Füllfarbe `#RRGGBB` oder `#RRGGBBAA` |
| `stroke` | Linien-/Randfarbe |
| `stroke_width` | Ganze Pixel, 0–16; Standard 1 |
| `symbol` | `circle`, `square`, `triangle`, `diamond`, `cross` |
| `size` | Punkt-/Symbolgröße 1–128 Pixel; Standard 12 |
| `icon` | Optionaler PNG-Dateiname aus `resources/`; ersetzt das geometrische Punktsymbol |
| `label_field` | Attributname; weglassen, um keine Labels zu zeichnen |
| `font` | TTF-Dateiname aus `resources/`; Standard `DejaVuSans.ttf` |
| `font_size` | FreeType-Schriftgröße 6–48 Punkt; Standard 11 |
| `label_color` | Textfarbe |
| `halo_color` | Farbe der Textumrandung; Standard Weiß |
| `halo` | Randbreite 0–4 Pixel; Standard 1 |
| `min_scale` | Sichtbar ab diesem Maßstabsnenner, inklusive; Standard 0 |
| `max_scale` | Unsichtbar ab diesem Maßstabsnenner; Standard unbegrenzt |
| `rules` | Geordnete Liste einfacher Attributregeln, maximal 32 |

Der Maßstabsnenner wird aus der horizontalen Kartenauflösung und dem WMS-Standardpixel von 0,28 mm bestimmt. Bei geografischen CRS wird der standardisierte Meter-pro-Grad-Faktor verwendet. Dies ist keine lokale Geländemaßstabsberechnung.

Farbalpha: `00` vollständig transparent, `ff` vollständig deckend. Beispiel: `#22994480` ist halbtransparentes Grün.

Eigene PNG-Symbole: maximal 512×512 Pixel und 1 MiB, vorzugsweise quadratisch mit transparentem Hintergrund. Die Ausgabe skaliert auf `size × size`. PNG und Schriftdateien werden nur lokal gelesen. Keine SVG-/URL-Symbole.

Labels werden nach allen Geometrien gezeichnet, horizontal gesetzt und an bis zu drei Positionen versucht. Maximal 1500 Labelkandidaten pro Anfrage, höchstens 80 Unicode-Zeichen je Text. Kollisionsprüfung gilt innerhalb eines Bildes. Über mehrere WMS-Kacheln hinweg gibt es keine gemeinsame Platzierungsplanung. Schriftarten mit komplexem Shaping sind nicht eigens unterstützt. Wenn eine Beschriftung am Bildrand oder wegen Kollision nicht passt, wird sie weggelassen.

### Klassen und Maßstabsgrenzen

```json
{
  "title": "Messstellen",
  "symbol": "circle",
  "size": 12,
  "fill": "#367ab8",
  "stroke": "#173d60",
  "label_field": "NAME",
  "max_scale": 250000,
  "rules": [
    {
      "field": "STATUS",
      "equals": "kritisch",
      "symbolizer": {
        "fill": "#d34836",
        "size": 18,
        "icon": "warnung.png"
      }
    }
  ]
}
```

Die erste passende Regel gewinnt; ihre Werte überschreiben die Basiswerte. Nur skalare Attributwerte werden verglichen; Arrays und Objekte passen auf keine Regel. Verglichen wird die Zeichenkettendarstellung des Attributs mit `equals`; keine frei ausführbaren Ausdrücke oder SQL-Filter. Maßstabsgrenzen können ebenfalls in einer Regel überschrieben werden.

### Weitere Stile für denselben Layer

Zwei Stil-Dateien anlegen, beispielsweise `normal.json` und `kontrast.json`, und im Layer `"styles": ["normal", "kontrast"]` setzen. WMS-Clients können sie über `STYLES` auswählen. Für mehrere Layer ist eine gleich lange kommagetrennte Stilliste erforderlich; leere Einträge wählen den jeweiligen Standard. `STYLES=` wählt für alle Layer die Standards.

## HTTP und WMS

CORS erlaubt standardmäßig öffentliche GET-Zugriffe ohne Cookies/Anmeldeinformationen. Für eingeschränkte Dienste explizite Ursprünge wie `https://karte.example.org` eintragen oder `[]` verwenden. CORS ersetzt keine Zugriffskontrolle. Kein Credential-CORS oder Preflight für Autorisierungsheader.

Nur GET. Parameternamen sind unabhängig von Groß-/Kleinschreibung; doppelte Namen, Arrayparameter und NUL-Bytes werden zurückgewiesen. Maximal 8192 Bytes Querystring. Unbekannte allgemeine Parameter werden ignoriert; auch nicht implementierte SLD-/Filter-/Zeit-/Höhenparameter werden ohne Auswertung ignoriert. Remote-URLs werden nie geladen.

GetFeatureInfo verlangt die Kartenparameter, `QUERY_LAYERS`, `I`, `J`, `INFO_FORMAT` und optional `FEATURE_COUNT` (Maximum pro Layer, Standard 1 auch bei ungültigen oder nicht positiven Werten; lokale Ausgabegrenze 100 pro Layer). Treffer werden nach Entfernung zum Abfragepixel sortiert. `SERVICE=WMS` ist nur bei GetCapabilities verpflichtend. Der Trefferradius beträgt bei Punkten mindestens 5 Pixel bzw. die halbe Symbolgröße, bei Linien mindestens 3 Pixel. Polygone werden einschließlich Löchern geometrisch geprüft. PNG-Symbole werden geometrisch über ihre Punktposition abgefragt, nicht pixelgenau über den Alpha-Kanal.

Bei Operationen außer GetCapabilities ist `VERSION=1.3.0` erforderlich. GetCapabilities handelt eine angefragte ältere/neue Version auf die einzige unterstützte Version 1.3.0 aus. `UPDATESEQUENCE`, XML-POST und In-Image-Exceptions werden nicht implementiert. `EXCEPTIONS=XML` ist das unterstützte Ausnahmeformat. HTTP-Antworten verwenden ETags und `Cache-Control: no-cache`; damit bleiben Datenaktualisierungen sofort überprüfbar.
