<?php
// ===== Bartek Biedrzycki 2026 =====
// ===== System Zarządzania Naborem 2.1 =====
// ===== https://github.com/godai78/SZN =====

// ===== KONFIGURACJA =====
// ===== To musisz zmienić =====
const PASSWORD_HASH = 'uzupełnij'; // Wklej tu hash hasła z password_hash() albo ustaw zmienną środowiskową EDITOR_PASSWORD_HASH. (Patrz README).
const SITE_NAME = 'uzupełnij'; // Podaj nazwę pisma/antologii/naboru.
const BASE_URL = 'uzupełnij'; // WYMAGANE: pełny adres HTTPS, np. https://zgloszenia.twojadomena.pl/index.php

// ===== Tego nie musisz zmieniać, ale możesz =====
const STATUSES = ['otrzymane', 'w czytaniu', 'czeka na decyzję', 'przyjęte', 'odrzucone', 'wycofane']; // statusy zgłoszeń, można dodać, odejmować, zmieniać. Przy zmianach popraw legendę dla użytkownika.

const DATA_FILE = __DIR__ . '/data.php'; // Plik z danymi.
const UPLOAD_DIR = __DIR__ . '/uploads/'; // Katalog do wgrywania plików.
const RATE_LIMIT_DIR = __DIR__ . '/rate-limits/'; // Tego nie musisz ruszać.
const DATA_LOCK_FILE = __DIR__ . '/data.lock'; // Tego nie musisz ruszać.

const ALLOWED_EXT = ['doc', 'docx', 'rtf'];
const MAX_FILE_SIZE = 1 * 1024 * 1024; // Dopuszczalna maksymalna wielkość plików, domyślnie 1MB.
const MAX_SUBMISSIONS_PER_IP = 10; // Maksymalna ilość zgłoszeń z jednego IP, domyślnie 10.
const SUBMISSION_RATE_WINDOW = 3600; // Okno limitu, domyślnie 1 godzina.
const MAX_LOGIN_ATTEMPTS_PER_IP = 5; // Maksymalna ilość nieudanych prób logowania do admin.
const LOGIN_RATE_WINDOW = 900; // Czas blokady po nieudanych próbach, domyślnie 15 minut.
const EDITOR_IDLE_TIMEOUT = 1800; // Czas wylogowania bezczynnego admina, domyślnie 30 minut.
// ===== KONIEC KONFIGURACJI =====

date_default_timezone_set('Europe/Warsaw');

const CSS = 'body{font:16px/1.5 system-ui,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#222}'
    . 'table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:.5rem;text-align:left;vertical-align:top}'
    . 'input,select{font:inherit;padding:.3rem;box-sizing:border-box}button{font:inherit;padding:.3rem .8rem;cursor:pointer}'
    . '.row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end}.row label{display:flex;flex-direction:column;font-size:.85rem}'
    . '.link{width:100%;font-size:.8rem;color:#555}.box{background:#f5f5f5;padding:1rem;border-radius:6px;margin:1rem 0}'
    . '.big{font-size:1.6rem;font-weight:600}small,.muted{color:#666}';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page($title, $body) {
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . h($title) . '</title><style>' . CSS . '</style></head><body>' . $body . '</body></html>';
}

function fail_server($publicMessage, $logMessage = '') {
    if ($logMessage !== '') error_log('[SZN] ' . $logMessage);
    http_response_code(500);
    exit($publicMessage);
}

function ensure_directory($path) {
    if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
        fail_server('Błąd konfiguracji katalogów aplikacji.', 'Nie można utworzyć katalogu: ' . $path);
    }
    @chmod($path, 0755);
    if (!is_writable($path)) {
        fail_server('Błąd konfiguracji katalogów aplikacji.', 'Katalog nie jest zapisywalny: ' . $path);
    }
}

function protect_upload_dir() {
    if (!is_dir(UPLOAD_DIR)) return;
    $htaccess = UPLOAD_DIR . '.htaccess';
    if (!is_file($htaccess)) {
        $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
        @file_put_contents($htaccess, $rules);
    }
    $index = UPLOAD_DIR . 'index.php';
    if (!is_file($index)) {
        @file_put_contents($index, "<?php http_response_code(404); exit;\n");
    }
}

