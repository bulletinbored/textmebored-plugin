<?php
/**
 * Plugin Name: textmebored
 * Author: mlzog
 * Description: Private messaging and chat system
 * License: BSD Zero Clause License
 */

function textmebored_init() {
    $pluginManager = App::getInstance()->pluginManager;
    $config = App::getInstance()->config;
    $pdo = App::getInstance()->pdo;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim(base_url(), '/');
    $pluginUrl = $baseUrl . '/plugins/textmebored';
    $apiUrl = $pluginUrl . '/api.php';

    $driver = $config['db_driver'] ?? 'sqlite';

    if (isset($pdo)) {
        if ($driver === 'mysql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS private_messages (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    sender_id INT NOT NULL,
                    recipient_id INT NOT NULL,
                    subject TEXT,
                    content TEXT NOT NULL,
                    is_read INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            try { $pdo->exec("CREATE INDEX idx_pm_recipient ON private_messages(recipient_id, is_read, created_at)"); } catch (Throwable $e) {}
            try { $pdo->exec("CREATE INDEX idx_pm_sender ON private_messages(sender_id, created_at)"); } catch (Throwable $e) {}
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS private_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sender_id INTEGER NOT NULL,
                    recipient_id INTEGER NOT NULL,
                    subject TEXT DEFAULT '',
                    content TEXT NOT NULL,
                    is_read INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_recipient ON private_messages(recipient_id, is_read, created_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_sender ON private_messages(sender_id, created_at)");
        }
    }

    $tmVer = function($rel) use ($pluginUrl) {
        $f = __DIR__ . '/' . $rel;
        return $pluginUrl . '/' . $rel . '?v=' . (file_exists($f) ? filemtime($f) : time());
    };
    $cssUrl = $tmVer('assets/css/textmebored.css');
    $jsUrl = $tmVer('assets/js/textmebored.js');
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
    $nonce = function_exists('csp_nonce') ? csp_nonce() : '';

    // User list used to power the recipient autocomplete in the composer
    // (native datalist + XHR suggestions).
    $tmUsers = [];
    if (isset($pdo)) {
        try {
            $uStmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
            $tmUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    $tmUsersJson = json_encode($tmUsers);

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $nonce = App::getInstance()->cspNonce ?? '';
    $head .= '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">window.textmebored = window.textmebored || {};window.textmebored.apiUrl = ' . json_encode($apiUrl) . ';window.textmebored.baseUrl = ' . json_encode($baseUrl) . ';window.textmebored.csrfToken = ' . json_encode($csrfToken) . ';window.textmebored.currentUserId = ' . json_encode($_SESSION['user_id'] ?? 0) . ';window.textmebored.users = ' . $tmUsersJson . ';</script>' . "\n";

    $footer = '<script src="' . $jsUrl . '" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });

    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });

    $pluginManager->addHook('navbar_icons', function() {
        $baseUrl = rtrim(base_url(), '/');
        $safeTitle = htmlspecialchars(t('messages'), ENT_QUOTES, 'UTF-8');
        echo '<li class="nav-item">';
        echo '<a class="nav-link nav-icon position-relative" href="' . $baseUrl . '/messages" title="' . $safeTitle . '" data-mobile-tab="messages">';
        echo '<i class="fas fa-envelope"></i>';
        echo '</a>';
        echo '</li>';
    });

    $pluginManager->addHook('mobile_tabbar_icons', function() {
        $baseUrl = rtrim(base_url(), '/');
        $safeTitle = htmlspecialchars(t('messages'), ENT_QUOTES, 'UTF-8');
        echo '<a href="' . $baseUrl . '/messages" class="mobile-tab" data-mobile-tab="messages" title="' . $safeTitle . '">';
        echo '<i class="fas fa-envelope"></i>';
        echo '</a>';
    });

    $pluginManager->addHook('mobile_stack_tabs', function() {
        $isActive = !isset($_SESSION['user_id']) ? '' : '';
        echo '<button type="button" class="mobile-stack-tab' . $isActive . '" data-tab="messages" role="tab"><i class="fas fa-envelope"></i></button>';
    });

    $pluginManager->addHook('mobile_stack_panes', function() {
        echo '<div class="mobile-stack-pane" data-pane="messages" id="paneMessages"><div class="mobile-stack-loading">Loading…</div></div>';
    });

    $pluginManager->registerRoute('GET', '/messages', function() {
        textmebored_handle_page('GET');
    }, ['auth']);
    $pluginManager->registerRoute('POST', '/messages', function() {
        textmebored_handle_page('POST');
    }, ['auth']);
}

function textmebored_handle_page(string $method): void
{
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }

    if ($method === 'POST' && isset($_POST['content']) && csrf_validate_request()) {
        $recipientId = (int)($_GET['conversation'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if ($recipientId > 0 && $content !== '') {
            $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, recipient_id, subject, content) VALUES (?, ?, '', ?)");
            $stmt->execute([$_SESSION['user_id'], $recipientId, $content]);
        }
        redirect(rtrim(base_url(), '/') . '/messages?conversation=' . $recipientId);
    }

    $conversationUserId = (int)($_GET['conversation'] ?? 0);
    if ($conversationUserId > 0) {
        $messages = $pdo->prepare("
            SELECT pm.*, u.username as sender_name
            FROM private_messages pm
            JOIN users u ON pm.sender_id = u.id
            WHERE (pm.sender_id = :me1 AND pm.recipient_id = :other1) OR (pm.sender_id = :other2 AND pm.recipient_id = :me2)
            ORDER BY pm.created_at ASC
        ");
        $messages->execute(['me1' => $_SESSION['user_id'], 'me2' => $_SESSION['user_id'], 'other1' => $conversationUserId, 'other2' => $conversationUserId]);
        $messages = $messages->fetchAll();

        $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE recipient_id = :me AND sender_id = :other AND is_read = 0")
            ->execute(['me' => $_SESSION['user_id'], 'other' => $conversationUserId]);

        $otherUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $otherUser->execute([$conversationUserId]);
        $otherUsername = $otherUser->fetchColumn();

        include __DIR__ . '/page/messages.php';
    } else {
        $convStmt = $pdo->prepare("
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
        $convStmt->execute([
            'uid1' => $_SESSION['user_id'],
            'uid2' => $_SESSION['user_id'],
            'uid3' => $_SESSION['user_id'],
            'uid4' => $_SESSION['user_id'],
        ]);
        $conversations = $convStmt->fetchAll();

        $msgStmt = $pdo->prepare("
            SELECT content FROM private_messages
            WHERE (sender_id = :uid1 AND recipient_id = :other1) OR (recipient_id = :uid2 AND sender_id = :other2)
            ORDER BY created_at DESC LIMIT 1
        ");
        $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        foreach ($conversations as &$conv) {
            $otherId = (int)$conv['other_user_id'];
            $msgStmt->execute(['uid1' => $_SESSION['user_id'], 'uid2' => $_SESSION['user_id'], 'other1' => $otherId, 'other2' => $otherId]);
            $conv['last_message'] = $msgStmt->fetchColumn();
            $userStmt->execute(['id' => $otherId]);
            $conv['other_username'] = $userStmt->fetchColumn();
        }
        unset($conv);
        include __DIR__ . '/page/messages.php';
    }
}
