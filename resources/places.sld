<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0" xmlns="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc">
  <NamedLayer>
    <Name>places</Name>
    <UserStyle>
      <Name>places_sld</Name>
      <Title>Places from SLD</Title>
      <IsDefault>1</IsDefault>
      <FeatureTypeStyle>
        <Rule>
          <Name>stations</Name><Title>Stations</Title>
          <ogc:Filter><ogc:PropertyIsEqualTo><ogc:PropertyName>kind</ogc:PropertyName><ogc:Literal>station</ogc:Literal></ogc:PropertyIsEqualTo></ogc:Filter>
          <PointSymbolizer><Graphic><Mark><WellKnownName>square</WellKnownName><Fill><CssParameter name="fill">#4274ad</CssParameter></Fill><Stroke><CssParameter name="stroke">#254a76</CssParameter><CssParameter name="stroke-width">2</CssParameter></Stroke></Mark><Size>16</Size></Graphic></PointSymbolizer>
        </Rule>
        <Rule>
          <Name>other_places</Name><Title>Other places</Title><ElseFilter/>
          <PointSymbolizer><Graphic><Mark><WellKnownName>circle</WellKnownName><Fill><CssParameter name="fill">#e77736</CssParameter></Fill></Mark><Size>14</Size></Graphic></PointSymbolizer>
        </Rule>
      </FeatureTypeStyle>
      <FeatureTypeStyle>
        <Rule>
          <Name>labels</Name><Title>Names</Title>
          <MaxScaleDenominator>100000</MaxScaleDenominator>
          <TextSymbolizer><Label><ogc:PropertyName>name</ogc:PropertyName></Label><Font><CssParameter name="font-family">DejaVu Sans</CssParameter><CssParameter name="font-size">16</CssParameter></Font><Halo><Radius>2</Radius><Fill><CssParameter name="fill">#ffffff</CssParameter></Fill></Halo><Fill><CssParameter name="fill">#233c42</CssParameter></Fill></TextSymbolizer>
        </Rule>
      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
