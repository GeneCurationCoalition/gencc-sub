"""Local-only deployment checks; never connect to an inventory host.

Requires ansible-core (including Jinja2/PyYAML). Optional container tests use
already cached images, override their entrypoints, and never pull images.
See ../PHP-INTERIM-MITIGATION.md for invocation and limitations.
"""
import http.client
from itertools import product
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import socket
import ssl
import struct
import subprocess
import sys
import tempfile
import time
import unittest

import jinja2
import yaml
from ansible.plugins.filter.core import to_bool

ROOT = Path(__file__).resolve().parents[1]
FILES = ROOT / 'roles/quadlet/files'
DEFAULTS = yaml.safe_load((ROOT / 'inventories/group_vars/all/vars.yml').read_text())


def context(environment):
    return DEFAULTS | yaml.safe_load(
        (ROOT / f'inventories/group_vars/{environment}/vars.yml').read_text())


def render(role, name, values):
    env = jinja2.Environment(loader=jinja2.FileSystemLoader(ROOT / f'roles/{role}/templates'),
                             undefined=jinja2.StrictUndefined, trim_blocks=True)
    env.filters['bool'] = to_bool
    return env.get_template(name).render(values)


def command(*args):
    result = subprocess.run(args, capture_output=True, text=True, timeout=45)
    if result.returncode:
        raise AssertionError(f'{args[:3]} failed ({result.returncode}): {result.stderr}')
    return result.stdout.strip()


