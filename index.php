<?php
// ===== Bartek Biedrzycki 2026 =====
// ===== https://github.com/godai78/SZN =====

// ===== KONFIGURACJA =====
const PASSWORD  = 'haslo';          // hasło do panelu redaktora (zmień na produkcyjne!)
const SITE_NAME = 'nazwa pisma/naboru';
const BASE_URL  = '';                    // opcjonalnie np. 'https://zgloszenia.twojadomena.pl/index.php'; puste = automatycznie
const STATUSES  = ['otrzymane', 'w czytaniu', 'czeka na decyzję', 'przyjęte', 'odrzucone', 'wycofane'];
const DATA_FILE = __DIR__ . '/data.php'; // dane (plik zabezpieczony przed odczytem z WWW)
const UPLOAD_DIR = __DIR__ . '/uploads/'; // katalog na pliki użytkowników
const ALLOWED_EXT = ['doc', 'docx', 'rtf']; 
const MAX_FILE_SIZE = 5 * 1024 * 1024; // obniżony limit do 5 MB
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

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    file_put_contents(UPLOAD_DIR . '.htaccess', "Deny from all\n");
}

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

function waiting($e) {
    if (in_array($e['status'], ['przyjęte', 'odrzucone', 'wycofane'], true)) return '';
    $d = date_diff(date_create(substr($e['created'], 0, 10)), date_create('today'));
    $n = $d->invert ? 0 : (int)$d->days;
    return $n . ' ' . ($n === 1 ? 'dzień' : 'dni');
}

