<?php
// Load the core bootstrap so we share the same session, config, database
// connection and mail settings. The bootstrap is designed to be included
// multiple times safely (it checks session_status() before session_start()).
$sessionDir = __DIR__ . '/../../data/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0755, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/helpers.php';

// Minimal i18n bootstrap so t()/send_email() work in this standalone endpoint.
$lang = $_SESSION['lang'] ?? $config['default_lang'] ?? 'en';
$GLOBALS['i18n'] = ['core' => []];
$coreLangFile = __DIR__ . '/../../lang/' . $lang . '.php';
if (file_exists($coreLangFile)) {
    $GLOBALS['i18n']['core'] = include $coreLangFile;
}
if (!function_exists('t')) {
    function t($key, $params = [], $scope = 'core') {
        $registry = $GLOBALS['i18n'] ?? [];
        $text = $registry[$scope][$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', $v, $text);
        }
        return $text;
    }
}

header('Content-Type: application/json');

// Always respond with JSON, even on fatal errors, so the frontend never
// receives an HTML error page that would break JSON.parse in production.
set_exception_handler(function ($e) {
    error_log('textmebored API exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'Internal error: ' . $e->getMessage()]);
    exit;
});

$baseUrl = rtrim(!empty($config['base_url']) ? $config['base_url'] : preg_replace('#/plugins/[^/]+/[^/]+$#', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
$pluginUrl = $baseUrl . '/plugins/textmebored';
$apiUrl = $pluginUrl . '/api.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Connect using the configured driver (not hard-coded SQLite).
if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
} else {
    $pdo = new PDO('sqlite:' . ($config['db_path'] ?? __DIR__ . '/../../data/database.sqlite'));
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure the private_messages table exists at runtime. This endpoint is
// standalone and does not run textmebored_init(), and a migrated/production DB
// may be missing the table, which would otherwise cause a 500.
ensure_private_messages_table($pdo);

function textmebored_validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

if ($method === 'GET') {
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = substr($requestUri, strlen($scriptName));
    $path = ltrim($path, '/');
    $pathAction = $path ? explode('/', $path)[0] : '';
    $action = $pathAction ?: $_GET['action'] ?? 'conversations';

    if ($action === 'conversations') {
        // Aggregate query kept strict-GROUP-BY safe (no subqueries in SELECT),
        // then enrich each conversation with its last message and username in
        // PHP. This avoids ONLY_FULL_GROUP_BY errors on MySQL 5.7+.
        $stmt = $pdo->prepare("
            SELECT
                CASE WHEN sender_id = :uid1 THEN recipient_id ELSE sender_id END as other_user_id,
                MAX(created_at) as last_message_at,
                MAX(is_read) as last_read,
                SUM(CASE WHEN recipient_id = :uid2 AND is_read = 0 THEN 1 ELSE 0 END) as unread_count
            FROM private_messages
            WHERE sender_id = :uid3 OR recipient_id = :uid4
            GROUP BY other_user_id
            ORDER BY last_message_at DESC
        ");
        $stmt->execute([
            'uid1' => $_SESSION['user_id'],
            'uid2' => $_SESSION['user_id'],
            'uid3' => $_SESSION['user_id'],
            'uid4' => $_SESSION['user_id'],
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $conversations = [];
        $msgStmt = $pdo->prepare("
            SELECT content FROM private_messages
            WHERE (sender_id = :uid1 AND recipient_id = :other1) OR (recipient_id = :uid2 AND sender_id = :other2)
            ORDER BY created_at DESC LIMIT 1
        ");
        $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");

        foreach ($rows as $row) {
            $otherId = (int)$row['other_user_id'];
            $msgStmt->execute(['uid1' => $_SESSION['user_id'], 'uid2' => $_SESSION['user_id'], 'other1' => $otherId, 'other2' => $otherId]);
            $row['last_message'] = $msgStmt->fetchColumn();
            $userStmt->execute(['id' => $otherId]);
            $row['other_username'] = $userStmt->fetchColumn();
            $conversations[] = $row;
        }

        echo json_encode([
            'success' => true,
            'conversations' => $conversations,
        ]);
        exit;
    }

    if ($action === 'messages') {
        $otherUserId = (int)($_GET['user_id'] ?? 0);
        if ($otherUserId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, sender_id, recipient_id, content, is_read, created_at
            FROM private_messages
            WHERE (sender_id = :me1 AND recipient_id = :other1) OR (sender_id = :other2 AND recipient_id = :me2)
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            'me1' => $_SESSION['user_id'],
            'me2' => $_SESSION['user_id'],
            'other1' => $otherUserId,
            'other2' => $otherUserId,
        ]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE recipient_id = :me1 AND sender_id = :other1 AND is_read = 0")
            ->execute(['me1' => $_SESSION['user_id'], 'other1' => $otherUserId]);

        echo json_encode([
            'success' => true,
            'messages' => $messages,
        ]);
        exit;
    }

    if ($action === 'unread_count') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE recipient_id = ? AND is_read = 0");
        $countStmt->execute([$_SESSION['user_id']]);
        $unreadCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
        exit;
    }

    if ($action === 'resolve_user') {
        $username = trim($_GET['username'] ?? '');
        if ($username === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $stmt->execute([$username, $_SESSION['user_id']]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'user_id' => (int)$userId,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

if ($method === 'POST') {
    if (!textmebored_validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === '') {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $path = ltrim(substr($requestUri, strlen($scriptName)), '/');
        $action = $path ? explode('/', $path)[0] : '';
    }

    if ($action === 'send') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($recipientId <= 0 || $content === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Recipient and content are required']);
            exit;
        }

        $recipientStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $recipientStmt->execute([$recipientId]);
        if (!$recipientStmt->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['error' => 'Recipient not found']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO private_messages (sender_id, recipient_id, subject, content)
            VALUES (?, ?, '', ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $recipientId, $content]);
        $messageId = $pdo->lastInsertId();

        // In-app notification for the recipient (textmebored owns this). The
        // email is sent just below.
        $senderStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $senderStmt->execute([$_SESSION['user_id']]);
        $senderName = $senderStmt->fetchColumn() ?: 'Someone';
        $pmLink = url('messages', ['conversation' => (int)$_SESSION['user_id']], true);
        $notifMsg = t('pm_notification', ['sender' => escape($senderName)]);
        create_notification($pdo, (int)$recipientId, 'pm', $notifMsg, $notifMsg, $pmLink);

        // Notify the recipient by email (case 8).
        $senderStmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
        $senderStmt->execute([$_SESSION['user_id']]);
        $sender = $senderStmt->fetch(PDO::FETCH_ASSOC);
        $recipientStmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
        $recipientStmt->execute([$recipientId]);
        $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);
        if ($recipient && !empty($recipient['email'])) {
            $subject = t('new_pm_subject', ['sender' => $sender['username'] ?? 'Someone']);
            $body = t('new_pm_body', [
                'username' => escape($recipient['username'] ?? ''),
                'sender' => escape($sender['username'] ?? 'Someone'),
                'message' => escape(mb_substr($content, 0, 500)),
                'link' => url('messages', ['conversation' => $recipientId], true),
            ]);
            try {
                send_email($recipient['email'], $subject, $body);
            } catch (Throwable $e) {
                error_log('textmebored: email notification failed: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message_id' => $pdo->lastInsertId(),
        ]);
        exit;
    }

    if ($action === 'resolve_user') {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $stmt->execute([$username, $_SESSION['user_id']]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'user_id' => (int)$userId,
        ]);
        exit;
    }

    if ($action === 'search_users') {
        $query = trim($_POST['query'] ?? '');
        if ($query === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Query is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE username LIKE ? AND id <> ? LIMIT 10");
        $stmt->execute(["{$query}%", $_SESSION['user_id']]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'users' => $users,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;
