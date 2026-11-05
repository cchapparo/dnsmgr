<?php

namespace app\lib\dns {
    // Exercise the driver without sending requests to a DNS account.
    function http_request($url, $body = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = null, $timeout = 10): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $GLOBALS['dynu_requests'][] = [
            'method' => $method,
            'path' => $path,
            'params' => $body === null ? null : json_decode($body, true),
        ];
        $response = $GLOBALS['dynu_responses'][$method . ' ' . $path] ?? null;
        if ($response === null) {
            throw new \RuntimeException('Unexpected request ' . $method . ' ' . $path);
        }
        return ['code' => 200, 'body' => json_encode($response)];
    }
}

namespace {
    // Run with PHP 8.2+ using `php tests/dynu.php`.
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    require __DIR__ . '/../app/lib/DnsInterface.php';
    require __DIR__ . '/../app/lib/DnsHelper.php';
    require __DIR__ . '/../app/lib/dns/dynu.php';

    function isNullOrEmpty($value)
    {
        return $value === null || $value === '';
    }

    function assertSame($expected, $actual, $description)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($description . '\nExpected ' . var_export($expected, true) . '\nActual ' . var_export($actual, true));
        }
    }

    $records = [
        ['recordType' => 'A', 'ipv4Address' => '192.0.2.1'],
        ['recordType' => 'AAAA', 'ipv6Address' => '2001:db8::1'],
        ['recordType' => 'CNAME', 'host' => 'target.example.com'],
        ['recordType' => 'NS', 'host' => 'ns.example.com'],
        ['recordType' => 'PTR', 'host' => 'ptr.example.com'],
        ['recordType' => 'MX', 'host' => 'mail.example.com', 'priority' => 20],
        ['recordType' => 'TXT', 'textData' => '0'],
        ['recordType' => 'CAA', 'flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
        ['recordType' => 'SRV', 'priority' => 10, 'weight' => 5, 'port' => 443, 'host' => 'target.example.com'],
    ];
    $values = [
        '192.0.2.1',
        '2001:db8::1',
        'target.example.com',
        'ns.example.com',
        'ptr.example.com',
        'mail.example.com',
        '0',
        '0 issue "letsencrypt.org"',
        '10 5 443 target.example.com',
    ];
    $dynu_responses = [];
    $dynu_requests = [];
    foreach ($records as $index => &$record) {
        $record += [
            'id' => $index + 1,
            'nodeName' => 'test',
            'ttl' => 120,
            'state' => false,
            'group' => 'home',
        ];
        // Live Dynu responses include the entire zone-file line in content.
        $record['content'] = 'test.example.com. 120 IN ' . $record['recordType'] . ' ' . $values[$index];
        $dynu_responses['GET /v2/dns/42/record/' . $record['id']] = ['statusCode' => 200] + $record;
    }
    unset($record);
    $dynu_responses['GET /v2/dns/42/record'] = ['statusCode' => 200, 'dnsRecords' => $records];
    $dynu_responses['GET /v2/dns/42'] = [
        'statusCode' => 200,
        'ipv4Address' => '192.0.2.2',
        'ipv6Address' => '2001:db8::2',
        'ipv4' => true,
        'ipv6' => false,
        'ttl' => 90,
    ];

    $dns = \app\lib\DnsHelper::getModel2([
        'type' => 'dynu',
        'config' => json_encode(['apikey' => 'test-key']),
        'name' => 'example.com',
        'thirdid' => 42,
    ]);
    assertSame(true, $dns instanceof \app\lib\DnsInterface, 'Factory loads Dynu');
    $list = $dns->getDomainRecords();
    assertSame(11, $list['total'], 'Custom records and primary addresses are listed');
    foreach ($records as $index => $record) {
        assertSame($values[$index], $list['list'][$index]['Value'], 'Structured list value for ' . $record['recordType']);
        $detail = $dns->getDomainRecordInfo($record['id']);
        assertSame($values[$index], $detail['Value'], 'Structured detail value for ' . $record['recordType']);
        assertSame('0', $detail['Status'], 'Disabled state is preserved');
    }
    assertSame('192.0.2.2', $list['list'][9]['Value'], 'Primary address has no custom-record fields');
    assertSame('0', $list['list'][10]['Status'], 'Disabled primary IPv6 is listed');
    $filtered = $dns->getDomainRecords(1, 20, null, 'TEST', '0', 'TXT', 'default', '0');
    assertSame(1, $filtered['total'], 'Name, type, zero value and disabled filters combine');

    $dynu_responses['POST /v2/dns/42/record/1'] = ['statusCode' => 200];
    assertSame(true, $dns->updateDomainRecord(1, 'test', 'A', '192.0.2.3'), 'Update succeeds');
    $request = $dynu_requests[array_key_last($dynu_requests)];
    assertSame(false, $request['params']['state'], 'Update keeps disabled state');
    assertSame('home', $request['params']['group'], 'Update keeps the existing group');
    assertSame('192.0.2.3', $request['params']['ipv4Address'], 'Update sends an address value');

    echo "Dynu regression tests passed\n";
}
