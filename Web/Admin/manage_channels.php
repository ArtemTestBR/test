<?php
session_start();
if (!isset($_SESSION['support_authenticated']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$channels_file = '../Config/channels.json';

function load_channels() {
    global $channels_file;
    if (!file_exists($channels_file)) {
        return [];
    }
    return json_decode(file_get_contents($channels_file), true) ?: [];
}

function save_channels($channels) {
    global $channels_file;
    file_put_contents($channels_file, json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    if ($action === 'add') {
        $channelId = $data['channelId'] ?? '';
        if (!$channelId || !str_starts_with($channelId, '@')) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid channel ID']);
            exit;
        }
        $channels = load_channels();
        if (in_array($channelId, $channels)) {
            http_response_code(400);
            echo json_encode(['error' => 'Channel already exists']);
            exit;
        }
        $channels[] = $channelId;
        save_channels($channels);
        echo json_encode(['success' => true]);
    } elseif ($action === 'delete') {
        $channelId = $data['channelId'] ?? '';
        $channels = load_channels();
        $channels = array_filter($channels, fn($c) => $c !== $channelId);
        save_channels(array_values($channels));
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list') {
    echo json_encode(load_channels());
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>