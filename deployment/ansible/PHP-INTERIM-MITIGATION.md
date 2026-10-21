# Interim PHP mitigation — October 2026

This deployment reduces exposure to CVE-2026-109618 and CVE-2026-109619 while
PHP remains unpatched. The interim settings do **not** completely mitigate either
vulnerability. Search and production portal retain them. Staging selects the
patched portal image `2.3.0` (PHP 8.3.36) and restores its normal limits and upload
support after runtime verification. These are inventory settings, not evidence
that a particular VM has already received this branch. It does not manage web1–web4.

`gencc_php_incident_portal_mitigations` controls the portal's incident nginx
restrictions, PHP/FPM mounts, and container memory cap together. Its shared default
is `true`; staging overrides it to `false`. Search always keeps its protections.
`gencc_php_incident_portal_offline` independently controls the public maintenance
response. Production keeps both defaults `true`; staging selects both `false`.

## Portal maintenance

`gencc_php_incident_portal_offline: true` is enabled in shared inventory defaults,
so the production portal remains closed. Staging overrides it for the patched
portal rollout. While enabled, nginx serves a static maintenance response using
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
remain available. With portal mitigations enabled, multipart requests receive 415;
oversized/malformed requests may be rejected before the notice.

This takes the **public portal** offline. It does not stop its container, queues,
scheduled jobs, or localhost-only backend. Search routing remains enabled with
the incident protections. Search remains an exposed unpatched PHP application.

For an already-provisioned VM, `playbooks/php-incident-edge.yml` invokes only the
`nginx_tls` role, allowing the portal to be closed before any app deployment. It
does not run database migrations, replace containers, or stop background jobs.
It also reconciles the role's existing nginx/certbot/fail2ban configuration and
packages; it does not install the separate PHP/FPM/container-cap changes.
Before reopening an unrestricted portal, even this edge-only entrypoint streams
the verifier into its running container and requires patched CLI/live FPM settings
before writing nginx configuration. A stale or incorrectly configured runtime
therefore leaves the existing edge configuration in place.
Commands for a later authorized rollout, from `deployment/ansible`:

```sh
ansible-playbook -i inventories/staging.ini playbooks/php-incident-edge.yml --ask-vault-pass
ansible-playbook -i inventories/production.ini playbooks/php-incident-edge.yml --ask-vault-pass
```

The full `site.yml` deployment also retains maintenance mode. Its final Laravel
`artisan up` task cannot reopen nginx routing. After patched runtime validation,
set `gencc_php_incident_portal_offline: false` for the intended environment and
reapply the edge playbook to restore normal routes. The separate mitigation flag
decides whether portal request restrictions remain. See the two-phase rollout below.

## Settings and persistence

| Layer | Setting | Purpose / impact |
|---|---|---|
| Host nginx | Case-insensitive `multipart/form-data` rejection, status 415 | Preserves the incident hot patch in HTTP bootstrap, HTTP redirects, both HTTPS apps, and legacy HTTPS redirects. Upload forms fail while enabled. |
| Host nginx | Portal body limit 8 MiB; search 1 MiB | Bounds request bodies, including chunked requests. Portal JSON submissions exceeding 8 MiB fail with 413; response downloads are unaffected. |
| PHP startup INI | `session.upload_progress.enabled=Off` | Disables native upload-progress processing before request parsing. |
| FPM pool | `pm.max_requests=1` | A worker handles one request then exits; this breaks documented same-worker, later-request stale-state sequences. Increased process churn can reduce throughput. |
| Search FPM pool | `pm.max_children=25` | Reduces maximum concurrent PHP requests from 50; excess requests wait subject to backlog and timeout limits. Portal concurrency is unchanged. |
| FPM pool | `php_admin_value[memory_limit]=-1` | Removes PHP's predictable memory-limit failure; application `ini_set()` cannot restore a finite value. Does not eliminate underlying allocation failures. |
| FPM pool | `post_max_size=8M`, `upload_max_filesize=2M` | Conservative body limits with upload size below POST size. Multipart remains blocked at nginx. |
| FPM pool | `max_input_vars=1000`, `max_input_nesting_level=32` | Retains the field-count bound and reduces nesting from 64. Does not limit arbitrary JSON decoding or all allocations caused by application code. |
| Quadlet/Podman | Portal 3072 MiB; search 2048 MiB | Kernel cgroup limits apply before any container processes start. Includes FPM, nginx, queues, and process managers. |

