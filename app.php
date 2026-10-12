<?php
declare(strict_types=1);
$sessionLifetime = 60 * 60 * 24 * 14;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => $cookieParams['path'] ?: '/',
    'domain' => $cookieParams['domain'],
    'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
// PHP can discard POST data before the script runs when post_max_size is exceeded.
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'errors'=>['This upload is larger than the server allows. The client will auto-compress images, or raise post_max_size in hosting PHP settings.']]);
        exit;
    }
    exit('This upload is larger than the server allows. Upload fewer or smaller images, or increase post_max_size in your hosting PHP settings.');
}
function load_env(string $path): array {
    if (!is_file($path) || !is_readable($path)) { http_response_code(500); exit('Database configuration is missing. Copy .env.example to .env and set your database details.'); }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key); $value = trim($value);
        if ($key === '') continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) $value = substr($value, 1, -1);
        $values[$key] = stripcslashes($value);
    }
    return $values;
}
$config = load_env(__DIR__ . '/.env');
function auth_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void {
    if (auth_user()) return;
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'errors'=>['Your login session has expired. Sign in again, then retry the upload.']]);
        exit;
    }
    header('Location: login.php');
    exit;
}
function db(): PDO {
    static $pdo;
    global $config;
    if (!$pdo) {
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $required) if (empty($config[$required])) { http_response_code(500); exit('Database settings are incomplete. Check your .env file.'); }
        $dsn = 'mysql:host=' . $config['DB_HOST'] . ';dbname=' . $config['DB_NAME'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    return $pdo;
}
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function check_csrf(): void {
    if (hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) return;
    http_response_code(403);
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'errors'=>['This page expired before the upload started. Reload the page and try again.']]);
        exit;
    }
    exit('Invalid request. Please go back and try again.');
}
function flash(?string $message = null, string $type = 'success'): ?array { if ($message !== null) { $_SESSION['flash'] = ['message'=>$message,'type'=>$type]; return null; } $value = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $value; }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function ensure_upload_dir(): string { $dir = __DIR__ . '/uploads'; if (!is_dir($dir)) mkdir($dir, 0755, true); return $dir; }
function save_webp(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'This image exceeds the upload_max_filesize setting on the server.',
            UPLOAD_ERR_FORM_SIZE => 'This image exceeds the maximum size allowed by the form.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload folder. Contact your hosting provider.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image to disk. Check hosting storage and permissions.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the image upload. Contact your hosting provider.',
        ];
        throw new RuntimeException($uploadErrors[$file['error']] ?? 'The image upload failed. Please try again.');
    }
    if (($file['size'] ?? 0) > 100 * 1024 * 1024) throw new RuntimeException('Each image must be under 100 MB.');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_file($tmp) || !is_readable($tmp)) throw new RuntimeException('Uploaded file is unreadable.');

    @ini_set('memory_limit', '256M');

    $info = @getimagesize($tmp);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new RuntimeException('Please upload a JPG, PNG, WebP or GIF image.');
    }
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('WebP conversion is unavailable. Enable PHP GD with WebP support in hosting control panel.');
    }

    $mime = $info['mime'];
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/gif'  => @imagecreatefromgif($tmp),
        default      => @imagecreatefromwebp($tmp),
    };
    if (!$image) throw new RuntimeException('That image could not be processed.');

    // Correct EXIF orientation for JPEGs
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        if (!empty($exif['Orientation'])) {
            $rotated = match ($exif['Orientation']) {
                3 => @imagerotate($image, 180, 0),
                6 => @imagerotate($image, -90, 0),
                8 => @imagerotate($image, 90, 0),
                default => null,
            };
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $max = 2000;
    if ($width > $max || $height > $max) {
        $scale = min($max / $width, $max / $height);
        $newW = (int)round($width * $scale);
        $newH = (int)round($height * $scale);
        $resized = imagecreatetruecolor($newW, $newH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    $name = bin2hex(random_bytes(16)) . '.webp';
    $path = ensure_upload_dir() . '/' . $name;
    $ok = imagewebp($image, $path, 82);
    imagedestroy($image);
    if (!$ok) throw new RuntimeException('Image conversion failed. Please try another image.');
    return 'uploads/' . $name;
}
function delete_image_file(string $path): void { $full = __DIR__ . '/' . $path; if (str_starts_with($path, 'uploads/') && is_file($full)) @unlink($full); }
function mobile_app_nav(string $active = ''): void {
    $items = [
        'home' => ['index.php', 'Rooms', '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5v10h13v-10M9 19.5v-6h6v6"/>'],
        'hotel' => ['hotel.php', 'Add hotel', '<path d="M12 3v18M3 12h18"/>'],
        'users' => ['users.php', 'Users', '<circle cx="9" cy="8" r="3.2"/><path d="M3 20v-1.5a6 6 0 0 1 12 0V20M16 5.2a3.2 3.2 0 0 1 0 6.2M18 14a5 5 0 0 1 3 4.5V20"/>'],
        'logout' => ['logout.php', 'Sign out', '<path d="M10 4H5v16h5M14 8l4 4-4 4M8 12h10"/>'],
    ];
    echo '<nav class="mobile-app-nav" aria-label="App navigation">';
    foreach ($items as $key => [$href, $label, $icon]) {
        $current = $active === $key ? ' aria-current="page"' : '';
        echo '<a href="' . h($href) . '"' . $current . '><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $icon . '</svg><span>' . h($label) . '</span></a>';
    }
    echo '</nav>';
}
