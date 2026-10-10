"""Linux integration test: parallel PHP workers, cache cleanup and render admission.

Uses an isolated temporary installation, never the project's live cache or datasets.
This is a synthetic concurrency check, not an Apache capacity certification.
"""
import concurrent.futures
import fcntl
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

project = Path(__file__).resolve().parents[1]
php = str(Path(shutil.which(sys.argv[1] if len(sys.argv) > 1 else 'php')).resolve())
checks = 0

def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1

with tempfile.TemporaryDirectory(prefix='wms-concurrency-') as directory:
    root = Path(directory)
    for name in ['config', 'public', 'resources', 'data']:
        shutil.copytree(project / name, root / name)
    (root / 'vendor').symlink_to(project / 'vendor', target_is_directory=True)
    (root / 'cache').mkdir()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    address = f'http://127.0.0.1:{port}/wms.php?'
    parameters = dict(REQUEST='GetMap', VERSION='1.3.0', LAYERS='land,routes,places', STYLES='', CRS='CRS:84', BBOX='11.28,48.18,11.39,48.245', WIDTH=600, HEIGHT=400, FORMAT='image/png')
    query = urllib.parse.urlencode(parameters)
    stop = threading.Event()
    lock_handles = []
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')], env={**os.environ, 'PHP_CLI_SERVER_WORKERS': '4'}, stdout=log, stderr=log, start_new_session=True)
        def request(q=query):
            start = time.monotonic()
            try:
                result = urllib.request.urlopen(address + q, timeout=15)
            except urllib.error.HTTPError as error:
                result = error
            with result:
                return result.status, dict(result.headers), result.read(), (time.monotonic() - start) * 1000
        def clear_cache():
            while not stop.wait(.002):
                for path in (root / 'cache').glob('map-*.bin'):
                    path.unlink(missing_ok=True)
        cleaner = None
        try:
            for attempt in range(60):
                try:
                    first = request()
                    break
                except urllib.error.URLError:
                    time.sleep(.05)
            else:
                raise AssertionError('PHP server did not start')
            check(first[0] == 200, 'Prime cache')
            for i in range(3):
                handle = open(root / 'cache' / f'worker-{i}.lock', 'a')
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                lock_handles.append(handle)
            check(request()[0] == 200, 'Cache hit works while all render slots are busy')
            busy = request(query + '&BGCOLOR=0x123456')
            check(busy[0] == 503 and busy[1].get('Retry-After') == '5', 'Render overload has controlled retry response')
            for handle in lock_handles:
                handle.close()
            lock_handles.clear()
            check(request(query + '&BGCOLOR=0x123456')[0] == 200, 'Render admission recovers after locks released')
            cleaner = threading.Thread(target=clear_cache)
            cleaner.start()
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                results = list(pool.map(lambda _: request(), range(80)))
            stop.set()
            cleaner.join()
            check(all(r[0] in (200, 503) for r in results), 'No unexpected status under concurrent cache deletion')
            check(all(r[2] == first[2] for r in results if r[0] == 200), 'Concurrent PNG results are byte-identical')
            check(all(b'Server busy' in r[2] or b'Map already rendering' in r[2] for r in results if r[0] == 503), 'Any overload response is admission control, not an internal failure')
            check(any(r[0] == 200 for r in results), 'Concurrent workload made progress')
            check(request()[0] == 200, 'Service recovers after workload')
            latencies = sorted(r[3] for r in results)
            print(json.dumps({'checks': checks, 'requests': len(results), 'clients': 8, 'successful': sum(r[0] == 200 for r in results), 'busy': sum(r[0] == 503 for r in results), 'median_ms': latencies[len(latencies)//2], 'p95_ms': latencies[int(len(latencies)*.95)-1]}, indent=2))
        finally:
            stop.set()
            if cleaner:
                cleaner.join()
            for handle in lock_handles:
                handle.close()
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=10)
            log.seek(0)
            errors = log.read()
            if b'Fatal error' in errors or b'Warning:' in errors:
                raise AssertionError(errors.decode())
