# Installation und erster eigener Dienst

## 1. Paket entpacken und PHP prüfen

Das ZIP enthält eine vollständige Installation mit Bibliotheken und synthetischen Beispieldaten. Es werden keine echten Verwaltungs- oder Nutzerdaten mitgeliefert.

Zum Ausprobieren der Demo auf dem Hosting sind **weder Composer noch SSH nötig**. Für eigene Daten wird das mitgelieferte Importwerkzeug einmalig lokal auf einem Rechner mit PHP ausgeführt. Alternativ kann es auf dem Hosting laufen, wenn dort eine PHP-Kommandozeile vorhanden ist.

Beim Hoster aktivieren: PHP ab 8.2, GD mit FreeType, PDO_SQLite, XMLWriter, mbstring und iconv. Wenn der Hoster ein PHP-Modul namens `sqlite3` anbietet, trotzdem kontrollieren, dass auch `pdo_sqlite` aktiv ist.

Lokal im entpackten Projektordner:

```sh
php bin/console.php doctor
```

Unter Windows kann `php` durch den vollständigen Pfad zur `php.exe` ersetzt werden. Die PHP-Version für den Import darf neuer sein als die Hosting-Version, sofern beide mindestens 8.2 sind. Die erzeugten SQLite-Datensätze sind keine PHP-Binärmodule.

## 2. Öffentliche URL eintragen

`config/server.json` bearbeiten:

```json
"base_url": "https://example.org/wms/public/wms.php"
```

Die vollständige tatsächliche URL zu `wms.php` einsetzen, **ohne Querystring**. Diese URL wird in QGIS als Dienstadresse verwendet und in den Capabilities veröffentlicht. Host-Header des Clients werden dafür nicht übernommen.

## 3. Auf Apache hochladen

### Variante A: Eigener DocumentRoot möglich — bevorzugt

Den Projektordner beispielsweise nach `/home/account/php-wms/` hochladen. DocumentRoot der gewünschten Subdomain auf `/home/account/php-wms/public/` setzen. Die öffentliche URL ist dann beispielsweise `https://karten.example.org/wms.php`.

`config/`, `sources/`, `data/`, `vendor/` und `cache/` liegen damit außerhalb des öffentlichen Verzeichnisses.

### Variante B: Nur FTP in ein festes Webroot möglich

Den gesamten Projektordner nach beispielsweise `public_html/wms/` hochladen, **einschließlich beider `.htaccess`-Dateien**. FTP-Programme blenden Dateien mit führendem Punkt teilweise aus.

- Aufruf: `https://example.org/wms/public/wms.php`.
- Die `.htaccess` im Projektstamm sperrt den Stamm und die privaten Unterverzeichnisse.
- Die `.htaccess` in `public/` gibt ausschließlich dieses Verzeichnis wieder frei.
- Apache muss diese Zugriffsregeln erlauben. Falls der Hoster `.htaccess` ignoriert, ist diese Variante ungeeignet; Variante A verwenden.

Nach dem Upload prüfen:

1. Der GetCapabilities-Aufruf aus der README liefert XML.
2. `https://example.org/wms/config/server.json` liefert **403 oder 404**, niemals Konfigurationsdaten.
3. `https://example.org/wms/data/places.sqlite` liefert **403 oder 404**, niemals die Datenbank.
4. Die Demo-Karten-URL liefert eine PNG-Karte.

Nur `cache/` muss im laufenden Webbetrieb für den PHP-Benutzer schreibbar sein. `data/` braucht Schreibrechte nur dort, wo importiert wird. Sonstige Dateien sollen lediglich lesbar sein. Keine pauschalen `chmod 777`-Rechte setzen. Welche Eigentümer-/Gruppenrechte erforderlich sind, hängt vom Hosting ab.

Falls Apache wegen `Options -Indexes` mit HTTP 500 antwortet, erlaubt der Hoster diese Options-Direktive nicht. Sie kann in `public/.htaccess` entfernt werden, wenn Verzeichnisauflistung bereits zentral deaktiviert ist. Die `Require`-Zugriffsregeln nicht entfernen, um einen Fehler zu umgehen.

## 4. In QGIS verbinden

1. Neue **WMS/WMTS-Verbindung** anlegen.
2. URL: `https://example.org/wms/public/wms.php?MAP=demo`.
3. Verbinden und einen oder mehrere Layer auswählen.
4. PNG und einen angebotenen CRS verwenden.
5. Mit „Objekte abfragen“ die freigegebenen Attribute prüfen.

