<?php
// ── One-off: set Aaron's Pichu to 100 points away from evolving ───────────────
// Drop next to api.php on the NAS (DSM File Station) and open in a browser:
//   https://habit.capconnex.com.au/tune-aaron-evo.php          ← dry-run (safe)
//   https://habit.capconnex.com.au/tune-aaron-evo.php?apply=1   ← actually writes
//
// Sets Aaron's pet growth_points so it needs exactly 100 more points to reach
// the next evolution stage (same treatment Ivy already got). Dry-run by default.
// DELETE THIS FILE once it has run.
header('Content-Type: text/plain; charset=UTF-8');

$apply  = isset($_GET['apply']) && $_GET['apply'] === '1';
$kidId  = $_GET['kid'] ?? 'aaron';          // override with ?kid=ivy if ever needed
$dbFile = __DIR__ . '/data/habit.db';

// Evolution costs mirror PET_SPECIES in index.html: each stage = cost × 20 pts.
$SPECIES_COST   = ['dragon'=>60,'ghost'=>45,'kitten'=>20,'doggy'=>20,'fish'=>10,'turtle'=>15,'bunny'=>20,'hamster'=>15,'parrot'=>25];
$SPECIES_STAGES = ['dragon'=>4,'ghost'=>4,'kitten'=>4,'doggy'=>4,'fish'=>3,'turtle'=>4,'bunny'=>3,'hamster'=>4,'parrot'=>3];

echo "Evolution tune for '$kidId' @ " . date('c') . "   mode=" . ($apply ? "APPLY" : "DRY-RUN") . "\n";
echo str_repeat('=', 56) . "\n\n";

if (!file_exists($dbFile)) { echo "❌ DB not found at $dbFile\n"; exit; }
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) { echo "❌ Cannot open DB: " . $e->getMessage() . "\n"; exit; }

$st = $db->prepare("SELECT * FROM pets WHERE kid_id=?"); $st->execute([$kidId]);
$pet = $st->fetch();
if (!$pet) { echo "❌ '$kidId' has no pet.\n"; exit; }

$sp = $pet['species_id'];
$costPerStage = ($SPECIES_COST[$sp] ?? 20) * 20;
$numStages = $SPECIES_STAGES[$sp] ?? 4;
$growth = (int)$pet['growth_points'];

// Current stage index = highest i where growth >= i*costPerStage.
$idx = 0;
for ($i = $numStages - 1; $i >= 0; $i--) { if ($growth >= $i * $costPerStage) { $idx = $i; break; } }

echo "Pet: species=$sp  growth=$growth  stage=$idx/" . ($numStages - 1) . "  (each stage = $costPerStage pts)\n\n";

if ($idx >= $numStages - 1) {
    echo "Pet is already at its FINAL stage — there is no next evolution to be 100 pts from. No change.\n";
    exit;
}

$nextThreshold = ($idx + 1) * $costPerStage;
$target = $nextThreshold - 100;
echo "PLAN: set growth $growth → $target  (next evolution at $nextThreshold, so 100 pts to go).\n";

if ($apply) {
    $db->prepare("UPDATE pets SET growth_points=? WHERE kid_id=?")->execute([$target, $kidId]);
    echo "✅ Done. '$kidId' now needs 100 more growth points to evolve.\n";
    echo "\n⚠️  DELETE this file (tune-aaron-evo.php) now that it has run.\n";
} else {
    echo "\nThis was a DRY-RUN. Re-open with ?apply=1 to apply.\n";
}