The table describes mitigated containers. The `quadlet` role writes root-owned
files under `gencc_php_config_dir` (default `/etc/gencc/php`). These two are mounted
read-only into search and into the portal while its mitigation flag is enabled:

- `zz-security-hardening.ini` → `/usr/local/etc/php/conf.d/zz-security-hardening.ini`
- `zz-security-hardening.conf` → `/usr/local/etc/php-fpm.d/zz-security-hardening.conf`

Search also mounts `zz-security-search-pool.conf` read-only at
`/usr/local/etc/php-fpm.d/zz-security-search-pool.conf` to limit concurrency to 25.
Its existing dynamic-pool settings (10 starting workers, 5 minimum and 20 maximum
spare workers) remain compatible with this limit.

The role also installs `php-incident-verify.php` in the same host directory. It
is streamed to the container's CLI for verification, not mounted into a web root.

With portal mitigations disabled, its Quadlet has neither incident mount nor
incident container memory cap. The patched image's normal PHP settings apply:
`memory_limit=2048M`, `post_max_size=20M`, and `upload_max_filesize=20M`. nginx allows
multipart requests and restores the host portal body limit to 100 MiB; PHP's
20 MiB input limits still apply. Portal FPM uses the image's normal worker settings.
Search keeps its read-only mounts, 2 GiB cap, worker recycling, and 25-worker limit.

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
For mitigated applications the playbook requires positive caps, working rootless
cgroup v2 memory limits, and enough physical RAM for the configured caps plus at
least 2 GiB for host services. The disabled portal cap is excluded from that sum. The
approximately 8 GiB VMs leave roughly 2.75 GiB outside the two app budgets.

These are temporary incident budgets, not validated peak-workload capacity.
Concurrent exports or imports may hit them. Search permits up to 25 concurrent
FPM children. A cgroup OOM may kill another process in the same container, not
necessarily the offending request, and does not guarantee prevention of all
allocation failures. No per-worker cgroup isolation is implemented.

Before the deployment's maintenance/downtime steps, preflight stages candidate
files in a temporary directory and tests the selected intended images as the deployment
user. Missing images are pulled before downtime. Disposable containers run only
PHP-FPM, with the proposed configuration and memory caps, no network access, no
published ports, and no application credentials or data mounts. The checks verify
actual `memory.max`, valid pool configuration, and live FPM behavior. Containers
and candidate files are cleaned up on success or failure. A failure stops the
play before active mitigation files, application services, or Quadlets change.

After preflight passes, the normal playbook installs the configuration,
regenerates selected Quadlets, and restarts the selected containers. Podman receives `--memory`
at creation time, so FPM's unlimited setting is not introduced before the cap.
For mitigated containers the same verifier then checks each selected running container: its actual
memory cap, resolved `www` pool's `pm.max_requests=1`, and search's
`pm.max_children=25`. Four local FastCGI requests check actual FPM INI settings,
the administrative memory-limit lock, and distinct worker PIDs. These synthetic
requests execute no application code. Their temporary PHP file lives outside the
web root and is removed afterward. Pool process-manager values come from FPM's
configuration dump; request settings are read from the live workers. nginx is
syntax-checked and its rendered request restrictions asserted before reload.
Startup of the new containers replaces every old FPM worker.

The patched portal verifier checks PHP 8.3.36 or a later 8.3 release in CLI and live FPM,
the normal 2048M memory limit, and 20M POST/upload limits. It does not require the
incident administrative memory lock or distinct worker PIDs. Verification does
not expose a public diagnostic route.

### Patched portal rollout: verify first, restore routing second

`gencc_deploy_apps` defaults to `['gencc-sub', 'gencc-search']`. Override it to
`['gencc-sub']` for this portal rollout so search's image, container, and PHP
settings are not replaced. Shared host/nginx roles still reconcile their normal
configuration; verify search externally after each phase.

