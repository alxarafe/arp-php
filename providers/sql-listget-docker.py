#!/usr/bin/env python3
"""Optional owned MariaDB 12.3 LIST/GET validation, using existing images and original pinned fixtures."""
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
    parser.add_argument('--contract', required=True, type=Path)
    parser.add_argument('--listget-contract', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--php-image', default='arp-cds-read-003-php-test:local')
    args = parser.parse_args()
    source = Path(__file__).resolve().parents[1]
    output = args.output.resolve()
    auxiliary = Path.home() / 'Desarrollo/tmp'
    assert output.is_relative_to(auxiliary), 'Auxiliary output must be under ~/Desarrollo/tmp'
    output.mkdir(parents=True, exist_ok=True)
    mounts = []
    for path, pin, destination in [(args.contract, 'contract.ref', '/contract'),
                                   (args.listget_contract, 'listget-contract.ref', '/listget-contract')]:
        path = path.resolve()
        actual = run('git', '-C', str(path), 'rev-parse', 'HEAD').stdout.strip()
        assert actual == (source / pin).read_text().strip(), 'Wrong contract pin: ' + pin
        assert not run('git', '-C', str(path), 'status', '--porcelain').stdout.strip(), 'Dirty contract'
        mounts += ['--mount', f'type=bind,src={path},dst={destination},readonly']
    # No builds/downloads; source and contracts are read-only. Composer autoload must already be generated.
    docker('image', 'inspect', args.php_image)
    image = 'mariadb:12.3'
    image_id = json.loads(docker('image', 'inspect', image))[0]['Id']
    before = docker('ps', '-aq').split()
    token = secrets.token_hex(8)
    password = secrets.token_hex(20)
    label = 'arp.sql.php.001.run'
    owned = []
    network = None
    report = {'completed': False, 'mariadbImage': image_id, 'phpImage': args.php_image}
    try:
        network = docker('network', 'create', '--label', label + '=' + token, 'arp-sql-php-' + token)
        db = docker('create', '--label', label + '=' + token, '--network', network, '--network-alias', 'db',
                    '-e', 'MARIADB_DATABASE=arpcds', '-e', 'MARIADB_ROOT_PASSWORD=' + password, image)
        owned.append(db)
        docker('start', db)
        for _ in range(100):
            ready = run('docker', 'exec', db, 'healthcheck.sh', '--connect', '--innodb_initialized', check=False)
            if ready.returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Owned database readiness timeout')
        docker('exec', '-e', 'MYSQL_PWD=' + password, db, 'mariadb', '-uroot', 'arpcds', '-e',
               "CREATE TABLE __arp_cds_test_owner(token varchar(32)); INSERT INTO __arp_cds_test_owner VALUES ('"
               + token + "')")
        store = output / ('physical-store-' + token)
        store.mkdir()
        phases = [('existing-observation', ['php', 'providers/mariadb/tests/integration.php']),
                  ('persistence-seed', ['php', 'providers/mariadb/tests/persistence.php', 'seed']),
                  ('persistence-recover', ['php', 'providers/mariadb/tests/persistence.php', 'recover']),
                  ('listget', ['php', 'providers/mariadb/tests/listget.php'])]
        for phase, command in phases:
            container = docker('create', '--label', label + '=' + token, '--network', network,
                               '--user', f'{os.getuid()}:{os.getgid()}',
                               '--mount', f'type=bind,src={source},dst=/app,readonly',
                               '--mount', f'type=bind,src={output},dst=/reports', *mounts,
                               '-e', 'ARP_CONTRACT_PATH=/contract', '-e', 'ARP_LISTGET_CONTRACT_PATH=/listget-contract',
                               '-e', 'ARP_DB_PASSWORD=' + password, '-e', 'ARP_CDS_RUN_TOKEN=' + token,
                               '-e', 'ARP_PHYSICAL_STORE=/reports/' + store.name,
                               '-w', '/app', args.php_image, *command)
            owned.append(container)
            result = run('docker', 'start', '-a', container, check=False)
            (output / (phase + '.log')).write_text(result.stdout.replace(password, '[redacted]'))
            status = json.loads(docker('inspect', container))[0]['State']['ExitCode']
            print(result.stdout, end='')
            assert status == 0, phase + ' failed; see phase log'
            assert json.loads(docker('inspect', container))[0]['Config']['Labels'].get(label) == token
            docker('rm', '-v', container)
            owned.remove(container)
        report['completed'] = True
    finally:
        for container in reversed(owned):
            details = json.loads(docker('inspect', container))[0]
            assert details['Config']['Labels'].get(label) == token, 'Ownership lost'
            docker('rm', '-fv', container)
        if network:
            assert json.loads(docker('network', 'inspect', network))[0]['Labels'].get(label) == token
            docker('network', 'rm', network)
        after = docker('ps', '-aq').split()
        report['preexistingContainersPreserved'] = set(before).issubset(after)
        (output / 'isolation.json').write_text(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()