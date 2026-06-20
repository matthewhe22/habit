<?php
// ── Auto-deploy hook ──────────────────────────────────────────────────────────
// Called by the GitHub Actions "Deploy to NAS" workflow after every push to
// main. It fetches the latest main and hard-resets the working tree to match,
// so the live site always reflects the repo.
//
// reset --hard only touches *tracked* files, so untracked / git-ignored paths —
// most importantly data/habit.db (the SQLite database) — are left untouched.
//
// Every step is logged in the response so a broken deploy is visible (in the
// GitHub Action output and when hitting this URL directly) instead of silently
// returning "success" while nothing changed.
header('Content-Type: text/plain; charset=UTF-8');

// Optional shared secret. Leave HABIT_DEPLOY_KEY unset to keep the endpoint
// open (matching the original behaviour); set it (and pass ?key=... from the
// workflow) to lock it down.
$deployKey = getenv('HABIT_DEPLOY_KEY') ?: '';
if ($deployKey !== '' && (($_GET['key'] ?? '') !== $deployKey)) {
    http_response_code(403);
    echo "Forbidden: bad or missing key\n";
    exit;
}

$dir = __DIR__;
$git = trim((string)@shell_exec('command -v git')) ?: '/usr/bin/git';

function run(string $cmd): array {
    $out = []; $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

if (!chdir($dir)) {
    http_response_code(500);
    echo "❌ Cannot chdir to $dir\n";
    exit;
}

echo "Deploy @ " . date('c') . "\n";
echo "Dir: $dir\nGit: $git\n\n";

// Avoid git's "detected dubious ownership" abort, which happens when the web
// server user differs from the user that owns the repo — a common cause of
// silent deploy failures on a NAS.
run(sprintf('%s config --global --add safe.directory %s', escapeshellarg($git), escapeshellarg($dir)));

$steps = [
    'branch' => sprintf('%s rev-parse --abbrev-ref HEAD', escapeshellarg($git)),
    'fetch'  => sprintf('%s fetch --prune origin main', escapeshellarg($git)),
    'reset'  => sprintf('%s reset --hard origin/main', escapeshellarg($git)),
    'head'   => sprintf('%s log -1 --oneline', escapeshellarg($git)),
];

$ok = true;
foreach ($steps as $name => $cmd) {
    [$code, $out] = run($cmd);
    echo "── $name (exit $code) ──\n" . ($out !== '' ? $out : '(no output)') . "\n\n";
    if ($code !== 0) $ok = false;
}

http_response_code($ok ? 200 : 500);
echo $ok ? "✅ Deploy complete\n" : "❌ Deploy failed — see step output above\n";
