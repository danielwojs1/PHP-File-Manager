<?php
/**
 * Text-only PHP File Manager (English UI)
 * Root: /data (absolute). If unavailable, falls back to ./data next to this script.
 * Features: folders, sorting, search, upload/download, create/delete folder, delete files.
 * Icons: /icons for folder and types (music, video, image, document, archive, unknown) or specific extensions.
 *
 * Place this file as index.php in your web directory.
 * Ensure /data is writable by PHP (or the script will create ./data).
 * Ensure /icons contains: folder.png, music.png, video.png, image.png, document.png, archive.png, unknown.png
 * Optionally add extension-specific icons like mp3.png, aac.png, avi.png, mp4.png, etc.
 */

declare(strict_types=1);

// -------------------------------
// Configuration
// -------------------------------
$ROOT = realpath('/data') ?: realpath(__DIR__ . '/data');
$SHOW_HIDDEN = false;                   // Whether to show dotfiles (., .., .git, etc.)
$MAX_UPLOAD_SIZE = 4096 * 1024 * 1024;   // 200 MB
$ALLOW_UPLOAD = true;
$ALLOW_DELETE = true;
$ALLOW_MKDIR  = true;
$ICONS_BASE_URL = "/apps/files/icons";             // e.g., /icons/music.png, /icons/mp3.png, etc.
$APP_TITLE = "ISDW Files";

// Create fallback ./data if /data is unavailable
if ($ROOT === false) {
    $fallback = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0775, true);
    }
    $ROOT = realpath($fallback);
}
if ($ROOT === false) {
    http_response_code(500);
    echo "Failed to initialize data root.";
    exit;
}

// -------------------------------
// Helpers (security & path handling)
// -------------------------------
function sanitizeSegment(string $seg): string {
    // Allow letters, digits, basic separators; strip other characters
    return preg_replace('/[^A-Za-z0-9._\- ]/', '', $seg) ?? '';
}

function safeJoin(string $base, string $relative): string {
    $relative = str_replace('\\', '/', $relative);
    $parts = array_filter(explode('/', $relative), fn($p) => $p !== '' && $p !== '.');
    $safeParts = array_map('sanitizeSegment', $parts);
    $joined = $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $safeParts);
    $real = realpath($joined);

    if ($real === false) {
        // Path may not exist yet (mkdir target or upload dest)
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $joined);
        // Prevent path traversal by verifying prefix
        if (strpos($candidate, $base) !== 0) {
            throw new RuntimeException('Access denied.');
        }
        return $candidate;
    }
    if (strpos($real, $base) !== 0) {
        throw new RuntimeException('Access denied.');
    }
    return $real;
}

function isHidden(string $name): bool {
    return strlen($name) > 0 && $name[0] === '.';
}

function ext(string $filename): string {
    $pos = strrpos($filename, '.');
    return $pos === false ? '' : strtolower(substr($filename, $pos + 1));
}

function typeForExtension(string $extension): string {
    $music = ['mp3','aac','wav','flac','ogg','m4a','aiff'];
    $video = ['mp4','avi','mkv','mov','wmv','webm','mpeg','mpg','m4v'];
    $image = ['jpg','jpeg','png','gif','bmp','webp','tiff','svg'];
    $doc   = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','rtf','csv'];
    $arch  = ['zip','rar','7z','tar','gz','bz2','xz'];
    if (in_array($extension, $music, true)) return 'music';
    if (in_array($extension, $video, true)) return 'video';
    if (in_array($extension, $image, true)) return 'image';
    if (in_array($extension, $doc, true))   return 'document';
    if (in_array($extension, $arch, true))  return 'archive';
    return 'unknown';
}

