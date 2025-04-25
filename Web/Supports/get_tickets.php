<?php
session_start();

ini_set('display_errors', 0);

if (!isset($_SESSION['support_authenticated'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$tickets_file = '../../Config/tickets.json';
$current_admin = $_SESSION['support_username'];
$tab = $_GET['tab'] ?? 'all';

function load_tickets() {
    global $tickets_file;
    if (!file_exists($tickets_file)) {
        return [];
    }
    $json = file_get_contents($tickets_file);
    return json_decode($json, true) ?: [];
}

$tickets = load_tickets();
$filtered_tickets = [];

foreach ($tickets as $user_id => $user_tickets) {
    foreach ($user_tickets as $ticket) {
        $assigned_to = isset($ticket['assigned_to']) ? $ticket['assigned_to'] : null;
        if ($ticket['status'] === 'Открыт') {
            if ($tab === 'all' && $assigned_to === null) {
                $filtered_tickets[$user_id][] = array_merge($ticket, ['telegram_user_id' => $user_id]);
            } elseif ($tab === 'my' && $assigned_to === $current_admin) {
                $filtered_tickets[$user_id][] = array_merge($ticket, ['telegram_user_id' => $user_id]);
            }
        }
    }
}

echo json_encode(array_values($filtered_tickets));
?>