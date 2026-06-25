<?php
// ── One-off recovery: Aaron's lost pet ────────────────────────────────────────
// Drop this file next to api.php on the NAS (via DSM File Station — no git/SSH
// needed) and open it in a browser:
//   https://habit.capconnex.com.au/restore-aaron-pet.php           ← dry-run (safe)
//   https://habit.capconnex.com.au/restore-aaron-pet.php?apply=1    ← actually writes
//
// What it does:
//   1. Scans for database backups (data/ and the app folder). If a backup is
//      found that still has Aaron's pet alive, it restores Aaron's pet row from
//      the most recent such backup (his last good state before he was lost) and
//      clears the death penalty.
//   2. If NO backup with Aaron's pet exists, it re-creates a Pichu for Aaron:
//      species "hamster" at the Pichu (baby) stage, hunger/joy full, energy full
//      (fatigue 0), and gives + equips a Cardboard Box bed.
//   3. Sets Ivy's pet so it needs exactly 100 more growth points to evolve.
//
// DRY-RUN by default: it only reports what it found and what it WOULD do.
// Add ?apply=1 to actually write. DELETE THIS FILE once you're done.
header('Content-Type: text/plain; charset=UTF-8');

$apply  = isset($_GET['apply']) && $_GET['apply'] === '1';
$appDir = __DIR__;
$dbFile = $appDir . '/data/habit.db';

// Evolution costs mirror PET_SPECIES in index.html: each stage = cost × 20 pts.
// (Pet-cost overrides only affect adoption price & hunger decay, NOT evolution.)
$SPECIES_COST = [
    'dragon'=>60, 'ghost'=>45, 'kitten'=>20, 'doggy'=>20,
    'fish'=>10,   'turtle'=>15,'bunny'=>20,  'hamster'=>15, 'parrot'=>25,
];
// Number of stages per species (incl. Egg) so we know when there is no "next".
$SPECIES_STAGES = [
    'dragon'=>4,'ghost'=>4,'kitten'=>4,'doggy'=>4,
    'fish'=>3, 'turtle'=>4,'bunny'=>3,'hamster'=>4,'parrot'=>3,
];

function openDb(string $f): ?PDO {
    try {
        $db = new PDO('sqlite:' . $f);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (Exception $e) { return null; }
}

echo "Aaron pet recovery @ " . date('c') . "   mode=" . ($apply ? "APPLY (writing)" : "DRY-RUN (read-only)") . "\n";
echo str_repeat('=', 64) . "\n\n";

if (!file_exists($dbFile)) { echo "❌ Live DB not found at $dbFile\n"; exit; }
$db = openDb($dbFile);
if (!$db) { echo "❌ Cannot open live DB\n"; exit; }

// ── Step 0: show current live state ──────────────────────────────────────────
function showPet(PDO $db, string $kid): void {
    $s = $db->prepare("SELECT * FROM pets WHERE kid_id=?"); $s->execute([$kid]);
    $p = $s->fetch();
    if (!$p) { echo "   $kid: NO pet right now.\n"; return; }
    echo "   $kid: species={$p['species_id']} growth={$p['growth_points']} "
       . "hunger={$p['hunger']} joy={$p['joy']} fatigue={$p['fatigue']} "
       . "home=" . ($p['home_item'] ?? '—') . " owned_homes=" . ($p['owned_homes'] ?? '[]') . "\n";
}
echo "Current live state:\n";
showPet($db, 'aaron');
showPet($db, 'ivy');
echo "\n";

// ── Step 1: hunt for backups that still have Aaron's pet ─────────────────────
$candidates = [];
foreach ([$appDir, $appDir . '/data'] as $dir) {
    if (!is_dir($dir)) continue;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        if (!is_file($path) || $path === $dbFile) continue;
        // Anything that looks like a DB copy / backup.
        $isBak = preg_match('/\.(db|sqlite|sqlite3|bak)$/i', $f)
              || stripos($f, 'backup') !== false
              || stripos($f, 'habit') !== false;
        if (!$isBak) continue;
        $bdb = openDb($path);
        if (!$bdb) continue;
        try {
            $st = $bdb->prepare("SELECT * FROM pets WHERE kid_id='aaron'");
            $st->execute();
            $arow = $st->fetch();
        } catch (Exception $e) { continue; } // not a habit DB
        $candidates[] = [
            'path' => $path, 'mtime' => filemtime($path),
            'aaronPet' => $arow ?: null,
        ];
    }
}
usort($candidates, function($a,$b){ return $b['mtime'] <=> $a['mtime']; });

echo "Backup scan:\n";
if (!$candidates) {
    echo "   No backup database files found in the app or data folder.\n";
    echo "   (NAS/DSM snapshots, if any, are not visible from PHP — restore those\n";
    echo "    via DSM if you have them and prefer a full rollback.)\n";
} else {
    foreach ($candidates as $c) {
        $tag = $c['aaronPet'] ? ("HAS Aaron's pet: " . $c['aaronPet']['species_id']
                 . " growth=" . $c['aaronPet']['growth_points']) : "no Aaron pet";
        echo "   " . date('Y-m-d H:i', $c['mtime']) . "  " . basename($c['path']) . "  → $tag\n";
    }
}
echo "\n";

