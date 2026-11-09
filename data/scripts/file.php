<?php declare(strict_types=1);

// Send a file whose url is signed by the module Access, without Omeka.
//
// A page rendered by Omeka checks the rights of each thumbnail and signs its
// url, so the files are sent here without starting Omeka for each of them. A
// request without valid signature is sent to Omeka, so the access is checked by
// the module as usual.
//
// The rule is managed by the module in the .htaccess, before the rule that
// redirects the files to the module:
//
//   RewriteCond %{QUERY_STRING} (^|&)access_signature= RewriteRule
//   "^files/(large|medium|square)/(.*)$" "modules/Access/data/scripts/file.php"
//   [END,E=ACCESS_FILE:$1/$2]
//
// If modules are installed via composer, replace "modules/" by
// "composer-addons/modules/".
//
// @see \Access\Stdlib\FileSignature

/**
 * Let Omeka serve the request, as if it was rewritten to its index.
 *
 * The script names should be the ones of the index, else Laminas cannot
 * determine the base path and no route matches.
 *
 * @see \ImageServer data/scripts/iiiftile.php
 */
function omekaIndex(string $omekaPath): void
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($scriptName, '/composer-addons/modules/');
    if ($pos === false) {
        $pos = strrpos($scriptName, '/modules/');
    }
    if ($pos !== false) {
        $_SERVER['SCRIPT_NAME'] = substr($scriptName, 0, $pos) . '/index.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    }
    $_SERVER['SCRIPT_FILENAME'] = $omekaPath . '/index.php';
    chdir($omekaPath);
    require $omekaPath . '/index.php';
}

// The path of the script is used first, because __DIR__ is the real path, that
// is outside of Omeka when the module is a symbolic link.
$omekaPath = null;
foreach ([dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')), __DIR__] as $scriptDir) {
    $path = dirname($scriptDir, 4);
    if (basename($path) === 'composer-addons') {
        $path = dirname($path);
    }
    if (is_file($path . '/config/database.ini')) {
        $omekaPath = $path;
        break;
    }
}
$omekaPath ??= dirname(__DIR__, 4);

require_once dirname(__DIR__, 2) . '/src/Stdlib/FileSignature.php';

$mediaTypes = [
    'avif' => 'image/avif',
    'gif' => 'image/gif',
    'jpeg' => 'image/jpeg',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

$path = (string) ($_SERVER['REDIRECT_ACCESS_FILE'] ?? $_SERVER['ACCESS_FILE'] ?? '');
$signature = (string) ($_GET[\Access\Stdlib\FileSignature::QUERY_ARG] ?? '');
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$filepath = $omekaPath . '/files/' . $path;
$signer = \Access\Stdlib\FileSignature::fromOmekaPath($omekaPath);

if (!preg_match('~^[a-zA-Z0-9][-a-zA-Z0-9]*/[^\0]+$~', $path)
    || strpos($path, '..') !== false
    || !isset($mediaTypes[$extension])
    || !$signer
    || !$signer->isValid($path, $signature)
    || !is_file($filepath)
    || !is_readable($filepath)
) {
    omekaIndex($omekaPath);
    exit;
}

// The signature is specific to the user, so the cache is private.
$lastModified = (int) filemtime($filepath);
header('Cache-Control: private, max-age=86400');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
$ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
if ($lastModified && $ifModifiedSince >= $lastModified) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mediaTypes[$extension]);
header('Content-Length: ' . filesize($filepath));
header('Omeka-S-Access-Status: signed');
readfile($filepath);
exit;