From `deployment/ansible`, deploy staging in two phases. Phase one overrides the
staging inventory's public-routing setting to keep the maintenance page active
through the runtime replacement and post-start verification:

```sh
ansible-playbook -i inventories/staging.ini playbooks/site.yml --ask-vault-pass \
  -e '{"gencc_deploy_apps":["gencc-sub"],"gencc_php_incident_portal_offline":true}'
# After the patched CLI/FPM checks and local portal application checks pass:
ansible-playbook -i inventories/staging.ini playbooks/php-incident-edge.yml --ask-vault-pass
```

Confirm public portal login/API/health routes, a normal multipart upload, and
search/Livewire/downloads. Monitor background jobs, memory, latency, and 5xx
responses. Local smoke tests do not establish workload capacity.

Production inventory deliberately keeps its previous image and mitigation flags.
For a later approved production upgrade, select image `2.3.0`, disable portal
mitigations, and run the same portal-only first phase with maintenance still
enabled. Restore public routing only after successful patched-runtime and
application verification, then repeat the external portal and search checks.

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
GENCC_PATCHED_PHP_TEST_IMAGE=localhost/gencc-sub:php83-security \
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
The patched-image test verifies normal portal limits and rejects incident FPM
mounts left on the patched portal. The deployment preflight command is also exercised against cached images, with
an invalid-pool rejection case. Verifier regression checks reject mismatched
memory caps, incorrect worker limits, and conflicting FPM-only configuration.

## Incident change boundaries

Search for `PHP INCIDENT 2026-10` to find the temporary deployment changes:

- Shared defaults group the portal toggle, configuration path, and memory budgets.
- `roles/quadlet/tasks/php_incident_preflight.yml`, `php_incident_prepare.yml`,
  and `php_incident_verify.yml` contain incident tasks. `deployment_prepare`
  imports preflight before downtime; direct Quadlet invocation also runs it.
- Both Quadlet templates mark the temporary mounts and memory caps together.
- `gencc_php_incident_portal_mitigations` conditionally removes the portal's
  mounts/cap and nginx restrictions after a patched-image rollout; search retains them.
- `gencc_deploy_apps` scopes image preflight, container replacement, and runtime
  verification so the portal can be upgraded without restarting search.
- The two `zz-security-hardening` files, `zz-security-search-pool.conf`, and
  `php-incident-verify.php` contain only incident configuration/verification.
- nginx templates isolate rejection and maintenance in `gencc-php-mitigation`
  and `gencc-portal-maintenance` includes and label request-size limits.
- `roles/nginx_tls/tasks/php_incident_validate.yml` contains incident assertions;
  normal HTTPS validation permits the portal-specific routes to be absent while
  maintenance mode is enabled.
- `playbooks/php-incident-edge.yml` is the incident-specific edge entrypoint.

Use the coordinated removal order below; deleting only the cap or only the
maintenance response is not a safe rollback of the remaining configuration.

## Removal / rollback

For an existing VM, `playbooks/portal-runtime.yml` replaces only the portal
container, verifies its runtime, and keeps nginx maintenance enabled. It preserves
the live host `.env` files (`gencc_deploy_write_env: false`) and does not run MySQL,
database bootstrap/migrations, or timer roles. Existing nonempty environment
files are required. Run this behind the existing incident maintenance page; after
verification, run `playbooks/php-incident-edge.yml` to restore staging routing.
The normal `site.yml` deployment still writes environment files from the vault,
so reconcile any live/inventory differences before returning to full deployments.

If the patched portal fails after reopening, apply the edge playbook with
`gencc_php_incident_portal_offline: true` **first**, and verify the public 503
response before replacing its runtime. To return to the previous vulnerable
image, also re-enable `gencc_php_incident_portal_mitigations: true` and deploy
only `gencc-sub` with maintenance still enabled. This restores the coordinated
nginx/PHP/cgroup protections; do not route traffic to the old image with the
patched-image mitigation flags. Preserve search's existing protections throughout.

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
