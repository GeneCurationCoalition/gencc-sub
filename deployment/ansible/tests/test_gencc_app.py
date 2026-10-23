"""gencc-app's deployment: its nginx blocks, unit, env file and certificate names."""

from itertools import product
import re
import unittest

import jinja2
import yaml
from ansible.plugins.filter.core import regex_findall, to_bool

from test_php_mitigations import ROOT, context, render


def server_blocks(config, hostname):
    """The rendered server blocks whose server_name is exactly this hostname."""
    blocks = re.split(r'(?m)^server \{', config)[1:]
    return [block for block in blocks if re.search(rf'server_name {re.escape(hostname)};', block)]


class GenccApp(unittest.TestCase):
    def test_only_an_environment_with_a_hostname_serves_gencc_app(self):
        staging, production = context('staging'), context('production')
        self.assertNotIn('gencc_app_hostname', production)
        for template in ['gencc-http.conf.j2', 'gencc-https.conf.j2']:
            self.assertNotIn(str(staging['gencc_app_port']), render('nginx_tls', template, production))
            blocks = server_blocks(render('nginx_tls', template, staging), staging['gencc_app_hostname'])
            self.assertEqual(len(blocks), 2 if template == 'gencc-https.conf.j2' else 1)
        self.assertIn('{{ gencc_app_hostname }}', (ROOT / 'inventories/group_vars/staging/vars.yml').read_text())

    def test_tls_block_passes_request_ids_and_leaves_app_headers_alone(self):
        values = context('staging')
        config = render('nginx_tls', 'gencc-https.conf.j2', values)
        [tls] = [block for block in server_blocks(config, values['gencc_app_hostname']) if 'listen 443' in block]
        # The app sets the others itself; nginx adding them would send each twice.
        self.assertEqual(re.findall(r'add_header\s+(\S+)', tls), ['Strict-Transport-Security'])
        locations = re.findall(r'location [^{]+\{[^}]*\}', tls)
        self.assertEqual(len(locations), 4)
        for location in locations:
            self.assertIn(f'proxy_pass http://127.0.0.1:{values["gencc_app_port"]};', location)
            self.assertIn('proxy_set_header X-Request-Id $request_id;', location)
        downloads = next(location for location in locations if 'download' in location)
        self.assertIn('limit_req  zone=exports', downloads)

    def test_env_file_is_unquoted_and_trusts_a_proxy_only_when_set(self):
        values = context('staging') | {'gencc_mysql_app_password': 'p@ss "word"', 'gencc_secrets_dir': '/etc/gencc'}
        for trust in ['', '10.0.2.100']:
            env = render('quadlet', 'gencc-app.env.j2', values | {'gencc_app_trust_proxy': trust})
            settings = dict(line.split('=', 1) for line in env.splitlines() if line and not line.startswith('#'))
            self.assertEqual(settings['DATABASE_PASSWORD'], 'p@ss "word"')
            self.assertEqual(settings['DATABASE_HOST'], '10.0.2.2')
            self.assertEqual(settings['RELEASES_BUCKET'], 'gencc-dev')
            self.assertEqual(settings.get('TRUST_PROXY'), trust or None)

    def test_unit_reads_settings_from_the_env_file_and_maps_the_node_user(self):
        unit = render('quadlet', 'gencc-app.container.j2', context('staging') | {
            'gencc_secrets_dir': '/etc/gencc', 'gencc_data_dir': '/var/lib/gencc'})
        self.assertIn('EnvironmentFile=/etc/gencc/gencc-app.env', unit)
        self.assertIn('UserNS=keep-id:uid=1000,gid=1000', unit)
        self.assertIn('PublishPort=127.0.0.1:8082:4100', unit)
        self.assertIn('Volume=/var/lib/gencc/gencc-app/data:/data', unit)

    def test_certificate_is_reissued_when_missing_expiring_or_short_a_domain(self):
        task = next(task for task in yaml.safe_load((ROOT / 'roles/nginx_tls/tasks/main.yml').read_text())
                    if task['name'].startswith('Obtain or update'))
        env = jinja2.Environment()
        env.filters['bool'] = to_bool
        env.filters['regex_findall'] = regex_findall
        env.filters['difference'] = lambda a, b: [item for item in a if item not in b]
        domains = ['portal-stage.thegencc.org', 'search-stage.thegencc.org', 'browser-stage.thegencc.org']
        san = 'X509v3 Subject Alternative Name: \n    DNS:{}\n'
        for exists, valid, names in product([True, False], [True, False], [domains, domains[:2]]):
            values = {
                'gencc_enable_letsencrypt': True,
                'gencc_letsencrypt_domains': domains,
                'gencc_le_cert_before': {'stat': {'exists': exists}},
                'gencc_le_cert_validity': {'rc': 0 if valid else 1},
                'gencc_le_cert_names': {'stdout': san.format(', DNS:'.join(names))},
            }
            runs = all(env.compile_expression(expression.strip())(**values) for expression in task['when'])
            with self.subTest(exists=exists, valid=valid, names=len(names)):
                self.assertEqual(runs, not exists or not valid or names != domains)


if __name__ == '__main__':
    unittest.main()
