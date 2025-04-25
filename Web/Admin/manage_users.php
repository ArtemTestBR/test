<?php
session_start();
if (!isset($_SESSION['support_authenticated']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$db_file = '../users.db';

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';

        if ($action === 'add') {
            $username = trim($data['username'] ?? '');
            $password = $data['password'] ?? '';
            $role = $data['role'] ?? 'support';

            if (!$username || !$password) {
                http_response_code(400);
                echo json_encode(['error' => 'Username and password are required']);
                exit;
            }
            if (!in_array($role, ['support', 'admin'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid role']);
                exit;
            }
            if (strlen($password) < 8) {
                http_response_code(400);
                echo json_encode(['error' => 'Password must be at least 8 characters long']);
                exit;
            }

            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Username already exists']);
                exit;
            }

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, :role)");
            $stmt->execute(['username' => $username, 'password' => $hashedPassword, 'role' => $role]);
            error_log("Added user: $username with role: $role");
            echo json_encode(['success' => true]);
        } elseif ($action === 'delete') {
            $username = trim($data['username'] ?? '');
            if ($username === 'admin') {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete admin account']);
                exit;
            }
            $stmt = $db->prepare("DELETE FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->rowCount() === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'User not found']);
                exit;
            }
            error_log("Deleted user: $username");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list') {
        $stmt = $db->query("SELECT username, role FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($users);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>