ensure_directory(UPLOAD_DIR);
ensure_directory(RATE_LIMIT_DIR);
protect_upload_dir();

function acquire_data_lock($mode) {
    $fp = @fopen(DATA_LOCK_FILE, 'c+');
    if (!$fp) fail_server('Nie można uzyskać dostępu do danych.', 'Nie można otworzyć pliku blokady danych.');
    if (!flock($fp, $mode)) {
        fclose($fp);
        fail_server('Nie można uzyskać dostępu do danych.', 'Nie można zablokować pliku danych.');
    }
    return $fp;
}

function release_data_lock($fp) {
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function load_data_unlocked() {
    if (!is_file(DATA_FILE)) return [];
    $raw = @file_get_contents(DATA_FILE);
    if ($raw === false) fail_server('Nie można odczytać danych zgłoszeń.', 'Błąd odczytu DATA_FILE.');
    $pos = strpos($raw, "\n");
    if ($pos === false) fail_server('Plik danych jest uszkodzony. Skontaktuj się z administratorem.', 'Brak separatora w DATA_FILE.');
    $json = json_decode(substr($raw, $pos + 1), true);
    if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
        fail_server('Plik danych jest uszkodzony. Skontaktuj się z administratorem.', 'Nieprawidłowy JSON w DATA_FILE: ' . json_last_error_msg());
    }
    foreach ($json as $entry) {
        if (!is_array($entry) || !isset($entry['id'], $entry['token'], $entry['author'], $entry['title'], $entry['status'], $entry['created'], $entry['updated'])) {
            fail_server('Plik danych zawiera nieprawidłowy wpis. Skontaktuj się z administratorem.', 'Nieprawidłowa struktura rekordu w DATA_FILE.');
        }
    }
    return $json;
}

function load_data() {
    $lock = acquire_data_lock(LOCK_SH);
    $data = load_data_unlocked();
    release_data_lock($lock);
    return $data;
}

function save_data_unlocked($data) {
    try {
        $json = json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        error_log('[SZN] Błąd kodowania danych: ' . $e->getMessage());
        return false;
    }

    $payload = "<?php http_response_code(404); exit; ?>\n" . $json;
    $tmp = @tempnam(__DIR__, '.data-');
    if ($tmp === false) {
        error_log('[SZN] Nie można utworzyć pliku tymczasowego dla danych.');
        return false;
    }

    $written = @file_put_contents($tmp, $payload, LOCK_EX);
    if ($written !== strlen($payload)) {
        @unlink($tmp);
        error_log('[SZN] Niepełny zapis pliku tymczasowego danych.');
        return false;
    }
    @chmod($tmp, 0600);

    if (!@rename($tmp, DATA_FILE)) {
        @unlink($tmp);
        error_log('[SZN] Nie udało się atomowo podmienić pliku danych.');
        return false;
    }
    @chmod(DATA_FILE, 0600);
    return true;
}

function client_ip() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function rate_limit_file($scope) {
    $key = hash('sha256', $scope . '|' . client_ip());
    return RATE_LIMIT_DIR . $key . '.json';
}

function rate_limit_allow($scope, $limit, $windowSeconds) {
    $path = rate_limit_file($scope);
    $fp = @fopen($path, 'c+');
    if (!$fp) fail_server('Nie można sprawdzić limitu żądań.', 'Nie można otworzyć pliku limitu: ' . $path);
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        fail_server('Nie można sprawdzić limitu żądań.', 'Nie można zablokować pliku limitu.');
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $state = $raw ? json_decode($raw, true) : [];
    if (!is_array($state)) $state = [];
    $now = time();
    $recent = [];
    foreach ($state as $timestamp) {
        if (is_int($timestamp) && $timestamp > ($now - $windowSeconds)) $recent[] = $timestamp;
    }

    if (count($recent) >= $limit) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($recent));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $recent[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    $encoded = json_encode($recent);
    $ok = ($encoded !== false && fwrite($fp, $encoded) === strlen($encoded) && fflush($fp));
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!$ok) fail_server('Nie można zapisać limitu żądań.', 'Błąd zapisu pliku limitu.');
    return true;
}

