<?php

namespace app\lib\cert;

use app\lib\CertInterface;
use app\lib\acme\ACMECert;
use Exception;

class google implements CertInterface
{
    private $directories = array(
        'live' => 'https://dv.acme-v02.api.pki.goog',
        'staging' => 'https://dv.acme-v02.test-api.pki.goog'
    );
    private $ac;
    private $config;
    private $ext;

    public function __construct($config, $ext = null)
    {
        $this->config = $config;
        if (empty($config['mode'])) $config['mode'] = 'live';
        if (empty($config['proxy_url'])) $config['proxy_url'] = '';
        $this->ac = new ACMECert($this->directories[$config['mode']] . '/directory', (int)$config['proxy'], [
            'origin' => $this->directories[$config['mode']],
            'proxy' => rtrim($config['proxy_url'], '/'),
        ]);
        if ($ext) {
            $this->ext = $ext;
            $this->ac->loadAccountKey($ext['key']);
            $this->ac->setAccount($ext['kid']);
        }
    }

    public function register()
    {
        if (empty($this->config['email'])) throw new Exception('邮件地址不能为空');

        if (isset($this->config['eabMode']) && $this->config['eabMode'] == 'auto') {
            $eab = $this->getEAB();
        } else {
            $eab = ['kid' => $this->config['kid'], 'key' => $this->config['key']];
        }

        if (!empty($this->ext['key'])) {
            $kid = $this->ac->registerEAB(true, $eab['kid'], $eab['key'], $this->config['email']);
            return ['kid' => $kid, 'key' => $this->ext['key']];
        }

        $key = $this->ac->generateRSAKey(2048);
        $this->ac->loadAccountKey($key);
        $kid = $this->ac->registerEAB(true, $eab['kid'], $eab['key'], $this->config['email']);
        return ['kid' => $kid, 'key' => $key];
    }

    public function buyCert($domainList, &$order)
    {
    }

    public function createOrder($domainList, &$order, $keytype, $keysize)
    {
        $domain_config = [];
        foreach ($domainList as $domain) {
            if (empty($domain)) continue;
            $domain_config[$domain] = ['challenge' => 'dns-01'];
        }
        if (empty($domain_config)) throw new Exception('域名列表不能为空');

        $order = $this->ac->createOrder($domain_config);

        $dnsList = [];
        if (!empty($order['challenges'])) {
            foreach ($order['challenges'] as $opts) {
                $mainDomain = getMainDomain($opts['domain']);
                $name = substr($opts['key'], 0, -(strlen($mainDomain) + 1));
                /*if (!array_key_exists($mainDomain, $dnsList)) {
                    $dnsList[$mainDomain][] = ['name' => '@', 'type' => 'CAA', 'value' => '0 issue "pki.goog"'];
                }*/
                $dnsList[$mainDomain][] = ['name' => $name, 'type' => 'TXT', 'value' => $opts['value']];
            }
        }

        return $dnsList;
    }

    public function authOrder($domainList, $order)
    {
        $this->ac->authOrder($order);
    }

    public function getAuthStatus($domainList, $order)
    {
        return true;
    }

    public function finalizeOrder($domainList, $order, $keytype, $keysize)
    {
        if (empty($domainList)) throw new Exception('域名列表不能为空');

        if ($keytype == 'ECC') {
            if (empty($keysize)) $keysize = '384';
            $private_key = $this->ac->generateECKey($keysize);
        } else {
            if (empty($keysize)) $keysize = '2048';
            $private_key = $this->ac->generateRSAKey($keysize);
        }
        $fullchain = $this->ac->finalizeOrder($domainList, $order, $private_key);

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        return ['private_key' => $private_key, 'fullchain' => $fullchain, 'issuer' => $certInfo['issuer']['CN'], 'subject' => $certInfo['subject']['CN'], 'validFrom' => $certInfo['validFrom_time_t'], 'validTo' => $certInfo['validTo_time_t']];
    }

    public function revoke($order, $pem)
    {
        $this->ac->revoke($pem);
    }

    public function cancel($order)
    {
    }

    public function setLogger($func)
    {
        $this->ac->setLogger($func);
    }

    private function getEAB()
    {
        $api = "https://gts.rat.dev/eab";
        $response = http_request($api, null, null, null, null, $this->config['proxy'] == 1, 'GET', 10);
        $result = json_decode($response['body'], true);
        if (!isset($result['msg'])) {
            throw new Exception('解析返回数据失败：' . $response['body']);
        } elseif ($result['msg'] != 'success') {
            throw new Exception('获取EAB失败：' . $result['msg']);
        } elseif (empty($result['data']['key_id']) || empty($result['data']['mac_key'])) {
            throw new Exception('获取EAB失败：返回数据不完整');
        }
        return ['kid' => $result['data']['key_id'], 'key' => $result['data']['mac_key']];
    }
}
