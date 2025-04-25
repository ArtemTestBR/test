<?php
session_start();
if (!isset($_SESSION['support_authenticated'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$tickets_file = '../../Config/tickets.json';
$admin_username = $_SESSION['support_username'];

function load_tickets() {
    global $tickets_file;
    if (!file_exists($tickets_file)) {
        return [];
    }
    $json = file_get_contents($tickets_file);
    return json_decode($json, true) ?: [];
}

function save_tickets($tickets) {
    global $tickets_file;
    $json = json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($tickets_file, $json, LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $ticket_id = $data['ticket_id'] ?? '';
    $telegram_user_id = $data['telegram_user_id'] ?? '';
    $reply_text = trim($data['reply_text'] ?? '');

    if (empty($ticket_id) || empty($telegram_user_id) || empty($reply_text)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    $tickets = load_tickets();

    if (!isset($tickets[$telegram_user_id])) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        exit;
    }

    $ticket_found = false;
    foreach ($tickets[$telegram_user_id] as &$ticket) {
        if ($ticket['id'] === $ticket_id) {
            $ticket_found = true;
            // Assign ticket to current admin if not assigned
            $assigned_to = isset($ticket['assigned_to']) ? $ticket['assigned_to'] : null;
            if ($assigned_to === null) {
                $ticket['assigned_to'] = $admin_username;
            }
            // Add reply
            $reply = [
                'text' => $reply_text,
                'timestamp' => date('c'),
                'sender' => 'admin',
                'support_username' => $admin_username
            ];
            $ticket['replies'][] = $reply;
            break;
        }
    }

    if (!$ticket_found) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        exit;
    }

    save_tickets($tickets);

    // Send notification to user via bot
    $url = 'http://localhost:8080/notify_reply';
    $data = [
        'ticket_id' => $ticket_id,
        'reply_text' => $reply_text,
        'telegram_user_id' => $telegram_user_id
    ];
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === false) {
        error_log("Failed to send notification for ticket $ticket_id");
    }

    echo json_encode(['success' => true, 'reply' => $reply]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>