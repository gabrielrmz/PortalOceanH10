<?php
// Copyright 1999-2025. WebPros International GmbH. All rights reserved.
// vim: set et :

require_once('sdk.php');

define('TARGET_VERSION', '18.0.67');

define('RESULT_NETWORK_PROBLEM', 1);
define('RESULT_ERROR', 2);
define('RESULT_LICENSE_PROBLEM', 3);
define('RESULT_LICENSE_OK', 4);

function finish($rc, $sure = true)
{
    if ($rc !== 0) {
        fwrite(STDERR, "\n");
        if ($sure) {
            fwrite(STDERR, "Your license key is not compatible with Plesk Obsidian.\n");
        } else {
            fwrite(STDERR, "Your license key may not be compatible with Plesk Obsidian.\n");
        }
        fwrite(STDERR, "You need to upgrade your license before updating Plesk.\n");
        fwrite(STDERR, "For details, refer to the KB https://support.plesk.com/hc/en-us/articles/360023612594\n");
    }
    exit($rc);
}


$skipFlag = PRODUCT_VAR . DIRECTORY_SEPARATOR . "plesk-installer-skip-license-key-check.flag";
if (file_exists($skipFlag)) {
    fwrite(STDERR, "Plesk license key upgrade availability check was skipped due to a flag file.\n");
    exit(0);
}

if (!function_exists('of_get_key_by_product') || !function_exists('of_get_versions')) {
    fwrite(STDERR, "Plesk license key upgrade availability check should be run on sw-engine only.\n");
    exit(2);
}

foreach (["plesk-unified", "plesk-unix", "plesk-win"] as $prod) {
    $key = of_get_key_by_product($prod);
    if ($key !== false) {
        break;
    }
}

if ($key === false) {
    fwrite(STDERR, "No Plesk license key was found. License upgrade check is skipped.\n");
    finish(0);
}

$targetVersion = TARGET_VERSION;
$vers = of_get_versions($key); /* plesk >= 10.0.0 */
if (!is_array($vers)) {
    $vers = [$vers];
}

$match = false;
foreach ($vers as $ver) {
    if (!is_array($ver)) {
        $match |= strtok($ver, ".") == strtok($targetVersion, ".");
    } else {
        $match |= ("any" == $ver[0] || version_compare($ver[0], $targetVersion) <= 0) &&
                  ("any" == $ver[1] || version_compare($ver[1], $targetVersion) >= 0);
    }
}

if ($match) {
    fwrite(STDERR, "You do not need to upgrade the current license key.\n");
    fwrite(STDOUT, "License upgrade check to $targetVersion can be skipped.\n");
    fwrite(STDOUT, "Plesk versions compatible with the license key: " . preg_replace('/\n\s*/', '', var_export($vers, true)) . "\n");
    finish(0);
}

if (!function_exists('ka_is_key_upgrade_available')) {
    // Plesk 17.0
    fwrite(STDERR, "Cannot check whether Plesk license key upgrade is available.\n");
    finish(1, false);
}

$si = getServerInfo();
$result = ka_is_key_upgrade_available($prod, $targetVersion, $si);

$isConfused = false;
switch ($result['code']) {
    case RESULT_LICENSE_OK:
        fwrite(STDERR, "The licensing server accepted the key upgrade request.\n");
        fwrite(STDERR, "License upgrade to $targetVersion is available.\n");
        fwrite(STDERR, "Response from the licensing server: {$result['message']}\n");
        finish(0);
    case RESULT_NETWORK_PROBLEM:
        fwrite(STDERR, "Unable to connect to the licensing server to check if license upgrade is available.\n");
        fwrite(STDERR, "Error message: {$result['message']}\n");
        finish(2, false);
    case RESULT_LICENSE_PROBLEM:
        fwrite(STDERR, "Warning: Your Plesk license key cannot be upgraded.\n");
        fwrite(STDERR, "Response from the licensing server: {$result['message']}\n");
        finish(2);
    default:
        $isConfused = true;
        // fall-through
    case RESULT_ERROR:
        // This includes "Software Update Service (SUS) is not found for the given license key" case, but also many others.
        fwrite(STDERR, "Failed to check whether a new license key is available.\n");
        fwrite(STDERR, "Error message: {$result['message']}\n");
        if ($isConfused) {
            fwrite(STDERR, "Error code: {$result['code']}\n");
        }
        finish(2, !$isConfused);
}
