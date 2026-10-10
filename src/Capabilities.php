<?php

declare(strict_types=1);

namespace PureWms;

final class Capabilities
{
    public static function build(Config $config, string $id, array $service, array $datasets): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'WMS_Capabilities', 'http://www.opengis.net/wms');
        $xml->writeAttribute('version', '1.3.0');
        $xml->writeAttributeNS('xsi', 'schemaLocation', 'http://www.w3.org/2001/XMLSchema-instance', 'http://www.opengis.net/wms https://schemas.opengis.net/wms/1.3.0/capabilities_1_3_0.xsd');
        $xml->writeAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $xml->startElement('Service');
        $xml->writeElement('Name', 'WMS');
        $xml->writeElement('Title', $service['title']);
        $xml->writeElement('Abstract', $service['abstract'] ?? '');
        self::online($xml, $config->server['base_url'] . '?MAP=' . rawurlencode($id));
        $xml->writeElement('Fees', $service['fees'] ?? 'none');
        $xml->writeElement('AccessConstraints', $service['access_constraints'] ?? 'none');
        $xml->writeElement('LayerLimit', '16');
        $xml->writeElement('MaxWidth', (string)$config->server['max_side']);
        $xml->writeElement('MaxHeight', (string)$config->server['max_side']);
        $xml->endElement();
        $xml->startElement('Capability');
        $xml->startElement('Request');
        foreach (['GetCapabilities' => ['text/xml'],'GetMap' => ['image/png'],'GetFeatureInfo' => ['application/json','text/plain']] as $operation => $formats) {
            $xml->startElement($operation);
            foreach ($formats as $format) {
                $xml->writeElement('Format', $format);
            }
            $xml->startElement('DCPType');
            $xml->startElement('HTTP');
            $xml->startElement('Get');
            self::online($xml, $config->server['base_url'] . '?MAP=' . rawurlencode($id) . '&');
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('Exception');
        $xml->writeElement('Format', 'XML');
        $xml->endElement();
        $xml->startElement('Layer');
        $xml->writeAttribute('queryable', '0');
        $xml->writeElement('Title', $service['title']);
        foreach ($config->crs->codes() as $crs) {
            $xml->writeElement('CRS', $crs);
        }
        $union = [INF, INF, -INF, -INF];
        foreach ($service['layers'] as $layer) {
            $b = $datasets[$layer['dataset']]->metadata['bbox'];
            $union = [min($union[0], $b[0]), min($union[1], $b[1]), max($union[2], $b[2]), max($union[3], $b[3])];
        }
        self::bounds($xml, $union);
        foreach ($service['layers'] as $name => $layer) {
            $bbox = $datasets[$layer['dataset']]->metadata['bbox'];
            $xml->startElement('Layer');
            $xml->writeAttribute('queryable', ($layer['queryable'] ?? true) ? '1' : '0');
            $xml->writeElement('Name', $name);
            $xml->writeElement('Title', $layer['title']);
            $xml->writeElement('Abstract', $layer['abstract'] ?? '');
            self::bounds($xml, $bbox);
            foreach ($layer['styles'] as $styleId) {
                $style = $config->style($styleId);
                $xml->startElement('Style');
                $xml->writeElement('Name', $styleId);
                $xml->writeElement('Title', $style['title'] ?? $styleId);
                $xml->startElement('LegendURL');
                $xml->writeAttribute('width', '260');
                $xml->writeAttribute('height', (string)(40 * (isset($style['_sld_groups']) ? array_sum(array_map('count', $style['_sld_groups'])) : 1 + count($style['rules'] ?? []))));
                $xml->writeElement('Format', 'image/png');
                self::online($xml, $config->server['base_url'] . '?' . http_build_query(['MAP' => $id,'SERVICE' => 'WMS','VERSION' => '1.3.0','REQUEST' => 'GetLegendGraphic','LAYER' => $name,'STYLE' => $styleId,'FORMAT' => 'image/png'], '', '&', PHP_QUERY_RFC3986));
                $xml->endElement();
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private static function bounds(\XMLWriter $xml, array $bbox): void
    {
        $xml->startElement('EX_GeographicBoundingBox');
        foreach (['westBoundLongitude','eastBoundLongitude','southBoundLatitude','northBoundLatitude'] as $i => $key) {
            $xml->writeElement($key, (string)$bbox[[0,2,1,3][$i]]);
        }
        $xml->endElement();
        $xml->startElement('BoundingBox');
        $xml->writeAttribute('CRS', 'EPSG:4326');
        foreach (['minx','miny','maxx','maxy'] as $i => $key) {
            $xml->writeAttribute($key, (string)$bbox[[1,0,3,2][$i]]);
        }
        $xml->endElement();
    }

    private static function online(\XMLWriter $xml, string $url): void
    {
        $xml->startElement('OnlineResource');
        $xml->writeAttribute('xlink:type', 'simple');
        $xml->writeAttribute('xlink:href', $url);
        $xml->endElement();
    }

    public static function exception(WmsException $error): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'ServiceExceptionReport', 'http://www.opengis.net/ogc');
        $xml->writeAttribute('version', '1.3.0');
        $xml->startElement('ServiceException');
        $xml->writeAttribute('code', $error->wmsCode);
        $xml->text($error->getMessage());
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
