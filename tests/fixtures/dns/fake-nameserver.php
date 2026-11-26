<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * A nameserver for DnsLookupTest, on 127.0.0.1, UDP and TCP on one port.
 *
 *   php fake-nameserver.php '<json>' <seconds to live>
 *
 * Prints the port, then answers until it is told to stop or its time is up.
 * The JSON:
 *   records:  name => {"A": [...], "AAAA": [...], "CNAME": "name", "rcode": n}
 *             (a name not listed is NXDOMAIN)
 *   silent:   types it never answers ("A", "AAAA"), but for the spoof
 *   truncate: true answers over UDP with TC set and no records, over TCP whole
 *   spoof:    an address sent first, with the wrong id and then for the wrong
 *             question, before each real answer
 *   log:      a file each question is appended to, "udp|tcp name TYPE"
 */

$config = json_decode($argv[1] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
$lifetime = (float) ($argv[2] ?? 20);
$types = ['A' => 1, 'CNAME' => 5, 'AAAA' => 28];
$names = array_flip($types);

$udp = @stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
if ($udp === false) {
    echo "ERR udp $error\n";
    exit(1);
}
$local = (string) stream_socket_get_name($udp, false);
$port = (int) substr($local, strrpos($local, ':') + 1);
$tcp = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($tcp === false) {
    echo "ERR tcp $error\n";
    exit(1);
}
echo $port, "\n";

$encode = static function (string $name): string {
    $wire = '';
    foreach (explode('.', $name) as $label) {
        $wire .= chr(strlen($label)) . $label;
    }

    return $wire . "\x00";
};

$question = static function (string $packet): ?array {
    if (strlen($packet) < 17) {
        return null;
    }
    $id = unpack('n', $packet)[1];
    $at = 12;
    $labels = [];
    while ($at < strlen($packet) && ($len = ord($packet[$at])) !== 0) {
        $labels[] = substr($packet, $at + 1, $len);
        $at += 1 + $len;
    }
    $q = unpack('ntype/nclass', $packet, $at + 1);

    return [
        'id' => $id,
        'name' => strtolower(implode('.', $labels)),
        'type' => $q['type'],
        'raw' => substr($packet, 12, $at + 5 - 12),
    ];
};

$answer = static function (
    array $q,
    bool $overUdp,
    ?int $id = null,
    ?string $address = null
) use (
    $config,
    $encode,
    $types
): string {
    $records = $config['records'] ?? [];
    $name = $q['name'];
    $rrs = [];
    $owner = "\xC0\x0C";
    $rcode = 0;
    if ($address !== null) {
        $data = (string) inet_pton($address);
        $rrs[] = $owner . pack('nnNn', strlen($data) === 4 ? 1 : 28, 1, 60, strlen($data)) . $data;
    } elseif (!isset($records[$name])) {
        $rcode = 3;
    } else {
        $rcode = (int) ($records[$name]['rcode'] ?? 0);
        $seen = [];
        while (isset($records[$name]['CNAME']) && !isset($seen[$name])) {
            $seen[$name] = true;
            $target = strtolower($records[$name]['CNAME']);
            $rdata = $encode($target);
            $rrs[] = $owner . pack('nnNn', 5, 1, 60, strlen($rdata)) . $rdata;
            $owner = $encode($target);
            $name = $target;
        }
        $type = $q['type'] === 1 ? 'A' : 'AAAA';
        foreach ($records[$name][$type] ?? [] as $addr) {
            $data = (string) inet_pton($addr);
            $rrs[] = $owner . pack('nnNn', $q['type'], 1, 60, strlen($data)) . $data;
        }
    }
    $flags = 0x8180 | $rcode;
    if ($overUdp && ($config['truncate'] ?? false) && $address === null) {
        $flags |= 0x0200;
        $rrs = [];
    }

    return pack('nnnnnn', $id ?? $q['id'], $flags, 1, count($rrs), 0, 0) . $q['raw'] . implode('', $rrs);
};

$log = static function (string $line) use ($config): void {
    if (isset($config['log'])) {
        file_put_contents($config['log'], $line . "\n", FILE_APPEND);
    }
};

$until = microtime(true) + $lifetime;
while (microtime(true) < $until) {
    $read = [$udp, $tcp, STDIN];
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 200000) < 1) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === STDIN) {
            if (feof(STDIN) || fgets(STDIN) !== false) {
                exit(0);
            }
            continue;
        }
        if ($socket === $udp) {
            $packet = stream_socket_recvfrom($udp, 65535, 0, $peer);
            $q = $question((string) $packet);
            if ($q === null) {
                continue;
            }
            $log('udp ' . $q['name'] . ' ' . ($names[$q['type']] ?? $q['type']));
            if (isset($config['spoof'])) {
                stream_socket_sendto($udp, $answer($q, true, $q['id'] ^ 1, $config['spoof']), 0, $peer);
                $other = $q;
                $other['raw'] = $encode('evil.example') . substr($q['raw'], -4);
                stream_socket_sendto($udp, $answer($other, true, $q['id'], $config['spoof']), 0, $peer);
            }
            if (in_array($names[$q['type']] ?? '', $config['silent'] ?? [], true)) {
                continue;
            }
            stream_socket_sendto($udp, $answer($q, true), 0, $peer);
            continue;
        }
        $conn = @stream_socket_accept($tcp, 1);
        if ($conn === false) {
            continue;
        }
        $length = fread($conn, 2);
        if (!is_string($length) || strlen($length) < 2) {
            fclose($conn);
            continue;
        }
        $packet = '';
        $need = unpack('n', $length)[1];
        while (strlen($packet) < $need && !feof($conn)) {
            $packet .= (string) fread($conn, $need - strlen($packet));
        }
        $q = $question($packet);
        if ($q !== null) {
            $log('tcp ' . $q['name'] . ' ' . ($names[$q['type']] ?? $q['type']));
            $reply = $answer($q, false);
            fwrite($conn, pack('n', strlen($reply)) . $reply);
        }
        fclose($conn);
    }
}
