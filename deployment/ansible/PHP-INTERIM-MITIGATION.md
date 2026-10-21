# Interim PHP mitigation — October 2026

This deployment reduces exposure to CVE-2026-109618 and CVE-2026-109619 while
PHP remains unpatched. It does **not** completely mitigate either vulnerability.
It applies to gencc-sub and gencc-search on both staging and production through
the normal `playbooks/site.yml` deployment. It does not manage web1–web4.

## Portal maintenance

`gencc_php_incident_portal_offline: true` is enabled in shared inventory defaults,
so both `portal-stage.thegencc.org` and `portal.thegencc.org` serve a static nginx
maintenance response after the configuration is deployed. The response uses
HTTP 503, `Content-Type: text/plain`, and `Cache-Control: no-store`:

```text
The GenCC Submission Portal is temporarily down for maintenance.

We are addressing PHP security vulnerabilities. For details, see:
https://www.php.net/archive/2026.php#2026-10-21-5
```

Every portal upstream is omitted, including API, authentication, PHP paths, and
`/-/healthz`. No PHP or Laravel execution is needed to serve the notice. Ordinary
HTTP requests still redirect to HTTPS when TLS is configured; the HTTP-only
bootstrap configuration serves the notice directly. ACME HTTP-01 challenges
remain available. Multipart requests still receive the existing 415 rejection;
oversized/malformed requests may be rejected before the notice.

This takes the **public portal** offline. It does not stop its container, queues,
scheduled jobs, or localhost-only backend. Search routing remains enabled with
the incident protections. Search remains an exposed unpatched PHP application.

For an already-provisioned VM, `playbooks/php-incident-edge.yml` invokes only the
`nginx_tls` role, allowing the portal to be closed before any app deployment. It
does not run database migrations, replace containers, or stop background jobs.
It also reconciles the role's existing nginx/certbot/fail2ban configuration and
packages; it does not install the separate PHP/FPM/container-cap changes.
Commands for a later authorized rollout, from `deployment/ansible`:

```sh
ansible-playbook -i inventories/staging.ini playbooks/php-incident-edge.yml --ask-vault-pass
ansible-playbook -i inventories/production.ini playbooks/php-incident-edge.yml --ask-vault-pass
```

The full `site.yml` deployment also retains maintenance mode. Its final Laravel
`artisan up` task cannot reopen nginx routing. After patched runtime validation,
set `gencc_php_incident_portal_offline: false` for the intended environment and
reapply the edge playbook to restore normal routes while retaining other incident
mitigations. Neither playbook has been executed as part of preparing this branch.

## Settings and persistence

| Layer | Setting | Purpose / impact |
|---|---|---|
| Host nginx | Case-insensitive `multipart/form-data` rejection, status 415 | Preserves the incident hot patch in HTTP bootstrap, HTTP redirects, both HTTPS apps, and legacy HTTPS redirects. Upload forms fail while enabled. |
| Host nginx | Portal body limit 8 MiB; search 1 MiB | Bounds request bodies, including chunked requests. Portal JSON submissions exceeding 8 MiB fail with 413; response downloads are unaffected. |
| PHP startup INI | `session.upload_progress.enabled=Off` | Disables native upload-progress processing before request parsing. |
| FPM pool | `pm.max_requests=1` | A worker handles one request then exits; this breaks documented same-worker, later-request stale-state sequences. Increased process churn can reduce throughput. |
| FPM pool | `php_admin_value[memory_limit]=-1` | Removes PHP's predictable memory-limit failure; application `ini_set()` cannot restore a finite value. Does not eliminate underlying allocation failures. |
| FPM pool | `post_max_size=8M`, `upload_max_filesize=2M` | Conservative body limits with upload size below POST size. Multipart remains blocked at nginx. |
| FPM pool | `max_input_vars=1000`, `max_input_nesting_level=32` | Retains the field-count bound and reduces nesting from 64. Does not limit arbitrary JSON decoding or all allocations caused by application code. |
| Quadlet/Podman | Portal 3072 MiB; search 2048 MiB | Kernel cgroup limits apply before any container processes start. Includes FPM, nginx, queues, and process managers. |

The `quadlet` role writes two root-owned files under `gencc_php_config_dir`
(default `/etc/gencc/php`) and mounts them read-only into **both** containers:

- `zz-security-hardening.ini` → `/usr/local/etc/php/conf.d/zz-security-hardening.ini`
- `zz-security-hardening.conf` → `/usr/local/etc/php-fpm.d/zz-security-hardening.conf`

These survive container replacement and VM reboot because the files live on the
host and the mounts are in the Quadlet units. They supersede the previous
container-layer INI hot patches. Existing published images can be used: no image
rebuild or sibling-repository change is required. Running these images outside
this Ansible/Quadlet deployment does not automatically enable these mitigations.

Only FPM gets unlimited PHP memory; CLI jobs keep their existing PHP limits.
The container cgroup budgets cover both. Do not copy the FPM override into an
uncapped deployment, use nginx per-request `PHP_VALUE`/`PHP_ADMIN_VALUE` overrides,
or substitute `RLIMIT_AS`/`RLIMIT_DATA` for kernel cgroup limits.

## Memory budget and deployment

