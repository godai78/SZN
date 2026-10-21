<?php
// ===== Bartek Biedrzycki 2026 =====
// ===== https://github.com/godai78/SZN =====

// ===== KONFIGURACJA =====
const PASSWORD  = 'haslo';          // hasło do panelu redaktora
const SITE_NAME = 'nazwa pisma';
const BASE_URL  = '';                    // opcjonalnie np. 'https://zgloszenia.twojadomena.pl/index.php'; puste = automatycznie
const STATUSES  = ['otrzymane', 'w czytaniu', 'czeka na decyzję', 'przyjęte', 'odrzucone', 'wycofane'];
const DATA_FILE = __DIR__ . '/data.php'; // dane (plik jest zabezpieczony przed odczytem z WWW)
// ========================

date_default_timezone_set('Europe/Warsaw');

const CSS = 'body{font:16px/1.5 system-ui,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#222}'
    . 'table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:.5rem;text-align:left;vertical-align:top}'
    . 'input,select{font:inherit;padding:.3rem;box-sizing:border-box}button{font:inherit;padding:.3rem .8rem;cursor:pointer}'
    . '.row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end}.row label{display:flex;flex-direction:column;font-size:.85rem}'
    . '.link{width:100%;font-size:.8rem;color:#555}.box{background:#f5f5f5;padding:1rem;border-radius:6px;margin:1rem 0}'
    . '.big{font-size:1.6rem;font-weight:600}small,.muted{color:#666}';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page($title, $body) {
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . h($title) . '</title><style>' . CSS . '</style></head><body>' . $body . '</body></html>';
}

// ---------- dane ----------
function load_data() {
    if (!is_file(DATA_FILE)) return [];
    $raw = (string)file_get_contents(DATA_FILE);
    $pos = strpos($raw, "\n");
    if ($pos === false) return [];
    $d = json_decode(substr($raw, $pos + 1), true);
    return is_array($d) ? $d : [];
}

function save_data($d) {
    $fp = fopen(DATA_FILE, 'c');
    if (!$fp) { http_response_code(500); exit('Brak zapisu do pliku danych.'); }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, "<?php http_response_code(404); exit; ?>\n" . json_encode(array_values($d), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// liczniki: otrzymane = wszystkie wpisy; w kolejce = otrzymane bez przyjętych, odrzuconych i wycofanych
function counts($data) {
    $c = ['total' => count($data), 'accepted' => 0, 'rejected' => 0, 'withdrawn' => 0];
    foreach ($data as $e) {
        if ($e['status'] === 'przyjęte') $c['accepted']++;
        if ($e['status'] === 'odrzucone') $c['rejected']++;
        if ($e['status'] === 'wycofane') $c['withdrawn']++;
    }
    $c['queue'] = $c['total'] - $c['accepted'] - $c['rejected'] - $c['withdrawn'];
    return $c;
}

// czas oczekiwania: dni od dodania do dziś; dla wpisów zamkniętych (przyjęte/odrzucone/wycofane) pusty tekst
function waiting($e) {
    if (in_array($e['status'], ['przyjęte', 'odrzucone', 'wycofane'], true)) return '';
    $d = date_diff(date_create(substr($e['created'], 0, 10)), date_create('today'));
    $n = $d->invert ? 0 : (int)$d->days;
    return $n . ' ' . ($n === 1 ? 'dzień' : 'dni');
}

// ---------- widok publiczny (autor) ----------
if (isset($_GET['t'])) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $t = (string)$_GET['t'];
    $found = null;
    $all = load_data();
    foreach ($all as $e) {
        if (hash_equals((string)$e['token'], $t)) { $found = $e; break; }
    }
    if (!$found) {
        http_response_code(404);
        page('Nie znaleziono', '<h1>Nie znaleziono zgłoszenia</h1><p class="muted">Sprawdź, czy link jest kompletny.</p>');
        exit;
    }
    $c = counts($all);
    $w = waiting($found);
    page($found['title'],
        '<p class="muted">' . h(SITE_NAME) . '</p>'
        . '<h1>' . h($found['title']) . '</h1>'
        . '<p>Autor: ' . h($found['author']) . '</p>'
        . '<div class="box"><div class="muted">Stan zgłoszenia</div><div class="big">' . h($found['status']) . '</div></div>'
        . '<p class="muted">Ostatnia zmiana: ' . h($found['updated']) . '</p>'
        . ($w !== '' ? '<p class="muted">Oczekuje: <b>' . h($w) . '</b></p>' : '')
        . '<p class="muted">Zgłoszenia oczekujące na czytanie: <b>' . $c['queue'] . '</b></p>'
		. '<p class="box">Legenda:<br>'
		. '"otrzymane" to znaczy, że wszystko porządku i czeka na czytanie.<br>'
		. '"w czytaniu" to ten nerwowy moment dla mnie i dla ciebie.<br>'
		. '"czeka na decyzję" oznacza, że muszę jeszcze przeczytać resztę i wtedy zadecydować.<br>'
		. '"przyjęte" to wiadomo, zaraz będzie mail<br>'
		. '"odrzucone" tu też wiadomo, żadnego maila już nie będzie, zapraszam za rok.</p>'
		. '<p class="muted">Zebrane dane nie są udostępniane ani przetwarzane, zostaną usunięte po zakończeniu naboru.</p>');
    exit;
}

// ---------- panel redaktora ----------
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$self = strtok($_SERVER['REQUEST_URI'], '?');

function redirect_self() {
    global $self;
    header('Location: ' . $self);
    exit;
}

function csrf_ok() {
    return isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

// logowanie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (hash_equals(PASSWORD, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        redirect_self();
    }
    sleep(1);
    $login_error = true;
}

