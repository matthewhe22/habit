<?php
// ── Habit Tracker API ─────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Database ──────────────────────────────────────────────────────────────────
$dbDir = __DIR__ . '/data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);

try {
    $db = new PDO('sqlite:' . $dbDir . '/habit.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys=ON;');
    initDB($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB: ' . $e->getMessage()]);
    exit;
}

// ── Router ────────────────────────────────────────────────────────────────────
try {
    switch ($action) {
        case 'state':          echo json_encode(getState($db)); break;
        case 'complete_task':  echo json_encode(completeTask($db, $body)); break;
        case 'undo_task':      echo json_encode(undoTask($db, $body)); break;
        case 'adopt_pet':      echo json_encode(adoptPet($db, $body)); break;
        case 'buy_item':       echo json_encode(buyItem($db, $body)); break;
        case 'pet_action':     echo json_encode(petAction($db, $body)); break;
        case 'update_pet_config': requireAdmin($db,$body); echo json_encode(updatePetConfig($db, $body)); break;
        case 'new_day':        requireAdmin($db,$body); echo json_encode(newDay($db)); break;
        case 'restart_system': requireAdmin($db,$body); echo json_encode(restartSystem($db)); break;
        case 'update_kid':     requireAdmin($db,$body); echo json_encode(updateKid($db, $body)); break;
        case 'add_kid':        requireAdmin($db,$body); echo json_encode(addKid($db, $body)); break;
        case 'delete_kid':     requireAdmin($db,$body); echo json_encode(deleteKid($db, $body)); break;
        case 'save_task':      requireAdmin($db,$body); echo json_encode(saveTask($db, $body)); break;
        case 'delete_task':    requireAdmin($db,$body); echo json_encode(deleteTask($db, $body)); break;
        case 'update_shop':    requireAdmin($db,$body); echo json_encode(updateShop($db, $body)); break;
        case 'update_pet_cost':requireAdmin($db,$body); echo json_encode(updatePetCost($db, $body)); break;
        case 'redeem_reward':  echo json_encode(redeemReward($db, $body)); break;
        case 'add_reward':     requireAdmin($db,$body); echo json_encode(addReward($db, $body)); break;
        case 'update_reward':  requireAdmin($db,$body); echo json_encode(updateReward($db, $body)); break;
        case 'delete_reward':  requireAdmin($db,$body); echo json_encode(deleteReward($db, $body)); break;
        case 'delete_redemption': requireAdmin($db,$body); echo json_encode(deleteRedemption($db, $body)); break;
        case 'verify_pin':     echo json_encode(verifyPin($db, $body)); break;
        case 'set_pin':        echo json_encode(setPin($db, $body)); break;
        default: http_response_code(400); echo json_encode(['error' => 'Unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Init & Seed ───────────────────────────────────────────────────────────────
function initDB(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE IF NOT EXISTS kids (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, emoji TEXT DEFAULT '🧒',
            color TEXT DEFAULT 'blue', balance INTEGER DEFAULT 0,
            starting_balance INTEGER DEFAULT 0, today_earned INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS tasks (
            id TEXT PRIMARY KEY, kid_id TEXT NOT NULL, category TEXT NOT NULL,
            title TEXT NOT NULL, points INTEGER NOT NULL, sort_order INTEGER DEFAULT 0,
            FOREIGN KEY (kid_id) REFERENCES kids(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS completed_today (
            kid_id TEXT NOT NULL, task_id TEXT NOT NULL,
            PRIMARY KEY (kid_id, task_id)
        );
        CREATE TABLE IF NOT EXISTS pets (
            kid_id TEXT PRIMARY KEY, species_id TEXT NOT NULL,
            mood INTEGER DEFAULT 100, growth_points INTEGER DEFAULT 0,
            last_pet INTEGER DEFAULT 0,
            hunger INTEGER DEFAULT 80, joy INTEGER DEFAULT 80,
            fatigue INTEGER DEFAULT 20, sleep_until INTEGER DEFAULT 0,
            FOREIGN KEY (kid_id) REFERENCES kids(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS shop_overrides (
            item_id TEXT PRIMARY KEY, cost INTEGER, mood_boost INTEGER
        );
        CREATE TABLE IF NOT EXISTS pet_cost_overrides (
            species_id TEXT PRIMARY KEY, cost INTEGER
        );
        CREATE TABLE IF NOT EXISTS rewards (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, emoji TEXT DEFAULT '🎁',
            cost INTEGER NOT NULL DEFAULT 50, sort_order INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS redemptions (
            id TEXT PRIMARY KEY, kid_id TEXT NOT NULL, reward_id TEXT,
            name TEXT NOT NULL, emoji TEXT DEFAULT '🎁', cost INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (kid_id) REFERENCES kids(id) ON DELETE CASCADE
        );
    ");
    // Migrate pets tables created before the petting / digital-pet features
    $mig=["last_pet INTEGER DEFAULT 0","hunger INTEGER DEFAULT 80","joy INTEGER DEFAULT 80","fatigue INTEGER DEFAULT 20","sleep_until INTEGER DEFAULT 0","hunger_low_days INTEGER DEFAULT 0","last_bath INTEGER DEFAULT 0","home_item TEXT","pet_name TEXT","pet_bg TEXT","owned_homes TEXT","owned_bgs TEXT","rest_start INTEGER DEFAULT 0"];
    foreach ($mig as $col) { try { $db->exec("ALTER TABLE pets ADD COLUMN $col"); } catch (Exception $e) {} }
    try { $db->exec("ALTER TABLE kids ADD COLUMN adoption_penalty INTEGER DEFAULT 0"); } catch (Exception $e) {}
    // Log of pet deaths so a disappearing pet is never a mystery.
    $db->exec("CREATE TABLE IF NOT EXISTS pet_deaths (id INTEGER PRIMARY KEY AUTOINCREMENT, kid_id TEXT, species_id TEXT, reason TEXT, died_at INTEGER)");
    $count = $db->query("SELECT COUNT(*) as c FROM kids")->fetch()['c'];
    if ($count == 0) seedData($db);
    $rc = $db->query("SELECT COUNT(*) as c FROM rewards")->fetch()['c'];
    if ($rc == 0) seedRewards($db);
}

function seedData(PDO $db): void {
    $db->exec("INSERT INTO kids VALUES
        ('aaron','Aaron','👦','blue',0,0,0,0),
        ('ivy','Ivy','👧','pink',0,0,0,1)
    ");
    $tasks = [
        // Aaron basic
        ['a_bed','aaron','basic','Get out of bed when alarm sounds',2,0],
        ['a_car','aaron','basic','Into car / ready to leave home on time',5,1],
        ['a_hw','aaron','basic','Finish school homework before sleep',5,2],
        ['a_cn','aaron','basic','Do Chinese School homework',5,3],
        ['a_fl1','aaron','basic','Practice flute (private lesson)',5,4],
        ['a_fl2','aaron','basic','Practice flute (band / others)',3,5],
        ['a_teeth','aaron','basic','Brush teeth twice (morning & before sleep)',2,6],
        ['a_shower','aaron','basic','Shower and change',1,7],
        ['a_cloth','aaron','basic','Put dirty clothes / socks into basket',2,8],
        ['a_pack','aaron','basic','Packed up for next day before sleep',5,9],
        ['a_bed9','aaron','basic','Go to bed before 9 pm',5,10],
        // Aaron bonus
        ['a_dish','aaron','bonus','Help with dishwasher or other housework',5,0],
        ['a_ivy','aaron','bonus',"Help with Ivy's reader or homework",5,1],
        // Aaron penalty
        ['a_p1','aaron','penalty','Dirty socks not in basket (e.g. coffee table)',-2,0],
        ['a_p2','aaron','penalty','Late for school / activity (own reason)',-5,1],
        // Ivy basic
        ['i_sleep','ivy','basic','Sleep and wake up in own room',5,0],
        ['i_car','ivy','basic','Into car / ready to leave home on time',5,1],
        ['i_hw','ivy','basic','Finish reader & school homework before sleep',5,2],
        ['i_cn','ivy','basic','Do Chinese School homework',2,3],
        ['i_cello','ivy','basic','Practice cello',5,4],
        ['i_shower','ivy','basic','Shower within 5 mins',3,5],
        ['i_cloth','ivy','basic','Put dirty clothes / socks into basket',2,6],
        ['i_clean','ivy','basic','Clean up and put things back after play',3,7],
        ['i_pack','ivy','basic','Packed up for next day before sleep',5,8],
        ['i_bed830','ivy','basic','Go to bed before 8:30 pm',5,9],
        // Ivy bonus
        ['i_house','ivy','bonus','Help with housework',5,0],
        ['i_read','ivy','bonus','Read another book before sleep',5,1],
        // Ivy penalty
        ['i_p1','ivy','penalty','Not clean up or put things back',-3,0],
        ['i_p2','ivy','penalty','Late for school / activity (own reason)',-5,1],
    ];
    $stmt = $db->prepare("INSERT INTO tasks VALUES (?,?,?,?,?,?)");
    foreach ($tasks as $t) $stmt->execute($t);
}

function seedRewards(PDO $db): void {
    $rewards = [
        ['rw_movie',   'Movie Night',          '🍿', 60, 0],
        ['rw_game',    '30 min Game Time',     '🎮', 30, 1],
        ['rw_icecream','Ice Cream Treat',       '🍦', 25, 2],
        ['rw_latebed', 'Stay Up 30 min Late',  '🌙', 40, 3],
        ['rw_dinner',  'Pick Family Dinner',   '🍕', 50, 4],
        ['rw_outing',  'Park / Outing Trip',   '🏞️', 80, 5],
    ];
    $stmt = $db->prepare("INSERT INTO rewards VALUES (?,?,?,?,?)");
    foreach ($rewards as $r) $stmt->execute($r);
}

// ── State ─────────────────────────────────────────────────────────────────────
function getState(PDO $db): array {
    // Wake any pet whose rest has finished: a full rest fully recharges energy.
    $db->prepare("UPDATE pets SET fatigue=0, joy=MIN(100,joy+5), sleep_until=0, rest_start=0 WHERE sleep_until>0 AND sleep_until<=?")->execute([time()]);
    $kids = [];
    foreach ($db->query("SELECT * FROM kids ORDER BY sort_order, name") as $kid) {
        $tasks = ['basic'=>[],'bonus'=>[],'penalty'=>[]];
        $ts = $db->prepare("SELECT * FROM tasks WHERE kid_id=? ORDER BY sort_order");
        $ts->execute([$kid['id']]);
        foreach ($ts as $t) $tasks[$t['category']][] = ['id'=>$t['id'],'title'=>$t['title'],'points'=>(int)$t['points']];
        $cs = $db->prepare("SELECT task_id FROM completed_today WHERE kid_id=?");
        $cs->execute([$kid['id']]);
        $pet = $db->prepare("SELECT * FROM pets WHERE kid_id=?");
        $pet->execute([$kid['id']]);
        $petRow = $pet->fetch();
        $lastBath = $petRow ? (int)$petRow['last_bath'] : 0;
        $daysSinceBath = ($lastBath > 0) ? (int)floor((time() - $lastBath) / 86400) : 0;
        // While resting, energy recovers proportionally to time slept so the bar
        // visibly climbs (the stored value is only committed when the pet wakes).
        $displayFatigue = $petRow ? (int)$petRow['fatigue'] : 0;
        if ($petRow) {
            $rs = (int)$petRow['rest_start']; $su = (int)$petRow['sleep_until'];
            if ($rs > 0 && $su > $rs) {
                $elapsed = max(0, min(time(), $su) - $rs);
                $recovered = (int)round(100 * $elapsed / ($su - $rs));
                $displayFatigue = max(0, (int)$petRow['fatigue'] - $recovered);
            }
        }
        $kids[] = [
            'id'=>$kid['id'], 'name'=>$kid['name'], 'emoji'=>$kid['emoji'], 'color'=>$kid['color'],
            'balance'=>(int)$kid['balance'], 'startingBalance'=>(int)$kid['starting_balance'],
            'todayEarned'=>(int)$kid['today_earned'],
            'adoptionPenalty'=>(bool)($kid['adoption_penalty']??false),
            'tasks'=>$tasks,
            'completedToday'=>array_column($cs->fetchAll(),'task_id'),
            'pet'=>$petRow ? [
                'id'=>$petRow['species_id'],
                'hunger'=>(int)$petRow['hunger'], 'joy'=>(int)$petRow['joy'], 'fatigue'=>$displayFatigue,
                'sleepUntil'=>(int)$petRow['sleep_until'],
                'sleeping'=>((int)$petRow['sleep_until'])>time(),
                // overall wellness, kept as 'mood' so existing UI bits work
                'mood'=>(int)round(((int)$petRow['hunger']+(int)$petRow['joy']+(100-$displayFatigue))/3),
                'growthPoints'=>(int)$petRow['growth_points'],
                'hungerLowDays'=>(int)$petRow['hunger_low_days'],
                'lastBath'=>$lastBath,
                'daysSinceBath'=>$daysSinceBath,
                'homeItem'=>$petRow['home_item'],
                'ownedHomes'=>json_decode($petRow['owned_homes'] ?? '[]', true) ?: [],
                'ownedBgs'=>json_decode($petRow['owned_bgs'] ?? '[]', true) ?: [],
                'petName'=>$petRow['pet_name'] ?: null,
                'petBg'=>$petRow['pet_bg'] ?: 'default',
            ] : null,
        ];
    }
    $shop = defaultShopItems();
    foreach ($db->query("SELECT * FROM shop_overrides") as $ov) {
        foreach (['food','toys','home','backgrounds'] as $cat)
            foreach ($shop[$cat] as &$i)
                if ($i['id']===$ov['item_id']) {
                    if ($ov['cost']!==null) $i['cost']=(int)$ov['cost'];
                    if ($ov['mood_boost']!==null && $cat!=='home') $i['moodBoost']=(int)$ov['mood_boost'];
                }
    }
    $costs = defaultPetCosts();
    foreach ($db->query("SELECT * FROM pet_cost_overrides") as $co) $costs[$co['species_id']]=(int)$co['cost'];
    $rewards = [];
    foreach ($db->query("SELECT * FROM rewards ORDER BY sort_order, name") as $r)
        $rewards[] = ['id'=>$r['id'],'name'=>$r['name'],'emoji'=>$r['emoji'],'cost'=>(int)$r['cost']];
    $redemptions = [];
    foreach ($db->query("SELECT * FROM redemptions ORDER BY created_at DESC LIMIT 100") as $r)
        $redemptions[] = ['id'=>$r['id'],'kidId'=>$r['kid_id'],'name'=>$r['name'],'emoji'=>$r['emoji'],'cost'=>(int)$r['cost'],'createdAt'=>(int)$r['created_at']];
    $pinRow = $db->query("SELECT value FROM settings WHERE key='admin_pin'")->fetch();
    return ['ok'=>true,'kids'=>$kids,'shopItems'=>$shop,'petCosts'=>$costs,'petConfig'=>getPetConfig($db),'rewards'=>$rewards,'redemptions'=>$redemptions,'hasPin'=>(bool)$pinRow];
}

function getPetConfig(PDO $db): array {
    $row=$db->query("SELECT value FROM settings WHERE key='pet_config'")->fetch();
    $cfg=$row ? (json_decode($row['value'],true)?:[]) : [];
    return [
        'patCost'=>(int)($cfg['patCost']??1),
        'playCost'=>(int)($cfg['playCost']??2),
        'restMinutes'=>(int)($cfg['restMinutes']??10),
        'bathCost'=>(int)($cfg['bathCost']??3),
        'sleepStart'=>isset($cfg['sleepStart'])?(int)$cfg['sleepStart']:-1,
        'sleepEnd'=>isset($cfg['sleepEnd'])?(int)$cfg['sleepEnd']:-1,
    ];
}

// ── Actions ───────────────────────────────────────────────────────────────────
function completeTask(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $taskId=$b['taskId']??''; $pts=(int)($b['points']??0);
    if (!$kidId||!$taskId) return err('Missing params');
    $chk=$db->prepare("SELECT 1 FROM completed_today WHERE kid_id=? AND task_id=?");
    $chk->execute([$kidId,$taskId]);
    if ($chk->fetch()) return err('Already completed');
    $db->beginTransaction();
    $db->prepare("INSERT INTO completed_today (kid_id,task_id) VALUES (?,?)")->execute([$kidId,$taskId]);
    $db->prepare("UPDATE kids SET balance=balance+?,today_earned=today_earned+? WHERE id=?")->execute([$pts,$pts,$kidId]);
    $db->commit();
    return getState($db);
}

function undoTask(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $taskId=$b['taskId']??''; $pts=(int)($b['points']??0);
    $db->beginTransaction();
    $db->prepare("DELETE FROM completed_today WHERE kid_id=? AND task_id=?")->execute([$kidId,$taskId]);
    $db->prepare("UPDATE kids SET balance=balance-?,today_earned=today_earned-? WHERE id=?")->execute([$pts,$pts,$kidId]);
    $db->commit();
    return getState($db);
}

function newDay(PDO $db): array {
    // The pet life-cycle (hunger decay / starvation / bath checks) must run at
    // most ONCE per calendar day. New Day is a manual button parents may press
    // more than once a day (to re-reset tasks, by mistake, etc.); without this
    // guard every extra press decayed the pets another full day, wiping out the
    // food the kids fed today and even starving pets to death. Tasks are always
    // reset (that part is idempotent); only the pet decay is gated by the date.
    $today = date('Y-m-d');
    $lastDecay = $db->query("SELECT value FROM settings WHERE key='last_pet_decay'")->fetchColumn();
    if ($lastDecay !== $today) {
        applyPetDailyDecay($db);
        $db->prepare("INSERT INTO settings(key,value) VALUES('last_pet_decay',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
           ->execute([$today]);
    }
    $db->exec("DELETE FROM completed_today; UPDATE kids SET today_earned=0;");
    return getState($db);
}

function applyPetDailyDecay(PDO $db): void {
    // Daily pet life-cycle: each species has a different hunger decay based on adoption cost
    $defaultCosts = defaultPetCosts();
    $overrides = [];
    foreach ($db->query("SELECT * FROM pet_cost_overrides") as $co) $overrides[$co['species_id']]=(int)$co['cost'];
    $pets = $db->query("SELECT kid_id, species_id, hunger, hunger_low_days, last_bath FROM pets")->fetchAll();
    $now = time();
    foreach ($pets as $pet) {
        $speciesId = $pet['species_id'];
        $cost = $overrides[$speciesId] ?? ($defaultCosts[$speciesId] ?? 20);
        // Hunger decay scales linearly with adoption cost (5 pts/day for cheapest, 40 pts/day for rarest)
        $hungerDecay = (int) max(5, min(60, round(5 + 35 * max(0, $cost - 10) / 50)));
        $newHunger = max(0, (int)$pet['hunger'] - $hungerDecay);
        $newHungerLowDays = $newHunger < 20 ? (int)$pet['hunger_low_days'] + 1 : 0;
        // Starvation: hunger below 20% for 3 consecutive days
        if ($newHungerLowDays >= 3) {
            $db->prepare("INSERT INTO pet_deaths (kid_id,species_id,reason,died_at) VALUES (?,?,'starved',?)")->execute([$pet['kid_id'],$speciesId,$now]);
            $db->prepare("DELETE FROM pets WHERE kid_id=?")->execute([$pet['kid_id']]);
            $db->prepare("UPDATE kids SET adoption_penalty=1 WHERE id=?")->execute([$pet['kid_id']]);
            continue;
        }
        // Bath: pet dies if more than 10 days pass without a bath
        $lastBath = (int)$pet['last_bath'];
        if ($lastBath > 0 && ($now - $lastBath) > 864000) {
            $db->prepare("INSERT INTO pet_deaths (kid_id,species_id,reason,died_at) VALUES (?,?,'unbathed',?)")->execute([$pet['kid_id'],$speciesId,$now]);
            $db->prepare("DELETE FROM pets WHERE kid_id=?")->execute([$pet['kid_id']]);
            $db->prepare("UPDATE kids SET adoption_penalty=1 WHERE id=?")->execute([$pet['kid_id']]);
            continue;
        }
        $db->prepare("UPDATE pets SET hunger=?, joy=MAX(0,joy-12), fatigue=MAX(0,fatigue-50), sleep_until=0, rest_start=0, hunger_low_days=? WHERE kid_id=?")
           ->execute([$newHunger, $newHungerLowDays, $pet['kid_id']]);
    }
}

// Full restart for going live: wipes all test activity to a clean slate —
// removes adopted pets, clears today's completed tasks and the entire
// redemption history, and zeroes every kid's balances. The kids themselves,
// their tasks, the rewards catalogue and all settings (PIN, pet config, shop &
// pet-cost overrides) are kept so the system is ready to use immediately.
function restartSystem(PDO $db): array {
    $db->beginTransaction();
    $db->exec("DELETE FROM pets;");
    $db->exec("DELETE FROM completed_today;");
    $db->exec("DELETE FROM redemptions;");
    $db->exec("UPDATE kids SET balance=0, starting_balance=0, today_earned=0, adoption_penalty=0;");
    $db->commit();
    return getState($db);
}

function petAction(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $type=$b['type']??'';
    $st=$db->prepare("SELECT p.*, k.balance FROM pets p JOIN kids k ON k.id=p.kid_id WHERE p.kid_id=?");
    $st->execute([$kidId]);
    $pet=$st->fetch();
    if (!$pet) return err('No pet');
    $cfg=getPetConfig($db); $now=time();
    $sleeping=((int)$pet['sleep_until'])>$now;
    // Active bed determines rest speed (better bed = faster recharge).
    $speed=1.0; $homeItem=$pet['home_item']??null;
    if($homeItem){ foreach(defaultShopItems()['home'] as $hi){ if($hi['id']===$homeItem){ $speed=(float)$hi['speedBonus']; break; } } }

    // Waking is always allowed (even during the night window): bank the energy
    // recovered so far, proportional to how long the pet has rested.
    if ($type==='wake') {
        $rs=(int)$pet['rest_start']; $su=(int)$pet['sleep_until'];
        if ($su<=$now || $rs<=0) return getState($db);
        $elapsed=max(0,min($now,$su)-$rs);
        $recovered=(int)round(100*$elapsed/max(1,($su-$rs)));
        $newFat=max(0,(int)$pet['fatigue']-$recovered);
        $db->prepare("UPDATE pets SET fatigue=?, joy=MIN(100,joy+5), rest_start=0, sleep_until=0 WHERE kid_id=?")->execute([$newFat,$kidId]);
        return getState($db);
    }

    $sleepStart=(int)($cfg['sleepStart']??-1); $sleepEnd=(int)($cfg['sleepEnd']??-1);
    if($sleepStart>=0&&$sleepEnd>=0&&$sleepStart!==$sleepEnd){
        $h=(int)date('G');
        $inWin=$sleepStart<=$sleepEnd?($h>=$sleepStart&&$h<$sleepEnd):($h>=$sleepStart||$h<$sleepEnd);
        if($inWin) return err('Pet is resting for the night 🌙 ('.$sleepStart.':00–'.$sleepEnd.':00)');
    }

    if ($type==='rest') {
        if ($sleeping) return err('Already resting! 💤');
        // A full rest takes a whole day and fully recharges energy; a faster bed
        // shortens that (so it recharges quicker). Wake any time to bank partial
        // recovery.
        $fullDur = (int) round(86400 / max(0.1,$speed));
        $db->prepare("UPDATE pets SET rest_start=?, sleep_until=? WHERE kid_id=?")->execute([$now, $now+$fullDur, $kidId]);
        return getState($db);
    }
    if ($sleeping) return err('Shh… your pet is sleeping! 💤');

    if ($type==='pat') {
        $cost=$cfg['patCost'];
        if ((int)$pet['balance']<$cost) return err('Not enough points to pat');
        // free pats are rate-limited so they can't be spammed
        if ($cost===0 && $now-(int)$pet['last_pet']<15) return getState($db);
        $db->beginTransaction();
        if ($cost>0) $db->prepare("UPDATE kids SET balance=balance-? WHERE id=?")->execute([$cost,$kidId]);
        $db->prepare("UPDATE pets SET joy=MIN(100,joy+8), growth_points=growth_points+1, last_pet=? WHERE kid_id=?")->execute([$now,$kidId]);
        $db->commit();
        return getState($db);
    }
    if ($type==='play') {
        $cost=$cfg['playCost'];
        if ((int)$pet['balance']<$cost) return err('Not enough points to play');
        if ((int)$pet['fatigue']>=80) return err('Too tired to play — needs a rest! 💤');
        if ((int)$pet['hunger']<=10) return err('Too hungry to play — feed me first! 🍖');
        $db->beginTransaction();
        if ($cost>0) $db->prepare("UPDATE kids SET balance=balance-? WHERE id=?")->execute([$cost,$kidId]);
        $db->prepare("UPDATE pets SET joy=MIN(100,joy+15), fatigue=MIN(100,fatigue+20), hunger=MAX(0,hunger-10), growth_points=growth_points+2 WHERE kid_id=?")->execute([$kidId]);
        $db->commit();
        return getState($db);
    }
    if ($type==='bath') {
        $cost=$cfg['bathCost'];
        if ((int)$pet['balance']<$cost) return err('Not enough points to give a bath');
        $db->beginTransaction();
        if ($cost>0) $db->prepare("UPDATE kids SET balance=balance-? WHERE id=?")->execute([$cost,$kidId]);
        $db->prepare("UPDATE pets SET last_bath=?, joy=MIN(100,joy+10), growth_points=growth_points+1 WHERE kid_id=?")->execute([$now,$kidId]);
        $db->commit();
        return getState($db);
    }
    return err('Unknown pet action');
}

function updatePetConfig(PDO $db, array $b): array {
    $cfg=getPetConfig($db);
    foreach (['patCost','playCost','restMinutes','bathCost'] as $f)
        if (isset($b[$f])) $cfg[$f]=max(0,(int)$b[$f]);
    if ($cfg['restMinutes']<1) $cfg['restMinutes']=1;
    foreach (['sleepStart','sleepEnd'] as $f)
        if (array_key_exists($f,$b)) $cfg[$f]=(int)$b[$f];
    $db->prepare("INSERT INTO settings(key,value) VALUES('pet_config',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
       ->execute([json_encode($cfg)]);
    return getState($db);
}

function adoptPet(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $speciesId=$b['speciesId']??''; $cost=(int)($b['cost']??0);
    $petName=trim($b['petName']??''); if(!$petName) $petName=null;
    $kid=$db->prepare("SELECT * FROM kids WHERE id=?"); $kid->execute([$kidId]);
    $kd=$kid->fetch();
    if (!$kd) return err('Kid not found');
    // Apply 1.5× death penalty if a previous pet died
    $penalty=(int)($kd['adoption_penalty']??0);
    $finalCost=$penalty?(int)ceil($cost*1.5):$cost;
    if ($kd['balance']<$finalCost) return err('Insufficient balance — you need '.$finalCost.' pts'.($penalty?' (1.5× death penalty)':''));
    $db->beginTransaction();
    $db->prepare("UPDATE kids SET balance=balance-?, adoption_penalty=0 WHERE id=?")->execute([$finalCost,$kidId]);
    // last_bath starts as adoption time so the first bath is due 10 days after adoption
    $db->prepare("INSERT OR REPLACE INTO pets (kid_id,species_id,mood,growth_points,last_bath,hunger_low_days,home_item,pet_name) VALUES (?,?,100,0,?,0,NULL,?)")->execute([$kidId,$speciesId,time(),$petName]);
    $db->commit();
    return getState($db);
}

function shopCategoryOf(string $id): ?string {
    foreach (defaultShopItems() as $cat=>$items)
        foreach ($items as $i) if ($i['id']===$id) return $cat;
    return null;
}

function buyItem(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $itemId=$b['itemId']??''; $cost=(int)($b['cost']??0);
    $moodBoost=(int)($b['moodBoost']??0); $growthGain=(int)($b['growthGain']??0);
    $hp=$db->prepare("SELECT * FROM pets WHERE kid_id=?"); $hp->execute([$kidId]);
    $pet=$hp->fetch();
    if (!$pet) return err('No pet');
    $kd=$db->prepare("SELECT balance FROM kids WHERE id=?"); $kd->execute([$kidId]);
    $kid=$kd->fetch();
    $cat=shopCategoryOf($itemId)??'food';
    if ($itemId==='default') $cat='backgrounds'; // the free starter scene

    // Beds & backgrounds are owned permanently: pay once, then switch between
    // owned ones for free (the 'default' scene is always owned).
    if ($cat==='home' || $cat==='backgrounds') {
        $ownCol = $cat==='home' ? 'owned_homes' : 'owned_bgs';
        $activeCol = $cat==='home' ? 'home_item' : 'pet_bg';
        $owned = json_decode($pet[$ownCol] ?? '[]', true) ?: [];
        $alreadyOwned = ($itemId==='default') || in_array($itemId, $owned, true);
        $effCost = $alreadyOwned ? 0 : $cost;
        if ((int)$kid['balance'] < $effCost) return err('Insufficient balance');
        $db->beginTransaction();
        if ($effCost > 0) $db->prepare("UPDATE kids SET balance=balance-? WHERE id=?")->execute([$effCost,$kidId]);
        if (!$alreadyOwned) { $owned[]=$itemId; $db->prepare("UPDATE pets SET $ownCol=? WHERE kid_id=?")->execute([json_encode($owned),$kidId]); }
        $db->prepare("UPDATE pets SET $activeCol=? WHERE kid_id=?")->execute([$itemId,$kidId]);
        $db->commit();
        return getState($db);
    }

    // Consumables (food / toys): charged every time, and the pet must be awake.
    if ((int)$kid['balance'] < $cost) return err('Insufficient balance');
    if ((int)$pet['sleep_until']>time()) return err('Shh… your pet is sleeping! 💤');
    $db->beginTransaction();
    $db->prepare("UPDATE kids SET balance=balance-? WHERE id=?")->execute([$cost,$kidId]);
    if ($cat==='food') {
        // Feeding tops up hunger, but any amount fed *past* full (100) is
        // overfeeding: those wasted points instead reduce joy and growth
        // (progress toward evolving) by the same number of points, so stuffing
        // an already-full pet is counter-productive.
        $curHunger=(int)$pet['hunger'];
        $overflow=max(0,$curHunger+$moodBoost-100);
        $newHunger=min(100,$curHunger+$moodBoost);
        $db->prepare("UPDATE pets SET hunger=?, joy=MAX(0,MIN(100,joy+?)), growth_points=MAX(0,growth_points+?) WHERE kid_id=?")
           ->execute([$newHunger, 5-$overflow, $growthGain-$overflow, $kidId]);
    }
    elseif ($cat==='toys')
        $db->prepare("UPDATE pets SET joy=MIN(100,joy+?), fatigue=MIN(100,fatigue+15), hunger=MAX(0,hunger-5), growth_points=growth_points+? WHERE kid_id=?")->execute([$moodBoost,$growthGain,$kidId]);
    else
        $db->prepare("UPDATE pets SET joy=MIN(100,joy+?), growth_points=growth_points+? WHERE kid_id=?")->execute([$moodBoost,$growthGain,$kidId]);
    $db->commit();
    return getState($db);
}

function updateKid(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $u=$b['updates']??[];
    $sets=[]; $params=[];
    if (isset($u['name']))           { $sets[]='name=?';            $params[]=$u['name']; }
    if (isset($u['emoji']))          { $sets[]='emoji=?';           $params[]=$u['emoji']; }
    if (isset($u['color']))          { $sets[]='color=?';           $params[]=$u['color']; }
    if (isset($u['balance']))        { $sets[]='balance=?';         $params[]=(int)$u['balance']; }
    if (isset($u['startingBalance'])){ $sets[]='starting_balance=?';$params[]=(int)$u['startingBalance']; }
    if (empty($sets)) return getState($db);
    $params[]=$kidId;
    $db->prepare("UPDATE kids SET ".implode(',',$sets)." WHERE id=?")->execute($params);
    return getState($db);
}

function addKid(PDO $db, array $b): array {
    $id='kid_'.uniqid();
    $ord=$db->query("SELECT COALESCE(MAX(sort_order)+1,0) as n FROM kids")->fetch()['n'];
    $db->prepare("INSERT INTO kids (id,name,emoji,color,sort_order) VALUES (?,?,?,?,?)")
       ->execute([$id,$b['name']??'Kid',$b['emoji']??'🧒',$b['color']??'green',$ord]);
    return getState($db);
}

function deleteKid(PDO $db, array $b): array {
    $id=$b['kidId']??'';
    foreach(['tasks','completed_today','pets'] as $t)
        $db->prepare("DELETE FROM $t WHERE kid_id=?")->execute([$id]);
    $db->prepare("DELETE FROM kids WHERE id=?")->execute([$id]);
    return getState($db);
}

function saveTask(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $taskId=$b['taskId']??'';
    $cat=$b['category']??'basic'; $title=$b['title']??'Task'; $pts=(int)($b['points']??5);
    if (!$kidId) return err('Missing kidId');
    if (!$taskId||($b['isNew']??false)) {
        $taskId=$kidId.'_'.$cat.'_'.uniqid();
        $ord=$db->prepare("SELECT COALESCE(MAX(sort_order)+1,0) as n FROM tasks WHERE kid_id=? AND category=?");
        $ord->execute([$kidId,$cat]);
        $db->prepare("INSERT INTO tasks (id,kid_id,category,title,points,sort_order) VALUES (?,?,?,?,?,?)")
           ->execute([$taskId,$kidId,$cat,$title,$pts,$ord->fetch()['n']]);
    } else {
        $db->prepare("UPDATE tasks SET title=?,points=? WHERE id=? AND kid_id=?")->execute([$title,$pts,$taskId,$kidId]);
    }
    return getState($db);
}

function deleteTask(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $taskId=$b['taskId']??'';
    $db->prepare("DELETE FROM tasks WHERE id=? AND kid_id=?")->execute([$taskId,$kidId]);
    $db->prepare("DELETE FROM completed_today WHERE kid_id=? AND task_id=?")->execute([$kidId,$taskId]);
    return getState($db);
}

function updateShop(PDO $db, array $b): array {
    $id=$b['itemId']??''; $field=$b['field']??''; $val=(int)($b['value']??0);
    if ($field==='cost')
        $db->prepare("INSERT INTO shop_overrides(item_id,cost,mood_boost) VALUES(?,?,NULL) ON CONFLICT(item_id) DO UPDATE SET cost=excluded.cost")->execute([$id,$val]);
    elseif ($field==='moodBoost')
        $db->prepare("INSERT INTO shop_overrides(item_id,cost,mood_boost) VALUES(?,NULL,?) ON CONFLICT(item_id) DO UPDATE SET mood_boost=excluded.mood_boost")->execute([$id,$val]);
    return getState($db);
}

function updatePetCost(PDO $db, array $b): array {
    $db->prepare("INSERT INTO pet_cost_overrides(species_id,cost) VALUES(?,?) ON CONFLICT(species_id) DO UPDATE SET cost=excluded.cost")
       ->execute([$b['speciesId']??'',(int)($b['cost']??0)]);
    return getState($db);
}

// ── Rewards & redemptions ───────────────────────────────────────────────────────
function redeemReward(PDO $db, array $b): array {
    $kidId=$b['kidId']??''; $rewardId=$b['rewardId']??'';
    if (!$kidId||!$rewardId) return err('Missing params');
    $r=$db->prepare("SELECT * FROM rewards WHERE id=?"); $r->execute([$rewardId]);
    $reward=$r->fetch();
    if (!$reward) return err('Reward not found');
    $k=$db->prepare("SELECT balance FROM kids WHERE id=?"); $k->execute([$kidId]);
    $kid=$k->fetch();
    if (!$kid) return err('Kid not found');
    if ((int)$kid['balance']<(int)$reward['cost']) return err('Not enough points to redeem that yet');
    $db->beginTransaction();
    $db->prepare("UPDATE kids SET balance=balance-? WHERE id=?")->execute([(int)$reward['cost'],$kidId]);
    $db->prepare("INSERT INTO redemptions (id,kid_id,reward_id,name,emoji,cost,created_at) VALUES (?,?,?,?,?,?,?)")
       ->execute(['rdm_'.uniqid(),$kidId,$rewardId,$reward['name'],$reward['emoji'],(int)$reward['cost'],time()]);
    $db->commit();
    return getState($db);
}

function addReward(PDO $db, array $b): array {
    $id='rw_'.uniqid();
    $ord=$db->query("SELECT COALESCE(MAX(sort_order)+1,0) as n FROM rewards")->fetch()['n'];
    $db->prepare("INSERT INTO rewards (id,name,emoji,cost,sort_order) VALUES (?,?,?,?,?)")
       ->execute([$id,$b['name']??'Reward',$b['emoji']??'🎁',max(1,(int)($b['cost']??50)),$ord]);
    return getState($db);
}

function updateReward(PDO $db, array $b): array {
    $id=$b['rewardId']??''; if (!$id) return err('Missing rewardId');
    $sets=[]; $params=[];
    if (isset($b['name']))  { $sets[]='name=?';  $params[]=$b['name']; }
    if (isset($b['emoji'])) { $sets[]='emoji=?'; $params[]=$b['emoji']; }
    if (isset($b['cost']))  { $sets[]='cost=?';  $params[]=max(1,(int)$b['cost']); }
    if (empty($sets)) return getState($db);
    $params[]=$id;
    $db->prepare("UPDATE rewards SET ".implode(',',$sets)." WHERE id=?")->execute($params);
    return getState($db);
}

function deleteReward(PDO $db, array $b): array {
    $db->prepare("DELETE FROM rewards WHERE id=?")->execute([$b['rewardId']??'']);
    return getState($db);
}

function deleteRedemption(PDO $db, array $b): array {
    $db->prepare("DELETE FROM redemptions WHERE id=?")->execute([$b['redemptionId']??'']);
    return getState($db);
}

function verifyPin(PDO $db, array $b): array {
    $row=$db->query("SELECT value FROM settings WHERE key='admin_pin'")->fetch();
    if (!$row) return ['ok'=>true,'verified'=>true]; // no PIN set = open
    return password_verify($b['pin']??'',$row['value'])
        ? ['ok'=>true,'verified'=>true]
        : ['ok'=>false,'verified'=>false,'error'=>'Wrong PIN'];
}

function setPin(PDO $db, array $b): array {
    $pin=$b['pin']??'';
    if (strlen($pin)<4) return err('PIN must be at least 4 digits');
    $hash=password_hash($pin,PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO settings(key,value) VALUES('admin_pin',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$hash]);
    return ['ok'=>true];
}

function requireAdmin(PDO $db, array $b): void {
    $row=$db->query("SELECT value FROM settings WHERE key='admin_pin'")->fetch();
    if (!$row) return; // no PIN set
    if (!password_verify($b['pin']??'',$row['value'])) {
        http_response_code(403);
        echo json_encode(['error'=>'Admin PIN required','authFail'=>true]);
        exit;
    }
}

function err(string $msg): array { return ['ok'=>false,'error'=>$msg]; }

// ── Static data ───────────────────────────────────────────────────────────────
function defaultShopItems(): array {
    return [
        'food'=>[
            ['id'=>'snack','name'=>'Yummy Snack','emoji'=>'🍎','cost'=>3,'moodBoost'=>10],
            ['id'=>'meal', 'name'=>'Fancy Meal', 'emoji'=>'🍖','cost'=>6,'moodBoost'=>20],
            ['id'=>'treat','name'=>'Sweet Treat','emoji'=>'🍩','cost'=>5,'moodBoost'=>15],
        ],
        'toys'=>[
            ['id'=>'ball',  'name'=>'Bouncy Ball','emoji'=>'⚽','cost'=>5,'moodBoost'=>15],
            ['id'=>'rope',  'name'=>'Tug Rope',   'emoji'=>'🪢','cost'=>4,'moodBoost'=>12],
            ['id'=>'puzzle','name'=>'Puzzle Toy', 'emoji'=>'🧩','cost'=>8,'moodBoost'=>20],
        ],
        'accessories'=>[],
        'home'=>[
            ['id'=>'box',    'name'=>'Cardboard Box', 'emoji'=>'📦','cost'=>5, 'moodBoost'=>0,'speedBonus'=>1.0],
            ['id'=>'pet_bed','name'=>'Cosy Pet Bed',  'emoji'=>'🛏️','cost'=>20,'moodBoost'=>0,'speedBonus'=>1.5],
            ['id'=>'luxury', 'name'=>'Luxury Bed',    'emoji'=>'🌟','cost'=>40,'moodBoost'=>0,'speedBonus'=>2.5],
            ['id'=>'palace', 'name'=>'Royal Palace',  'emoji'=>'🏰','cost'=>80,'moodBoost'=>0,'speedBonus'=>4.0],
        ],
        'backgrounds'=>[
            ['id'=>'backyard','name'=>'Backyard',        'emoji'=>'🏡','cost'=>10,'moodBoost'=>0],
            ['id'=>'forest',  'name'=>'Enchanted Forest','emoji'=>'🌲','cost'=>20,'moodBoost'=>0],
            ['id'=>'fantasy', 'name'=>'Fantasy Castle',  'emoji'=>'🏰','cost'=>25,'moodBoost'=>0],
            ['id'=>'beach',   'name'=>'Sunny Beach',     'emoji'=>'🏖️','cost'=>30,'moodBoost'=>0],
        ],
    ];
}

function defaultPetCosts(): array {
    return ['dragon'=>60,'ghost'=>45,'kitten'=>20,'doggy'=>20,'fish'=>10,'turtle'=>15,'bunny'=>20,'hamster'=>15,'parrot'=>25];
}