Ein Dienstname entspricht einer Datei unter `config/services/`, beispielsweise `demo.json` → `MAP=demo`. Mehrere Dienste können dieselben vorbereiteten Daten nutzen.

## 5. Eigenes Shapefile vorbereiten

Alle zusammengehörenden Dateien mit gleichem Basenamen in `sources/` ablegen:

```text
schutzgebiete.shp
schutzgebiete.shx
schutzgebiete.dbf
schutzgebiete.prj
schutzgebiete.cpg
```

Der Server benötigt die Geometrie- und Attributdateien `.shp` und `.dbf`. `.shx`, `.prj` und `.cpg` als vollständigen Datensatz mitführen. **CRS und DBF-Encoding werden bewusst in der Konfiguration angegeben; die PRJ-/CPG-Dateien werden nicht automatisch interpretiert.** So wird eine unbekannte Projektion nicht stillschweigend als WGS84 behandelt.

In QGIS kontrollieren: gültige Geometrien, unterstützter CRS, keine exotischen DBF-Spalten. Für Bayern ist beispielsweise **EPSG:25832** üblich. Ist die SHP-Datei zu groß, die Daten in Teilgebiete aufteilen oder als GeoJSON exportieren. Bei GeoJSON nach EPSG:4326 exportieren.

## 6. Datenquelle registrieren

In `config/sources.json` einen weiteren Eintrag ergänzen. Beispiel für DBF in Windows-1252:

```json
{
  "schutzgebiete": {
    "file": "schutzgebiete.shp",
    "crs": "EPSG:25832",
    "encoding": "Windows-1252"
  }
}
```

Das Beispiel zeigt einen vollständigen Inhalt für genau eine Datenquelle. Bereits vorhandene Einträge beibehalten, wenn die Demo weiterhin funktionieren soll. Für UTF-8-DBF `"encoding": "UTF-8"` verwenden.

Weitere Formate:

```json
{
  "orte": {
    "file": "orte.geojson",
    "crs": "EPSG:4326"
  },
  "wege": {
    "file": "landschaft.gpkg",
    "table": "wege",
    "crs": "EPSG:25832"
  }
}
```

Bei GeoPackage muss `table` exakt dem Namen der Vektortabelle entsprechen; die EPSG-Nummer muss mit der GeoPackage-Metadatenangabe übereinstimmen. Rasterkacheln oder SQL-Ansichten sind keine unterstützten Datenquellen.

## 7. Lokal importieren

```sh
php bin/console.php import schutzgebiete
```

Ergebnis: `data/schutzgebiete.sqlite` mit Geometrien, Attributen und räumlichem Index. Die Quelldateien bleiben unverändert.

Falls der Shapefile-Importer zu wenig Speicher meldet, kann der **lokale** Import mit höherem Speicherlimit erfolgen:

```sh
php -d memory_limit=1G bin/console.php import schutzgebiete
```

Die feste SHP-Grenze von 16 MiB bleibt bestehen. Größere Daten vorab anders exportieren oder aufteilen. GeoJSON wird sequenziell gelesen; ein einzelnes riesiges Feature kann dennoch viel Speicher benötigen. Es gelten die Grenzen aus der Konfigurationsdokumentation.

## 8. Stil erstellen

`config/styles/schutzgebiet.json`:

```json
{
  "title": "Schutzgebiete",
  "legend_geometry": "polygon",
  "fill": "#75b76b70",
  "stroke": "#2b6933",
  "stroke_width": 2,
  "label_field": "NAME",
  "font_size": 12,
  "label_color": "#173d20",
  "halo_color": "#ffffff",
  "halo": 2
}
```

`NAME` ist ein Beispiel. Den tatsächlichen, groß-/kleinschreibungsabhängigen DBF-Spaltennamen einsetzen.

## 9. Dienst erstellen

`config/services/umwelt.json`:

```json
{
  "title": "Umweltkarten",
  "abstract": "Schutzgebiete und weitere Umweltdaten",
  "layers": {
    "schutzgebiete": {
      "title": "Schutzgebiete",
      "dataset": "schutzgebiete",
      "styles": ["schutzgebiet"],
      "queryable": true,
      "query_fields": ["NAME", "TYP"]
    }
  }
}
```