Shared inventory variables define `gencc_sub_memory_limit_mb` (3072),
`gencc_search_memory_limit_mb` (2048), and `gencc_host_memory_reserve_mb` (2048).
The playbook requires positive caps, cgroup v2 with a memory controller, and
enough physical RAM for both caps plus at least 2 GiB for host services. The
approximately 8 GiB VMs leave roughly 2.75 GiB outside the two app budgets.

These are temporary incident budgets, not validated peak-workload capacity.
Concurrent exports or imports may hit them. Search still permits 50 concurrent
FPM children. A cgroup OOM may kill another process in the same container, not
necessarily the offending request, and does not guarantee prevention of all
allocation failures. No per-worker cgroup isolation is implemented.

The normal playbook writes the configuration, regenerates Quadlets, and restarts
both containers. Podman receives `--memory` at creation time, so FPM's unlimited
setting is not introduced before the cap. Deployment verifies actual
`memory.max`, PHP-FPM syntax, and the startup upload-progress setting. nginx is
syntax-checked and its rendered request restrictions asserted before the final
reload. Startup of the new containers replaces every old FPM worker.

Deploy to staging first using the existing runbook. Confirm the public portal
returns the static maintenance notice, including API and health paths. Check
search/Livewire, downloads, exports, and background jobs. Portal login/API checks
must use an isolated validation setup while public routing is disabled. Confirm
multipart rejection, inspect `memory.events`, and watch worker churn, latency,
and 5xx responses before production. Local smoke tests do not establish workload
capacity. This branch does not deploy itself or change image pins.

## Scope of the PHP recommendations

- `fatal_error_backtraces=0` applies to PHP 8.5, not our 8.1/7.4 runtimes.
- The deployment supplies startup/pool settings, not early per-request INI
  diagnostics. Existing nginx configurations had no `PHP_VALUE`/`PHP_ADMIN_VALUE`.
- No blanket timeout reduction is made: PHP execution timeouts can themselves
  produce stale state. Attacker-reachable application fatal errors still need
  application-specific investigation; this deployment does not claim to fix them.
- nginx body limits and multipart rejection do not constitute a general-purpose
  field/header/JSON complexity filter. Ordinary multipart uploads are unavailable.

## Local validation (no VM access)

Use a Python environment containing ansible-core (including Jinja2 and PyYAML).
From the repository root:

```sh
python deployment/ansible/tests/test_php_mitigations.py
cd deployment/ansible
ansible-playbook -i localhost, --syntax-check playbooks/site.yml
```

Optional container tests require cached PHP-FPM images and an image containing
nginx (the portal image contains both). They use `--pull=never`, replace image
entrypoints with PHP-FPM/nginx binaries, bind ephemeral localhost ports, use a
small synthetic PHP probe instead of Laravel, and remove their containers/files.
No remote scripts or exploits are executed. Example from the repository root:

```sh
GENCC_PHP_TEST_IMAGES=php:8.1-fpm,php:7.4-fpm-alpine \
GENCC_NGINX_TEST_IMAGE=ghcr.io/genecurationcoalition/gencc-sub:2.2.1 \
python deployment/ansible/tests/test_php_mitigations.py
```

Choose native-architecture cached images when possible. Tests check live FPM
settings, failed attempts to override the memory limit, unique worker PIDs per
request, caps/settings after container restart, HTTP/HTTPS multipart rejection,
redirect ordering, ACME accessibility, ordinary JSON requests, and oversized
declared/chunked bodies. Template tests cover both inventories and optional
redirects, reject unsafe memory budget configurations, and exercise both portal
maintenance and restored routing. Maintenance response tests include API, login,
health, and PHP paths; they assert plain text, no-store, and the advisory URL.

## Incident change boundaries

Search for `PHP INCIDENT 2026-10` to find the temporary deployment changes:

- Shared defaults group the portal toggle, configuration path, and memory budgets.
- `roles/quadlet/tasks/php_incident_prepare.yml` and `php_incident_verify.yml`
  contain incident tasks, imported at the required points in the main role.
- Both Quadlet templates mark the temporary mounts and memory caps together.
- The two `zz-security-hardening` files contain only incident configuration.
- nginx templates isolate rejection and maintenance in `gencc-php-mitigation`
  and `gencc-portal-maintenance` includes and label request-size limits.
- `roles/nginx_tls/tasks/php_incident_validate.yml` contains incident assertions;
  normal HTTPS validation permits the portal-specific routes to be absent while
  maintenance mode is enabled.
- `playbooks/php-incident-edge.yml` is the incident-specific edge entrypoint.

Use the coordinated removal order below; deleting only the cap or only the
maintenance response is not a safe rollback of the remaining configuration.

## Removal / rollback

After deploying patched PHP, remove the FPM unlimited-memory override and restore
appropriate finite PHP limits **before** relaxing external memory caps. Remove
the relevant Quadlet mounts and host files together, then recreate the containers
and verify effective FPM settings. Removing only a mount may reveal an old baked
or hot-patched file beneath it; inspect the resulting configuration.

Restore upload support and larger request limits separately after deciding it is
safe; native upload progress may remain disabled if unused. Do not use a blanket
revert as an incident rollback: it would also remove nginx rejection and memory
containment. Prefer correcting the specific failing layer while keeping the
other mitigations active.

Sources: [PHP announcement](https://www.php.net/archive/2026.php#2026-10-21-5),
[OOM advisory](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w),
[stale-state advisory](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp),
[FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php).
