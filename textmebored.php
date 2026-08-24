<?php
/**
 * Plugin Name: textmebored
 * Author: mlzog
 * Description: Private messaging and chat system
 * License: BSD Zero Clause License
 */

function textmebored_init() {
    global $pluginManager, $config, $pdo;

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
    $nonce = $GLOBALS['CSP_NONCE'] ?? '';
    $head .= '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">window.textmebored = window.textmebored || {};window.textmebored.apiUrl = ' . json_encode($apiUrl) . ';window.textmebored.baseUrl = ' . json_encode($baseUrl) . ';window.textmebored.csrfToken = ' . json_encode($csrfToken) . ';window.textmebored.currentUserId = ' . json_encode($_SESSION['user_id'] ?? 0) . ';window.textmebored.users = ' . $tmUsersJson . ';</script>' . "\n";

    $footer = '<script src="' . $jsUrl . '" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });

    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });
}
