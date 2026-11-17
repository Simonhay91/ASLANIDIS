<?php
// Ընդհանուր ֆունկցիաներ request.php-ի և webhook.php-ի համար։
function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        $c = is_file($f) ? require $f : [];
    }
    return $c;
}

function tg(string $method, array $body): array {
    $c = cfg();
    $url = 'https://api.telegram.org/bot' . $c['BOT_TOKEN'] . '/' . $method;
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['ok'])) {
        error_log('Telegram ' . $method . ': ' . (is_string($raw) ? $raw : 'no response'));
        throw new RuntimeException('telegram');
    }
    return $j;
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
}

function out(int $code, array $obj): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj);
    exit;
}