$restoreSrc = null;
foreach ($candidates as $c) { if ($c['aaronPet']) { $restoreSrc = $c; break; } }

// ── Step 2: plan + (optionally) apply for Aaron ──────────────────────────────
if ($restoreSrc) {
    echo "PLAN for Aaron: RESTORE from backup " . basename($restoreSrc['path'])
       . " (" . date('Y-m-d H:i', $restoreSrc['mtime']) . ") — his last good pet.\n";
    if ($apply) {
        $row = $restoreSrc['aaronPet'];
        // Intersect backup columns with the live pets schema so older/newer
        // backups both work.
        $liveCols = array_column($db->query("PRAGMA table_info(pets)")->fetchAll(), 'name');
        $cols = array_values(array_intersect(array_keys($row), $liveCols));
        $place = implode(',', array_fill(0, count($cols), '?'));
        $vals = array_map(function($c) use ($row){ return $row[$c]; }, $cols);
        $db->beginTransaction();
        $db->prepare("DELETE FROM pets WHERE kid_id='aaron'")->execute();
        $db->prepare("INSERT INTO pets (" . implode(',', $cols) . ") VALUES ($place)")->execute($vals);
        $db->prepare("UPDATE kids SET adoption_penalty=0 WHERE id='aaron'")->execute();
        $db->commit();
        echo "   ✅ Restored Aaron's pet from backup and cleared the death penalty.\n";
    }
} else {
    echo "PLAN for Aaron: no backup with his pet → CREATE a fresh Pichu.\n";
    echo "   species=hamster, stage=Pichu (growth=300), hunger=100, joy=100,\n";
    echo "   energy=full (fatigue=0), Cardboard Box owned + equipped.\n";
    if ($apply) {
        $now = time();
        $db->beginTransaction();
        $db->prepare("DELETE FROM pets WHERE kid_id='aaron'")->execute();
        $db->prepare(
            "INSERT INTO pets
             (kid_id, species_id, mood, growth_points, last_pet, hunger, joy, fatigue,
              sleep_until, hunger_low_days, last_bath, home_item, pet_name, pet_bg,
              owned_homes, owned_bgs, rest_start)
             VALUES ('aaron','hamster',100,300,0,100,100,0,0,0,?, 'box', NULL,'default','[\"box\"]','[]',0)"
        )->execute([$now]);
        $db->prepare("UPDATE kids SET adoption_penalty=0 WHERE id='aaron'")->execute();
        $db->commit();
        echo "   ✅ Created Aaron's Pichu with full stats + Cardboard Box.\n";
    }
}
echo "\n";

// ── Step 3: Ivy — set growth so she needs exactly 100 more pts to evolve ──────
$is = $db->prepare("SELECT * FROM pets WHERE kid_id='ivy'"); $is->execute();
$ivy = $is->fetch();
if (!$ivy) {
    echo "PLAN for Ivy: SKIPPED — Ivy has no pet right now (nothing to adjust).\n";
} else {
    $sp = $ivy['species_id'];
    $costPerStage = ($SPECIES_COST[$sp] ?? 20) * 20;
    $numStages = $SPECIES_STAGES[$sp] ?? 4;
    $growth = (int)$ivy['growth_points'];
    // Current stage index = highest i where growth >= i*costPerStage.
    $idx = 0;
    for ($i = $numStages - 1; $i >= 0; $i--) { if ($growth >= $i * $costPerStage) { $idx = $i; break; } }
    if ($idx >= $numStages - 1) {
        echo "PLAN for Ivy: SKIPPED — pet ({$sp}) is already at its FINAL stage; "
           . "there is no next evolution to be '100 pts away' from.\n";
    } else {
        $nextThreshold = ($idx + 1) * $costPerStage;
        $target = $nextThreshold - 100;            // 100 pts short of evolving
        echo "PLAN for Ivy: set growth {$growth} → {$target} "
           . "(next evolution at {$nextThreshold}, so 100 pts to go).\n";
        if ($apply) {
            $db->prepare("UPDATE pets SET growth_points=? WHERE kid_id='ivy'")->execute([$target]);
            echo "   ✅ Ivy's pet now needs 100 more growth points to evolve.\n";
        }
    }
}
echo "\n";

echo str_repeat('=', 64) . "\n";
if ($apply) {
    echo "Done. Final live state:\n";
    showPet($db, 'aaron');
    showPet($db, 'ivy');
    echo "\n⚠️  DELETE this file (restore-aaron-pet.php) now that it has run.\n";
} else {
    echo "This was a DRY-RUN. Re-open with ?apply=1 to perform the changes above.\n";
}
