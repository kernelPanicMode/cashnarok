<?php
// ── Cashnarok™ – REST API ───────────────────────────────────
session_start();
header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Helpers ───────────────────────────────────────────────────

function ensureUsersTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(64)  NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') NOT NULL DEFAULT 'user',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getAuthMode(PDO $db): string {
    try {
        $s = $db->prepare("SELECT `value` FROM settings WHERE `key`='auth_mode'");
        $s->execute();
        return $s->fetchColumn() ?: 'pin';
    } catch (Exception $e) { return 'pin'; }
}

// ── Public endpoints (no auth required) ──────────────────────

if ($action === 'check_auth') {
    $authMode = 'pin';
    try { $authMode = getAuthMode(getDB()); } catch (Exception $e) {}
    echo json_encode([
        'authenticated' => !empty($_SESSION['budsjett_auth']),
        'username'      => $_SESSION['budsjett_username'] ?? null,
        'role'          => $_SESSION['budsjett_role']     ?? null,
        'auth_mode'     => $authMode,
    ]);
    exit;
}

if ($action === 'login' && $method === 'POST') {
    try {
        $db       = getDB();
        $authMode = getAuthMode($db);

        if ($authMode === 'users') {
            $username = trim($body['username'] ?? '');
            $password = $body['password'] ?? '';
            if (!$username || !$password) {
                echo json_encode(['ok' => false, 'error' => 'Mangler brukernavn eller passord']);
                exit;
            }
            ensureUsersTable($db);
            $stmt = $db->prepare("SELECT id, password_hash, role FROM users WHERE username=?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['budsjett_auth']     = true;
                $_SESSION['budsjett_username'] = $username;
                $_SESSION['budsjett_role']     = $user['role'];
                echo json_encode(['ok' => true, 'role' => $user['role'], 'username' => $username]);
            } else {
                echo json_encode(['ok' => false]);
            }
        } else {
            // PIN mode (original behaviour)
            $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'pin'");
            $stmt->execute();
            $row  = $stmt->fetch();
            $pin  = $row ? $row['value'] : '1234';
            if (($body['pin'] ?? '') === $pin) {
                $_SESSION['budsjett_auth'] = true;
                unset($_SESSION['budsjett_username'], $_SESSION['budsjett_role']);
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false]);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

// ── Auth guard ────────────────────────────────────────────────
if (empty($_SESSION['budsjett_auth'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Ikke autentisert']);
    exit;
}

try {
    $db = getDB();
    route($action, $method, $body, $db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Router ────────────────────────────────────────────────────
function route(string $action, string $method, array $body, PDO $db): void {

    $isAdmin = ($_SESSION['budsjett_role'] ?? '') === 'admin';

    // ── Settings ──────────────────────────────────────────────
    if ($action === 'settings' && $method === 'GET') {
        $stmt = $db->query("SELECT `key`, `value` FROM settings");
        $out  = [];
        foreach ($stmt->fetchAll() as $r) {
            $k = $r['key'];
            $v = $r['value'];
            if (in_array($k, ['owners', 'types', 'statuses'])) {
                $out[$k] = json_decode($v, true);
            } else {
                $out[$k] = $v;
            }
        }
        echo json_encode($out);
        return;
    }

    if ($action === 'settings' && $method === 'POST') {
        $allowed = ['title','subtitle','footer','owners','types','statuses','paidStatus',
                    'permanentNotes','lang','currency','darkMode','auth_mode'];
        $stmt = $db->prepare(
            "INSERT INTO settings (`key`,`value`) VALUES (?,?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
        );
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $body)) continue;
            $v = is_array($body[$k])
                ? json_encode($body[$k], JSON_UNESCAPED_UNICODE)
                : (string)$body[$k];
            $stmt->execute([$k, $v]);
        }
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'change_pin' && $method === 'POST') {
        $pin = preg_replace('/[^0-9]/', '', $body['pin'] ?? '');
        if (strlen($pin) < 4) {
            echo json_encode(['ok' => false, 'error' => 'PIN må være minst 4 sifre']);
            return;
        }
        $stmt = $db->prepare(
            "INSERT INTO settings (`key`,`value`) VALUES ('pin',?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
        );
        $stmt->execute([$pin]);
        echo json_encode(['ok' => true]);
        return;
    }

    // ── User management ───────────────────────────────────────
    // In pin mode anyone authenticated can manage users (no roles exist yet).
    // In users mode only admins can manage users.
    $authMode      = getAuthMode($db);
    $canManageUsers = ($authMode === 'pin') || $isAdmin;

    if ($action === 'users' && $method === 'GET') {
        if (!$canManageUsers) { http_response_code(403); echo json_encode(['error' => 'Kun admin']); return; }
        ensureUsersTable($db);
        $stmt = $db->query(
            "SELECT id, username, role,
                    DATE_FORMAT(created_at,'%d.%m.%Y') AS created
             FROM users ORDER BY role DESC, username"
        );
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        return;
    }

    if ($action === 'users' && $method === 'POST') {
        if (!$canManageUsers) { http_response_code(403); echo json_encode(['error' => 'Kun admin']); return; }
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $role     = in_array($body['role'] ?? '', ['admin','user']) ? $body['role'] : 'user';
        if (!$username || strlen($password) < 6) {
            echo json_encode(['ok' => false, 'error' => 'Brukernavn mangler eller passord er for kort (min. 6 tegn)']);
            return;
        }
        ensureUsersTable($db);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?,?,?)");
            $stmt->execute([$username, $hash, $role]);
            echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'Brukernavnet er allerede i bruk']);
        }
        return;
    }

    if ($action === 'user' && $method === 'DELETE') {
        if (!$canManageUsers) { http_response_code(403); echo json_encode(['error' => 'Kun admin']); return; }
        $id = (int)($_GET['id'] ?? 0);
        ensureUsersTable($db);
        $stmt = $db->prepare("SELECT username, role FROM users WHERE id=?");
        $stmt->execute([$id]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) { echo json_encode(['ok' => false, 'error' => 'Bruker ikke funnet']); return; }
        if ($target['username'] === ($_SESSION['budsjett_username'] ?? '')) {
            echo json_encode(['ok' => false, 'error' => 'Du kan ikke slette din egen bruker']); return;
        }
        if ($target['role'] === 'admin') {
            $cnt = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            if ($cnt <= 1) { echo json_encode(['ok' => false, 'error' => 'Kan ikke slette siste administrator']); return; }
        }
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'user_password' && $method === 'POST') {
        $id          = (int)($body['id'] ?? 0);
        $newPassword = $body['password'] ?? '';
        ensureUsersTable($db);
        $stmt = $db->prepare("SELECT username FROM users WHERE id=?");
        $stmt->execute([$id]);
        $targetUser   = $stmt->fetchColumn();
        $isOwnAccount = $targetUser === ($_SESSION['budsjett_username'] ?? '');
        if (!$isOwnAccount && !$isAdmin) {
            http_response_code(403); echo json_encode(['error' => 'Ingen tilgang']); return;
        }
        if (strlen($newPassword) < 6) {
            echo json_encode(['ok' => false, 'error' => 'Passord må være minst 6 tegn']); return;
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $id]);
        echo json_encode(['ok' => true]);
        return;
    }

    // ── Sheets ────────────────────────────────────────────────
    if ($action === 'sheets' && $method === 'GET') {
        $stmt   = $db->query("SELECT `key` FROM sheets");
        $keys   = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $months = array_values(array_filter($keys, fn($k) => $k !== 'MAL'));
        usort($months, function ($a, $b) {
            [$am, $ay] = explode('-', $a);
            [$bm, $by] = explode('-', $b);
            return mktime(0,0,0,(int)$am,1,(int)$ay) - mktime(0,0,0,(int)$bm,1,(int)$by);
        });
        $hasMal = in_array('MAL', $keys);
        $result = $hasMal ? array_merge(['MAL'], $months) : $months;
        echo json_encode(array_values($result));
        return;
    }

    if ($action === 'sheet' && $method === 'GET') {
        $key = $_GET['key'] ?? '';

        $stmt = $db->prepare(
            "SELECT id, name, budgeted, actual
               FROM income WHERE sheet_key=? ORDER BY sort_order, id"
        );
        $stmt->execute([$key]);
        $income = array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'name'     => $r['name'],
            'budgeted' => (float)$r['budgeted'],
            'actual'   => $r['actual'] !== null ? (float)$r['actual'] : null,
        ], $stmt->fetchAll());

        $stmt = $db->prepare(
            "SELECT id, name, budgeted, actual, type, status, owner, note
               FROM expenses WHERE sheet_key=? ORDER BY sort_order, id"
        );
        $stmt->execute([$key]);
        $expenses = array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'name'     => $r['name'],
            'budgeted' => (float)$r['budgeted'],
            'actual'   => $r['actual'] !== null ? (float)$r['actual'] : null,
            'type'     => $r['type'],
            'status'   => $r['status'],
            'owner'    => $r['owner'],
            'note'     => $r['note'] ?? '',
        ], $stmt->fetchAll());

        $stmt = $db->prepare("SELECT notes FROM sheets WHERE `key`=?");
        $stmt->execute([$key]);
        $notes = $stmt->fetchColumn() ?: '';

        echo json_encode(['income' => $income, 'expenses' => $expenses, 'notes' => $notes]);
        return;
    }

    if ($action === 'sheet' && $method === 'POST') {
        $key      = $body['key']       ?? '';
        $copyFrom = $body['copy_from'] ?? 'MAL';
        if (!$key) { echo json_encode(['ok' => false, 'error' => 'Mangler nøkkel']); return; }

        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key`='paidStatus'");
        $stmt->execute();
        $paidStatus = $stmt->fetchColumn() ?: 'Betalt';

        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key`='statuses'");
        $stmt->execute();
        $statusesRaw = $stmt->fetchColumn() ?: '["Ikke betalt","Betalt","Ikke gjeldende"]';
        $statuses    = json_decode($statusesRaw, true);
        $defaultUnpaid = '';
        foreach ($statuses as $s) {
            if ($s !== $paidStatus) { $defaultUnpaid = $s; break; }
        }

        $db->beginTransaction();

        $stmt = $db->prepare("INSERT IGNORE INTO sheets (`key`, notes) VALUES (?, '')");
        $stmt->execute([$key]);

        $src = $db->prepare("SELECT name, budgeted, sort_order FROM income WHERE sheet_key=?");
        $src->execute([$copyFrom]);
        $ins = $db->prepare("INSERT INTO income (sheet_key, name, budgeted, actual, sort_order) VALUES (?,?,?,NULL,?)");
        foreach ($src->fetchAll() as $r) {
            $ins->execute([$key, $r['name'], $r['budgeted'], $r['sort_order']]);
        }

        $src = $db->prepare("SELECT name, budgeted, type, status, owner, sort_order FROM expenses WHERE sheet_key=?");
        $src->execute([$copyFrom]);
        $ins = $db->prepare(
            "INSERT INTO expenses (sheet_key, name, budgeted, actual, type, status, owner, note, sort_order)
             VALUES (?,?,?,NULL,?,?,?,?,?)"
        );
        foreach ($src->fetchAll() as $r) {
            $newStatus = ($r['status'] === $paidStatus) ? $defaultUnpaid : $r['status'];
            $ins->execute([$key, $r['name'], $r['budgeted'], $r['type'], $newStatus, $r['owner'], '', $r['sort_order']]);
        }

        $db->commit();
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'sheet' && $method === 'DELETE') {
        $key = $_GET['key'] ?? '';
        if ($key === 'MAL') {
            echo json_encode(['ok' => false, 'error' => 'Kan ikke slette MAL']);
            return;
        }
        $stmt = $db->prepare("DELETE FROM sheets WHERE `key`=?");
        $stmt->execute([$key]);
        echo json_encode(['ok' => true]);
        return;
    }

    // ── Apply MAL to existing month ───────────────────────────
    if ($action === 'apply_mal' && $method === 'POST') {
        $key = $body['key'] ?? '';
        if (!$key || $key === 'MAL') {
            echo json_encode(['ok' => false, 'error' => 'Ugyldig nøkkel']);
            return;
        }

        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key`='paidStatus'");
        $stmt->execute();
        $paidStatus = $stmt->fetchColumn() ?: 'Betalt';

        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key`='statuses'");
        $stmt->execute();
        $statusesRaw = $stmt->fetchColumn() ?: '["Ikke betalt","Betalt","Ikke gjeldende"]';
        $statuses = json_decode($statusesRaw, true);
        $defaultUnpaid = '';
        foreach ($statuses as $s) {
            if ($s !== $paidStatus) { $defaultUnpaid = $s; break; }
        }

        $db->beginTransaction();

        $db->prepare("INSERT IGNORE INTO sheets (`key`, notes) VALUES (?, '')")->execute([$key]);
        $db->prepare("DELETE FROM income   WHERE sheet_key=?")->execute([$key]);
        $db->prepare("DELETE FROM expenses WHERE sheet_key=?")->execute([$key]);

        $src = $db->prepare("SELECT name, budgeted, sort_order FROM income WHERE sheet_key='MAL'");
        $src->execute();
        $ins = $db->prepare("INSERT INTO income (sheet_key, name, budgeted, actual, sort_order) VALUES (?,?,?,NULL,?)");
        foreach ($src->fetchAll() as $r) {
            $ins->execute([$key, $r['name'], $r['budgeted'], $r['sort_order']]);
        }

        $src = $db->prepare("SELECT name, budgeted, type, status, owner, sort_order FROM expenses WHERE sheet_key='MAL'");
        $src->execute();
        $ins = $db->prepare(
            "INSERT INTO expenses (sheet_key, name, budgeted, actual, type, status, owner, note, sort_order)
             VALUES (?,?,?,NULL,?,?,?,?,?)"
        );
        foreach ($src->fetchAll() as $r) {
            $newStatus = ($r['status'] === $paidStatus) ? $defaultUnpaid : $r['status'];
            $ins->execute([$key, $r['name'], $r['budgeted'], $r['type'], $newStatus, $r['owner'], '', $r['sort_order']]);
        }

        $db->commit();
        echo json_encode(['ok' => true]);
        return;
    }

    // ── Notes ─────────────────────────────────────────────────
    if ($action === 'notes' && $method === 'POST') {
        $stmt = $db->prepare("UPDATE sheets SET notes=? WHERE `key`=?");
        $stmt->execute([$body['notes'] ?? '', $body['key'] ?? '']);
        echo json_encode(['ok' => true]);
        return;
    }

    // ── Income ────────────────────────────────────────────────
    if ($action === 'income' && $method === 'POST') {
        $actual = (isset($body['actual']) && $body['actual'] !== null && $body['actual'] !== '')
                  ? (float)$body['actual'] : null;
        $ord = $db->prepare("SELECT COALESCE(MAX(sort_order)+1,0) FROM income WHERE sheet_key=?");
        $ord->execute([$body['sheet_key']]);
        $sortOrder = (int)$ord->fetchColumn();

        $stmt = $db->prepare(
            "INSERT INTO income (sheet_key, name, budgeted, actual, sort_order) VALUES (?,?,?,?,?)"
        );
        $stmt->execute([$body['sheet_key'], $body['name'], (float)($body['budgeted'] ?? 0), $actual, $sortOrder]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        return;
    }

    if ($action === 'income' && $method === 'PUT') {
        $id     = (int)($_GET['id'] ?? 0);
        $actual = (isset($body['actual']) && $body['actual'] !== null && $body['actual'] !== '')
                  ? (float)$body['actual'] : null;
        $stmt = $db->prepare("UPDATE income SET name=?, budgeted=?, actual=? WHERE id=?");
        $stmt->execute([$body['name'], (float)($body['budgeted'] ?? 0), $actual, $id]);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'income' && $method === 'DELETE') {
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM income WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        return;
    }

    // ── Expenses ──────────────────────────────────────────────
    if ($action === 'expense' && $method === 'POST') {
        $actual = (isset($body['actual']) && $body['actual'] !== null && $body['actual'] !== '')
                  ? (float)$body['actual'] : null;
        $ord = $db->prepare("SELECT COALESCE(MAX(sort_order)+1,0) FROM expenses WHERE sheet_key=?");
        $ord->execute([$body['sheet_key']]);
        $sortOrder = (int)$ord->fetchColumn();

        $stmt = $db->prepare(
            "INSERT INTO expenses (sheet_key, name, budgeted, actual, type, status, owner, note, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $body['sheet_key'],
            $body['name'],
            (float)($body['budgeted'] ?? 0),
            $actual,
            $body['type']   ?? 'Fast',
            $body['status'] ?? 'Ikke betalt',
            $body['owner']  ?? '',
            $body['note']   ?? '',
            $sortOrder,
        ]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        return;
    }

    if ($action === 'expense' && $method === 'PUT') {
        $id     = (int)($_GET['id'] ?? 0);
        $actual = (isset($body['actual']) && $body['actual'] !== null && $body['actual'] !== '')
                  ? (float)$body['actual'] : null;
        $stmt = $db->prepare(
            "UPDATE expenses SET name=?, budgeted=?, actual=?, type=?, status=?, owner=?, note=? WHERE id=?"
        );
        $stmt->execute([
            $body['name'],
            (float)($body['budgeted'] ?? 0),
            $actual,
            $body['type']   ?? 'Fast',
            $body['status'] ?? 'Ikke betalt',
            $body['owner']  ?? '',
            $body['note']   ?? '',
            $id,
        ]);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'expense' && $method === 'DELETE') {
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM expenses WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Ukjent handling: ' . $action]);
}
