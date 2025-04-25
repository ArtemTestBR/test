<?php
session_start();
if (!isset($_SESSION['support_authenticated']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$bots_file = '../../Config/bots.json';

function load_bots() {
    global $bots_file;
    if (!file_exists($bots_file)) {
        return [];
    }
    return json_decode(file_get_contents($bots_file), true) ?: [];
}

function save_bots($bots) {
    global $bots_file;
    file_put_contents($bots_file, json_encode($bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'add') {
        $bot_token = $data['botToken'] ?? '';
        $bot_name = trim($data['botName'] ?? '');
        if (!$bot_token || !$bot_name) {
            http_response_code(400);
            echo json_encode(['error' => 'Bot token and name required']);
            exit;
        }
        $bots = load_bots();
        foreach ($bots as $bot) {
            if ($bot['token'] === $bot_token) {
                http_response_code(400);
                echo json_encode(['error' => 'Bot token already exists']);
                exit;
            }
        }
        $bots[] = ['token' => $bot_token, 'name' => $bot_name];
        save_bots($bots);
        echo json_encode(['success' => true]);
    } elseif ($action === 'delete') {
        $bot_token = $data['botToken'] ?? '';
        $bots = load_bots();
        $bots = array_filter($bots, fn($bot) => $bot['token'] !== $bot_token);
        save_bots(array_values($bots));
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list') {
    echo json_encode(load_bots());
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>