function clear_rate_limit($scope) {
    $path = rate_limit_file($scope);
    if (is_file($path)) @unlink($path);
}

function counts($data) {
    $c = ['total' => count($data), 'accepted' => 0, 'rejected' => 0, 'withdrawn' => 0, 'queue' => 0];
    foreach ($data as $e) {
        $status = (string)($e['status'] ?? '');
        if ($status === 'przyjęte') $c['accepted']++;
        if ($status === 'odrzucone') $c['rejected']++;
        if ($status === 'wycofane') $c['withdrawn']++;
        if ($status === 'otrzymane') $c['queue']++;
    }
    return $c;
}

function waiting($e) {
    if (in_array($e['status'] ?? '', ['przyjęte', 'odrzucone', 'wycofane'], true)) return '';
    try {
        $created = date_create(substr((string)($e['created'] ?? ''), 0, 10));
        if (!$created) return '';
        $diff = date_diff($created, date_create('today'));
        $days = $diff->invert ? 0 : (int)$diff->days;
        return $days . ' ' . ($days === 1 ? 'dzień' : 'dni');
    } catch (Throwable $e) {
        return '';
    }
}

function validate_file_magic_bytes($path, $ext) {
    if (!is_uploaded_file($path)) return false;
    $handle = @fopen($path, 'rb');
    if (!$handle) return false;
    $bytes = fread($handle, 8);
    fclose($handle);

    if ($ext === 'docx') {
        if (strncmp($bytes, "\x50\x4B\x03\x04", 4) !== 0 || !class_exists('ZipArchive')) return false;
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return false;
        $valid = ($zip->locateName('[Content_Types].xml') !== false
            && $zip->locateName('word/document.xml') !== false);
        $zip->close();
        return $valid;
    }
    if ($ext === 'rtf') return strncmp($bytes, '{\\rtf', 5) === 0;
    if ($ext === 'doc') return strncmp($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    return false;
}

function find_upload_path($filename) {
    if (!is_string($filename) || !preg_match('/^[a-f0-9]{16}\\.(doc|docx|rtf)$/', $filename)) return false;
    $path = UPLOAD_DIR . $filename;
    return is_file($path) ? $path : false;
}

function download_filename_headers($originalFilename) {
    $name = basename(str_replace('\\', '/', (string)$originalFilename));
    $name = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name);
    if ($name === '' || $name === '.' || $name === '..') $name = 'plik';

    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    $fallback = trim((string)$fallback, '._-');
    if ($fallback === '') $fallback = 'plik';

    header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
}

// ===== KONFIGURACJA URL I SESJI =====
if (BASE_URL === '' || filter_var(BASE_URL, FILTER_VALIDATE_URL) === false || strtolower((string)parse_url(BASE_URL, PHP_URL_SCHEME)) !== 'https') {
    fail_server('Administrator musi skonfigurować BASE_URL jako pełny adres HTTPS.', 'Nieprawidłowe lub puste BASE_URL.');
}
$self = parse_url(BASE_URL, PHP_URL_PATH);
if (!is_string($self) || $self === '' || $self[0] !== '/') $self = '/';
$base = rtrim(BASE_URL, '/');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => true,
]);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (!empty($_SESSION['ok'])) {
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > EDITOR_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
        session_regenerate_id(true);
    } else {
        $_SESSION['last_activity'] = time();
    }
}

if (empty($_SESSION['public_csrf'])) {
    $_SESSION['public_csrf'] = bin2hex(random_bytes(32));
}