class Templates(unittest.TestCase):
    def test_php_preflight_precedes_downtime_and_active_configuration(self):
        prepare = yaml.safe_load((ROOT / 'roles/deployment_prepare/tasks/main.yml').read_text())
        self.assertEqual(prepare[0]['ansible.builtin.import_role'],
                         {'name': 'quadlet', 'tasks_from': 'php_incident_preflight'})
        install = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_prepare.yml').read_text())
        self.assertEqual(install[0]['ansible.builtin.include_tasks'], 'php_incident_preflight.yml')
        preflight = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_preflight.yml').read_text())
        self.assertEqual(preflight[-1]['always'][0]['ansible.builtin.file']['state'], 'absent')

    def test_deployment_assertions_accept_maintenance_and_restored_routes(self):
        tasks = yaml.safe_load((ROOT / 'roles/nginx_tls/tasks/php_incident_validate.yml').read_text())
        tasks += [task for task in yaml.safe_load((ROOT / 'roles/nginx_tls/tasks/main.yml').read_text())
                  if task['name'] == 'Assert hardened HTTPS nginx config is rendered']
        env = jinja2.Environment()
        env.filters['bool'] = to_bool
        for offline in [True, False]:
            values = context('production') | {'gencc_php_incident_portal_offline': offline}
            config = render('nginx_tls', 'gencc-https.conf.j2', values)
            values['gencc_nginx_rendered_config'] = {'stdout': config}
            for task in tasks:
                if task['name'] == 'Require static portal maintenance with no portal upstream' and not offline:
                    continue
                for expression in task['ansible.builtin.assert']['that']:
                    self.assertTrue(env.compile_expression(expression)(**values), expression)

    def test_incident_edge_entrypoint_excludes_application_deployment(self):
        play = yaml.safe_load((ROOT / 'playbooks/php-incident-edge.yml').read_text())[0]
        self.assertEqual(play['roles'], ['nginx_tls'])
        self.assertFalse(play.get('tasks') or play.get('pre_tasks') or play.get('post_tasks'))

    def test_portal_offline_removes_every_upstream_and_can_be_reversed(self):
        for environment in ['staging', 'production']:
            values = context(environment)
            self.assertTrue(values['gencc_php_incident_portal_offline'])
            for template in ['gencc-http.conf.j2', 'gencc-https.conf.j2']:
                for offline in [True, False]:
                    with self.subTest(environment=environment, template=template, offline=offline):
                        config = render('nginx_tls', template, values | {'gencc_php_incident_portal_offline': offline})
                        self.assertEqual('proxy_pass http://127.0.0.1:8080;' not in config, offline)
                        self.assertIn('proxy_pass http://127.0.0.1:8081;', config)
                        self.assertEqual('return 503' in config, offline)
                        if offline:
                            self.assertIn('default_type text/plain;', config)
                            self.assertIn('https://www.php.net/archive/2026.php#2026-10-21-5', config)

    def test_every_http_tls_and_redirect_server_rejects_multipart_first(self):
        for environment in ['staging', 'production']:
            values = context(environment)
            # Also exercise the optional portal redirect, absent in inventories.
            values['gencc_submit_hostname_redirects'] = ['old-portal.example.test']
            for name in ['gencc-http.conf.j2', 'gencc-https.conf.j2']:
                with self.subTest(environment=environment, template=name):
                    config = render('nginx_tls', name, values)
                    servers = re.split(r'(?m)^server \{', config)[1:]
                    self.assertGreaterEqual(len(servers), 2)
                    for server in servers:
                        self.assertRegex(server, r'^\s*#.*\n\s*if \(\$http_content_type ~\* "multipart/form-data"\) \{\s*return 415;')
                    self.assertIn('client_max_body_size 8m;', config)
                    self.assertIn('client_max_body_size 1m;', config)

    def test_recreated_containers_get_both_readonly_configs_and_finite_caps(self):
        for environment in ['staging', 'production']:
            values = context(environment)
            for app in ['sub', 'search']:
                config = render('quadlet', f'gencc-{app}.container.j2', values)
                self.assertIn(f'PodmanArgs=--memory={values[f"gencc_{app}_memory_limit_mb"]}m', config)
                for filename, destination in [('zz-security-hardening.ini', 'php/conf.d'),
                                               ('zz-security-hardening.conf', 'php-fpm.d')]:
                    self.assertIn(f'Volume={values["gencc_php_config_dir"]}/{filename}:/usr/local/etc/{destination}/{filename}:ro', config)

    def test_memory_budget_guard_accepts_vm_and_rejects_unbounded_or_oversized_caps(self):
        task = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_preflight.yml').read_text())[0]
        env = jinja2.Environment()
        conditions = [env.compile_expression(c) for c in task['ansible.builtin.assert']['that']]
        values = DEFAULTS | {'ansible_facts': {'memtotal_mb': 7937}}
        self.assertTrue(all(c(**values) for c in conditions))
        for overrides in [{'gencc_sub_memory_limit_mb': 0}, {'gencc_search_memory_limit_mb': -1},
                          {'gencc_host_memory_reserve_mb': 0}, {'gencc_sub_memory_limit_mb': 7000},
                          {'ansible_facts': {'memtotal_mb': 4096}}]:
            self.assertFalse(all(c(**(values | overrides)) for c in conditions))


