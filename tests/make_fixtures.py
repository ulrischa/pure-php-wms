"""Generate small synthetic ESRI Shapefile and GeoPackage fixtures using stdlib."""
import struct, pathlib, sqlite3, json
root=pathlib.Path(__file__).parent/'fixtures'
root.mkdir(exist_ok=True)
def write_shape(name,shape_type,records,names):
    content=[]
    for geometry in records:
        if shape_type==1:
            body=struct.pack('<idd',1,*geometry)
        else:
            points=[point for ring in geometry for point in ring]
            bbox=[min(x for x,y in points),min(y for x,y in points),max(x for x,y in points),max(y for x,y in points)]
            body=struct.pack('<i4d2i',shape_type,*bbox,len(geometry),len(points))
            offset=0
            for ring in geometry:body+=struct.pack('<i',offset);offset+=len(ring)
            for point in points:body+=struct.pack('<dd',*point)
        content.append(body)
    all_points=records if shape_type==1 else [p for rec in records for ring in rec for p in ring]
    bbox=[min(p[0] for p in all_points),min(p[1] for p in all_points),max(p[0] for p in all_points),max(p[1] for p in all_points)]
    def header(size):return struct.pack('>7i',9994,0,0,0,0,0,size//2)+struct.pack('<2i8d',1000,shape_type,*bbox,0,0,0,0)
    shp=header(100+sum(len(b)+8 for b in content));shx=header(100+8*len(content));offset=50
    for i,b in enumerate(content,1):shp+=struct.pack('>2i',i,len(b)//2)+b;shx+=struct.pack('>2i',offset,len(b)//2);offset+=4+len(b)//2
    (root/f'{name}.shp').write_bytes(shp);(root/f'{name}.shx').write_bytes(shx)
    descriptor=b'NAME'+b'\0'*7+b'C'+b'\0'*4+bytes([40,0])+b'\0'*14
    header_dbf=bytes([3,126,10,9])+struct.pack('<IHH',len(names),65,41)+b'\0'*20
    dbf=header_dbf+descriptor+b'\r'+b''.join(b' '+n.encode('cp1252').ljust(40,b' ') for n in names)+b'\x1a'
    (root/f'{name}.dbf').write_bytes(dbf)
    (root/f'{name}.cpg').write_text('1252')
    (root/f'{name}.prj').write_text('GEOGCS["WGS 84",DATUM["WGS_1984",SPHEROID["WGS 84",6378137,298.257223563]],PRIMEM["Greenwich",0],UNIT["degree",0.0174532925199433]]')
write_shape('points',1,[(11.333,48.223),(11.35,48.201)],['Gröbenzell','Bahnhof'])
outer=[(11.29,48.19),(11.38,48.19),(11.38,48.235),(11.29,48.235),(11.29,48.19)]
hole=[(11.321,48.205),(11.341,48.205),(11.341,48.217),(11.321,48.217),(11.321,48.205)]
island=[(11.385,48.19),(11.395,48.19),(11.395,48.2),(11.385,48.2),(11.385,48.19)]
write_shape('polygons',5,[[outer,hole,island]],['Fläche'])
write_shape('lines',3,[[[(11.30,48.20),(11.37,48.23)]]],['Weg'])
gpkg=root/'points.gpkg'
if gpkg.exists():gpkg.unlink()
db=sqlite3.connect(gpkg)
db.executescript('''PRAGMA application_id=1196444487; PRAGMA user_version=10300;
CREATE TABLE gpkg_spatial_ref_sys(srs_name TEXT NOT NULL,srs_id INTEGER NOT NULL PRIMARY KEY,organization TEXT NOT NULL,organization_coordsys_id INTEGER NOT NULL,definition TEXT NOT NULL,description TEXT);
CREATE TABLE gpkg_contents(table_name TEXT NOT NULL PRIMARY KEY,data_type TEXT NOT NULL,identifier TEXT UNIQUE,description TEXT DEFAULT '',last_change DATETIME NOT NULL DEFAULT '2026-10-09T00:00:00Z',min_x DOUBLE,min_y DOUBLE,max_x DOUBLE,max_y DOUBLE,srs_id INTEGER);
CREATE TABLE gpkg_geometry_columns(table_name TEXT NOT NULL,column_name TEXT NOT NULL,geometry_type_name TEXT NOT NULL,srs_id INTEGER NOT NULL,z TINYINT NOT NULL,m TINYINT NOT NULL,PRIMARY KEY(table_name,column_name));
CREATE TABLE places(fid INTEGER PRIMARY KEY,geom BLOB,name TEXT);
INSERT INTO gpkg_geometry_columns VALUES('places','geom','POINT',4326,0,0);
INSERT INTO gpkg_contents(table_name,data_type,srs_id) VALUES('places','features',4326);
''')
for srs,name,org,oid,definition in [(-1,'Undefined Cartesian','NONE',-1,'undefined'),(0,'Undefined geographic','NONE',0,'undefined'),(4326,'WGS 84','EPSG',4326,(root/'points.prj').read_text())]:
 db.execute('INSERT INTO gpkg_spatial_ref_sys VALUES(?,?,?,?,?,NULL)',(name,srs,org,oid,definition))
blob=b'GP\0\1'+struct.pack('<i',4326)+struct.pack('<BI2d',1,1,11.333,48.223)
db.execute('INSERT INTO places VALUES(1,?,?)',(blob,'Olching'));db.commit();db.close()