// ===== POBIERANIE PLIKU PRZEZ ZALOGOWANEGO REDAKTORA =====
if (isset($_GET['download']) && !empty($_SESSION['ok'])) {
    $id = (string)$_GET['download'];
    $data = load_data();
    foreach ($data as $entry) {
        if (($entry['id'] ?? '') === $id && !empty($entry['file'])) {
            $filePath = find_upload_path($entry['file']);
            if ($filePath !== false) {
                $filename = $entry['original_filename'] ?? 'plik';
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                download_filename_headers($filename);
                header('X-Content-Type-Options: nosniff');
                header('Expires: 0');
                header('Cache-Control: private, no-store, must-revalidate');
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

// ===== PUBLICZNY PODGLĄD STATUSU =====
if (isset($_GET['t'])) {
    $token = (string)$_GET['t'];
    $found = null;
    $all = load_data();
    foreach ($all as $entry) {
        if (isset($entry['token']) && hash_equals((string)$entry['token'], $token)) {
            $found = $entry;
            break;
        }
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
		. '<hr style="margin:3rem 0;border:0;border-top:1px solid #ddd">'
        . '<p class="muted">Dane zgłoszenia są wykorzystywane do obsługi naboru i nie są publicznie udostępniane. Informacje o okresie przechowywania danych znajdują się w zasadach naboru.</p>'
		. '<p><a href="https://github.com/godai78/SZN" target="_blank">System Zarządzania Naborem</a> 2.1 © <a href="https://gniazdoswiatow.net" target="_blank">Bartek Biedrzycki</a> 2026</p>
    <hr style="margin:3rem 0;border:0;border-top:1px solid #ddd">');
    exit;
}

// ===== PUBLICZNE ZGŁOSZENIE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'public_submit') {
    if (!isset($_SESSION['public_csrf'], $_POST['public_csrf']) || !hash_equals($_SESSION['public_csrf'], (string)$_POST['public_csrf'])) {
        http_response_code(400);
        exit('Błąd bezpieczeństwa (CSRF). Odśwież stronę.');
    }

    $pub_error = '';
    $saved_filename = '';
    $author = trim(mb_substr((string)($_POST['author'] ?? ''), 0, 200, 'UTF-8'));
    $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 300, 'UTF-8'));

    if (!rate_limit_allow('public-submit', MAX_SUBMISSIONS_PER_IP, SUBMISSION_RATE_WINDOW)) {
        $pub_error = 'Z tego adresu wysłano już wiele zgłoszeń. Spróbuj ponownie później.';
    } elseif ($author === '' || $title === '') {
        $pub_error = 'Wypełnij imię/pseudonim oraz tytuł.';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        $pub_error = 'Musisz wgrać plik ze swoim tekstem.';
    } else {
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $pub_error = 'Błąd podczas wgrywania pliku. Sprawdź limit wielkości pliku na serwerze.';
        } elseif (!isset($file['size']) || (int)$file['size'] <= 0 || (int)$file['size'] > MAX_FILE_SIZE || !is_uploaded_file($file['tmp_name'])) {
            $pub_error = 'Plik jest pusty, za duży lub nie został prawidłowo przesłany (maksymalnie 1 MB).';
        } else {
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXT, true)) {
                $pub_error = 'Niedozwolony format pliku. Dozwolone są wyłącznie pliki: DOC, DOCX, RTF.';
            } elseif (!validate_file_magic_bytes($file['tmp_name'], $ext)) {
                $pub_error = 'Plik jest uszkodzony lub jego zawartość nie odpowiada zadeklarowanemu formatowi.';
            } else {
                $saved_filename = bin2hex(random_bytes(8)) . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $saved_filename)) {
                    $saved_filename = '';
                    $pub_error = 'Nie udało się zapisać pliku na serwerze.';
                }
            }
        }
    }

    if ($pub_error === '') {
        $lock = acquire_data_lock(LOCK_EX);
        $data = load_data_unlocked();
        $now = date('Y-m-d H:i');
        $token = bin2hex(random_bytes(32));
        $id = bin2hex(random_bytes(8));
        $originalFilename = basename(str_replace('\\', '/', (string)$_FILES['file']['name']));
        $data[] = [
            'id' => $id,
            'token' => $token,
            'author' => $author,
            'title' => $title,
            'status' => 'otrzymane',
            'file' => $saved_filename,
            'original_filename' => $originalFilename,
            'created' => $now,
            'updated' => $now,
        ];

        if (!save_data_unlocked($data)) {
            release_data_lock($lock);
            if ($saved_filename !== '' && is_file(UPLOAD_DIR . $saved_filename)) @unlink(UPLOAD_DIR . $saved_filename);
            $pub_error = 'Nie udało się zapisać zgłoszenia. Spróbuj ponownie później.';
        } else {
            release_data_lock($lock);
            unset($_SESSION['public_csrf']);
            $personalLink = $base . '?t=' . rawurlencode($token);
            page('Zgłoszenie przyjęte',
                '<h1>Dziękujemy za zgłoszenie!</h1>'
                . '<p>Twoje opowiadanie <b>' . h($title) . '</b> zostało pomyślnie wgrane i zarejestrowane.</p>'
                . '<div class="box"><p><b>Oto Twój osobisty link do śledzenia statusu zgłoszenia:</b></p>'
                . '<div class="row"><input class="link" readonly value="' . h($personalLink) . '" onclick="this.select()">'
                . '<button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Skopiowano\';">Skopiuj link</button></div>'
                . '<p><small>Zapisz ten link! Nie wysyłamy go automatycznie mailem – to jedyny sposób na sprawdzenie statusu tekstu.</small></p></div>'
                . '<p><a href="' . h($self) . '">&larr; Wróć do strony głównej naboru</a></p>');
            exit;
        }
    }
}

