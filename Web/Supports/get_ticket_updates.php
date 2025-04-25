<?php
session_start();
if (!isset($_SESSION['support_authenticated'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$ticket_id = $_GET['ticket_id'] ?? '';

if (empty($ticket_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ticket ID required']);
    exit;
}

$tickets = file_exists('../../Config/tickets.json') ? json_decode(file_get_contents('../../Config/tickets.json'), true) : [];

foreach ($tickets as $user_tickets) {
    foreach ($user_tickets as $ticket) {
        if ($ticket['id'] === $ticket_id) {
            // Возвращаем и ответы, и данные тикета (для аватарки и имени пользователя)
            echo json_encode([
                'replies' => $ticket['replies'] ?? [],
                'ticketData' => [
                    'telegram_username' => $ticket['telegram_username'] ?? null,
                    'telegram_user_id' => $ticket['telegram_user_id'] ?? null,
                    'profile_photo_url' => $ticket['profile_photo_url'] ?? null
                ]
            ]);
            exit;
        }
    }
}

http_response_code(404);
echo json_encode(['error' => 'Ticket not found']);
?>