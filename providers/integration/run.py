#!/usr/bin/env python3
"""Owned optional PDO integrations. Requires existing engine images and an already prepared PHP image."""
import argparse
import json
import os
from pathlib import Path
import secrets
import subprocess


def run(*args, check=True):
    return subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, check=check)


def docker(*args):
    return run('docker', *args).stdout.strip()


def inventory():
    ids = docker('ps', '-aq').split()
    return {c['Id']: (c['State']['Running'], c['State']['StartedAt'], c['RestartCount'])
            for c in json.loads(docker('inspect', *ids))} if ids else {}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--engine', required=True, choices=['postgresql', 'mysql', 'mariadb', 'sqlserver'])
    parser.add_argument('--contract', required=True, type=Path)
    parser.add_argument('--listget-contract', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--php-image', default='arp-sql-php-002-test:local')
    args = parser.parse_args()
    source = Path(__file__).resolve().parents[2]
    output = args.output.resolve()
    assert output.is_relative_to(Path.home() / 'Desarrollo/tmp'), 'Auxiliary output must be under ~/Desarrollo/tmp'
    output.mkdir(parents=True, exist_ok=True)
    mounts = []
    for path, pin, destination in [(args.contract, 'contract.ref', '/contract'),
                                   (args.listget_contract, 'listget-contract.ref', '/listget-contract')]:
        path = path.resolve()
        assert run('git', '-C', str(path), 'rev-parse', 'HEAD').stdout.strip() == (source / pin).read_text().strip()
        assert not run('git', '-C', str(path), 'status', '--porcelain').stdout.strip(), 'Dirty contract'
        mounts += ['--mount', f'type=bind,src={path},dst={destination},readonly']
    images = {'postgresql': 'postgres:18.6', 'mysql': 'mysql:8.4', 'mariadb': 'mariadb:12.3',
              'sqlserver': 'mcr.microsoft.com/mssql/server:2022-latest'}
    engine_image = docker('image', 'inspect', images[args.engine], '--format', '{{.Id}}')
    php_image = docker('image', 'inspect', args.php_image, '--format', '{{.Id}}')
    token = secrets.token_hex(8)
    password = 'Arp!' + secrets.token_hex(20)
    label = 'arp.sql.php.002.run'
    before = inventory()
    volumes = set(docker('volume', 'ls', '-q').split())
    owned = []
    network = None
    report = {'engine': args.engine, 'databaseImage': engine_image, 'phpImage': php_image, 'completed': False}
    try:
        network = docker('network', 'create', '--label', label + '=' + token, 'arp-sql-php-002-' + token)
        environment = {
            'postgresql': ['POSTGRES_USER=arp', 'POSTGRES_DB=arpcds', 'POSTGRES_PASSWORD=' + password],
            'mysql': ['MYSQL_DATABASE=arpcds', 'MYSQL_ROOT_PASSWORD=' + password],
            'mariadb': ['MARIADB_DATABASE=arpcds', 'MARIADB_ROOT_PASSWORD=' + password],
            'sqlserver': ['ACCEPT_EULA=Y', 'MSSQL_PID=Developer', 'MSSQL_SA_PASSWORD=' + password],
        }[args.engine]
        options = ['create', '--label', label + '=' + token, '--network', network, '--network-alias', 'db']
        for value in environment:
            options += ['-e', value]
        db = docker(*options, engine_image)
        owned.append(db)
        docker('start', db)
        for phase, command in [('bootstrap', 'providers/integration/bootstrap.php'),
                               ('listget', 'providers/tests/sql-listget.php')]:
            runner = docker('create', '--label', label + '=' + token, '--network', network,
                            '--user', f'{os.getuid()}:{os.getgid()}',
                            '--mount', f'type=bind,src={source},dst=/app,readonly',
                            '--mount', f'type=bind,src={output},dst=/reports', *mounts,
                            '-e', 'ARP_CONTRACT_PATH=/contract', '-e', 'ARP_LISTGET_CONTRACT_PATH=/listget-contract',
                            '-e', 'ARP_SQL_ENGINE=' + args.engine, '-e', 'ARP_DB_PASSWORD=' + password,
                            '-e', 'ARP_CDS_RUN_TOKEN=' + token, '-w', '/app', php_image, 'php', command)
            owned.append(runner)
            result = run('docker', 'start', '-a', runner, check=False)
            log = result.stdout.replace(password, '[redacted]')
            (output / (phase + '.log')).write_text(log)
            print(log, end='')
            assert json.loads(docker('inspect', runner))[0]['State']['ExitCode'] == 0, phase + ' failed'
            assert json.loads(docker('inspect', runner))[0]['Config']['Labels'].get(label) == token
            docker('rm', '-v', runner)
            owned.remove(runner)
        report['completed'] = True
    finally:
        for container in reversed(owned):
            assert json.loads(docker('inspect', container))[0]['Config']['Labels'].get(label) == token
            docker('rm', '-fv', container)
        if network:
            assert json.loads(docker('network', 'inspect', network))[0]['Labels'].get(label) == token
            docker('network', 'rm', network)
        after = inventory()
        report['preexistingContainersPreserved'] = all(after.get(k) == v for k, v in before.items())
        report['preexistingVolumesPreserved'] = volumes.issubset(set(docker('volume', 'ls', '-q').split()))
        (output / 'isolation.json').write_text(json.dumps(report, indent=2))
        assert report['preexistingContainersPreserved'] and report['preexistingVolumesPreserved']


if __name__ == '__main__':
    main()