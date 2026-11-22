#!/usr/bin/env python3
"""Reproducible isolated PHP/MariaDB CRUD validation; never connects to an application database."""
import argparse
import json
import os
from pathlib import Path
import secrets
import subprocess
import time


def run(*args, check=True):
    return subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=check)


def docker(*args):
    return run('docker', *args).stdout.strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--contract', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--php-image', default='arp-cds-read-003-php-test:local')
    args = parser.parse_args()
    source = Path(__file__).resolve().parents[1]
    contract = args.contract.resolve()
    expected = (source / 'write-contract.ref').read_text().strip()
    assert run('git', '-C', str(contract), 'rev-parse', 'HEAD').stdout.strip() == expected, 'Wrong contract revision'
    assert not run('git', '-C', str(contract), 'status', '--porcelain').stdout.strip(), 'Dirty contract'
    output = args.output.resolve()
    assert not output.is_relative_to(source) and not source.is_relative_to(output), 'Reports must be outside source'
    output.mkdir(parents=True, exist_ok=True)
    assert not (output / 'crud-mariadb-report.json').exists(), 'Use a new report directory'
    token = secrets.token_hex(12)
    password = secrets.token_hex(24)
    label = 'arp.crud.run'
    owned = []
    network = None
    before = set(docker('ps', '-aq').split())
    report = {'completed': False, 'contract': expected, 'phpImage': args.php_image}
    try:
        report['mariadbImage'] = json.loads(docker('image', 'inspect', 'mariadb:12.3'))[0]['Id']
        report['phpImageId'] = json.loads(docker('image', 'inspect', args.php_image))[0]['Id']
        network = docker('network', 'create', '--label', label + '=' + token, 'arp-crud-' + token)
        database = docker('create', '--label', label + '=' + token, '--network', network, '--network-alias', 'db',
                          '-e', 'MARIADB_DATABASE=arpcds', '-e', 'MARIADB_ROOT_PASSWORD=' + password, 'mariadb:12.3')
        owned.append(database)
        docker('start', database)
        for _ in range(90):
            if run('docker', 'exec', database, 'healthcheck.sh', '--connect', '--innodb_initialized',
                   check=False).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Owned database readiness timeout')
        docker('exec', '-e', 'MYSQL_PWD=' + password, database, 'mariadb', '-uroot', 'arpcds', '-e',
               "CREATE TABLE __arp_crud_test_owner(token varchar(64)); INSERT INTO __arp_crud_test_owner VALUES ('"
               + token + "')")
        for phase, script in [('native', 'providers/tests/crud-mariadb.php'),
                              ('integer', 'providers/tests/integer-mariadb.php'),
                              ('seed', 'providers/tests/seed-mariadb.php')]:
            container = docker('create', '--label', label + '=' + token, '--network', network,
                               '--user', f'{os.getuid()}:{os.getgid()}',
                               '--mount', f'type=bind,src={source},dst=/app,readonly',
                               '--mount', f'type=bind,src={contract},dst=/write-contract,readonly',
                               '--mount', f'type=bind,src={output},dst=/reports',
                               '-e', 'ARP_WRITE_CONTRACT_PATH=/write-contract', '-e', 'ARP_DB_PASSWORD=' + password,
                               '-e', 'ARP_CRUD_RUN_TOKEN=' + token,
                               '-e', 'ARP_WRITE_REPORT=/reports/crud-mariadb-report.json',
                               '-e', 'ARP_INTEGER_REPORT=/reports/integer-mariadb-report.json',
                               '-w', '/app', args.php_image, 'php', script)
            owned.append(container)
            result = run('docker', 'start', '-a', container, check=False)
            (output / (phase + '.log')).write_text(result.stdout.replace(password, '[redacted]'))
            print(result.stdout, end='')
            assert json.loads(docker('inspect', container))[0]['State']['ExitCode'] == 0, 'Native validation failed'
        report['completed'] = True
    finally:
        for container in reversed(owned):
            assert json.loads(docker('inspect', container))[0]['Config']['Labels'].get(label) == token
            docker('rm', '-fv', container)
        if network:
            assert json.loads(docker('network', 'inspect', network))[0]['Labels'].get(label) == token
            docker('network', 'rm', network)
        report['preexistingContainersPreserved'] = before.issubset(set(docker('ps', '-aq').split()))
        (output / 'isolation.json').write_text(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()