function iconFor(string $name, bool $isDir, string $iconsBase): string {
    if ($isDir) {
        $folder = $iconsBase . '/folder.png';
        return file_exists($_SERVER['DOCUMENT_ROOT'] . $folder) ? $folder : ($iconsBase . '/unknown.png');
    }
    $e = ext($name);
    $specificWeb = $iconsBase . '/' . $e . '.png';                 // e.g., /icons/mp3.png
    $typeWeb     = $iconsBase . '/' . typeForExtension($e) . '.png';// e.g., /icons/music.png
    $unknownWeb  = $iconsBase . '/unknown.png';

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

    $specificFs = $docroot . $specificWeb;
    $typeFs     = $docroot . $typeWeb;
    $unknownFs  = $docroot . $unknownWeb;

    if ($e !== '' && $docroot && file_exists($specificFs)) {
        return $specificWeb;
    }
    if ($docroot && file_exists($typeFs)) {
        return $typeWeb;
    }
    return $unknownWeb;
}


function humanSize(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $val = max($bytes, 0);
    while ($val >= 1024 && $i < count($units)-1) { $val /= 1024; $i++; }
    return sprintf('%.2f %s', $val, $units[$i]);
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function urlPath(string $path): string {
    $rel = trim(str_replace('\\', '/', $path), '/');
    return $rel === '' ? '' : $rel;
}

// -------------------------------
// Request parsing
// -------------------------------
$reqPath = $_GET['path'] ?? '';
$pathRel = ltrim((string)$reqPath, '/');
$cwd     = safeJoin($ROOT, $pathRel);

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$search  = trim((string)($_GET['q'] ?? ''));
$sort    = $_GET['sort'] ?? 'name'; // name|size|type|mtime
$order   = $_GET['order'] ?? 'asc'; // asc|desc

// -------------------------------
// Actions: mkdir, delete file/folder, upload, download
// -------------------------------
$flash = '';
try {
    if ($action === 'mkdir' && $ALLOW_MKDIR) {
        $newName = sanitizeSegment($_POST['dirname'] ?? '');
        if ($newName === '') throw new RuntimeException('Folder name is required.');
        $newPath = safeJoin($cwd, $newName);
        if (file_exists($newPath)) throw new RuntimeException('Folder already exists.');
        if (!mkdir($newPath, 0775)) throw new RuntimeException('Failed to create folder.');
        $flash = 'Folder created.';
    } elseif ($action === 'delete' && $ALLOW_DELETE) {
        $target = $_POST['target'] ?? '';
        $abs    = safeJoin($cwd, $target);
        if (!file_exists($abs)) throw new RuntimeException('Target not found.');
        if (is_dir($abs)) {
            // Delete empty directory only for safety
            if (!@rmdir($abs)) throw new RuntimeException('Failed to delete folder. Make sure it is empty.');
            $flash = 'Folder deleted.';
        } else {
            if (!@unlink($abs)) throw new RuntimeException('Failed to delete file.');
            $flash = 'File deleted.';
        }
    } elseif ($action === 'upload' && $ALLOW_UPLOAD) {
        if (!isset($_FILES['upload'])) throw new RuntimeException('No file uploaded.');
        $f = $_FILES['upload'];
        if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error code: ' . (string)$f['error']);
        if ((int)$f['size'] > $MAX_UPLOAD_SIZE) throw new RuntimeException('File too large.');
        $basename = sanitizeSegment(basename((string)$f['name']));
        if ($basename === '') throw new RuntimeException('Invalid filename.');
        $dest = safeJoin($cwd, $basename);
        if (!@move_uploaded_file($f['tmp_name'], $dest)) throw new RuntimeException('Failed to save uploaded file.');
        $flash = 'File uploaded.';
    } elseif ($action === 'download') {
        $target = $_GET['target'] ?? '';
        $abs    = safeJoin($cwd, $target);
        if (!is_file($abs)) throw new RuntimeException('File not found.');
        // Stream file to client
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string)filesize($abs));
        header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
        readfile($abs);
        exit;
    }
} catch (Throwable $e) {
    $flash = 'Error: ' . $e->getMessage();
}

