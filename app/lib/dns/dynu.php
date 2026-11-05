<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

/**
 * @see https://www.dynu.com/Support/API
 */
class dynu implements DnsInterface
{
    private $apikey;
    private $baseUrl = 'https://api.dynu.com/v2';
    private $error;
    private $domain;
    private $domainid;
    private $proxy;

    public function __construct($config)
    {
        $this->apikey = $config['apikey'];
        $this->domain = $config['domain'] ?? null;
        $this->domainid = $config['domainid'] ?? null;
        $this->proxy = isset($config['proxy']) && $config['proxy'] == 1;
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        return $this->getDomainList() !== false;
    }

    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $data = $this->send_request('GET', '/dns');
        if ($data === false) return false;

        $list = [];
        foreach ($data['domains'] as $row) {
            if (!isNullOrEmpty($KeyWord) && stripos($row['name'], $KeyWord) === false) continue;
            $list[] = [
                'DomainId' => $row['id'],
                'Domain' => $row['name'],
                'RecordCount' => 0,
            ];
        }
        return ['total' => count($list), 'list' => $list];
    }

    private function getDomainId()
    {
        if (!isNullOrEmpty($this->domainid)) return $this->domainid;

        $data = $this->getDomainList();
        if ($data === false) return false;
        foreach ($data['list'] as $row) {
            if (strcasecmp($row['Domain'], $this->domain) === 0) {
                $this->domainid = $row['DomainId'];
                return $this->domainid;
            }
        }
        $this->setError('域名未添加到 Dynu DNS 服务');
        return false;
    }

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $id = $this->getDomainId();
        if ($id === false) return false;
        $data = $this->send_request('GET', '/dns/' . $id . '/record');
        if ($data === false) return false;
        $domain = $this->send_request('GET', '/dns/' . $id);
        if ($domain === false) return false;

        $records = $data['dnsRecords'];
        // Dynu 将主域名的默认地址保存在域名设置中，不包含在自定义记录列表内。
        foreach (['A' => 'ipv4', 'AAAA' => 'ipv6'] as $type => $field) {
            if (empty($domain[$field . 'Address'])) continue;
            $records[] = [
                'id' => 'primary-' . $type,
                'nodeName' => '',
                'recordType' => $type,
                'content' => $domain[$field . 'Address'],
                'ttl' => $domain['ttl'],
                'state' => $domain[$field],
                'updatedOn' => $domain['updatedOn'] ?? null,
            ];
        }

        $list = [];
        foreach ($records as $row) {
            $record = $this->convertRecord($row);
            if (!isNullOrEmpty($SubDomain) && strcasecmp($record['Name'], $SubDomain) !== 0) continue;
            if (!isNullOrEmpty($KeyWord) && stripos($record['Name'], $KeyWord) === false && stripos($record['Value'], $KeyWord) === false) continue;
            if (!isNullOrEmpty($Value) && $record['Value'] != $Value) continue;
            if (!isNullOrEmpty($Type) && strcasecmp($record['Type'], $Type) !== 0) continue;
            if (!isNullOrEmpty($Line) && $Line !== 'default') continue;
            if (!isNullOrEmpty($Status) && $record['Status'] != $Status) continue;
            $list[] = $record;
        }
        return ['total' => count($list), 'list' => $list];
    }

    private function convertRecord($row)
    {
        $value = $row['content'] ?? '';
        // 实际 API 的 content 是完整区域文件行，记录值应读取对应的结构化字段。
        $valueFields = [
            'A' => 'ipv4Address',
            'AAAA' => 'ipv6Address',
            'CNAME' => 'host',
            'NS' => 'host',
            'PTR' => 'host',
            'MX' => 'host',
            'TXT' => 'textData',
        ];
        if (isset($valueFields[$row['recordType']])) {
            $value = $row[$valueFields[$row['recordType']]] ?? $value;
        } elseif ($row['recordType'] === 'SRV') {
            $value = $row['priority'] . ' ' . $row['weight'] . ' ' . $row['port'] . ' ' . $row['host'];
        } elseif ($row['recordType'] === 'CAA') {
            $value = $row['flags'] . ' ' . $row['tag'] . ' "' . $row['value'] . '"';
        }
        return [
            'RecordId' => $row['id'],
            'Domain' => $this->domain,
            'Name' => $row['nodeName'] === '' ? '@' : $row['nodeName'],
            'Type' => $row['recordType'],
            'Value' => $value,
            'Line' => 'default',
            'TTL' => $row['ttl'],
            'MX' => $row['recordType'] === 'MX' ? $row['priority'] : null,
            'Status' => $row['state'] ? '1' : '0',
            'Weight' => null,
            'Remark' => null,
            'UpdateTime' => $row['updatedOn'] ?? null,
        ];
    }

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        if ($SubDomain === '') $SubDomain = '@';
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    public function getDomainRecordInfo($RecordId)
    {
        if ($this->isPrimaryRecord($RecordId)) {
            $data = $this->getSubDomainRecords('@', 1, 0, substr($RecordId, 8));
            if ($data === false) return false;
            foreach ($data['list'] as $row) {
                if ($row['RecordId'] === $RecordId) return $row;
            }
            $this->setError('主域名地址不存在');
            return false;
        }
        $id = $this->getDomainId();
        if ($id === false) return false;
        $data = $this->send_request('GET', '/dns/' . $id . '/record/' . rawurlencode($RecordId));
        return $data === false ? false : $this->convertRecord($data);
    }

    private function buildRecordParams($Name, $Type, $Value, $TTL, $MX)
    {
        $params = [
            'nodeName' => $Name === '@' ? '' : $Name,
            'recordType' => $Type,
            'ttl' => (int)$TTL,
            'state' => true,
        ];
        switch ($Type) {
            case 'A':
                $params['ipv4Address'] = $Value;
                break;
            case 'AAAA':
                $params['ipv6Address'] = $Value;
                break;
            case 'CNAME':
            case 'NS':
            case 'PTR':
                $params['host'] = $Value;
                break;
            case 'MX':
                $params['host'] = $Value;
                $params['priority'] = (int)$MX;
                break;
            case 'TXT':
                $params['textData'] = $Value;
                break;
            case 'CAA':
                if (!preg_match('/^(\d+)\s+(issue|issuewild|iodef)\s+(.+)$/s', trim($Value), $parts)) {
                    $this->setError('CAA 记录格式应为 flags tag "value"');
                    return false;
                }
                $params['flags'] = (int)$parts[1];
                $params['tag'] = $parts[2];
                $params['value'] = trim($parts[3], '"');
                break;
            case 'SRV':
                if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)$/', trim($Value), $parts)) {
                    $this->setError('SRV 记录格式应为 priority weight port target');
                    return false;
                }
                $params['priority'] = (int)$parts[1];
                $params['weight'] = (int)$parts[2];
                $params['port'] = (int)$parts[3];
                $params['host'] = $parts[4];
                break;
            default:
                $this->setError('暂不支持此 Dynu 记录类型');
                return false;
        }
        return $params;
    }

    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $params = $this->buildRecordParams($Name, $Type, $Value, $TTL, $MX);
        if ($params === false) return false;
        $id = $this->getDomainId();
        if ($id === false) return false;
        $data = $this->send_request('POST', '/dns/' . $id . '/record', $params);
        return $data === false ? false : $data['id'];
    }

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        if ($this->isPrimaryRecord($RecordId)) {
            if (!in_array($Name, ['@', ''], true) || $Type !== substr($RecordId, 8)) {
                $this->setError('Dynu 主域名地址不能更改主机名或记录类型');
                return false;
            }
            $field = $Type === 'A' ? 'ipv4Address' : 'ipv6Address';
            return $this->updatePrimaryRecord([$field => $Value, 'ttl' => (int)$TTL]);
        }

        $params = $this->buildRecordParams($Name, $Type, $Value, $TTL, $MX);
        if ($params === false) return false;
        $id = $this->getDomainId();
        if ($id === false) return false;
        $path = '/dns/' . $id . '/record/' . rawurlencode($RecordId);
        $record = $this->send_request('GET', $path);
        if ($record === false) return false;
        $params['state'] = $record['state'];
        if (isset($record['group'])) $params['group'] = $record['group'];
        return $this->send_request('POST', $path, $params) !== false;
    }

    private function isPrimaryRecord($RecordId)
    {
        return in_array($RecordId, ['primary-A', 'primary-AAAA'], true);
    }

    private function updatePrimaryRecord($params)
    {
        $id = $this->getDomainId();
        if ($id === false) return false;
        // 域名更新接口接收完整设置，保留另一地址族、通配符和 DNSSEC 等选项。
        $domain = $this->send_request('GET', '/dns/' . $id);
        if ($domain === false) return false;
        $fields = ['name', 'group', 'ipv4Address', 'ipv6Address', 'ttl', 'ipv4', 'ipv6', 'ipv4WildcardAlias', 'ipv6WildcardAlias', 'allowZoneTransfer', 'dnssec'];
        $settings = array_intersect_key($domain, array_flip($fields));
        return $this->send_request('POST', '/dns/' . $id, array_merge($settings, $params)) !== false;
    }

    public function deleteDomainRecord($RecordId)
    {
        if ($this->isPrimaryRecord($RecordId)) {
            $this->setError('Dynu 主域名地址请使用暂停功能，或在 Dynu 控制面板删除');
            return false;
        }
        $id = $this->getDomainId();
        if ($id === false) return false;
        return $this->send_request('DELETE', '/dns/' . $id . '/record/' . rawurlencode($RecordId)) !== false;
    }

    public function setDomainRecordStatus($RecordId, $Status)
    {
        if ($this->isPrimaryRecord($RecordId)) {
            $field = $RecordId === 'primary-A' ? 'ipv4' : 'ipv6';
            return $this->updatePrimaryRecord([$field => $Status == 1]);
        }
        $id = $this->getDomainId();
        if ($id === false) return false;
        $action = $Status == 1 ? 'enable' : 'disable';
        return $this->send_request('GET', '/dns/' . $id . '/record/' . rawurlencode($RecordId) . '/' . $action) !== false;
    }

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    public function getRecordLine()
    {
        return ['default' => ['name' => '默认', 'parent' => null]];
    }

    public function getMinTTL()
    {
        return false;
    }

    public function addDomain($Domain)
    {
        return false;
    }

    private function send_request($method, $path, $params = null)
    {
        $this->error = null;
        $headers = ['API-Key' => $this->apikey, 'Accept' => 'application/json'];
        $body = null;
        if ($params !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params);
        }
        try {
            $response = http_request($this->baseUrl . $path, $body, null, null, $headers, $this->proxy, $method, 30);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            $this->setError('Dynu API 返回无效响应，HTTP ' . $response['code']);
            return false;
        }
        if (isset($data['exception'])) {
            $exception = $data['exception'];
            $this->setError(($exception['type'] ?? 'Dynu API 错误') . ' (' . ($exception['statusCode'] ?? $response['code']) . ') ' . ($exception['message'] ?? '请求失败'));
            return false;
        }
        if ($response['code'] < 200 || $response['code'] >= 300 || ($data['statusCode'] ?? 0) != 200) {
            $this->setError('Dynu API 请求失败，HTTP ' . $response['code'] . '，状态码 ' . ($data['statusCode'] ?? '未知'));
            return false;
        }
        return $data;
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