// Walidacja struktury plików (magic bytes) zapobiegająca spoofingowi rozszerzeń
function validate_file_magic_bytes($path, $ext) {
    $handle = @fopen($path, 'rb');
    if (!$handle) return false;
    $bytes = fread($handle, 8);
    fclose($handle);

    if ($ext === 'docx') {
        // ZIP nagłówek: PK (50 4B 03 04)
        return strncmp($bytes, "\x50\x4B\x03\x04", 4) === 0;
    } elseif ($ext === 'rtf') {
        // RTF zaczyna się od {\rtf
        return strncmp($bytes, '{\rtf', 5) === 0;
    } elseif ($ext === 'doc') {
        // OLE Compound File Binary Format: D0 CF 11 E0 A1 B1 1A E1
        return strncmp($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    }
    return false;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$self = strtok($_SERVER['REQUEST_URI'], '?');
$base = BASE_URL !== '' ? BASE_URL : $scheme . '://' . $_SERVER['HTTP_HOST'] . $self;

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// Inicjalizacja tokenu CSRF dla formularza publicznego
if (empty($_SESSION['public_csrf'])) {
    $_SESSION['public_csrf'] = bin2hex(random_bytes(16));
}

// Bezpieczne pobieranie pliku przez redaktora
if (isset($_GET['download']) && !empty($_SESSION['ok'])) {
    $id = (string)$_GET['download'];
    $data = load_data();
    foreach ($data as $e) {
        if ($e['id'] === $id && !empty($e['file'])) {
            $filePath = UPLOAD_DIR . $e['file'];
            if (is_file($filePath)) {
                $filename = $e['original_filename'] ?? 'plik';
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                exit;
            }
        }
    }
    http_response_code(404);
    exit('Nie znaleziono pliku.');
}

// Widok publiczny (autor - podgląd statusu po tokenie)
if (isset($_GET['t'])) {
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
        . '"otrzymane" to znaczy, że wszystko w porządku i czeka na czytanie.<br>'
        . '"w czytaniu" to ten nerwowy moment dla mnie i dla ciebie.<br>'
        . '"czeka na decyzję" oznacza, że muszę jeszcze przeczytać resztę i wtedy zadecydować.<br>'
        . '"przyjęte" to wiadomo, zaraz będzie mail.<br>'
        . '"odrzucone" tu też wiadomo, żadnego maila już nie będzie, zapraszam za rok.</p>'
        . '<p class="muted">Zebrane dane nie są udostępniane ani przetwarzane, zostaną usunięte po zakończeniu naboru.</p>');
    exit;
}

// Obsługa publicznego wysłania zgłoszenia z plikiem i ochroną CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'public_submit') {
    if (!isset($_SESSION['public_csrf'], $_POST['public_csrf']) || !hash_equals($_SESSION['public_csrf'], (string)$_POST['public_csrf'])) {
        http_response_code(400);
        exit('Błąd bezpieczeństwa (CSRF). Odśwież stronę.');
    }

    $author = trim(mb_substr((string)($_POST['author'] ?? ''), 0, 200));
    $title  = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 300));
    
    $pub_error = '';
    $saved_filename = '';

    if ($author === '' || $title === '') {
        $pub_error = 'Wypełnij imię/pseudonim oraz tytuł.';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        $pub_error = 'Musisz wgrać plik ze swoim tekstem.';
    } else {
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $pub_error = 'Błąd podczas wgrywania pliku.';
        } elseif ($file['size'] > MAX_FILE_SIZE) {
            $pub_error = 'Plik jest za duży (maksymalnie 5 MB).';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXT, true)) {
                $pub_error = 'Niedozwolony format pliku. Dozwolone są wyłącznie pliki: DOC, DOCX, RTF.';
            } elseif (!validate_file_magic_bytes($file['tmp_name'], $ext)) {
                $pub_error = 'Plik jest uszkodzony lub jego zawartość nie odpowiada zadeklarowanemu formatowi.';
            } else {
                $saved_filename = bin2hex(random_bytes(8)) . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $saved_filename)) {
                    $pub_error = 'Nie udało się zapisać pliku na serwerze.';
                }
            }
        }
    }

    if (empty($pub_error)) {
        $data = load_data();
        $now = date('Y-m-d H:i');
        $token = bin2hex(random_bytes(16));
        $id = bin2hex(random_bytes(4));
        
        $data[] = [
            'id' => $id,
            'token' => $token,
            'author' => $author, 
            'title' => $title, 
            'status' => 'otrzymane',
            'file' => $saved_filename,
            'original_filename' => $_FILES['file']['name'],
            'created' => $now, 
            'updated' => $now,
        ];
        save_data($data);
        
        // Reset tokenu po udanym wysłaniu zapobiega podwójnemu wysłaniu przez odświeżenie
        unset($_SESSION['public_csrf']);
        
        $personal_link = $base . '?t=' . $token;
        
        page('Zgłoszenie przyjęte', 
            '<h1>Dziękujemy za zgłoszenie!</h1>'
            . '<p>Twoje opowiadanie <b>' . h($title) . '</b> zostało pomyślnie wgrane i zarejestrowane.</p>'
            . '<div class="box"><p><b>Oto Twój osobisty link do śledzenia statusu zgłoszenia:</b></p>'
            . '<div class="row"><input class="link" readonly value="' . h($personal_link) . '" onclick="this.select()">'
            . '<button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Skopiowano\';">Skopiuj link</button></div>'
            . '<p><small>Zapisz ten link! Nie wysyłamy go automatycznie mailem – to jedyny sposób na sprawdzenie statusu tekstu.</small></p></div>'
            . '<p><a href="' . h($self) . '">&larr; Wróć do strony głównej naboru</a></p>');
        exit;
    }
}

// Logowanie redaktora
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (hash_equals(PASSWORD, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ' . $self);
        exit;
    }
    sleep(1);
    $login_error = true;
}