// -------------------------------
// Listing: read directory
// -------------------------------
$entries = [];
$items = @scandir($cwd);
if ($items === false) {
    $items = [];
    $flash = $flash ? $flash : 'Error: Unable to read directory.';
}
foreach ($items as $name) {
    if ($name === '.' || $name === '..') continue;
    if (!$SHOW_HIDDEN && isHidden($name)) continue;

    $full = $cwd . DIRECTORY_SEPARATOR . $name;
    $isDir = is_dir($full);
    $size  = $isDir ? 0 : (int)@filesize($full);
    $mtime = (int)@filemtime($full);
    $extension = $isDir ? '' : ext($name);
    $type = $isDir ? 'folder' : typeForExtension($extension);

    // Search filter
    if ($search !== '' && stripos($name, $search) === false) continue;

    $entries[] = [
        'name' => $name,
        'isDir'=> $isDir,
        'size' => $size,
        'mtime'=> $mtime,
        'ext'  => $extension,
        'type' => $type,
        'icon' => iconFor($name, $isDir, $ICONS_BASE_URL),
    ];
}

// -------------------------------
// Sorting
// -------------------------------
usort($entries, function($a, $b) use ($sort, $order) {
    // Always sort folders before files to keep navigation natural
    if ($a['isDir'] !== $b['isDir']) return $a['isDir'] ? -1 : 1;
    $dir = ($order === 'desc') ? -1 : 1;

    switch ($sort) {
        case 'size':
            return ($a['size'] <=> $b['size']) * $dir;
        case 'type':
            return strcmp($a['type'], $b['type']) * $dir;
        case 'mtime':
            return ($a['mtime'] <=> $b['mtime']) * $dir;
        case 'name':
        default:
            return strcasecmp($a['name'], $b['name']) * $dir;
    }
});

// -------------------------------
// Navigation helpers
// -------------------------------
$pathParts = array_filter(explode('/', urlPath(substr($cwd, strlen($ROOT)))));

// Build breadcrumbs
$crumbs = [];
$accum = '';
foreach ($pathParts as $p) {
    $accum = ($accum === '') ? $p : ($accum . '/' . $p);
    $crumbs[] = ['label' => $p, 'rel' => $accum];
}
$parentRel = '';
if (!empty($pathParts)) {
    $parentRel = implode('/', array_slice($pathParts, 0, -1));
}
function linkTo(string $rel, array $params = []): string {
    $base = '?path=' . urlencode($rel);
    foreach ($params as $k => $v) {
        $base .= '&' . urlencode($k) . '=' . urlencode((string)$v);
    }
    return $base;
}

// -------------------------------
// Render HTML (text-only, minimal tags)
// -------------------------------
header('Content-Type: text/html; charset=UTF-8');

?>

<?php if ($flash !== ''): ?>
<p><strong>Status:</strong> <?= esc($flash) ?></p>
<?php endif; ?>

<!-- Breadcrumbs -->
  <strong>Path:</strong>
  <a href="<?= linkTo('') ?>">/</a>
  <?php foreach ($crumbs as $i => $c): ?>
    / <a href="<?= linkTo($c['rel']) ?>"><?= esc($c['label']) ?></a>
  <?php endforeach; ?>
<hr>

<!-- Controls: Search + Sorting -->
<form method="get" action="">
  <input type="hidden" name="path" value="<?= esc(implode('/', $pathParts)) ?>">
  <label><strong>Search:</strong>
    <input type="text" name="q" value="<?= esc($search) ?>" placeholder="Name of the file or folder...">
  </label>
  <label><strong>Sort by:</strong>
    <select name="sort">
      <option value="name" <?= $sort==='name'?'selected':'' ?>>Name</option>
      <option value="size" <?= $sort==='size'?'selected':'' ?>>Size</option>
      <option value="type" <?= $sort==='type'?'selected':'' ?>>Type</option>
      <option value="mtime" <?= $sort==='mtime'?'selected':'' ?>>Date of last modify</option>
    </select>
  </label>
  <label><strong>Order:</strong>
    <select name="order">
      <option value="asc"  <?= $order==='asc'?'selected':'' ?>>Ascending</option>
      <option value="desc" <?= $order==='desc'?'selected':'' ?>>Descending</option>
    </select>
  </label>
  <button type="submit">OK</button>
