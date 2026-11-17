<?php
// Telegram webhook՝ ✅ Ընդունել / ❌ Մերժել կոճակների սեղմումներ (փոխարինում է server.js-ի poll()-ին)
require __DIR__ . '/lib.php';

$c = cfg();
if (empty($c['BOT_TOKEN']) || empty($c['CHAT_ID']) || empty($c['WEBHOOK_SECRET'])) { http_response_code(500); exit; }

$hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($c['WEBHOOK_SECRET'], $hdr)) { http_response_code(403); exit; }

$u = json_decode(file_get_contents('php://input'), true);
$cq = $u['callback_query'] ?? null;
if (!$cq || empty($cq['message'])) { http_response_code(200); exit; }
if ((string)$cq['message']['chat']['id'] !== (string)$c['CHAT_ID']) { http_response_code(200); exit; }

$action = explode(':', (string)($cq['data'] ?? ''))[0];
if ($action !== 'ok' && $action !== 'no') { http_response_code(200); exit; }
$label = $action === 'ok' ? '✅ Ընդունված է' : '❌ Մերժված է';

try { tg('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $label]); } catch (Throwable $e) {}
try {
    tg('editMessageReplyMarkup', [
        'chat_id' => $cq['message']['chat']['id'],
        'message_id' => $cq['message']['message_id'],
        'reply_markup' => ['inline_keyboard' => [[['text' => $label, 'callback_data' => 'done']]]],
    ]);
} catch (Throwable $e) {}
http_response_code(200);
