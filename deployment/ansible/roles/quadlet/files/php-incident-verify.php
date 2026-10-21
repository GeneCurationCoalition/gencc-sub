<?php
// PHP INCIDENT 2026-10. Run via CLI inside the container, never through nginx.
// Usage: php /dev/stdin EXPECTED_MEMORY_BYTES EXPECTED_MAX_CHILDREN (0 = image default).
function requireCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fcgiRecord($type, $body)
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body;
}

function readExact($socket, $length)
{
    $result = '';
    while (strlen($result) < $length) {
        $chunk = fread($socket, $length - strlen($result));
        requireCheck($chunk !== false && $chunk !== '', 'FastCGI timeout or unexpected EOF');
        $result .= $chunk;
    }
    return $result;
}

function probeFpm($path)
{
    $socket = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://127.0.0.1:9000', $errno, $error, 1);
        if ($socket !== false) {
            break;
        }
        usleep(100000);
    }
    requireCheck($socket !== false, 'FPM did not become available on container localhost:9000');
    stream_set_timeout($socket, 5);
    try {
        $params = '';
        foreach (['SCRIPT_FILENAME' => $path, 'REQUEST_METHOD' => 'GET',
                  'SERVER_PROTOCOL' => 'HTTP/1.1', 'REDIRECT_STATUS' => '200'] as $key => $value) {
            requireCheck(strlen($value) < 128, 'Unexpected FastCGI parameter length');
            $params .= chr(strlen($key)) . chr(strlen($value)) . $key . $value;
        }
        $request = fcgiRecord(1, pack('nCxxxxx', 1, 0)) . fcgiRecord(4, $params)
            . fcgiRecord(4, '') . fcgiRecord(5, '');
        while ($request !== '') {
            $written = fwrite($socket, $request);
            requireCheck($written !== false && $written > 0, 'FastCGI write failed');
            $request = substr($request, $written);
        }
        $output = '';
        do {
            $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', readExact($socket, 8));
            $body = readExact($socket, $header['length']);
            readExact($socket, $header['padding']);
            if ($header['type'] === 6) {
                $output .= $body;
            }
        } while ($header['type'] !== 3);
        $parts = explode("\r\n\r\n", $output, 2);
        $result = json_decode($parts[1] ?? '', true);
        requireCheck(is_array($result) && isset($result['pid']), 'FPM probe did not return settings');
        return $result;
    } finally {
        fclose($socket);
    }
}

$directory = '/tmp/gencc-php-check-' . bin2hex(random_bytes(8));
$exitCode = 0;
try {
    requireCheck(trim(file_get_contents('/sys/fs/cgroup/memory.max')) === ($argv[1] ?? ''),
        'Container memory.max does not match the deployment budget');
    requireCheck(mkdir($directory, 0755), 'Cannot create temporary probe directory');
    chmod($directory, 0755);
    // Override diagnostic logging only: the included pool configuration is unchanged.
    file_put_contents($directory . '/fpm.conf', "include=/usr/local/etc/php-fpm.conf\n"
        . "[global]\nerror_log=/proc/self/fd/2\nlog_level=notice\n");
    exec('php-fpm -tt -y ' . escapeshellarg($directory . '/fpm.conf') . ' 2>&1', $dump, $status);
    requireCheck($status === 0, "FPM configuration validation failed:\n" . implode("\n", $dump));
    $pool = '';
    $settings = [];
    foreach ($dump as $line) {
        if (preg_match('/NOTICE:\s*\[([^]]+)\]/', $line, $match)) {
            $pool = $match[1];
        } elseif ($pool === 'www' && preg_match('/NOTICE:\s*(\S+)\s*=\s*(.*)$/', $line, $match)) {
            $settings[$match[1]] = trim($match[2]);
        }
    }
    requireCheck(($settings['pm.max_requests'] ?? '') === '1', 'FPM pm.max_requests must be 1');
    if (($argv[2] ?? '0') !== '0') {
        requireCheck(($settings['pm.max_children'] ?? '') === $argv[2], 'Unexpected FPM pm.max_children');
    }
    requireCheck(in_array($settings['listen'] ?? '', ['9000', '127.0.0.1:9000', '0.0.0.0:9000'], true),
        'Unexpected FPM listen address; update the incident verifier before deploying');
    file_put_contents($directory . '/probe.php', <<<'PHP'
<?php
$memory = ini_get('memory_limit');
$override = ini_set('memory_limit', '32M');
header('Content-Type: application/json');
echo json_encode(['pid' => getmypid(), 'memory' => $memory,
    'after' => ini_get('memory_limit'), 'override' => $override,
    'progress' => ini_get('session.upload_progress.enabled'),
    'post' => ini_get('post_max_size'), 'upload' => ini_get('upload_max_filesize'),
    'vars' => ini_get('max_input_vars'), 'nesting' => ini_get('max_input_nesting_level')]);
PHP
    );
    chmod($directory . '/probe.php', 0644);
    $pids = [];
    for ($request = 0; $request < 4; $request++) {
        $result = probeFpm($directory . '/probe.php');
        requireCheck($result['memory'] === '-1' && $result['after'] === '-1' && $result['override'] === false,
            'Live FPM memory_limit must be administratively locked to -1');
        requireCheck(in_array($result['progress'], ['', '0'], true), 'Live FPM upload progress is enabled');
        requireCheck([$result['post'], $result['upload'], $result['vars'], $result['nesting']]
            === ['8M', '2M', '1000', '32'], 'Live FPM input limits do not match mitigation settings');
        $pids[] = $result['pid'];
    }
    requireCheck(count(array_unique($pids)) === 4, 'FPM reused a worker across probe requests');
    echo "Verified container memory cap, www pool limits, live FPM settings and worker recycling.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    foreach (['probe.php', 'fpm.conf'] as $filename) {
        if (is_file($directory . '/' . $filename)) {
            unlink($directory . '/' . $filename);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}
exit($exitCode);
