<?php

header('Content-Type: application/json; charset=utf-8');
$ecid = isset($_GET['ecid']) ? $_GET['ecid'] : (isset($_POST['ecid']) ? $_POST['ecid'] : '');
$model = isset($_GET['model']) ? $_GET['model'] : (isset($_POST['model']) ? $_POST['model'] : '');

if (empty($ecid) || empty($model)) {
    echo json_encode([
        'success' => false,
        'error' => '缺少必要参数：ecid 和 model',
        'versions' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[0-9a-fA-F]+$/', $ecid)) {
    echo json_encode([
        'success' => false,
        'error' => 'ECID格式错误，应为16进制数',
        'versions' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ecid_decimal = hexdec($ecid);

try {
    $encrypt_data = [
        'ecid' => (int)$ecid_decimal,
        'model' => $model,
        'time' => (int)round(microtime(true) * 1000)
    ];
    
    $json_data = json_encode($encrypt_data, JSON_NUMERIC_CHECK);

    $key = '2015aisi1234sj7890smartflashi4pc';
    $encrypted_param = encrypt_3des_ecb($json_data, $key);
    
    if (!$encrypted_param) {
        throw new Exception('3DES加密失败');
    }

    $encoded_param = urlencode($encrypted_param);

    $query_url = "https://i4tool2.i4.cn/requestBackupSHSHList.xhtml?param=" . $encoded_param;

    $response = http_request($query_url);

    $result = json_decode($response, true);
    
    if ($result === null) {
        throw new Exception('响应数据解析失败');
    }

    if (!isset($result['success']) || !$result['success']) {
        throw new Exception('爱思服务器查询失败: ' . (isset($result['message']) ? $result['message'] : '未知错误'));
    }

    $versions = [];
    if (isset($result['list']) && is_array($result['list'])) {
        foreach ($result['list'] as $item) {
            if (isset($item['ios'])) {
                $versions[] = [
				    'osver_build' => isset($item['ios_order']) ? $item['ios_order'] : $item['ios']
                    'osver' => $item['ios'],
                ];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'error' => '',
        'device_info' => [
            'ecid_hex' => $ecid,
            'ecid_dec' => $ecid_decimal,
            'model' => $model
        ],
        'versions' => $versions,
        'count' => count($versions)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'versions' => []
    ], JSON_UNESCAPED_UNICODE);
}

function encrypt_3des_ecb($data, $key) {
    $method = 'DES-EDE3';

    $block_size = 8;
    $padding = $block_size - (strlen($data) % $block_size);
    $data .= str_repeat(chr($padding), $padding);
    
    $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);
    
    if ($encrypted === false) {
        $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA);
    }
    
    if ($encrypted === false) {
        return false;
    }
    
    return base64_encode($encrypted);
}

function http_request($url) {
    $ch = curl_init();
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*'
        ]
    ];
    
    curl_setopt_array($ch, $options);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception('请求失败: ' . $curl_error);
    }
    
    if ($http_code !== 200) {
        throw new Exception('服务器返回错误: HTTP ' . $http_code);
    }
    
    return $response;
}
?>