# Temporary PHP incident deployment — October 2026

[PHP advisory](https://www.php.net/archive/2026.php#2026-10-21-5).
Staging portal uses image `2.3.0` (PHP 8.3.36) with uploads restored.
Production portal remains in maintenance; search retains interim mitigations
in both environments.

## Portal rollout

From this directory, with the existing maintenance page active:

```sh
ansible-playbook -i inventories/staging.ini playbooks/portal-runtime.yml --ask-vault-pass
# Verify private backend health/login and queue startup before reopening:
ansible-playbook -i inventories/staging.ini playbooks/php-incident-edge.yml --ask-vault-pass
```

Then verify a browser upload, search availability, and search multipart rejection.
For production, first select the patched image and disable portal mitigations in
its inventory, then use the same commands with `inventories/production.ini`.

The runtime playbook preserves the live `.env`. Staging's live settings differ
from its vault; reconcile that drift before using the full `site.yml` playbook.
Preserve APP_KEY and persistent storage.

## Rollback / removal

Close routing before reverting the image:

```sh
ansible-playbook -i inventories/staging.ini playbooks/php-incident-edge.yml --ask-vault-pass \
  -e gencc_php_incident_portal_offline=true
```

Verify 503, then restore the old image **with portal mitigations enabled**.
Keep search protected. After patching each application, remove its unlimited FPM
memory override and incident container cap together; check for old in-container
hot patches. Temporary sections are marked `PHP INCIDENT 2026-10`.
