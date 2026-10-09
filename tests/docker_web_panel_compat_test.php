<?php

$root = dirname(__DIR__);
$keyboardPage = file_get_contents($root . '/panel/keyboard.php');
$keyboardBundle = file_get_contents($root . '/panel/js/sort_keyboard.js');
$functions = file_get_contents($root . '/function.php');
$admin = file_get_contents($root . '/admin.php');
$installer = file_get_contents($root . '/install.sh');
$runtimeStart = strpos($installer, 'cat > "$dir/container-start.sh"');
$runtimeEnd = $runtimeStart === false ? false : strpos($installer, "\nEOF", $runtimeStart);
$containerRuntime = ($runtimeStart !== false && $runtimeEnd !== false)
    ? substr($installer, $runtimeStart, $runtimeEnd - $runtimeStart)
    : '';

$assertions = [
    'keyboard data is served from the authenticated panel page' => strpos($keyboardBundle, 'window.location.pathname + "?action=data"') !== false,
    'keyboard save uses the authenticated panel page' => strpos($keyboardBundle, 'he.post(window.location.pathname') !== false,
    'keyboard bundle no longer depends on root api route' => strpos($keyboardBundle, 'window.location.origin) + "/api/keyboard.php"') === false,
    'keyboard endpoint requires panel authentication' => strpos($keyboardPage, 'require_auth();') !== false,
    'keyboard JSON responses disable caching' => strpos($keyboardPage, "header('Cache-Control: no-store") !== false,
    'keyboard assets carry a deployment cache version' => strpos($keyboardPage, "filemtime(__DIR__ . '/js/sort_keyboard.js')") !== false,
    'application cron registration is skipped inside Docker' => strpos($functions, "getenv('MIRZA_DOCKER_INSTANCE') !== false") !== false,
    'lottery toggle leaves Docker scheduler ownership to the container' => strpos($admin, '$isDockerRuntime = getenv(\'MIRZA_DOCKER_INSTANCE\') !== false;') !== false,
    'Docker container runtime is generated' => $containerRuntime !== '',
    'Docker runtime includes service monitoring cron' => strpos($containerRuntime, '/cronbot/NoticationsService.php') !== false,
    'Docker runtime includes payment expiry cron' => strpos($containerRuntime, '/cronbot/payment_expire.php') !== false,
    'Docker runtime includes panel uptime cron' => strpos($containerRuntime, '/cronbot/uptime_panel.php') !== false,
    'Docker runtime executes cron scripts locally' => strpos($containerRuntime, 'php /var/www/html/cronbot/') !== false,
    'Docker runtime cron does not call its public domain' => strpos($containerRuntime, 'curl https://') === false,
];

$failed = [];
foreach ($assertions as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Docker/web-panel compatibility checks failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Docker/web-panel compatibility checks passed.\n";