if (empty($_SESSION['ok'])) {
    page('Logowanie',
        '<h1>Panel redakcji</h1>'
        . (!empty($login_error) ? '<p style="color:#b00">Błędne hasło.</p>' : '')
        . '<form method="post"><input type="hidden" name="action" value="login">'
        . '<div class="row"><label>Hasło<input type="password" name="password" autofocus required></label>'
        . '<button>Zaloguj</button></div></form>');
    exit;
}

// akcje (wymagają tokenu CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Błąd CSRF. Odśwież stronę.'); }
    $action = $_POST['action'] ?? '';
    $data = load_data();
    $now = date('Y-m-d H:i');

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect_self();
    }
    if ($action === 'add') {
        $author = trim(mb_substr((string)($_POST['author'] ?? ''), 0, 200));
        $title  = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 300));
        $status = (string)($_POST['status'] ?? '');
        if ($author !== '' && $title !== '' && in_array($status, STATUSES, true)) {
            $data[] = [
                'id' => bin2hex(random_bytes(4)),
                'token' => bin2hex(random_bytes(16)), // 128 bitów losowości
                'author' => $author, 'title' => $title, 'status' => $status,
                'created' => $now, 'updated' => $now,
            ];
            save_data($data);
        }
    }
    if ($action === 'status' || $action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        foreach ($data as $i => $e) {
            if ($e['id'] !== $id) continue;
            if ($action === 'delete') {
                unset($data[$i]);
            } elseif (in_array($_POST['status'] ?? '', STATUSES, true) && $_POST['status'] !== $e['status']) {
                $data[$i]['status'] = $_POST['status'];
                $data[$i]['updated'] = $now;
            }
            save_data($data);
            break;
        }
    }
    redirect_self();
}

// widok listy
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = BASE_URL !== '' ? BASE_URL : $scheme . '://' . $_SERVER['HTTP_HOST'] . $self;
$csrf = $_SESSION['csrf'];
$rows = array_reverse(load_data());
$c = counts($rows);

ob_start(); ?>
<div class="row" style="justify-content:space-between">
  <h1><?= h(SITE_NAME) ?> – zgłoszenia</h1>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="logout"><button>Wyloguj</button></form>
</div>

<form method="post" class="box">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add">
  <div class="row">
    <label>Autor<input name="author" required></label>
    <label>Tytuł<input name="title" required></label>
    <label>Stan<select name="status">
      <?php foreach (STATUSES as $s): ?><option><?= h($s) ?></option><?php endforeach; ?>
    </select></label>
    <button>Dodaj</button>
  </div>
</form>

<p>Otrzymane: <b><?= $c['total'] ?></b> · przyjęte: <b><?= $c['accepted'] ?></b> · odrzucone: <b><?= $c['rejected'] ?></b></p>

<table>
  <tr><th>Autor / tytuł</th><th>Stan</th></tr>
  <?php foreach ($rows as $e): $link = $base . '?t=' . $e['token']; $w = waiting($e); ?>
  <tr>
    <td>
      <b><?= h($e['title']) ?></b><br><?= h($e['author']) ?><br>
      <small>dodano <?= h($e['created']) ?>, zmiana <?= h($e['updated']) ?><?= $w !== '' ? ', oczekuje ' . h($w) : '' ?></small><br>
      <div class="row">
        <input class="link" readonly value="<?= h($link) ?>" onclick="this.select()">
        <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Skopiowano';">Kopiuj link</button>
      </div>
    </td>
    <td>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= h($e['id']) ?>">
        <select name="status">
          <?php foreach (STATUSES as $s): ?><option <?= $s === $e['status'] ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?>
        </select>
        <button>Zapisz</button>
      </form>
      <form method="post" onsubmit="return confirm('Usunąć wpis? Link autora przestanie działać.')" style="margin-top:.4rem">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($e['id']) ?>">
        <button><small>Usuń</small></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="2" class="muted">Brak wpisów.</td></tr><?php endif; ?>
</table>
<?php
page('Panel redakcji', ob_get_clean());
