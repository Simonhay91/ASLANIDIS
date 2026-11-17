<?php
// Հայտի ընդունում -> Telegram (փոխարինում է server.js-ի POST /api/request-ին)
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false]);
$c = cfg();
if (empty($c['BOT_TOKEN']) || empty($c['CHAT_ID'])) out(500, ['ok' => false]);

// Սահմանափակում՝ IP-ի համար 5 հայտ 10 րոպեում
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$rl = sys_get_temp_dir() . '/aslan_rl_' . md5($ip);
$now = time();
$hits = is_file($rl) ? array_filter(array_map('intval', explode(',', (string)file_get_contents($rl))), fn($t) => $now - $t < 600) : [];
$hits[] = $now;
@file_put_contents($rl, implode(',', $hits));
if (count($hits) > 5) out(429, ['ok' => false]);

$raw = file_get_contents('php://input', false, null, 0, 10 * 1024 + 1);
if ($raw === false || strlen($raw) > 10 * 1024) out(400, ['ok' => false]);
$b = json_decode($raw, true);
if (!is_array($b)) out(400, ['ok' => false]);

if (!empty($b['website'])) out(200, ['ok' => true]); // թակարդ ռոբոտների համար

$s = fn($k, $max) => is_string($b[$k] ?? null) ? mb_substr(trim($b[$k]), 0, $max) : '';
$name = $s('name', 80); $phone = $s('phone', 30); $tour = $s('tour', 80);
$date = $s('date', 40); $comment = $s('comment', 600);
$people = $b['people'] ?? null;
$people = is_numeric($people) ? (float)$people : 0;
$lang = in_array($b['lang'] ?? '', ['hy', 'en', 'el'], true) ? $b['lang'] : 'hy';

if (mb_strlen($name) < 2) out(400, ['ok' => false]);
if (!preg_match('/^[+\d][\d\s()\-]{6,24}$/', $phone)) out(400, ['ok' => false]);
if ($people != floor($people) || $people < 1 || $people > 60) out(400, ['ok' => false]);
$people = (int)$people;

$id = strtoupper(base_convert((string)(int)(microtime(true) * 1000), 10, 36));
$langName = ['hy' => 'Հայերեն', 'en' => 'English', 'el' => 'Ελληνικά'][$lang];
$text = "🚌 <b>Նոր հայտ #$id</b>\n\n"
    . "<b>Անուն՝</b> " . esc($name) . "\n"
    . "<b>Հեռախոս՝</b> " . esc($phone) . "\n"
    . "<b>Տուր՝</b> " . esc($tour ?: '—') . "\n"
    . "<b>Ուղևորներ՝</b> $people\n"
    . "<b>Ամսաթիվ՝</b> " . esc($date ?: '—') . "\n"
    . "<b>Էջի լեզուն՝</b> $langName\n"
    . ($comment !== '' ? "<b>Մեկնաբանություն՝</b> " . esc($comment) . "\n" : '');

try {
    tg('sendMessage', [
        'chat_id' => $c['CHAT_ID'],
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => ['inline_keyboard' => [[
            ['text' => '✅ Ընդունել', 'callback_data' => "ok:$id"],
            ['text' => '❌ Մերժել', 'callback_data' => "no:$id"],
        ]]],
    ]);
} catch (Throwable $e) {
    out(502, ['ok' => false]);
}
out(200, ['ok' => true]);
