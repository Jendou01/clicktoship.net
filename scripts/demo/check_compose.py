#!/usr/bin/env python3
"""CI render validation; no containers or public ports are started."""
import json
import os
from pathlib import Path
from policy import run, validate_compose
from control import FIXED

if __name__=='__main__':
    image='sha256:'+'a'*64
    instance='cts-demo-'+'1'*12
    env={**os.environ,'CTS_PROJECT':instance,'CTS_SECRET_DIR':'/run/'+instance,
         'CTS_PROXY_IP':'172.30.73.10','CTS_API_IMAGE':image,'CTS_WEB_IMAGE':image,'CTS_GATEWAY_IMAGE':image}
    root=Path(__file__).resolve().parents[2]
    for public in [False,True]:
        command=['docker','compose','--profile','maintenance','-f',str(root/'infrastructure/demo/compose.yml')]
        if public:
            command+=['-f',str(root/'infrastructure/demo/publish.yml')]
        c=json.loads(run(command+['-p',instance,'config','--format','json'],env=env))
        if not validate_compose(c,instance,{**FIXED,'api':image,'web':image,'gateway':image},public):
            raise SystemExit('DEMO_COMPOSE_POLICY_FAILED')
    print('DEMO_COMPOSE_RENDER_PASSED_NOT_RUNTIME')
