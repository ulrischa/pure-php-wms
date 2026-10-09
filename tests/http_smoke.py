"""Exercise real HTTP headers, methods and exception responses on PHP's test server."""
import json, os, socket, subprocess, sys, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=sys.argv[1] if len(sys.argv)>1 else 'php'
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t',str(root/'public')],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
base=f'http://127.0.0.1:{port}/wms.php?'
checks=0
def request(query,headers=None,method='GET'):
    try:
        result=urllib.request.urlopen(urllib.request.Request(base+query,headers=headers or {},method=method),timeout=15)
    except urllib.error.HTTPError as error: result=error
    return result.status,result.headers,result.read()
def check(ok,message):
    global checks
    if not ok:raise AssertionError(message)
    checks+=1
try:
    for attempt in range(40):
        try:request('SERVICE=WMS&REQUEST=GetCapabilities');break
        except urllib.error.URLError:time.sleep(.1)
    status,headers,body=request('SERVICE=WMS&REQUEST=GetCapabilities')
    check(status==200 and b'WMS_Capabilities' in body,'Capabilities')
    check(headers.get('X-Content-Type-Options')=='nosniff','MIME sniffing disabled')
    status,headers,body=request('SERVICE=WMS&REQUEST=GetCapabilities',method='POST')
    check(status==405 and headers.get('Allow')=='GET' and b'ServiceException' in body,'Method rejection')
    q=urllib.parse.urlencode({'SERVICE':'WMS','REQUEST':'GetMap','VERSION':'1.3.0','LAYERS':'land,routes,places','STYLES':'','CRS':'CRS:84','BBOX':'11.28,48.18,11.39,48.245','WIDTH':'600','HEIGHT':'400','FORMAT':'image/png'})
    status,headers,body=request(q)
    check(status==200 and body.startswith(b'\x89PNG\r\n\x1a\n'),'PNG bytes without warnings')
    check(int(headers['Content-Length'])==len(body),'Exact content length')
    check(headers.get('Access-Control-Allow-Origin')=='*','Public CORS policy')
    etag=headers['ETag']
    status,headers,body=request(q,{'If-None-Match':etag})
    check(status==304 and not body,'Conditional map request')
    for query in ['SERVICE=WMS&REQUEST=GetMap&request=GetCapabilities','SERVICE=WMS&LAYERS[]=x','SERVICE=WMS&REQUEST=GetCapabilities&MAP=../config','SERVICE=WMS&REQUEST=GetMap&VERSION=1.3.0&FORMAT=image/png&CRS=CRS:84&BBOX=0,0,1,1&WIDTH=999999&HEIGHT=256&LAYERS=land&STYLES=']:
        status,headers,body=request(query)
        check(status==400 and b'ServiceException' in body,'Invalid request rejected')
        check(b'/workspace/' not in body and b'Stack trace' not in body,'No internal details leaked')
    status,headers,body=request(q.replace('SERVICE=WMS&','')+'&TIME=invalid&SLD=http%3A%2F%2Flocalhost%2Fprivate')
    check(status==200 and body.startswith(b'\x89PNG'),'Core GetMap ignores unsupported extensions without SERVICE')
    for info_format in ['application/json','text/plain']:
        iq=q.replace('REQUEST=GetMap','REQUEST=GetFeatureInfo')+'&QUERY_LAYERS=places&I=289&J=135&INFO_FORMAT='+info_format
        status,headers,body=request(iq)
        check(status==200 and headers.get('Content-Type')==info_format,'Exact INFO_FORMAT on HTTP wire')
    status,headers,body=request(iq+'&FEATURE_COUNT=0')
    check(status==200,'Invalid FEATURE_COUNT defaults on HTTP wire')
    status,headers,body=request(iq.replace('I=289','I=9999'))
    check(status==400 and b'code="InvalidPoint"' in body,'InvalidPoint on HTTP wire')
    print(f'Passed {checks} HTTP checks')
finally:
    server.terminate()
    _,errors=server.communicate(timeout=5)
    if b'Fatal error' in errors or b'Warning:' in errors: print(errors.decode(),file=sys.stderr)
