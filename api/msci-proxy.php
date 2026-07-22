<?php
/**
 * MSCI API Proxy for HostEurope Shared Hosting
 * Simple date-based lookup with retry logic
 */

// CORS headers - allow your frontend domain
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS requests
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Extract price from MSCI API response for a given date
 */
function extractPriceFromMSCI($data, $dateStr) {
    if (!isset($data['indexes']['INDEX_LEVELS']) || !is_array($data['indexes']['INDEX_LEVELS'])) {
        return ['price' => 'Not found', 'date' => null, 'valid' => false];
    }

    $dateNum = (int)str_replace('-', '', $dateStr);

    foreach ($data['indexes']['INDEX_LEVELS'] as $level) {
        if (isset($level['calc_date']) && (int)$level['calc_date'] === $dateNum) {
            $displayDate = preg_replace('/(\d{4})(\d{2})(\d{2})/', '$1-$2-$3', (string)$level['calc_date']);
            $price = $level['level_eod'] ?? null;

            // Only return valid if we have an actual numeric price
            if ($price !== null && $price !== '' && is_numeric($price)) {
                return [
                    'price' => (float)$price,
                    'date' => $displayDate,
                    'valid' => true
                ];
            }

            // Price exists but is not a valid number - treat as not found for retry purposes
            return [
                'price' => 'Not found',
                'date' => $displayDate,
                'valid' => false
            ];
        }
    }

    return ['price' => 'Not found', 'date' => null, 'valid' => false];
}

/**
 * Make HTTP request via cURL
 */
function makeRequest($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MSCI-Proxy/1.0)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'body' => $body ?: '',
        'httpCode' => $httpCode,
        'error' => $error
    ];
}

/**
 * Validate date string (YYYY-MM-DD or YYYYMMDD format)
 */
function validateDate($dateParam) {
    // Try YYYY-MM-DD format first
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
        try {
            $date = DateTime::createFromFormat('Y-m-d', $dateParam);
            if ($date && $date->format('Y-m-d') === $dateParam) {
                return $date;
            }
        } catch (Exception $e) {
            return null;
        }
    }

    // Try YYYYMMDD format
    if (preg_match('/^\d{8}$/', $dateParam)) {
        $year = substr($dateParam, 0, 4);
        $month = substr($dateParam, 4, 2);
        $day = substr($dateParam, 6, 2);
        $formatted = "{$year}-{$month}-{$day}";
        try {
            $date = DateTime::createFromFormat('Y-m-d', $formatted);
            if ($date) {
                return $date;
            }
        } catch (Exception $e) {
            return null;
        }
    }

    return null;
}

/**
 * Fetch MSCI price with retry logic (tries each day until finding valid data)
 */
function fetchPriceWithRetry($dateParam, $maxRetries = 10) {
    $currentDate = validateDate($dateParam);

    if (!$currentDate) {
        return ['error' => 'Invalid date format. Use YYYY-MM-DD or YYYYMMDD'];
    }

    $apiUrl = 'https://app2.msci.com/products/service/index/indexmaster/getLevelDataForGraph';

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $dateYMD = $currentDate->format('Ymd');
        $formattedDate = $currentDate->format('Y-m-d');

        // Build query parameters
        $params = [
            'currency_symbol' => 'USD',
            'index_variant' => 'STRD',
            'start_date' => $dateYMD,
            'end_date' => $dateYMD,
            'index_codes' => '892400',
            'data_frequency' => 'DAILY'
        ];

        $queryString = http_build_query($params);
        $fullUrl = $apiUrl . '?' . $queryString;

        $response = makeRequest($fullUrl);

        if ($response['httpCode'] === 200 && !$response['error']) {
            $data = json_decode($response['body'], true);
            $result = extractPriceFromMSCI($data, $formattedDate);

            if ($result['valid'] && $result['price'] !== null && $result['price'] !== '') {
                return $result;
            }
        }

        // Try next day
        $currentDate->modify('+1 day');
    }

    return ['error' => "Failed after {$maxRetries} attempts", 'date' => null];
}

// Main endpoint - expects ?date=YYYY-MM-DD or ?date=YYYYMMDD
if (isset($_GET['date'])) {
    $retries = isset($_GET['retries']) ? (int)$_GET['retries'] : 10;
    $result = fetchPriceWithRetry($_GET['date'], $retries);
    echo json_encode($result);
    exit;
}

// No date parameter provided
http_response_code(400);
echo json_encode(['error' => 'Missing required parameter: date (use YYYY-MM-DD or YYYYMMDD format)']);