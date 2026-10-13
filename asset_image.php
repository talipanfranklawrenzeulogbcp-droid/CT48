<?php
require_once __DIR__ . '/includes/helpers.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    exit;
}

try {
    $stmt = db()->prepare("SELECT asset_tag FROM assets WHERE id = ? AND NOT (COALESCE(asset_tag, '') = 'AST-0003' OR COALESCE(serial_number, '') = 'SN-GSMS-0003' OR LOWER(TRIM(COALESCE(name, ''))) = 'network printer') LIMIT 1");
    $stmt->execute([(int)$id]);
    $assetTag = $stmt->fetchColumn();
    if (!is_string($assetTag) || $assetTag === '') {
        http_response_code(404);
        exit;
    }

    $base = hash('sha256', strtoupper(trim($assetTag)));
    $directory = __DIR__ . '/storage/asset_images';
    $mimeByExtension = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    foreach ($mimeByExtension as $extension => $expectedMime) {
        $path = $directory . '/' . $base . '.' . $extension;
        if (!is_file($path) || !is_readable($path)) continue;
        $actualMime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($actualMime !== $expectedMime || @getimagesize($path) === false) continue;

        header('Content-Type: ' . $actualMime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: inline; filename="asset-' . (int)$id . '.' . $extension . '"');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
} catch (Throwable $e) {
    error_log('Asset image request failed: ' . $e->getMessage());
}
http_response_code(404);
exit;