class ContainerTests(unittest.TestCase):
    def setUp(self):
        # Under the workspace so Podman-machine can share it on macOS.
        self.temp = tempfile.TemporaryDirectory(prefix='.php-mitigation-test-', dir=ROOT)
        self.directory = Path(self.temp.name)
        self.directory.chmod(0o755)
        self.containers = []

    def tearDown(self):
        for cid in self.containers:
            command('podman', 'rm', '-f', cid)
        self.temp.cleanup()

    def start(self, image, entrypoint, args, ports):
        cmd = ['podman', 'run', '-d', '--pull=never', '--memory=512m', '--entrypoint', entrypoint,
               '-v', f'{self.directory}:/test:ro']
        for port in ports:
            cmd += ['-p', f'127.0.0.1::{port}']
        for name, destination in [('zz-security-hardening.ini', 'php/conf.d'),
                                  ('zz-security-hardening.conf', 'php-fpm.d')]:
            cmd += ['-v', f'{FILES / name}:/usr/local/etc/{destination}/{name}:ro']
        cid = command(*cmd, image, *args)
        self.containers.append(cid)
        result = {}
        for port in ports:
            address = command('podman', 'port', cid, f'{port}/tcp')
            result[port] = int(address.rsplit(':', 1)[1])
        return cid, result

    def wait(self, port):
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=1):
                    return
            except OSError:
                time.sleep(0.1)
        self.fail('Local container did not start listening')

    @unittest.skipUnless(os.environ.get('GENCC_PHP_TEST_IMAGES'), 'set GENCC_PHP_TEST_IMAGES for preflight tests')
    def test_deployment_preflight_checks_candidates_and_rejects_bad_pool(self):
        tasks = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_preflight.yml').read_text())
        shell = next(task['ansible.builtin.shell'] for task in tasks[-1]['block']
                     if task['name'].startswith('Preflight candidate'))
        if sys.platform == 'darwin':
            # The remote macOS client uses its own socket; Linux deployment needs XDG.
            shell = shell.replace('export XDG_RUNTIME_DIR="/run/user/$(id -u)"\n', '')
        env = jinja2.Environment(undefined=jinja2.StrictUndefined, trim_blocks=True)
        env.filters['quote'] = shlex.quote
        for name in ['zz-security-hardening.ini', 'zz-security-hardening.conf',
                     'zz-security-search-pool.conf', 'php-incident-verify.php']:
            shutil.copy(FILES / name, self.directory / name)
        for image in os.environ['GENCC_PHP_TEST_IMAGES'].split(','):
            for app, children in [('gencc-sub', 0), ('gencc-search', 25)]:
                with self.subTest(image=image, app=app):
                    rendered = env.from_string(shell).render(
                        gencc_php_incident_candidate={'path': str(self.directory)},
                        item={'name': app, 'image': image, 'memory_mb': 512, 'max_children': children})
                    self.assertIn('Verified container memory cap', command('bash', '-c', rendered))
            original = (self.directory / 'zz-security-hardening.conf').read_text()
            try:
                (self.directory / 'zz-security-hardening.conf').write_text(original + '\ninvalid_fpm_directive = yes\n')
                result = subprocess.run(['bash', '-c', rendered], capture_output=True, text=True, timeout=45)
                self.assertNotEqual(result.returncode, 0, 'Invalid pool configuration passed preflight')
            finally:
                (self.directory / 'zz-security-hardening.conf').write_text(original)

    def verify_deployment_probe(self, cid, expected_memory='536870912', expected_children='0'):
        return subprocess.run(['podman', 'exec', '-i', cid, 'php', '/dev/stdin',
                               expected_memory, expected_children],
                              input=(FILES / 'php-incident-verify.php').read_text(),
                              capture_output=True, text=True, timeout=45)

    @unittest.skipUnless(os.environ.get('GENCC_PHP_TEST_IMAGES'), 'set GENCC_PHP_TEST_IMAGES for local FPM tests')
    def test_live_fpm_admin_settings_worker_recycling_and_restart(self):
        (self.directory / 'probe.php').write_text('''<?php
$before = ini_get('memory_limit');
$override = ini_set('memory_limit', '32M');
header('Content-Type: application/json');
echo json_encode(['pid' => getmypid(), 'version' => PHP_VERSION,
 'before' => $before, 'after' => ini_get('memory_limit'), 'override' => $override,
 'progress' => ini_get('session.upload_progress.enabled'),
 'post' => ini_get('post_max_size'), 'upload' => ini_get('upload_max_filesize'),
 'nesting' => ini_get('max_input_nesting_level')]);
''')
        for image in os.environ['GENCC_PHP_TEST_IMAGES'].split(','):
            with self.subTest(image=image):
                cid, ports = self.start(image, 'php-fpm', ['-F'], [9000])
                for cycle in range(2):
                    if cycle:
                        command('podman', 'restart', cid)
                    self.wait(ports[9000])
                    self.assertEqual(command('podman', 'exec', cid, 'cat', '/sys/fs/cgroup/memory.max'), '536870912')
                    verified = self.verify_deployment_probe(cid)
                    self.assertEqual(verified.returncode, 0, verified.stderr)
                    rejected = self.verify_deployment_probe(cid, expected_memory='1073741824')
                    self.assertNotEqual(rejected.returncode, 0)
                    self.assertIn('memory.max', rejected.stderr)
                    rejected = self.verify_deployment_probe(cid, expected_children='999')
                    self.assertNotEqual(rejected.returncode, 0)
                    self.assertIn('pm.max_children', rejected.stderr)
                    responses = [fastcgi(ports[9000]) for _ in range(4)]
                    self.assertEqual(len({r['pid'] for r in responses}), 4)
                    for r in responses:
                        self.assertEqual((r['before'], r['after'], r['override']), ('-1', '-1', False))
                        self.assertIn(r['progress'], ['', '0'])
                        self.assertEqual((r['post'], r['upload'], r['nesting']), ('8M', '2M', '32'))
                # Conflicting pool settings can affect FPM while CLI INI is unchanged.
                # PHP admin values in these runtimes are applied in reverse include order.
                command('podman', 'exec', cid, 'sh', '-c',
                        "printf '%s\n' '[www]' 'php_admin_value[memory_limit] = 64M' > /usr/local/etc/php-fpm.d/00-test.conf")
                command('podman', 'restart', cid)
                self.wait(ports[9000])
                rejected = self.verify_deployment_probe(cid)
                self.assertNotEqual(rejected.returncode, 0)
                self.assertIn('Live FPM memory_limit', rejected.stderr)
                command('podman', 'exec', cid, 'sh', '-c',
                        "rm /usr/local/etc/php-fpm.d/00-test.conf; printf '%s\n' '[www]' 'pm.max_requests = 2' > /usr/local/etc/php-fpm.d/zzzz-test.conf")
                command('podman', 'restart', cid)
                self.wait(ports[9000])
                rejected = self.verify_deployment_probe(cid)
                self.assertNotEqual(rejected.returncode, 0)
                self.assertIn('pm.max_requests', rejected.stderr)
                self.assertEqual(command('podman', 'exec', cid, 'sh', '-c',
                                         "find /tmp -maxdepth 1 -name 'gencc-php-check-*' -print"), '')

    @unittest.skipUnless(os.environ.get('GENCC_NGINX_TEST_IMAGE'), 'set GENCC_NGINX_TEST_IMAGE for local HTTP tests')
    def test_nginx_bootstrap_tls_redirects_and_chunked_body_limit(self):
        command('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-subj', '/CN=localhost', '-keyout', str(self.directory / 'privkey.pem'),
                '-out', str(self.directory / 'fullchain.pem'))
        values = context('production') | {'gencc_submit_hostname_redirects': ['old-portal.example.test']}
        for name in ['gencc-security.conf', 'gencc-ip-blocklist.conf', 'gencc-proxy-params.conf']:
            (self.directory / name).write_text(render('nginx_tls', name + '.j2', values))
        acme = self.directory / 'acme/.well-known/acme-challenge'
        acme.mkdir(parents=True)
        (acme / 'test').write_text('challenge')
        for template, offline in product(['gencc-http.conf.j2', 'gencc-https.conf.j2'], [False, True]):
            values['gencc_php_incident_portal_offline'] = offline
            config = render('nginx_tls', template, values)
            config = config.replace('/etc/nginx/snippets/', '/test/')
            config = config.replace('/etc/letsencrypt/live/gencc-prod/', '/test/')
            config = config.replace('/var/www/acme-challenge', '/test/acme')
            (self.directory / 'nginx.conf').write_text('events {}\nhttp {\ninclude /test/gencc-security.conf;\n' + config +
                '\nserver { listen 8080; location / { return 200 "upstream"; } }\n' +
                'server { listen 8081; location / { return 200 "upstream"; } }\n}\n')
            cid, ports = self.start(os.environ['GENCC_NGINX_TEST_IMAGE'], 'nginx',
                                   ['-c', '/test/nginx.conf', '-g', 'daemon off;'], [80, 443])
            self.wait(ports[80])
            hosts = [values['gencc_submit_hostname'], values['gencc_search_hostname'], 'search.thegencc.org']
            if template == 'gencc-https.conf.j2':
                hosts += ['old-portal.example.test']
            for host in hosts:
                protocols = [False, True] if template == 'gencc-https.conf.j2' else [False]
                for tls in protocols:
                    for content_type in ['multipart/form-data; boundary=test', 'MuLtIpArT/FoRm-DaTa']:
                        self.assertEqual(http_status(ports, host, tls, content_type=content_type), 415)
            tls = template == 'gencc-https.conf.j2'
            self.assertEqual(http_status(ports, hosts[0], tls, content_type='application/json'), 503 if offline else 200)
            self.assertEqual(http_status(ports, hosts[1], tls, content_type='application/json'), 200)
            if offline:
                for path in ['/', '/login', '/api/submit', '/api/pubmed', '/index.php', '/-/healthz']:
                    status, headers, body = http_status(ports, hosts[0], tls, path=path, details=True)
                    self.assertEqual(status, 503)
                    self.assertEqual(headers['Content-Type'], 'text/plain')
                    self.assertEqual(headers['Cache-Control'], 'no-store')
                    self.assertIn('temporarily down for maintenance', body)
                    self.assertIn('https://www.php.net/archive/2026.php#2026-10-21-5', body)
                    if tls:
                        self.assertIn('Strict-Transport-Security', headers)
            self.assertEqual(http_status(ports, hosts[0], False, path='/.well-known/acme-challenge/test'), 200)
            self.assertEqual(http_status(ports, hosts[0], tls, oversized_length=8*1024*1024+1), 413)
            self.assertEqual(http_status(ports, hosts[1], tls, chunked=True), 413)
            if tls:
                self.assertEqual(http_status(ports, hosts[0], False), 301)
                self.assertEqual(http_status(ports, 'search.thegencc.org', True), 301)
            command('podman', 'rm', '-f', cid)
            self.containers.remove(cid)


