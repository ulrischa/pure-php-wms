# Prüfung gegen die bereitgestellten OGC-Referenzen

Geprüft am 9. Oktober 2026 gegen OGC 06-042 (WMS 1.3.0), die bereitgestellten Capabilities-/Exception-XSDs und XML-Beispiele sowie `ExtendedSecurityCapabilities.xsd`. Die XML-/XSD-Originale liegen unverändert in `tests/references/`; `manifest.json` enthält SHA-256-Prüfsummen sämtlicher bereitgestellter Referenzen einschließlich des PDF. Das PDF wird nicht mitgeliefert. Für Offline-Validierung verweist ausschließlich die Arbeitskopie unter `tests/schemas/` auf lokale XLink-/XML-Schemas.

Dies ist eine gezielte Implementierungs- und Referenzprüfung, kein vollständiger OGC Abstract Test Suite-/CITE-Lauf und keine Zertifizierung.

## Befunde und umgesetzte Korrekturen

| Referenz | Befund vorher | Umsetzung und Nachweis |
|---|---|---|
| Tabellen 3, 8, 9; 7.2.3.3 | SERVICE wurde bei jeder Operation verlangt | Nur GetCapabilities benötigt SERVICE; Map und FeatureInfo ohne SERVICE getestet |
| 6.8.1, 6.9.5, Anhang C.3.5 | Nicht unterstützte Erweiterungen, TIME und ELEVATION wurden abgelehnt | Ignoriert, keine Auswertung oder Remote-Zugriffe; unbekannte Namen dürfen auch Punkt/Bindestrich enthalten |
| 7.2.4.1 | schemaLocation fehlte am Capabilities-Wurzelelement | Namensraum und Schemahinweis ergänzt; XSD-Validierung bleibt erfolgreich |
| 7.2.4.6.8 | Kategorien-Wurzellayer ohne BoundingBox | Vereinigte geografische Ausdehnung aller veröffentlichten Layer ergänzt |
| 7.3.3.6 | Mindestgröße 1e-9 schloss positive BBOX aus | Nur strikt positive Breite/Höhe verlangt; winziger Ausschnitt getestet |
| 7.4.3.6 | FEATURE_COUNT global, ungültige Werte abgelehnt | Maximum pro Layer; ungültig/nicht positiv ergibt Standard 1; maximal 100 Ergebnisse pro Layer als lokale Ausgabegrenze |
| 7.4.3.7 | Generischer Fehler für I/J | InvalidPoint bei fehlenden, ungültigen oder außerhalb liegenden Pixeln |
| 7.4.4 | Erste Treffer in Datenbankreihenfolge | Entfernung zum Pixelmittelpunkt sortiert; Linien, Flächenlöcher, Mehrfachgeometrien berücksichtigt; Integrationstest mit später gespeichertem näherem Punkt |
| 6.10, 7.4.3.5 | INFO_FORMAT mit zusätzlichem Zeichensatz-Suffix | Exakter MIME-Type, auch im echten HTTP-Header geprüft; Inhalte bleiben UTF-8 |
| 6.5, 6.8.1 | Ganzzahlen unnötig eingeschränkt; CRS-Werte in Großschrift umgewandelt | Vorzeichen/führende Nullen bei Ganzzahlen akzeptiert; CRS-Werte entsprechend Schreibweise geprüft |
| Anhang E | Fehler-XML bisher nur auf Existenz geprüft | Antwort validiert gegen bereitgestelltes Exception-XSD; beide hochgeladenen XML-Beispiele gegen jeweilige XSD validiert |

`php tests/run.php`: **105 Prüfungen bestanden**, auch mit 128 MiB PHP-memory_limit. `python tests/http_smoke.py php`: **20 HTTP-Prüfungen bestanden**. Keine Aussage über Speicherverbrauch beliebig komplexer Geodaten.

## Bereits passende bzw. optionale Eigenschaften

- Ausschließlich WMS 1.3.0 anzubieten ist zulässig. GetCapabilities handelt bei anderen syntaktisch gültigen Versionsangaben die einzige unterstützte Version aus (6.2.4).
- PNG als einziges Kartenformat ist zulässig; zusätzliche JPEG-/Rasterformate sind keine Pflicht.
- Ohne serverseitiges UpdateSequence liefert GetCapabilities auch bei mitgeliefertem UPDATESEQUENCE die aktuellen Metadaten (Tabelle 4).
- EPSG:4326 nutzt in WMS 1.3.0 Breite/Länge; CRS:84 Länge/Breite. Regressionstest bestätigt gleiche Karten nach Achsentausch.
- Die Security-Erweiterung bezieht sich auf OGC 17-007r1 und ergänzt WMS optional. Sie fordert keine Implementierung von Login, Rollenverwaltung oder OWS-Security-Metadaten in einem öffentlichen Basis-WMS.
- GetLegendGraphic ist eine begrenzte Erweiterung. SLD, Zeit-/Höhendimensionen und In-Bild-Fehler werden nicht angeboten. Nur angebotene Formate/Dimensionen müssen unterstützt werden.

## Verbleibende Anpassungen und Freigabeschritte

1. **Empfehlung aus 7.3.3.6:** Anfragen außerhalb des numerisch unterstützten CRS-Bereichs liefern weiterhin eine XML-Ausnahme statt einer leeren Karte. Der Standard verwendet hierfür „should“. Für globalen/polaren Betrieb wären robustes Clipping und erweiterte Projektionstests nötig; derzeit gilt das dokumentierte regionale Betriebsprofil. Eine positive BBOX außerhalb der Daten innerhalb des unterstützten Bereichs funktioniert bereits.
2. **Metadaten:** Optionale Kontaktangaben, Keywords und zusätzliche projizierte BoundingBoxes könnten für Katalogintegration ergänzt werden. Der geografische Umfang und die angebotenen CRS sind vorhanden.
3. **Formaler Konformitätsnachweis:** Vollständigen OGC-CITE-/TEAM-Engine-Lauf gegen den bereitgestellten Dienst durchführen; die lokale Auswahl von Tests deckt nicht den gesamten Standard ab.
4. **Betriebsfreigabe:** Apache-Zugriffsschutz, QGIS-Anbindung, reale Fachdatensätze und parallele Last auf dem konkreten Webhosting prüfen. Die bisherigen HTTP-Tests laufen auf PHPs Entwicklungsserver.

GeoTIFF-Unterstützung, Antimeridian-/Polarverarbeitung und beliebige Projektionssysteme bleiben bewusst außerhalb dieses Projektausbaus. Sie werden durch die Schema-Dateien nicht zu Pflichtfunktionen.
