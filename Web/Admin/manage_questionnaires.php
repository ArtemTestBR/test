<?php
session_start();

ini_set('display_errors', 0);

if (!isset($_SESSION['support_authenticated']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');


$questionnaires_file = '../../Config/questionnaires.json';
$db_file = '../users.db';
$bots_file = '../../Config/bots.json';

function load_questionnaires() {
    global $questionnaires_file;
    if (!file_exists($questionnaires_file)) {
        return [];
    }
    return json_decode(file_get_contents($questionnaires_file), true) ?: [];
}

function load_bots() {
    global $bots_file;
    if (!file_exists($bots_file)) {
        return [];
    }
    return json_decode(file_get_contents($bots_file), true) ?: [];
}

function save_questionnaires($questionnaires) {
    global $questionnaires_file;
    file_put_contents($questionnaires_file, json_encode($questionnaires, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function get_bot_name($bot_token) {
    $bots = load_bots();
    foreach ($bots as $bot) {
        if ($bot['token'] === $bot_token) {
            return $bot['name'];
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    if ($action === 'create') {
        $questions = $data['questions'] ?? [];
        $bot_token = $data['bot_token'] ?? '';
        if (count($questions) < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'At least one question required']);
            exit;
        }
        if (!$bot_token) {
            http_response_code(400);
            echo json_encode(['error' => 'Bot token required']);
            exit;
        }
        $bots = load_bots();
        $valid_token = false;
        foreach ($bots as $bot) {
            if ($bot['token'] === $bot_token) {
                $valid_token = true;
                break;
            }
        }
        if (!$valid_token) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid bot token']);
            exit;
        }
        $questionnaires = load_questionnaires();
        // Remove existing questionnaire for this bot
        $questionnaires = array_filter($questionnaires, fn($q) => $q['bot_token'] !== $bot_token);
        $questionnaires[] = [
            'id' => uniqid(),
            'bot_token' => $bot_token,
            'questions' => array_map('trim', $questions)
        ];
        save_questionnaires(array_values($questionnaires));
        echo json_encode(['success' => true]);
    } elseif ($action === 'delete') {
        $id = $data['id'] ?? '';
        $questionnaires = load_questionnaires();
        $questionnaires = array_filter($questionnaires, fn($q) => $q['id'] !== $id);
        save_questionnaires(array_values($questionnaires));
        try {
            $db = new PDO("sqlite:$db_file");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $db->prepare("DELETE FROM questionnaire_answers WHERE questionnaire_id = :id");
            $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
        }
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'list') {
        $questionnaires = load_questionnaires();
        $result = [];
        foreach ($questionnaires as $q) {
            $result[] = [
                'id' => $q['id'],
                'bot_token' => $q['bot_token'],
                'bot_name' => get_bot_name($q['bot_token']),
                'questions' => $q['questions']
            ];
        }
        echo json_encode($result);
    } elseif ($_GET['action'] === 'list_answers') {
        try {
            $db = new PDO("sqlite:$db_file");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $db->query("SELECT user_id, questionnaire_id, bot_token, answers, timestamp FROM questionnaire_answers ORDER BY timestamp DESC");
            $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $questionnaires = load_questionnaires();
            $result = [];
            foreach ($answers as $answer) {
                $q = array_filter($questionnaires, fn($q) => $q['id'] === $answer['questionnaire_id']);
                $q = reset($q);
                $decoded_answers = json_decode($answer['answers'], true);
                $result[] = [
                    'user_id' => $answer['user_id'],
                    'bot_token' => $answer['bot_token'],
                    'bot_name' => get_bot_name($answer['bot_token']),
                    'timestamp' => $answer['timestamp'],
                    'questions' => $q ? $q['questions'] : [],
                    'answers' => $decoded_answers ?: []
                ];
            }
            echo json_encode($result);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>