def fastcgi(port):
    def record(kind, data):
        return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data
    params = {'SCRIPT_FILENAME': '/test/probe.php', 'REQUEST_METHOD': 'GET',
              'SERVER_PROTOCOL': 'HTTP/1.1', 'REDIRECT_STATUS': '200'}
    encoded = b''.join(bytes([len(k), len(v)]) + k.encode() + v.encode() for k, v in params.items())
    request = record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded) + record(4, b'') + record(5, b'')
    output = b''
    with socket.create_connection(('127.0.0.1', port), timeout=10) as connection:
        connection.sendall(request)
        def read(size):
            data = b''
            while len(data) < size:
                more = connection.recv(size - len(data))
                if not more:
                    raise AssertionError('Unexpected FastCGI EOF')
                data += more
            return data
        while True:
            _, kind, _, length, padding, _ = struct.unpack('!BBHHBB', read(8))
            content = read(length)
            read(padding)
            if kind == 6:
                output += content
            if kind == 3:
                break
    return json.loads(output.split(b'\r\n\r\n', 1)[1])


def http_status(ports, host, tls, content_type=None, path='/-/healthz', oversized_length=None, chunked=False, details=False):
    if tls:
        connection = http.client.HTTPSConnection('127.0.0.1', ports[443], context=ssl._create_unverified_context(), timeout=10)
    else:
        connection = http.client.HTTPConnection('127.0.0.1', ports[80], timeout=10)
    headers = {'Host': host, 'User-Agent': 'GenCC-local-mitigation-test'}
    try:
        if oversized_length:
            connection.putrequest('POST', path, skip_host=True)
            for k, v in headers.items():
                connection.putheader(k, v)
            connection.putheader('Content-Length', str(oversized_length))
            connection.endheaders()
        elif chunked:
            connection.request('POST', path, body=iter([b'x' * 16384] * 65), headers=headers, encode_chunked=True)
        else:
            if content_type:
                headers['Content-Type'] = content_type
            connection.request('POST' if content_type else 'GET', path, body=b'{}' if content_type else None, headers=headers)
        response = connection.getresponse()
        if details:
            return response.status, dict(response.getheaders()), response.read().decode()
        return response.status
    finally:
        connection.close()


if __name__ == '__main__':
    unittest.main(verbosity=2)
