<?php
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['ticket_id']) || empty($data['reply_text']) || empty($data['telegram_user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$command = 'python3 bot.py notify ' . escapeshellarg($data['ticket_id']) . ' ' . 
           escapeshellarg($data['reply_text']) . ' ' . 
           escapeshellarg($data['telegram_user_id']) . ' > /dev/null 2>&1 &';
exec($command);

echo json_encode(['success' => true]);
?>