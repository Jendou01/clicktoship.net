#!/usr/bin/env python3
"""Loopback-only, 60-second synthetic load probe. No runtime throttle overrides."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import http.cookiejar
import math
from pathlib import Path
import time
import urllib.request
import urllib.error
from urllib.parse import urlsplit, unquote
import uuid

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base-url', default='http://127.0.0.1:8080')
    p.add_argument('--fixture', required=True)
    p.add_argument('--output', required=True)
    a = p.parse_args()
    url = urlsplit(a.base_url)
    if url.scheme != 'http' or url.hostname not in ['127.0.0.1', 'localhost'] or url.username or url.password or url.query or url.fragment or url.path not in ['', '/']:
        p.error('Only an explicit loopback development origin is allowed')
    fixture_path = Path(a.fixture)
    if fixture_path.stat().st_mode & 0o077:
        p.error('Fixture credentials must be private mode 0600')
    f = json.loads(fixture_path.read_text())
    if f.get('environment') != 'testing' or f.get('database') != 'clicktoship_e2e' or f.get('expires_at', 0) < time.time() or len(f.get('accounts', [])) != 4:
        p.error('Current isolated E2E fixture required')
    opener = urllib.request.build_opener(NoRedirect)
    deadline = time.monotonic() + 60
    routes = ['/api/v1/shipments', '/api/v1/dashboard?range=7d', '/api/v1/analytics/reports/shipment_summary?range=7d', '/api/v1/rates', '/api/v1/commerce/orders', '/api/v1/tracking/shipments']
    def send(account, route, body=None, client=None, jar=None):
        if time.monotonic() >= deadline:
            raise RuntimeError('Bounded probe deadline exceeded')
        data = json.dumps(body).encode() if body is not None else None
        headers={'Accept':'application/json','Content-Type':'application/json'}
        if client:
            headers.update({'Origin':a.base_url,'Referer':a.base_url+'/en/dashboard'})
            if jar is not None:
                for cookie in jar:
                    if cookie.name=='XSRF-TOKEN':headers['X-XSRF-TOKEN']=unquote(cookie.value)
        else:headers['Authorization']='Bearer '+account['token']
        r = urllib.request.Request(a.base_url.rstrip('/') + route, data=data, headers=headers)
        start = time.perf_counter()
        try:
            with (client or opener).open(r, timeout=5) as response:
                payload = response.read(262145)
                status = response.status
        except urllib.error.HTTPError as e:
            status, payload = e.code, e.read(4096)
        return {'ms': (time.perf_counter()-start)*1000, 'status': status, 'bytes': len(payload)}, payload
    stages = []
    for concurrency in [1, 2, 4]:
        started = time.perf_counter()
        def worker(index):
            out = []
            account = f['accounts'][index]
            for i in range(12):
                route = routes[i % len(routes)]
                body = None
                if i % 6 == 0:
                    route = '/api/v1/shipments'
                    body = {'draft': account['draft'], 'idempotency_key': str(uuid.uuid4())}
                row, _ = send(account, route, body)
                out.append(row)
            return out
        with ThreadPoolExecutor(max_workers=concurrency) as pool:
            rows = sum(pool.map(worker, range(concurrency)), [])
        times = sorted(r['ms'] for r in rows)
        quantile = lambda q: round(times[math.ceil(q*len(times))-1], 3)
        stages.append({'concurrency': concurrency, 'requests': len(rows), 'p50_ms': quantile(.5), 'p95_ms': quantile(.95), 'p99_ms': quantile(.99), 'throughput_rps': round(len(rows)/(time.perf_counter()-started), 2), 'errors': sum(r['status'] not in [200, 201] for r in rows), 'status_counts': {str(s):sum(r['status']==s for r in rows) for s in sorted({r['status'] for r in rows})}, 'payload_bytes_max':max(r['bytes'] for r in rows)})
    key = str(uuid.uuid4())
    def duplicate(_):
        row, raw = send(f['accounts'][0], '/api/v1/shipments', {'draft': f['accounts'][0]['draft'], 'idempotency_key': key})
        if row['status'] != 201:
            raise RuntimeError('Concurrent draft request failed with status '+str(row['status']))
        return json.loads(raw)['data']['shipment']['id']
    with ThreadPoolExecutor(max_workers=4) as pool:
        ids = list(pool.map(duplicate, range(4)))
    sessions=[]
    for account in f['accounts']:
        jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(NoRedirect,urllib.request.HTTPCookieProcessor(jar))
        row,_=send(account,'/sanctum/csrf-cookie',client=client,jar=jar)
        if row['status']!=204:raise RuntimeError('QA_CSRF_BOOTSTRAP_FAILED')
        row,_=send(account,'/api/v1/auth/login',{'email':account['email'],'password':account['password']},client,jar)
        if row['status']!=200:raise RuntimeError('QA_SYNTHETIC_LOGIN_FAILED')
        sessions.append((client,jar))
    switches=[]
    for concurrency in [1,2,4]:
        started=time.perf_counter()
        def switch(index):
            out=[];account=f['accounts'][index];client,jar=sessions[index]
            for i in range(12):
                merchant=account['merchants'][i%2]
                row,_=send(account,'/api/v1/merchants/select',{'merchant_id':merchant},client,jar);out.append(row)
                read,raw=send(account,'/api/v1/auth/me',client=client,jar=jar);out.append(read)
                if row['status']!=200 or read['status']!=200 or json.loads(raw)['data']['active_merchant_id']!=merchant:raise RuntimeError('QA_SWITCH_CONTEXT_MISMATCH')
            return out
        with ThreadPoolExecutor(max_workers=concurrency) as pool:rows=sum(pool.map(switch,range(concurrency)),[])
        times=sorted(r['ms'] for r in rows)
        switches.append({'concurrency':concurrency,'requests':len(rows),'p50_ms':round(times[math.ceil(len(times)*.5)-1],3),'p95_ms':round(times[math.ceil(len(times)*.95)-1],3),'p99_ms':round(times[math.ceil(len(times)*.99)-1],3),'throughput_rps':round(len(rows)/(time.perf_counter()-started),2),'errors':0,'context_verified_each_switch':True})
    result = {'transport':'actual loopback HTTP through Nginx / bearer-authenticated Laravel', 'database':'clicktoship_e2e', 'deadline_seconds':60, 'max_concurrency':4, 'total_requests':264,'session_switch_stages':switches, 'stages':stages, 'concurrent_duplicate_draft_unique_ids':len(set(ids)), 'production_sla_claim':False}
    Path(a.output).write_text(json.dumps(result, indent=2)+'\n')
    print(json.dumps(result, indent=2))
    return bool(any(s['errors'] for s in stages) or len(set(ids)) != 1)

if __name__ == '__main__':
    raise SystemExit(main())
