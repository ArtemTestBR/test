<?php
session_start();
if (!isset($_SESSION['support_authenticated'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$tickets_file = '../../Config/tickets.json';
$tickets = file_exists($tickets_file) ? json_decode(file_get_contents($tickets_file), true) : [];

$ticket_id = $data['ticket_id'];
$found = false;
$telegram_user_id = null;

foreach ($tickets as &$user_tickets) {
    foreach ($user_tickets as &$ticket) {
        if ($ticket['id'] === $ticket_id) {
            if ($ticket['status'] === 'Закрыт') {
                http_response_code(400);
                echo json_encode(['error' => 'Ticket already closed']);
                exit;
            }
            $ticket['status'] = 'Закрыт';
            $telegram_user_id = $ticket['telegram_user_id'] ?? null;
            $found = true;
            break 2;
        }
    }
}

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
    exit;
}

if (!file_put_contents($tickets_file, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save ticket status']);
    exit;
}

if ($telegram_user_id) {
    $ch = curl_init('http://localhost:8080/notify_close');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'ticket_id' => $ticket_id,
        'telegram_user_id' => $telegram_user_id
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('Failed to notify Telegram bot: ' . curl_error($ch));
    }
    curl_close($ch);
}

echo json_encode(['success' => true]);
?>