// ===== LOGOWANIE REDAKTORA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $configuredHash = getenv('EDITOR_PASSWORD_HASH') ?: PASSWORD_HASH;
    $allowed = rate_limit_allow('editor-login', MAX_LOGIN_ATTEMPTS_PER_IP, LOGIN_RATE_WINDOW);
    if ($allowed && $configuredHash !== '' && password_verify((string)($_POST['password'] ?? ''), $configuredHash)) {
        clear_rate_limit('editor-login');
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: ' . $self, true, 303);
        exit;
    }
    sleep(1);
    $login_error = true;
    $login_rate_limited = !$allowed;
    if ($configuredHash === '') error_log('[SZN] Brak skonfigurowanego EDITOR_PASSWORD_HASH/PASSWORD_HASH.');
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
            <label>Imię / Nazwisko / Pseudonim<input name="author" required maxlength="200" value="<?= h($_POST['author'] ?? '') ?>"></label>
            <label>Tytuł utworu<input name="title" required maxlength="300" value="<?= h($_POST['title'] ?? '') ?>"></label>
            <label>Plik z tekstem
                <small class="muted" style="margin-bottom:0.2rem">Dozwolone formaty: <b>DOC, DOCX, RTF</b> (maksymalnie 1 MB)</small>
                <input type="file" name="file" required accept=".doc,.docx,.rtf">
            </label>
            <div><button style="margin-top:.5rem">Wyślij zgłoszenie</button></div>
        </div>
    </form>

    <hr style="margin:3rem 0;border:0;border-top:1px solid #ddd">
	<p><a href="https://github.com/godai78/SZN" target="_blank">System Zarządzania Naborem</a> 2.1 © <a href="https://gniazdoswiatow.net" target="_blank">Bartek Biedrzycki</a> 2026</p>
    <hr style="margin:3rem 0;border:0;border-top:1px solid #ddd">	
    <details>
        <summary style="cursor:pointer;color:#666">Panel redakcji (dla administratora)</summary>
        <form method="post" style="margin-top:1rem">
            <input type="hidden" name="action" value="login">
            <?php if (!empty($login_error)): ?>
                <p style="color:#b00"><?= !empty($login_rate_limited) ? 'Zbyt wiele prób logowania. Spróbuj ponownie za 15 minut.' : 'Błędne hasło lub brak konfiguracji hasła.' ?></p>
            <?php endif; ?>
            <div class="row"><label>Hasło redaktora<input type="password" name="password" required autocomplete="current-password"></label>
            <button>Zaloguj</button></div>
        </form>
    </details>
    <?php
    page(SITE_NAME, ob_get_clean());
    exit;
}

