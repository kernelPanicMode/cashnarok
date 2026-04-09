<?php
// ── Cashnarok™ – Første gangs oppsett ──────────────────────
// Etter vellykket installasjon omdøpes denne filen til install.php.done
// og kan ikke kjøres igjen.

require_once __DIR__ . '/db.php';

$error   = '';
$success = false;

// ── Check if already installed ────────────────────────────────
function tablesExist(PDO $db): bool {
    try {
        $db->query("SELECT 1 FROM settings LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ── SQL: create tables ────────────────────────────────────────
function createTables(PDO $db): void {
    // PDO/MySQL does not support multiple statements in one exec() call.
    // Each CREATE TABLE must be executed separately.
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        `key`   VARCHAR(64)  PRIMARY KEY,
        `value` TEXT         NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS sheets (
        `key`   VARCHAR(16)  PRIMARY KEY,
        notes   TEXT         DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS income (
        id          INT           AUTO_INCREMENT PRIMARY KEY,
        sheet_key   VARCHAR(16)   NOT NULL,
        name        VARCHAR(255)  NOT NULL,
        budgeted    DECIMAL(10,2) DEFAULT 0,
        actual      DECIMAL(10,2) DEFAULT NULL,
        sort_order  INT           DEFAULT 0,
        FOREIGN KEY (sheet_key) REFERENCES sheets(`key`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS expenses (
        id          INT           AUTO_INCREMENT PRIMARY KEY,
        sheet_key   VARCHAR(16)   NOT NULL,
        name        VARCHAR(255)  NOT NULL,
        budgeted    DECIMAL(10,2) DEFAULT 0,
        actual      DECIMAL(10,2) DEFAULT NULL,
        type        VARCHAR(64)   DEFAULT 'Fast',
        status      VARCHAR(64)   DEFAULT 'Ikke betalt',
        owner       VARCHAR(64)   DEFAULT '',
        note        TEXT          DEFAULT '',
        sort_order  INT           DEFAULT 0,
        FOREIGN KEY (sheet_key) REFERENCES sheets(`key`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT           AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(64)   NOT NULL UNIQUE,
        password_hash VARCHAR(255)  NOT NULL,
        role          ENUM('admin','user') NOT NULL DEFAULT 'user',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ── Seed settings ─────────────────────────────────────────────
function seedSettings(PDO $db, array $data): void {
    $stmt = $db->prepare(
        "INSERT INTO settings (`key`,`value`) VALUES (?,?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
    );
    $owners = json_encode([
        ['name' => $data['owner1'], 'color' => $data['color1']],
        ['name' => $data['owner2'], 'color' => $data['color2']],
    ], JSON_UNESCAPED_UNICODE);

    $rows = [
        ['title',          $data['title']],
        ['subtitle',       $data['subtitle']],
        ['footer',         ''],
        ['owners',         $owners],
        ['types',          json_encode(['Fast','Variabel','Stipulert','Midlertidig'], JSON_UNESCAPED_UNICODE)],
        ['statuses',       json_encode(['Ikke betalt','Betalt','Ikke gjeldende'], JSON_UNESCAPED_UNICODE)],
        ['paidStatus',     'Betalt'],
        ['permanentNotes', ''],
        ['lang',           'no'],
        ['pin',            $data['pin']],
    ];
    foreach ($rows as [$k, $v]) { $stmt->execute([$k, $v]); }
}

// ── Seed demo data ────────────────────────────────────────────
function seedDemoData(PDO $db, string $o1, string $o2): void {
    // MAL sheet
    $db->prepare("INSERT IGNORE INTO sheets (`key`, notes) VALUES ('MAL', '')")->execute();

    $malIncome = [
        [$o1, 'Lønn ' . $o1,  38000],
        [$o2, 'Lønn ' . $o2,  32000],
        [$o1, 'Barnetrygd',    1766],
    ];
    $incStmt = $db->prepare(
        "INSERT INTO income (sheet_key, name, budgeted, actual, sort_order) VALUES ('MAL',?,?,NULL,?)"
    );
    foreach ($malIncome as $i => [, $name, $budgeted]) {
        $incStmt->execute([$name, $budgeted, $i]);
    }

    $malExpenses = [
        ['Boliglån',                 13500, 'Fast',        'Ikke betalt', $o1, ''],
        ['Dagligvarer',               9000, 'Variabel',    'Ikke betalt', $o2, ''],
        ['Strøm – nettleie',          1800, 'Variabel',    'Ikke betalt', $o1, ''],
        ['Strøm – forbruk',           2200, 'Variabel',    'Ikke betalt', $o1, ''],
        ['Internett og TV',            850, 'Fast',        'Ikke betalt', $o1, ''],
        ['Mobiltelefon 1',             499, 'Fast',        'Ikke betalt', $o1, ''],
        ['Mobiltelefon 2',             399, 'Fast',        'Ikke betalt', $o2, ''],
        ['Forsikringer',              3200, 'Fast',        'Ikke betalt', $o1, ''],
        ['Barnehage / SFO',           3315, 'Fast',        'Ikke betalt', $o2, ''],
        ['Billån',                    3800, 'Fast',        'Ikke betalt', $o2, ''],
        ['Bensin og transport',       2000, 'Variabel',    'Ikke betalt', $o2, ''],
        ['Treningssenter',             499, 'Fast',        'Ikke betalt', $o1, ''],
        ['Strømmetjenester',           350, 'Fast',        'Ikke betalt', $o2, ''],
        ['Klær og sko',               1200, 'Variabel',    'Ikke betalt', $o2, ''],
        ['Renovasjon (stipulert)',      650, 'Stipulert',   'Ikke betalt', $o1, 'Årsbeløp fordelt på 12 mnd'],
        ['Kommunale avgifter (stip.)', 1400, 'Stipulert',   'Ikke betalt', $o1, 'Årsbeløp fordelt på 12 mnd'],
        ['Vedlikehold og reparasjon', 1000, 'Stipulert',   'Ikke betalt', $o1, ''],
        ['Fritidsaktiviteter (stip.)',  600, 'Stipulert',   'Ikke betalt', $o2, ''],
        ['Gaver og høytider',          400, 'Variabel',    'Ikke betalt', $o2, ''],
        ['Spising ute',                700, 'Variabel',    'Ikke betalt', $o1, ''],
        ['Ekstraordinær utgift',      2000, 'Midlertidig', 'Ikke betalt', $o1, 'Legg til ved behov'],
        ['Sparing',                   2000, 'Stipulert',   'Ikke gjeldende', $o1, 'Sett opp som fast overføring'],
    ];
    $expStmt = $db->prepare(
        "INSERT INTO expenses (sheet_key, name, budgeted, actual, type, status, owner, note, sort_order)
         VALUES ('MAL',?,?,NULL,?,?,?,?,?)"
    );
    foreach ($malExpenses as $i => $r) {
        $expStmt->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $i]);
    }

    // Demo month: current month
    $mm  = date('m');
    $yyyy = date('Y');
    $key  = $mm . '-' . $yyyy;
    $db->prepare("INSERT IGNORE INTO sheets (`key`, notes) VALUES (?, 'Husk å oppdatere strømforbruk når faktura kommer.')")->execute([$key]);

    // Income — same as MAL but with actuals
    $incStmt2 = $db->prepare(
        "INSERT INTO income (sheet_key, name, budgeted, actual, sort_order) VALUES (?,?,?,?,?)"
    );
    $incStmt2->execute([$key, 'Lønn ' . $o1, 38000, 38000, 0]);
    $incStmt2->execute([$key, 'Lønn ' . $o2, 32000, 31750, 1]);
    $incStmt2->execute([$key, 'Barnetrygd',   1766,  1766, 2]);

    $demoExp = [
        ['Boliglån',                 13500, 13500, 'Fast',        'Betalt',         $o1, ''],
        ['Dagligvarer',               9000,  8640, 'Variabel',    'Betalt',         $o2, ''],
        ['Strøm – nettleie',          1800,  1743, 'Variabel',    'Betalt',         $o1, ''],
        ['Strøm – forbruk',           2200,  null, 'Variabel',    'Ikke betalt',    $o1, ''],
        ['Internett og TV',            850,   850, 'Fast',        'Betalt',         $o1, ''],
        ['Mobiltelefon 1',             499,   499, 'Fast',        'Betalt',         $o1, ''],
        ['Mobiltelefon 2',             399,   399, 'Fast',        'Betalt',         $o2, ''],
        ['Forsikringer',              3200,  3200, 'Fast',        'Betalt',         $o1, ''],
        ['Barnehage / SFO',           3315,  null, 'Fast',        'Ikke betalt',    $o2, ''],
        ['Billån',                    3800,  3800, 'Fast',        'Betalt',         $o2, ''],
        ['Bensin og transport',       2000,  2340, 'Variabel',    'Betalt',         $o2, 'Høyere pga lengre pendling'],
        ['Treningssenter',             499,   499, 'Fast',        'Betalt',         $o1, ''],
        ['Strømmetjenester',           350,   350, 'Fast',        'Betalt',         $o2, ''],
        ['Klær og sko',               1200,  null, 'Variabel',    'Ikke betalt',    $o2, ''],
        ['Renovasjon (stipulert)',      650,  null, 'Stipulert',   'Ikke betalt',    $o1, 'Årsbeløp fordelt på 12 mnd'],
        ['Kommunale avgifter (stip.)', 1400,  1400, 'Stipulert',   'Betalt',         $o1, 'Årsbeløp fordelt på 12 mnd'],
        ['Vedlikehold og reparasjon', 1000,  null, 'Stipulert',   'Ikke betalt',    $o1, ''],
        ['Fritidsaktiviteter (stip.)',  600,   550, 'Stipulert',   'Betalt',         $o2, ''],
        ['Gaver og høytider',          400,  null, 'Variabel',    'Ikke betalt',    $o2, ''],
        ['Spising ute',                700,  null, 'Variabel',    'Ikke betalt',    $o1, ''],
        ['Ekstraordinær utgift',      2000,  null, 'Midlertidig', 'Ikke betalt',    $o1, 'Legg til ved behov'],
        ['Sparing',                   2000,  null, 'Stipulert',   'Ikke gjeldende', $o1, 'Sett opp som fast overføring'],
    ];
    $expStmt2 = $db->prepare(
        "INSERT INTO expenses (sheet_key, name, budgeted, actual, type, status, owner, note, sort_order)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    foreach ($demoExp as $i => $r) {
        $expStmt2->execute([$key, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $i]);
    }
}

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title']    ?? 'Cashnarok™');
    $subtitle = trim($_POST['subtitle'] ?? 'Valhalla Certified™ – For Mortal Wallets');
    $owner1   = trim($_POST['owner1']   ?? 'Anita');
    $color1   =      $_POST['color1']   ?? '#2b6cb0';
    $owner2   = trim($_POST['owner2']   ?? 'Hans');
    $color2   =      $_POST['color2']   ?? '#b83280';
    $pin      = preg_replace('/[^0-9]/', '', $_POST['pin'] ?? '1234');

    if (strlen($pin) < 4) {
        $error = 'PIN-koden må være minst 4 sifre.';
    } elseif (empty($owner1) || empty($owner2)) {
        $error = 'Begge eiernavnene må fylles inn.';
    } else {
        try {
            $db = getDB();
            createTables($db);
            seedSettings($db, [
                'title'    => $title ?: 'Cashnarok™',
                'subtitle' => $subtitle,
                'owner1'   => $owner1,
                'color1'   => $color1,
                'owner2'   => $owner2,
                'color2'   => $color2,
                'pin'      => $pin,
            ]);
            seedDemoData($db, $owner1, $owner2);
            // Disable installer
            rename(__FILE__, __FILE__ . '.done');
            $success = true;
        } catch (Exception $e) {
            $error = 'Databasefeil: ' . $e->getMessage();
        }
    }
}

// ── Check already installed ───────────────────────────────────
$alreadyInstalled = false;
if (!$success) {
    try {
        $db = getDB();
        $alreadyInstalled = tablesExist($db);
    } catch (Exception $e) {
        // DB not reachable yet — show form anyway
    }
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cashnarok™ – Installer</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Segoe UI', system-ui, sans-serif;
      background: linear-gradient(135deg, #1a365d 0%, #2a4a7f 100%);
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      padding: 24px;
    }
    .card {
      background: white; border-radius: 16px; padding: 40px;
      width: 100%; max-width: 500px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.35);
    }
    h1 { font-size: 22px; font-weight: 800; color: #1a365d; margin-bottom: 4px; }
    .sub { font-size: 13px; color: #718096; margin-bottom: 28px; }
    .section-title {
      font-size: 11px; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.6px; color: #718096; margin: 20px 0 10px;
      padding-bottom: 6px; border-bottom: 1px solid #e2e8f0;
    }
    .field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
    .field label { font-size: 12px; font-weight: 600; color: #718096; text-transform: uppercase; letter-spacing: 0.4px; }
    .field input[type=text], .field input[type=password], .field input[type=number] {
      border: 1px solid #e2e8f0; border-radius: 6px; padding: 9px 12px;
      font-size: 14px; font-family: inherit; outline: none; transition: border-color 0.15s;
    }
    .field input:focus { border-color: #3182ce; box-shadow: 0 0 0 3px rgba(49,130,206,0.12); }
    .field-row { display: grid; grid-template-columns: 1fr 44px; gap: 8px; }
    input[type=color] { width: 44px; height: 40px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 3px; cursor: pointer; }
    .btn {
      width: 100%; background: #3182ce; color: white; border: none;
      border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 700;
      cursor: pointer; margin-top: 20px; transition: opacity 0.15s;
    }
    .btn:hover { opacity: 0.88; }
    .error { background: #fff5f5; border: 1px solid #feb2b2; border-radius: 8px; padding: 12px 16px; color: #c53030; font-size: 13px; margin-bottom: 16px; }
    .success-box { text-align: center; }
    .success-box .icon { font-size: 48px; margin-bottom: 12px; }
    .success-box h2 { color: #276749; font-size: 20px; margin-bottom: 8px; }
    .success-box p { color: #718096; font-size: 14px; margin-bottom: 20px; }
    .btn-go { display: inline-block; background: #38a169; color: white; border-radius: 8px; padding: 12px 28px; font-size: 15px; font-weight: 700; text-decoration: none; transition: opacity 0.15s; }
    .btn-go:hover { opacity: 0.88; }
    .already { text-align: center; color: #744210; background: #fffbeb; border: 1px solid #f6e05e; border-radius: 10px; padding: 20px; }
    .hint { font-size: 12px; color: #a0aec0; margin-top: 4px; }
  </style>
</head>
<body>
<div class="card">

<?php if ($success): ?>
  <div class="success-box">
    <div class="icon">✅</div>
    <h2>Installasjon fullført!</h2>
    <p>Databasen er satt opp og demomåned er lagt inn.<br>Installer-siden er deaktivert.</p>
    <a href="/" class="btn-go">Åpne Budsjett →</a>
  </div>

<?php elseif ($alreadyInstalled): ?>
  <div class="already">
    <strong>⚠️ Allerede installert</strong><br><br>
    Tabellene finnes allerede i databasen.<br>
    Slett install.php manuelt og gå til appen.
    <br><br>
    <a href="/" style="color:#2b6cb0;font-weight:600">→ Gå til appen</a>
  </div>

<?php else: ?>
  <h1>🗂 Cashnarok™</h1>
  <p class="sub">Første gangs oppsett – fyll inn og klikk Installer</p>

  <?php if ($error): ?>
    <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="section-title">Applikasjon</div>

    <div class="field">
      <label>Tittel</label>
      <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? 'Cashnarok™') ?>">
    </div>
    <div class="field">
      <label>Undertittel</label>
      <input type="text" name="subtitle" value="<?= htmlspecialchars($_POST['subtitle'] ?? 'Valhalla Certified™ – For Mortal Wallets') ?>">
    </div>

    <div class="section-title">Eiere</div>

    <div class="field">
      <label>Eier 1 – navn og farge</label>
      <div class="field-row">
        <input type="text" name="owner1" placeholder="f.eks. Anita" value="<?= htmlspecialchars($_POST['owner1'] ?? '') ?>">
        <input type="color" name="color1" value="<?= htmlspecialchars($_POST['color1'] ?? '#2b6cb0') ?>">
      </div>
    </div>
    <div class="field">
      <label>Eier 2 – navn og farge</label>
      <div class="field-row">
        <input type="text" name="owner2" placeholder="f.eks. Hans" value="<?= htmlspecialchars($_POST['owner2'] ?? '') ?>">
        <input type="color" name="color2" value="<?= htmlspecialchars($_POST['color2'] ?? '#b83280') ?>">
      </div>
    </div>

    <div class="section-title">Sikkerhet</div>

    <div class="field">
      <label>PIN-kode</label>
      <input type="password" name="pin" placeholder="Minst 4 sifre" maxlength="12"
             style="letter-spacing:4px;font-size:18px"
             value="<?= htmlspecialchars($_POST['pin'] ?? '') ?>">
      <span class="hint">Brukes til å låse appen. Standard er 1234.</span>
    </div>

    <button class="btn" type="submit">🚀 Installer</button>
  </form>
<?php endif; ?>

</div>
</body>
</html>