if (empty($_SESSION['ok'])) {
    ob_start(); ?>
    <h1><?= h(SITE_NAME) ?></h1>
    <p>Wypełnij poniższy formularz, aby zgłosić swój tekst do naboru. Po wysłaniu otrzymasz unikalny link do śledzenia statusu.</p>

    <?php if (!empty($pub_error)): ?>
        <div class="box" style="background:#ffe6e6;color:#900"><b>Błąd:</b> <?= h($pub_error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="box">
        <input type="hidden" name="action" value="public_submit">
        <input type="hidden" name="public_csrf" value="<?= h($_SESSION['public_csrf']) ?>">
        <div class="row" style="flex-direction:column;align-items:stretch">
            <label>Imię / Nazwisko / Pseudonim<input name="author" required value="<?= h($_POST['author'] ?? '') ?>"></label>
            <label>Tytuł utworu<input name="title" required value="<?= h($_POST['title'] ?? '') ?>"></label>
            
            <label>Plik z tekstem
                <small class="muted" style="margin-bottom:0.2rem">Dozwolone formaty: <b>DOC, DOCX, RTF</b> (maksymalnie 5 MB)</small>
                <input type="file" name="file" required accept=".doc,.docx,.rtf">
            </label>
            
            <div><button style="margin-top:.5rem">Wyślij zgłoszenie</button></div>
        </div>
    </form>

    <hr style="margin:3rem 0;border:0;border-top:1px solid #ddd">
    
    <details>
        <summary style="cursor:pointer;color:#666">Panel redakcji (dla administratora)</summary>
        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="action" value="login">
            <?php if (!empty($login_error)): ?><p style="color:#b00">Błędne hasło.</p><?php endif; ?>
            <div class="row"><label>Hasło redaktora<input type="password" name="password" required></label>
            <button>Zaloguj</button></div>
        </form>
    </details>
    <?php
    page(SITE_NAME, ob_get_clean());
    exit;
}

// Akcje redaktora (wymagają tokenu CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['csrf'], $_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        http_response_code(400); 
        exit('Błąd CSRF. Odśwież stronę.');
    }
    
    $action = $_POST['action'] ?? '';
    $data = load_data();
    $now = date('Y-m-d H:i');

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . $self);
        exit;
    }
    if ($action === 'status' || $action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        foreach ($data as $i => $e) {
            if ($e['id'] !== $id) continue;
            if ($action === 'delete') {
                if (!empty($e['file']) && is_file(UPLOAD_DIR . $e['file'])) {
                    @unlink(UPLOAD_DIR . $e['file']);
                }
                unset($data[$i]);
            } elseif (in_array($_POST['status'] ?? '', STATUSES, true) && $_POST['status'] !== $e['status']) {
                $data[$i]['status'] = $_POST['status'];
                $data[$i]['updated'] = $now;
            }
            save_data($data);
            break;
        }
    }
    header('Location: ' . $self);
    exit;
}

$csrf = $_SESSION['csrf'];
$rows = array_reverse(load_data());
$c = counts($rows);

ob_start(); ?>
<div class="row" style="justify-content:space-between">
  <h1><?= h(SITE_NAME) ?> – zgłoszenia</h1>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="logout"><button>Wyloguj</button></form>
</div>

<p>Otrzymane: <b><?= $c['total'] ?></b> · przyjęte: <b><?= $c['accepted'] ?></b> · odrzucone: <b><?= $c['rejected'] ?></b></p>

<table>
  <tr><th>Autor / tytuł / plik</th><th>Stan</th></tr>
  <?php foreach ($rows as $e): $link = $base . '?t=' . $e['token']; $w = waiting($e); ?>
  <tr>
    <td>
      <b><?= h($e['title']) ?></b><br><?= h($e['author']) ?><br>
      <?php if (!empty($e['file'])): ?>
        <small>Plik: <a href="<?= h($self) ?>?download=<?= h($e['id']) ?>"><?= h($e['original_filename'] ?? 'pobierz') ?></a></small><br>
      <?php endif; ?>
      <small>dodano <?= h($e['created']) ?>, zmiana <?= h($e['updated']) ?><?= $w !== '' ? ', oczekuje ' . h($w) : '' ?></small><br>
      <div class="row" style="margin-top:.3rem">
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
      <form method="post" onsubmit="return confirm('Usunąć wpis i plik? Link autora przestanie działać.')" style="margin-top:.4rem">
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
