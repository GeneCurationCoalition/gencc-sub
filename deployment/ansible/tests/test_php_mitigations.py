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
        for environment, offline in product(['staging', 'production'], [True, False]):
            values = context(environment) | {'gencc_php_incident_portal_offline': offline}
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

    def test_edge_reopening_requires_patched_live_portal_before_any_nginx_writes(self):
        task = yaml.safe_load((ROOT / 'roles/nginx_tls/tasks/main.yml').read_text())[0]
        self.assertEqual(task['name'], 'Require patched live portal before reopening nginx routes')
        self.assertIn('podman exec -i gencc-sub php /dev/stdin', task['ansible.builtin.shell'])
        self.assertIn('/quadlet/files/php-patched-version.php', task['args']['stdin'])
        env = jinja2.Environment()
        env.filters['bool'] = to_bool
        for offline, mitigations in product([True, False], repeat=2):
            values = {'gencc_php_incident_portal_offline': offline,
                      'gencc_php_incident_portal_mitigations': mitigations}
            checks = [env.compile_expression(expression)(**values) for expression in task['when']]
            self.assertEqual(all(checks), not offline and not mitigations)

    def test_portal_offline_removes_every_upstream_and_can_be_reversed(self):
        for environment in ['staging', 'production']:
            values = context(environment)
            self.assertEqual(values['gencc_php_incident_portal_offline'], environment == 'production')
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
            values = context(environment) | {'gencc_php_incident_portal_mitigations': True}
            # Also exercise the optional portal redirect, absent in inventories.
            values['gencc_submit_hostname_redirects'] = ['old-portal.example.test']
            for name in ['gencc-http.conf.j2', 'gencc-https.conf.j2']:
                with self.subTest(environment=environment, template=name):
                    config = render('nginx_tls', name, values)
                    servers = re.split(r'(?m)^server \{', config)[1:]
                    self.assertGreaterEqual(len(servers), 2)
                    for server in servers:
                        self.assertRegex(server, r'^\s*(?:#[^\n]*\n\s*)*if \(\$http_content_type ~\* "multipart/form-data"\) \{\s*return 415;')
                    self.assertIn('client_max_body_size 8m;', config)
                    self.assertIn('client_max_body_size 1m;', config)

    def test_recreated_containers_get_both_readonly_configs_and_finite_caps(self):
        for environment in ['staging', 'production']:
            values = context(environment) | {'gencc_php_incident_portal_mitigations': True}
            for app in ['sub', 'search']:
                config = render('quadlet', f'gencc-{app}.container.j2', values)
                self.assertIn(f'PodmanArgs=--memory={values[f"gencc_{app}_memory_limit_mb"]}m', config)
                for filename, destination in [('zz-security-hardening.ini', 'php/conf.d'),
                                               ('zz-security-hardening.conf', 'php-fpm.d')]:
                    self.assertIn(f'Volume={values["gencc_php_config_dir"]}/{filename}:/usr/local/etc/{destination}/{filename}:ro', config)

    def test_staging_restores_only_patched_portal_and_production_stays_closed(self):
        for environment in ['staging', 'production']:
            values = context(environment) | {'gencc_submit_hostname_redirects': ['old-portal.example.test']}
            protected = environment == 'production'
            self.assertEqual(values['gencc_php_incident_portal_mitigations'], protected)
            self.assertEqual(values['gencc_php_incident_portal_offline'], protected)
            if not protected:
                self.assertTrue(values['gencc_sub_image'].endswith(':2.3.0'))
            sub = render('quadlet', 'gencc-sub.container.j2', values)
            self.assertEqual('PodmanArgs=--memory=' in sub, protected)
            self.assertEqual('zz-security-hardening.ini:' in sub, protected)
            self.assertEqual('zz-security-hardening.conf:' in sub, protected)
            self.assertNotIn('zz-security-search-pool.conf:', sub)
            search = render('quadlet', 'gencc-search.container.j2', values)
            self.assertIn('PodmanArgs=--memory=2048m', search)
            for filename in ['zz-security-hardening.ini', 'zz-security-hardening.conf', 'zz-security-search-pool.conf']:
                self.assertIn(filename + ':ro', search)
            for template in ['gencc-http.conf.j2', 'gencc-https.conf.j2']:
                config = render('nginx_tls', template, values)
                self.assertEqual('return 503' in config, protected)
                for server in re.split(r'(?m)^server \{', config)[1:]:
                    names = re.search(r'\bserver_name\s+([^;]+);', server).group(1).split()
                    portal = any(name in [values['gencc_submit_hostname'], 'old-portal.example.test'] for name in names)
                    with self.subTest(environment=environment, template=template, names=names):
                        self.assertEqual('return 415;' in server, protected if portal else True)
                        limit = ('8m' if protected else '100m') if portal else '1m'
                        self.assertIn(f'client_max_body_size {limit};', server)

    def test_portal_maintenance_can_precede_relaxing_patched_runtime_restrictions(self):
        values = context('staging') | {'gencc_php_incident_portal_offline': True}
        for template in ['gencc-http.conf.j2', 'gencc-https.conf.j2']:
            config = render('nginx_tls', template, values)
            self.assertIn('return 503', config)
            self.assertNotIn('proxy_pass http://127.0.0.1:8080;', config)
            self.assertIn('proxy_pass http://127.0.0.1:8081;', config)

    def test_memory_budget_guard_accepts_vm_and_rejects_unbounded_or_oversized_caps(self):
        task = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_preflight.yml').read_text())[0]
        env = jinja2.Environment()
        env.filters['bool'] = to_bool
        conditions = [env.compile_expression(c) for c in task['ansible.builtin.assert']['that']]
        values = DEFAULTS | {'ansible_facts': {'memtotal_mb': 7937}}
        self.assertTrue(all(c(**values) for c in conditions))
        for overrides in [{'gencc_sub_memory_limit_mb': 0}, {'gencc_search_memory_limit_mb': -1},
                          {'gencc_host_memory_reserve_mb': 0}, {'gencc_sub_memory_limit_mb': 7000},
                          {'ansible_facts': {'memtotal_mb': 4096}}]:
            self.assertFalse(all(c(**(values | overrides)) for c in conditions))

    def test_selected_portal_preflight_has_no_incident_mounts_or_cap(self):
        tasks = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_preflight.yml').read_text())
        selection = next(task for task in tasks if 'gencc_php_incident_applications' in task.get('vars', {}))
        env = jinja2.Environment(undefined=jinja2.StrictUndefined, trim_blocks=True)
        env.filters['bool'] = to_bool
        env.filters['quote'] = shlex.quote
        for environment in ['staging', 'production']:
            for selected in [['gencc-sub'], ['gencc-search'], ['gencc-sub', 'gencc-search']]:
                values = context(environment) | {'gencc_deploy_apps': selected}
                apps = [{key: env.from_string(value).render(values) if isinstance(value, str) else value
                         for key, value in app.items()}
                        for app in selection['vars']['gencc_php_incident_applications']]
                expression = selection['ansible.builtin.set_fact']['gencc_php_incident_targets'].strip()[2:-2]
                targets = env.compile_expression(expression)(**values, gencc_php_incident_applications=apps)
                self.assertEqual([item['name'] for item in targets], selected)
                shell = next(task['ansible.builtin.shell'] for task in tasks[-1]['block']
                             if task['name'].startswith('Preflight candidate'))
                for item in targets:
                    rendered = env.from_string(shell).render(values, item=item,
                        gencc_php_incident_candidate={'path': '/tmp/candidate'})
                    patched = item['name'] == 'gencc-sub' and environment == 'staging'
                    with self.subTest(environment=environment, selected=selected, app=item['name']):
                        self.assertEqual(item['mode'], 'patched' if patched else 'mitigated')
                        self.assertEqual('--memory=' in rendered, not patched)
                        self.assertEqual('zz-security-hardening.ini:' in rendered, not patched)
                        self.assertEqual('zz-security-hardening.conf:' in rendered, not patched)
                        if patched:
                            self.assertIn("-c 'php /dev/stdin && php-fpm -t'", rendered)
                            self.assertNotIn('podman exec', rendered)
        main = yaml.safe_load((ROOT / 'roles/quadlet/tasks/main.yml').read_text())
        restart = next(task for task in main if task['name'] == 'Start (or restart) quadlet services')
        self.assertEqual(restart['loop'], '{{ gencc_deploy_apps }}')
        verify = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_verify.yml').read_text())[0]
        self.assertEqual(verify['loop'], '{{ gencc_php_incident_targets }}')

    def test_runtime_only_rollout_preserves_environment_and_stays_in_maintenance(self):
        play = yaml.safe_load((ROOT / 'playbooks/portal-runtime.yml').read_text())[0]
        self.assertEqual(play['roles'], ['quadlet', 'nginx_tls'])
        self.assertEqual(play['vars']['gencc_deploy_apps'], ['gencc-sub'])
        self.assertFalse(play['vars']['gencc_deploy_write_env'])
        self.assertTrue(play['vars']['gencc_php_incident_portal_offline'])
        tasks = yaml.safe_load((ROOT / 'roles/quadlet/tasks/main.yml').read_text())
        write = next(task for task in tasks if task['name'] == 'Write application .env files from vault')
        self.assertIn('gencc_deploy_write_env | bool', write['when'])
        check = next(task for task in tasks if task['name'].startswith('Require existing nonempty'))
        self.assertEqual(check['when'], 'not (gencc_deploy_write_env | bool)')


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

    def start(self, image, args, ports):
        cmd = ['podman', 'run', '-d', '--pull=never', '--entrypoint=nginx', '--memory=512m',
               '-v', f'{self.directory}:/test:ro']
        for port in ports:
            cmd += ['-p', f'127.0.0.1::{port}']
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

    def preflight(self, image, app='gencc-sub', mode='mitigated', candidate=None):
        tasks = yaml.safe_load((ROOT / 'roles/quadlet/tasks/php_incident_preflight.yml').read_text())
        task = next(task for task in tasks[-1]['block'] if task['name'].startswith('Preflight candidate'))
        shell = task['ansible.builtin.shell']
        if sys.platform == 'darwin':
            shell = shell.replace('export XDG_RUNTIME_DIR="/run/user/$(id -u)"\n', '')
        env = jinja2.Environment(undefined=jinja2.StrictUndefined, trim_blocks=True)
        env.filters['quote'] = shlex.quote
        env.globals['lookup'] = lambda kind, name: Path(name).read_text()
        values = {'role_path': str(FILES.parent),
                  'gencc_php_incident_candidate': {'path': str(candidate or self.directory)},
                  'item': {'name': app, 'image': image, 'memory_mb': 512, 'mode': mode}}
        return subprocess.run(['bash', '-c', env.from_string(shell).render(values)],
                              input=env.from_string(task['args']['stdin']).render(values),
                              capture_output=True, text=True, timeout=45)

    @unittest.skipUnless(os.environ.get('GENCC_PHP_TEST_IMAGES'), 'set GENCC_PHP_TEST_IMAGES for preflight tests')
    def test_deployment_preflight_checks_candidates_and_rejects_bad_pool(self):
        files = ['zz-security-hardening.ini', 'zz-security-hardening.conf', 'zz-security-search-pool.conf']
        for name in files:
            shutil.copy(FILES / name, self.directory / name)
        for index, image in enumerate(os.environ['GENCC_PHP_TEST_IMAGES'].split(',')):
            for app in ['gencc-sub', 'gencc-search']:
                with self.subTest(image=image, app=app):
                    result = self.preflight(image, app)
                    self.assertEqual(result.returncode, 0, result.stderr)
            # Fresh paths avoid reusing previously mounted file contents on macOS.
            bad = self.directory / f'bad-{index}'
            bad.mkdir()
            for name in files:
                shutil.copy(FILES / name, bad / name)
            with (bad / 'zz-security-hardening.conf').open('a') as config:
                config.write('\ninvalid_fpm_directive = yes\n')
            result = self.preflight(image, 'gencc-search', candidate=bad)
            self.assertNotEqual(result.returncode, 0,
                                'Invalid pool configuration passed preflight: ' + result.stdout + result.stderr)

    @unittest.skipUnless(os.environ.get('GENCC_PATCHED_PHP_TEST_IMAGE'), 'set GENCC_PATCHED_PHP_TEST_IMAGE for patched portal checks')
    def test_portal_version_gate_accepts_patched_and_rejects_old_php(self):
        result = self.preflight(os.environ['GENCC_PATCHED_PHP_TEST_IMAGE'], mode='patched')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Verified patched PHP', result.stdout)
        for image in filter(None, os.environ.get('GENCC_PHP_TEST_IMAGES', '').split(',')):
            with self.subTest(image=image):
                result = self.preflight(image, mode='patched')
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('Expected PHP 8.3.36', result.stderr)

    @unittest.skipUnless(os.environ.get('GENCC_NGINX_TEST_IMAGE'), 'set GENCC_NGINX_TEST_IMAGE for local HTTP tests')
    def test_restored_staging_portal_allows_multipart_while_search_stays_blocked(self):
        command('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-subj', '/CN=localhost', '-keyout', str(self.directory / 'privkey.pem'),
                '-out', str(self.directory / 'fullchain.pem'))
        values = context('staging') | {'gencc_search_hostname_redirects': ['old-search.example.test']}
        for name in ['gencc-security.conf', 'gencc-ip-blocklist.conf', 'gencc-proxy-params.conf']:
            (self.directory / name).write_text(render('nginx_tls', name + '.j2', values))
        for template in ['gencc-http.conf.j2', 'gencc-https.conf.j2']:
            config = render('nginx_tls', template, values)
            config = config.replace('/etc/nginx/snippets/', '/test/')
            config = config.replace('/etc/letsencrypt/live/gencc-stage/', '/test/')
            (self.directory / 'nginx.conf').write_text(
                'events {}\nhttp {\ninclude /test/gencc-security.conf;\n' + config +
                '\nserver { listen 8080; location / { return 200 "portal upstream"; } }\n' +
                'server { listen 8081; location / { return 200 "search upstream"; } }\n}\n')
            cid, ports = self.start(os.environ['GENCC_NGINX_TEST_IMAGE'],
                                   ['-c', '/test/nginx.conf', '-g', 'daemon off;'], [80, 443])
            self.wait(ports[80])
            tls = template == 'gencc-https.conf.j2'
            for content_type in ['multipart/form-data; boundary=test', 'MuLtIpArT/FoRm-DaTa']:
                status, _, body = http_status(ports, values['gencc_submit_hostname'], tls,
                                              content_type=content_type, details=True)
                self.assertEqual(status, 200)
                self.assertEqual(body, 'portal upstream')
                if tls:
                    self.assertEqual(http_status(ports, values['gencc_submit_hostname'], False,
                                                 content_type=content_type), 301)
                for host, protocol in product([values['gencc_search_hostname'], 'old-search.example.test'],
                                               [False, True] if tls else [False]):
                    self.assertEqual(http_status(ports, host, protocol, content_type=content_type), 415)
            command('podman', 'rm', '-f', cid)
            self.containers.remove(cid)

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
            cid, ports = self.start(os.environ['GENCC_NGINX_TEST_IMAGE'],
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