// ===== AKCJE REDAKTORA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['csrf'], $_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        http_response_code(400);
        exit('Błąd CSRF. Odśwież stronę.');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . $self, true, 303);
        exit;
    }

    if ($action === 'status' || $action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        $fileToDelete = '';
        $lock = acquire_data_lock(LOCK_EX);
        $data = load_data_unlocked();
        $changed = false;

        foreach ($data as $i => $entry) {
            if (($entry['id'] ?? '') !== $id) continue;

            if ($action === 'delete') {
                $fileToDelete = (string)($entry['file'] ?? '');
                unset($data[$i]);
                $changed = true;
            } else {
                $newStatus = (string)($_POST['status'] ?? '');
                if (in_array($newStatus, STATUSES, true) && $newStatus !== ($entry['status'] ?? '')) {
                    $data[$i]['status'] = $newStatus;
                    $data[$i]['updated'] = date('Y-m-d H:i');
                    $changed = true;
                }
            }
            break;
        }

        if ($changed && !save_data_unlocked($data)) {
            release_data_lock($lock);
            fail_server('Nie udało się zapisać zmian. Spróbuj ponownie.', 'Błąd zapisu po akcji redaktora.');
        }
        release_data_lock($lock);

        if ($action === 'delete' && $fileToDelete !== '') {
            $path = find_upload_path($fileToDelete);
            if ($path !== false && !@unlink($path)) {
                error_log('[SZN] Nie udało się usunąć pliku zgłoszenia: ' . $fileToDelete);
            }
        }
    }

    header('Location: ' . $self, true, 303);
    exit;
}

// ===== PANEL REDAKCJI =====
$csrf = $_SESSION['csrf'];
$rows = array_reverse(load_data());
$c = counts($rows);

ob_start(); ?>
<div class="row" style="justify-content:space-between">
  <h1><?= h(SITE_NAME) ?> – zgłoszenia</h1>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="logout"><button>Wyloguj</button></form>
</div>

<p>Otrzymane: <b><?= $c['total'] ?></b> · oczekujące na czytanie: <b><?= $c['queue'] ?></b> · przyjęte: <b><?= $c['accepted'] ?></b> · odrzucone: <b><?= $c['rejected'] ?></b></p>

<table>
  <tr><th>Autor / tytuł / plik</th><th>Stan</th></tr>
  <?php foreach ($rows as $entry): $link = $base . '?t=' . rawurlencode((string)$entry['token']); $w = waiting($entry); ?>
  <tr>
    <td>
      <b><?= h($entry['title']) ?></b><br><?= h($entry['author']) ?><br>
      <?php if (!empty($entry['file'])): ?>
        <small>Plik: <a href="<?= h($self) ?>?download=<?= h($entry['id']) ?>"><?= h($entry['original_filename'] ?? 'pobierz') ?></a></small><br>
      <?php endif; ?>
      <small>dodano <?= h($entry['created']) ?>, zmiana <?= h($entry['updated']) ?><?= $w !== '' ? ', oczekuje ' . h($w) : '' ?></small><br>
      <div class="row" style="margin-top:.3rem">
        <input class="link" readonly value="<?= h($link) ?>" onclick="this.select()">
        <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Skopiowano';">Kopiuj link</button>
      </div>
    </td>
    <td>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= h($entry['id']) ?>">
        <select name="status">
          <?php foreach (STATUSES as $status): ?><option value="<?= h($status) ?>" <?= $status === $entry['status'] ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?>
        </select>
        <button>Zapisz</button>
      </form>
      <form method="post" onsubmit="return confirm('Usunąć wpis i plik? Link autora przestanie działać.')" style="margin-top:.4rem">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($entry['id']) ?>">
        <button><small>Usuń</small></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="2" class="muted">Brak wpisów.</td></tr><?php endif; ?>
</table>
<?php
page('Panel redakcji', ob_get_clean());