</form>

<hr>

<!-- Folder create -->
<?php if ($ALLOW_MKDIR): ?>
<form method="post" action="?path=<?= urlencode(implode('/', $pathParts)) ?>">
  <input type="hidden" name="action" value="mkdir">
  <label><strong>Create new folder:</strong>
    <input type="text" name="dirname" placeholder="Name of the new folder...">
  </label>
  <button type="submit">OK</button>
</form>
<hr>
<?php endif; ?>

<!-- Upload -->
<?php if ($ALLOW_UPLOAD): ?>
<form method="post" enctype="multipart/form-data" action="?path=<?= urlencode(implode('/', $pathParts)) ?>">
  <input type="hidden" name="action" value="upload">
  <label><strong>Upload file:</strong>
    <input type="file" name="upload">
  </label>
  <button type="submit">OK</button>
  <small>(max <?= esc(humanSize($MAX_UPLOAD_SIZE)) ?>)</small>
</form>
<?php endif; ?>

<hr>

<!-- Parent link -->
<?php if ($parentRel !== ''): ?>
<p><a href="<?= linkTo($parentRel, ['q'=>$search,'sort'=>$sort,'order'=>$order]) ?>">Go to parent folder</a></p>
<?php endif; ?>

<!-- Listing --><center>
<table border="1" cellpadding="4" cellspacing="0">
  <thead>
    <tr>
      <th>Icon</th>
      <th>Name</th>
      <th>Type</th>
      <th>Size</th>
      <th>Modified</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($entries)): ?>
      <tr><td colspan="6">No items found.</td></tr>
    <?php else: ?>
      <?php foreach ($entries as $e): ?>
        <tr>
          <td><center>
            <img src="<?= esc($e['icon']) ?>" alt="icon" width="16" height="16"></center>
          </td>
          <td>
            <?php if ($e['isDir']): ?>
              <a href="<?= linkTo(trim(implode('/', $pathParts) . '/' . $e['name'], '/'), ['q'=>$search,'sort'=>$sort,'order'=>$order]) ?>">
                <?= esc($e['name']) ?>
              </a>
            <?php else: ?>
              <?= esc($e['name']) ?>
            <?php endif; ?>
          </td>
          <td><?= esc($e['isDir'] ? 'folder' : $e['type']) ?></td>
          <td><?= esc($e['isDir'] ? '-' : humanSize($e['size'])) ?></td>
          <td><?= esc(date('Y-m-d H:i:s', $e['mtime'])) ?></td>
          <td>
            <?php if ($e['isDir']): ?>
              <!-- Delete folder (empty only) -->
              <?php if ($ALLOW_DELETE): ?>
              <form method="post" action="?path=<?= urlencode(implode('/', $pathParts)) ?>" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="target" value="<?= esc($e['name']) ?>">
                <button type="submit" onclick="return confirm('Delete folder? (must be empty)')">Delete</button>
              </form>
              <?php endif; ?>
            <?php else: ?>
              <!-- Download file -->
              <button><a href="?path=<?= urlencode(implode('/', $pathParts)) ?>&action=download&target=<?= urlencode($e['name']) ?>">Download</a></button>
              <!-- Delete file -->
              <?php if ($ALLOW_DELETE): ?>
              <form method="post" action="?path=<?= urlencode(implode('/', $pathParts)) ?>" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="target" value="<?= esc($e['name']) ?>">
                <button type="submit" onclick="return confirm('Delete file?')">Delete</button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table></center>
</div>