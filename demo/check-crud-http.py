#!/usr/bin/env python3
"""Real HTTP checks. Run only against an isolated demo initialized from its 30-row CSV."""
import json
import os
import time
import urllib.error
import urllib.request

URL = os.environ.get('ARP_DEMO_URL', 'http://127.0.0.1:' + os.environ.get('ARP_DEMO_PORT', '5300'))
TOKEN = os.environ['ARP_DEMO_CRUD_TOKEN']
ORIGIN = os.environ.get('ARP_DEMO_ORIGIN', 'http://127.0.0.1:4173')
COUNT = 0


def request(path, body=None, expected=200, headers=None, raw=None):
    global COUNT
    h = {'Authorization': 'Bearer ' + TOKEN, 'Origin': ORIGIN, 'Content-Type': 'application/json'}
    if headers:
        for key, value in headers.items():
            if value is None:
                h.pop(key, None)
            else:
                h[key] = value
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(URL + path, data=data, headers=h)
    try:
        result = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error:
        result = error
    payload = json.loads(result.read())
    assert result.status == expected, (result.status, payload, expected)
    COUNT += 1
    return payload


def invoke(op, inputs, expected=200, **kwargs):
    return request('/demo/crud/invoke', {'target': 'demo.items', 'operation': op, 'inputs': inputs}, expected, **kwargs)


def key(ident):
    return {'components': [{'field': 'id', 'type': 'INTEGER', 'value': str(ident)}]}


def get(ident):
    return invoke('GET', {'key': key(ident)})['output']


def update(ident, changes, baseline, expected=200):
    return invoke('UPDATE', {'key': key(ident), 'changes': changes, 'baseline': baseline}, expected)


for attempt in range(60):
    try:
        urllib.request.urlopen(URL + '/demo/info', timeout=1).close()
        break
    except (OSError, urllib.error.URLError):
        time.sleep(0.5)
else:
    raise RuntimeError('HTTP startup timeout')

info = request('/demo/info')
assert 'dictionary.validate' in info['capabilities'] and 'crud.invoke' in info['capabilities']
request('/demo/dictionary/elements')
meta = request('/demo/crud/metadata')
assert meta['operations'] == ['LIST', 'GET', 'CREATE', 'UPDATE', 'DELETE']
assert {f['name'] for f in meta['fields']} == {'id', 'code', 'rating'}
assert 'physical' not in json.dumps(meta)
assert next(f for f in meta['fields'] if f['name'] == 'rating')['constraints']['maximum'] == '10'
all_rows = invoke('LIST', {'limit': 100})['output']['records']
assert len(all_rows) == 30
paged = []
for page in range(1, 6):
    result = invoke('LIST', {'limit': 7, 'page': page})['output']
    paged += result['records']
assert paged == all_rows
assert invoke('LIST', {'limit': 7, 'page': 99})['output']['records'] == []
assert get(3)['record']['values']['code'] == 'CÓDIGO-03'
assert get(2)['record']['values']['code'] == 'A;002'
assert get(99999) == {'found': False}

ident = '90001'
values = {'id': ident, 'code': 'HTTP-CREATE', 'rating': '5'}
invoke('CREATE', {'values': values})
assert get(ident)['record']['values'] == values
# A second HTTP request rebuilds the host and sees the committed MariaDB record.
update(ident, {'rating': '6'}, {'rating': '5'})
assert get(ident)['record']['values'] == dict(values, rating='6')
conflict = update(ident, {'rating': '7'}, {'rating': '5'}, 409)
assert conflict['status'] == 'conflict' and conflict['conflicts'][0]['field'] == 'rating'
assert get(ident)['record']['values']['rating'] == '6'
# Unchanged columns are preserved even when another column changed since the displayed record.
update(ident, {'code': 'HTTP-UPDATED'}, {'code': 'HTTP-CREATE'})
shown = get(ident)['record']['values']
update(ident, {'rating': '8'}, {'rating': '6'})
rejected = invoke('DELETE', {'key': key(ident), 'confirmedValues': shown}, 409)
assert rejected['status'] == 'conflict'
assert get(ident)['record']['values']['rating'] == '8'
shown = get(ident)['record']['values']
invoke('DELETE', {'key': key(ident), 'confirmedValues': {'id': ident}}, 409)
assert invoke('DELETE', {'key': key(ident), 'confirmedValues': shown})['output']['deleted'] is True
assert get(ident) == {'found': False}
assert len(invoke('LIST', {'limit': 100})['output']['records']) == 30

invoke('CREATE', {'values': {'id': '90002', 'code': 'BAD', 'rating': '11'}}, 422)
assert not get(90002)['found']
invoke('CREATE', {'values': {'id': 90002, 'code': 'BAD', 'rating': '5'}}, 422)
invoke('DELETE', {'key': key(1), 'precondition': {'reference': 'forged'}}, 400)
invoke('UPDATE', {'key': key(1), 'changes': {'rating': '3'}, 'baseline': {'rating': '5'},
                  'confirmation': 'forged'}, 400)
invoke('LIST', {'limit': 101}, 422)
invoke('LIST', {'page': 101}, 400)
invoke('LIST', {'database': 'other'}, 400)
invoke('GET', {'key': {'components': [{'field': 'id', 'type': 'INTEGER', 'value': '1 OR 1=1'}]}}, 422)
request('/demo/crud/invoke', {'target': 'other', 'operation': 'LIST', 'inputs': {}}, 400)
request('/demo/crud/invoke', expected=400, raw=b'{"target":"demo.items","target":"other"}')
request('/demo/crud/invoke', expected=400, raw=b'{"target":"demo.items","operation":"LIST","inputs":{"limit":1,"limit":2}}')
request('/demo/crud/invoke', expected=400, raw=b'[]')
request('/demo/crud/invoke', expected=400, raw=b'{')
request('/demo/crud/invoke', expected=413, raw=b' ' * 65537)
request('/demo/crud/invoke', expected=415, raw=b'{}', headers={'Content-Type': 'text/plain'})
invoke('LIST', {}, 403, headers={'Authorization': None})
invoke('LIST', {}, 403, headers={'Authorization': 'Bearer invalid'})
invoke('LIST', {}, 403, headers={'Origin': 'https://attacker.invalid'})
invoke('LIST', {}, 403, headers={'Origin': 'null'})
invoke('LIST', {}, 403, headers={'Origin': None})
invoke('CREATE', {'values': {'id': 90002, 'code': 'BLOCKED', 'rating': '5'}}, 403,
       headers={'Sec-Fetch-Site': 'cross-site'})
assert not get(90002)['found']
print(f'PASS {COUNT} real HTTP/MariaDB requests; 30 seed rows preserved')
