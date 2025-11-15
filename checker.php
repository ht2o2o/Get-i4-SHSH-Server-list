<?php

// 获取参数
$ecid = isset($_GET['ecid']) ? $_GET['ecid'] : (isset($_POST['ecid']) ? $_POST['ecid'] : '');
$model = isset($_GET['model']) ? $_GET['model'] : (isset($_POST['model']) ? $_POST['model'] : '');
$osver = isset($_GET['osver']) ? $_GET['osver'] : (isset($_POST['osver']) ? $_POST['osver'] : '');
if (empty($ecid) || empty($model)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => '缺少必要参数：ecid 和 model',
        'versions' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[0-9a-fA-F]+$/', $ecid)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'ECID格式错误，应为16进制数',
        'versions' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ecid_decimal = hexdec($ecid);

try {
    if (!empty($osver)) {
        download_shsh_file($ecid_decimal, $model, $osver, $ecid);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        query_shsh_versions($ecid_decimal, $model, $ecid);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'versions' => []
    ], JSON_UNESCAPED_UNICODE);
}

function download_shsh_file($ecid_decimal, $model, $osver, $ecid_hex) {
    $available_versions = get_available_versions($ecid_decimal, $model);
    $matched_version = find_matching_version($available_versions, $osver);
    if (!$matched_version) {
        throw new Exception("未找到匹配的iOS版本: {$osver}。可用版本: " . implode(', ', array_column($available_versions, 'version')));
    }
    $shsh_content = null;
    $used_format = '';
    $used_version = '';
    
    $version_formats = [
        'build' => $matched_version['osver_build']
    ];
    
    foreach ($version_formats as $format_type => $version_value) {
        $result = try_download_with_version($ecid_decimal, $model, $version_value);
        if ($result['success'] && !empty($result['shsh_content'])) {
            $shsh_content = $result['shsh_content'];
            $used_format = $format_type;
            $used_version = $version_value;
            break;
        }
    }
    
    if (empty($shsh_content)) {
        throw new Exception("无法下载SHSH文件，所有版本格式尝试都失败");
    }

    $filename = generate_shsh_filename($ecid_hex, $model, $used_version);
    $filepath = __DIR__ . '/shsh/' . $filename;
    
    if (file_put_contents($filepath, $shsh_content) === false) {
        throw new Exception("无法保存SHSH文件到: {$filename}，请尝试检查文件夹权限");
    }
    
    header('Content-Type: application/x-plist; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($shsh_content));
    header('X-Filename: ' . $filename);
    header('X-ECID: ' . $ecid_hex);
    header('X-Model: ' . $model);
    header('X-iOS-Version: ' . $used_version);
    header('X-File-Path: ' . $filepath);
    
    echo $shsh_content;
    
}

function generate_shsh_filename($ecid_hex, $model, $version) {
    $clean_version = preg_replace('/[^a-zA-Z0-9._-]/', '_', $version);
    $filename = "{$ecid_hex}_{$model}_{$clean_version}.shsh";
    return $filename;
}

function query_shsh_versions($ecid_decimal, $model, $ecid_hex) {
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

    $versions = extract_versions_from_response($result);
    
    /*  显示下载方式
    foreach ($versions as &$version) {
        $version['download_url'] = "?ecid=" . $ecid_hex . "&model=" . urlencode($model) . "&osver=" . urlencode($version['build']);
        $version['download_filename'] = generate_shsh_filename($ecid_hex, $model, $version['build']);
    }*/

    echo json_encode([
        'success' => true,
        'error' => '',
        'device_info' => [
            'ecid_hex' => $ecid_hex,
            'ecid_decimal' => $ecid_decimal,
            'model' => $model
        ],
        'versions' => $versions,
        'count' => count($versions)
    ], JSON_UNESCAPED_UNICODE);
}

function get_available_versions($ecid_decimal, $model) {
    $encrypt_data = [
        'ecid' => (int)$ecid_decimal,
        'model' => $model,
        'time' => (int)round(microtime(true) * 1000)
    ];
    
    $json_data = json_encode($encrypt_data, JSON_NUMERIC_CHECK);
    $key = '2015aisi1234sj7890smartflashi4pc';
    $encrypted_param = encrypt_3des_ecb($json_data, $key);
    $encoded_param = urlencode($encrypted_param);
    $query_url = "https://i4tool2.i4.cn/requestBackupSHSHList.xhtml?param=" . $encoded_param;
    
    $response = http_request($query_url);
    $result = json_decode($response, true);
    
    if ($result === null || !isset($result['success']) || !$result['success']) {
        throw new Exception('获取版本列表失败');
    }
    
    return extract_versions_from_response($result);
}

function find_matching_version($available_versions, $requested_version) {
    foreach ($available_versions as $version) {
        // 检查是否匹配build号
        if ($version['build'] === $requested_version) {
            return $version;
        }
        // 检查是否匹配简化版本号
        if ($version['simple_version'] === $requested_version) {
            return $version;
        }
        // 检查是否匹配完整版本号
        if ($version['version'] === $requested_version) {
            return $version;
        }
        // 检查是否包含请求的版本
        if (strpos($version['version'], $requested_version) !== false) {
            return $version;
        }
    }
    
    return null;
}

function try_download_with_version($ecid_decimal, $model, $version) {
    $encrypt_data = [
        'ecid' => (int)$ecid_decimal,
        'model' => $model,
        'time' => (int)round(microtime(true) * 1000),
        'ios' => $version
    ];
    
    $json_data = json_encode($encrypt_data, JSON_NUMERIC_CHECK);
    $key = '2015aisi1234sj7890smartflashi4pc';
    $encrypted_param = encrypt_3des_ecb($json_data, $key);
    $encoded_param = urlencode($encrypted_param);
    $download_url = "https://i4tool2.i4.cn/downloadConvertSHSH.xhtml?param=" . $encoded_param;
    
    $response = http_request($download_url);
    $result = json_decode($response, true);
    
    return [
        'success' => ($result !== null && isset($result['success']) && $result['success'] && isset($result['shsh']) && !empty($result['shsh'])),
        'shsh_content' => isset($result['shsh']) ? $result['shsh'] : null,
        'server_response' => $result
    ];
}

function extract_versions_from_response($result) {
    $versions = [];
    
    if (isset($result['list']) && is_array($result['list'])) {
        foreach ($result['list'] as $item) {
            if (isset($item['ios'])) {
                // 提取build号（括号内的内容）
                $build = '';
                if (preg_match('/\((.*?)\)/', $item['ios'], $matches)) {
                    $build = $matches[1];
                }
                
                $versions[] = [
                    'osver_build' => $item['ios'],
                    'osver' => isset($item['ios_order']) ? $item['ios_order'] : $item['ios'],
                    'build' => $build
                ];
            }
        }
    }
    
    return $versions;
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
            'Accept: application/json, text/plain, */*',
            'Referer: https://www.i4.cn/'
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