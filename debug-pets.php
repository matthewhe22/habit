<?php
// ── Pet diagnostics (read-only) ───────────────────────────────────────────────
// Drop this file into the same folder as api.php on the NAS (via DSM File
// Station — no git/SSH needed), then open it in a browser:
//   https://habit.capconnex.com.au/debug-pets.php
// It shows each kid's current pet status, the death-penalty flag, and the pet
// death log (if any) so you can see why a pet disappeared. Delete the file when
// you're done.
header('Content-Type: text/plain; charset=UTF-8');

$dbFile = __DIR__ . '/data/habit.db';
if (!file_exists($dbFile)) { echo "DB not found at $dbFile\n"; exit; }

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) { echo "Cannot open DB: ".$e->getMessage()."\n"; exit; }

$now = time();
echo "Pet diagnostics @ " . date('c', $now) . "\n";
echo str_repeat('=', 60) . "\n\n";

// Per-kid current status
foreach ($db->query("SELECT * FROM kids ORDER BY sort_order, name") as $k) {
    $penalty = (int)($k['adoption_penalty'] ?? 0);
    echo "👤 {$k['name']}  (id={$k['id']})\n";
    echo "   balance: {$k['balance']} pts   death-penalty flag: " . ($penalty ? "YES (1.5× next adoption)" : "no") . "\n";

    $ps = $db->prepare("SELECT * FROM pets WHERE kid_id=?");
    $ps->execute([$k['id']]);
    $p = $ps->fetch();
    if (!$p) {
        echo "   pet: NONE right now.\n";
        echo "        → " . ($penalty
            ? "penalty flag is set, so the pet DIED (starved or not bathed for 10+ days)."
            : "no penalty flag — pet was never adopted, or was removed via Restart/delete.") . "\n";
    } else {
        $lastBath = (int)($p['last_bath'] ?? 0);
        $daysBath = $lastBath > 0 ? floor(($now - $lastBath) / 86400) : 0;
        echo "   pet: {$p['species_id']}" . (!empty($p['pet_name']) ? " \"{$p['pet_name']}\"" : "") . "\n";
        echo "        hunger={$p['hunger']}  joy={$p['joy']}  fatigue={$p['fatigue']}\n";
        echo "        hunger_low_days=" . (int)($p['hunger_low_days'] ?? 0) . "/3  (3 = starves & dies)\n";
        echo "        days since bath={$daysBath}/10  (10+ = dies)\n";
    }
    echo "\n";
}

// Death log (populated going forward once the new code is deployed)
echo str_repeat('-', 60) . "\n";
echo "Pet death log:\n";
try {
    $rows = $db->query("SELECT * FROM pet_deaths ORDER BY died_at DESC LIMIT 50")->fetchAll();
    if (!$rows) {
        echo "  (empty — no deaths recorded yet. Past deaths weren't logged; the\n";
        echo "   new code records every future death with its reason.)\n";
    } else {
        foreach ($rows as $r) {
            echo "  " . date('Y-m-d H:i', (int)$r['died_at']) . "  kid={$r['kid_id']}  pet={$r['species_id']}  reason={$r['reason']}\n";
        }
    }
} catch (Exception $e) {
    echo "  (pet_deaths table not present yet — appears after the new code is deployed.)\n";
}
