<?php
// Gate reopening the portal on the patched PHP 8.3 runtime we deploy.
if (version_compare(PHP_VERSION, '8.3.36', '<') || version_compare(PHP_VERSION, '8.4.0', '>=')) {
    fwrite(STDERR, 'Expected PHP 8.3.36 or later in the 8.3 series; found ' . PHP_VERSION . "\n");
    exit(1);
}
echo 'Verified patched PHP ' . PHP_VERSION . "\n";
