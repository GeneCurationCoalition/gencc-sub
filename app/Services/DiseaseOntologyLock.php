<?php

namespace App\Services;

use RuntimeException;

/**
 * Keeps a disease audit from reading the diseases table while update:diseases
 * is rewriting it. Imports share the lock; an audit needs it alone, so an audit
 * refuses to start during an import or another audit, and an import started
 * during an audit waits for it to finish.
 *
 * A process-owned file lock has no lease to expire and is released when the
 * process exits. Timers, deploys and the queue worker all run inside the one
 * application container, so a file under storage/ covers every entry point.
 */
class DiseaseOntologyLock
{
    /** @return resource Held until release(). */
    public static function forImport()
    {
        $handle = self::open();
        if (! flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new RuntimeException('Cannot lock the disease ontology tables for import.');
        }

        return $handle;
    }

    /** @return resource|null Null when an import or another audit holds the lock. */
    public static function forAudit()
    {
        $handle = self::open();
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    public static function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public static function path(): string
    {
        // SQLite test runs must not take the running development database's lock.
        return storage_path('framework/'.(app()->environment('testing') ? 'testing-' : '').'disease-ontology.lock');
    }

    /** @return resource */
    private static function open()
    {
        $handle = fopen(self::path(), 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the disease ontology lock.');
        }

        return $handle;
    }
}