Nur die genannten `query_fields` werden durch GetFeatureInfo veröffentlicht. Alle anderen Attribute bleiben aus dieser Antwort ausgeschlossen. Die Geometrie des abgefragten Objekts wird als GeoJSON mitgeliefert. Beschriftungsfelder werden natürlich auf der Karte sichtbar; sensible Attribute deshalb auch nicht als Label verwenden.

Lokal prüfen:

```sh
php bin/console.php validate
```

## 10. Eigene Dateien hochladen

Hochladen:

- aktualisierte `config/sources.json`;
- `config/services/umwelt.json`;
- `config/styles/schutzgebiet.json`;
- `data/schutzgebiete.sqlite`;
- zusätzliche Schriften/Symbole aus `resources/`, falls verwendet.

Die Quelldateien unter `sources/` müssen nicht auf das Hosting, wenn nur lokal importiert wird. Die vorbereiteten SQLite-Dateien genügen für die Kartenauslieferung. `validate` erwartet Quelldateien und ist deshalb für die lokale Arbeitskopie vorgesehen.

Dienst-URL: `https://example.org/wms/public/wms.php?MAP=umwelt`.

## Aktualisierungen ohne beschädigte Zwischenstände

1. Lokale Quelldatei ersetzen und erneut importieren.
2. Neue SQLite-Datei zuerst unter einem temporären Namen hochladen, beispielsweise `schutzgebiete.sqlite.upload`.
3. Erst nach vollständig abgeschlossenem Upload per serverseitigem FTP-Umbenennen die veröffentlichte Datei ersetzen, sofern der Hoster atomisches Ersetzen unterstützt.
4. Ist atomisches Ersetzen nicht möglich: neues Dataset unter neuem Namen hochladen und danach die Dienstkonfiguration auf diesen Namen umstellen; Konfiguration ebenfalls möglichst per Umbenennen ersetzen.

Nie eine gerade verwendete SQLite-Datei direkt stückweise per FTP überschreiben. Ein erfolgreicher Import erzeugt automatisch eine neue Revision, sodass alte Karten nicht aus dem Anwendungscache übernommen werden. Nach einem Softwareupdate den Karten-Cache leeren; Konfigurations- und Symboländerungen fließen bereits in den Schlüssel ein.

## Verwaltungskommandos

| Kommando | Zweck |
|---|---|
| `php bin/console.php doctor` | PHP-Erweiterungen, FreeType, SQLite-RTree und Verzeichnisrechte prüfen |
| `php bin/console.php validate` | Dienste, Stile und vorhandene Quellen prüfen |
| `php bin/console.php import --all` | Alle konfigurierten Quellen nacheinander importieren |
| `php bin/console.php import NAME` | Einen Datensatz aktualisieren |
| `php bin/console.php list` | Importrevision, Ausdehnung und Objektanzahl anzeigen |
| `php bin/console.php clear-cache` | Kartencache leeren |

Es gibt absichtlich keinen öffentlich erreichbaren Import-/Upload-Endpunkt und kein zusätzliches Web-Administratorkonto. Die Administration erfolgt über die strukturierten Dateien und diese lokalen Kommandos. Für nichtöffentliche WMS-Dienste kann der Hoster Apache-Zugriffsschutz vor `public/` schalten.

## Fehlerdiagnose

- **HTTP 400 mit ServiceException:** Parameter, Layer, Stil, BBOX und WMS-Version kontrollieren.
- **HTTP 503 „Dataset not imported“ im PHP-Log:** Zugehörige `data/NAME.sqlite` fehlt oder ist nicht lesbar.
- **HTTP 503 „Rendering budget exceeded“:** Größer hineinzoomen, Geometrien vereinfachen, weniger Layer oder kleineres Bild anfragen.
- **HTTP 503 „Server busy“:** Alle Renderplätze sind belegt; später erneut anfragen oder Hosting-Limits prüfen.
- **HTTP 503 mit Referenznummer:** Nach der Referenz im PHP-Error-Log suchen. Dateipfade und Stacktraces werden nicht an Clients ausgegeben.
- **Leere Karte:** CRS und Achsenreihenfolge, Layername, Datenausdehnung und Maßstabsgrenzen des Stils prüfen.
- **Falsche Umlaute:** DBF-Encoding korrigieren und neu importieren.
- **Config-Datei über HTTP lesbar:** Veröffentlichung stoppen und Apache-Zugriffsschutz korrigieren; die Datenordner vorzugsweise außerhalb des Webroots ablegen.
