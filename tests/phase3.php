<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use ParkNGo\Services\{Auth, Database, Environment};

if (Environment::get('APP_ENV', 'local') !== 'local' || !str_ends_with(Environment::get('DB_DATABASE'), '_test')) { fwrite(STDERR, "Use a fresh isolated *_test database.\n"); exit(1); }
$base = rtrim(Environment::get('TEST_BASE_URL', 'http://127.0.0.1:8088'), '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Loopback HTTP tests only.');
$count = 0;
function assertPhase3(bool $condition, string $label): void { global $count; if (!$condition) throw new RuntimeException('FAIL ' . $label); ++$count; echo "PASS $label\n"; }
final class InventoryBrowser
{
    private CurlHandle $curl;
    public string $body = ''; public int $status = 0; public array $headers = [];
    public function __construct(private string $base) { $this->curl = curl_init(); curl_setopt_array($this->curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]); }
    public function get(string $path, ?array $data = null): self {
        $this->headers = []; curl_setopt($this->curl, CURLOPT_URL, $this->base . '/' . $path);
        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, function ($curl, string $line): int { if (str_contains($line, ':')) { [$key,$value] = explode(':', $line, 2); $this->headers[strtolower($key)] = trim($value); } return strlen($line); });
        if ($data === null) curl_setopt($this->curl, CURLOPT_HTTPGET, true); else curl_setopt_array($this->curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
        $body = curl_exec($this->curl); if ($body === false) throw new RuntimeException(curl_error($this->curl));
        $this->body = $body; $this->status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE); return $this;
    }
    public function token(string $page): string { $this->get('index.php?page=' . $page); if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $this->body, $m)) throw new RuntimeException('CSRF missing'); return $m[1]; }
    public function login(string $email, string $password): void { $csrf = $this->token('login'); $this->get('index.php?page=login', compact('email','password','csrf')); }
    public function save(string $page, array $data): self { $csrf = $this->token($page); return $this->get('index.php?page=' . $page, $data + ['csrf' => $csrf]); }
}
try {
    $guest = new InventoryBrowser($base); $guest->get('index.php?page=home');
    $fingerprint = hash('sha256', Environment::get('DB_HOST', '127.0.0.1') . ':' . Environment::get('DB_PORT', '3306') . ':' . Environment::get('DB_DATABASE'));
    assertPhase3(hash_equals($fingerprint, $guest->headers['x-parkngo-test-database'] ?? ''), 'test server matches isolated database');
    $pdo = Database::connection(); $run = bin2hex(random_bytes(5)); $password = 'Fixture-' . bin2hex(random_bytes(10)); $users = [];
    foreach (['admin','driver','attendant'] as $role) {
        $email = $role . '-inventory-' . $run . '@parkngo.test';
        $pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)')->execute(['Inventory ' . $role, $email, Auth::hashPassword($password), $role]);
        $users[$role] = ['id' => (int) $pdo->lastInsertId(), 'email' => $email];
    }
    $guest->get('index.php?page=admin-facilities'); assertPhase3($guest->status === 303, 'guest cannot manage inventory');
    foreach (['driver','attendant'] as $role) { $browser = new InventoryBrowser($base); $browser->login($users[$role]['email'], $password); foreach (['admin-facilities','admin-floors','admin-slots'] as $route) { $browser->get('index.php?page=' . $route); assertPhase3($browser->status === 403, "$role denied $route"); } $csrf=$browser->token('profile');$browser->get('index.php?page=admin-facilities',['csrf'=>$csrf,'id'=>'0','name'=>'Unauthorized']);assertPhase3($browser->status===403,"$role cannot POST inventory changes"); }
    $admin = new InventoryBrowser($base); $admin->login($users['admin']['email'], $password); assertPhase3($admin->status === 303, 'admin authenticates');
    $admin->get('index.php?page=admin-facilities', ['name'=>'No CSRF']); assertPhase3($admin->status === 419, 'inventory POST requires CSRF');
    $name = 'Inventory ' . $run;
    $facilityForm = ['id'=>'0','name'=>$name,'address'=>'Fixture address','city'=>'Test City','description'=>'<script>fixture</script>','is_active'=>'1'];
    $admin->save('admin-facilities', $facilityForm); assertPhase3($admin->status === 303, 'create facility through HTTP');
    $find = $pdo->prepare('SELECT id FROM parking_facilities WHERE name = ?'); $find->execute([$name]); $facilityId = (int) $find->fetchColumn();
    $guest->get('index.php?page=facilities&q=' . urlencode($name)); assertPhase3($guest->status === 200 && str_contains($guest->body, $name) && !str_contains($guest->body, 'Central Square Parking'), 'facility search reads MySQL');
    $guest->get('index.php?page=facility&facility_id=' . $facilityId); assertPhase3(str_contains($guest->body, 'No active floors yet') && str_contains($guest->body, '&lt;script&gt;fixture&lt;/script&gt;'), 'empty facility and stored escaping');
    $floorForm = ['id'=>'0','facility_id'=>(string)$facilityId,'label'=>'Test floor','sort_order'=>'0','is_active'=>'1'];
    $admin->save('admin-floors&facility_id=' . $facilityId, $floorForm); assertPhase3($admin->status === 303, 'create floor through HTTP');
    $find = $pdo->prepare('SELECT id FROM parking_floors WHERE facility_id = ?'); $find->execute([$facilityId]); $floorId = (int) $find->fetchColumn();
    $admin->save('admin-floors&facility_id=' . $facilityId, $floorForm); assertPhase3($admin->status === 422 && str_contains($admin->body, 'already exists'), 'duplicate floor label rejected');
    $slotRoute = 'admin-slots&floor_id=' . $floorId; $slotIds = [];
    for ($i=0;$i<10;$i++) {
        $form = ['id'=>'0','floor_id'=>(string)$floorId,'label'=>'T-' . $i,'status'=>$i===1?'maintenance':($i===2?'disabled':'available'),'position_x'=>(string)($i%6),'position_y'=>(string)intdiv($i,6)];
        $admin->save($slotRoute, $form); if ($admin->status !== 303) throw new RuntimeException('Slot creation failed: ' . $admin->status);
        $find = $pdo->prepare('SELECT id FROM parking_slots WHERE floor_id = ? AND label = ?'); $find->execute([$floorId, 'T-' . $i]); $slotIds[] = (int) $find->fetchColumn();
    }
    assertPhase3(count(array_filter($slotIds)) === 10, 'create unique slots and positions through HTTP');
    $slotForm = ['id'=>'0','floor_id'=>(string)$floorId,'label'=>'Extra','status'=>'available','position_x'=>'0','position_y'=>'0'];
    $admin->save($slotRoute, $slotForm); assertPhase3($admin->status === 422 && str_contains($admin->body, 'already contains'), 'duplicate map coordinates rejected');
    $slotForm['position_x']='12'; $admin->save($slotRoute, $slotForm); assertPhase3($admin->status === 422, 'out-of-range coordinates rejected');
    $slotForm['position_x']='5';$slotForm['position_y']='9';$slotForm['label']='T-0'; $admin->save($slotRoute, $slotForm); assertPhase3($admin->status === 422, 'duplicate slot label rejected');
    $slotForm['label']='Invalid status';$slotForm['status']='occupied'; $admin->save($slotRoute, $slotForm); assertPhase3($admin->status === 422, 'admin cannot forge derived occupancy status');
    $pdo->prepare('INSERT INTO parking_rates (facility_id, initial_centavos, succeeding_hour_centavos, effective_from) VALUES (?, 7500, 2500, UTC_TIMESTAMP())')->execute([$facilityId]); $rateId=(int)$pdo->lastInsertId();
    $arrival = new DateTimeImmutable('tomorrow 12:00', new DateTimeZone('Asia/Manila')); $start=$arrival->setTimezone(new DateTimeZone('UTC')); $end=$start->modify('+2 hours');
    $reservation = function (int $slot, string $status, DateTimeImmutable $from, DateTimeImmutable $to) use ($pdo,$run,$users,$rateId): int {
        $pdo->prepare('INSERT INTO reservations (reference,user_id,slot_id,rate_id,starts_at,ends_at,status,estimated_centavos,rate_snapshot) VALUES (?,?,?,?,?,?,?,7500,?)')->execute(['P3-'.$run.'-'.$slot,$users['driver']['id'],$slot,$rateId,$from->format('Y-m-d H:i:s'),$to->format('Y-m-d H:i:s'),$status,'{}']); return (int)$pdo->lastInsertId();
    };
    $reservation($slotIds[3],'confirmed',$start,$end);
    $reservation($slotIds[7],'confirmed',$end,$end->modify('+1 hour'));
    $reservation($slotIds[8],'cancelled',$start,$end);
    $activeReservation=$reservation($slotIds[6],'confirmed',$start->modify('-2 days'),$start->modify('-1 day'));
    $pdo->prepare("INSERT INTO parking_sessions (reservation_id,entered_by,entry_at,status) VALUES (?,?,UTC_TIMESTAMP(),'active')")->execute([$activeReservation,$users['attendant']['id']]);
    foreach ([4=>[1800,null],5=>[-1800,null],9=>[1800,'2026-01-01 00:00:00']] as $index=>[$seconds,$released]) {
        $pdo->prepare('INSERT INTO reservation_holds (user_id,slot_id,token_hash,starts_at,ends_at,expires_at,released_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),?)')->execute([$users['driver']['id'],$slotIds[$index],hash('sha256',$run.$index),$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$seconds,$released]);
    }
    $query = http_build_query(['resource'=>'availability','facility_id'=>$facilityId,'floor_id'=>$floorId,'date'=>$arrival->format('Y-m-d'),'time'=>'12:00','hours'=>'2']);
    $guest->get('api.php?' . $query); $payload=json_decode($guest->body,true,64,JSON_THROW_ON_ERROR); $data=$payload['data'];
    assertPhase3($guest->status===200 && str_contains($guest->headers['content-type']??'','application/json'), 'availability returns actual JSON');
    $statuses=array_column($data['slots'],'status','id');
    foreach ([0=>'available',1=>'maintenance',2=>'disabled',3=>'reserved',4=>'held',5=>'available',6=>'occupied',7=>'available',8=>'available',9=>'available'] as $index=>$expected) assertPhase3($statuses[$slotIds[$index]]===$expected, "slot $index resolves $expected");
    assertPhase3($data['counts']['available']===5 && array_sum($data['counts'])===10,'availability counts match slots');
    assertPhase3($data['rate']['initial_centavos']===7500 && $data['rate']['initial_minutes']===60,'integer centavo rate returned');
    assertPhase3($data['window']['starts_at']===$start->format(DATE_ATOM),'Manila arrival converted to UTC');
    assertPhase3(!str_contains($guest->body,$users['driver']['email']) && !str_contains($guest->body,'token_hash') && !$data['selection_creates_hold'],'public JSON exposes no private booking data and creates no hold');
    $guest->get('api.php?' . $query . '&hours=0'); assertPhase3($guest->status===422,'invalid duration rejected');
    $guest->get('api.php?' . $query . '&date=2026-02-30'); assertPhase3($guest->status===422,'impossible date rejected');
    $guest->get('api.php?' . $query . '&date[]=x'); assertPhase3($guest->status===422,'array-valued arrival rejected');
    $guest->get('api.php?resource=availability&facility_id='.$facilityId.'&floor_id=1'); assertPhase3($guest->status===404,'cross-facility floor rejected');
    $guest->get('api.php?' . $query, []); assertPhase3($guest->status===405,'availability endpoint rejects POST');
    $guest->get('api.php?resource=paymongo-webhook'); assertPhase3($guest->status===404,'local inventory API does not pretend to be payment integration');
    $facilityUrl='index.php?page=facility&'.str_replace('resource=availability&','',$query);
    $guest->get($facilityUrl.'&slot_id='.$slotIds[0]); assertPhase3($guest->status===200&&str_contains($guest->body,'id="selected-slot">T-0'),'server-rendered selection works without JavaScript');
    $guest->get($facilityUrl.'&slot_id='.$slotIds[3]); assertPhase3($guest->status===409&&str_contains($guest->body,'no longer available'),'server rejects unavailable selected slot');
    $admin->save($slotRoute,['id'=>(string)$slotIds[3],'floor_id'=>(string)$floorId,'label'=>'Changed','status'=>'disabled','position_x'=>'3','position_y'=>'0']); assertPhase3($admin->status===422&&str_contains($admin->body,'confirmed reservation'),'committed slot cannot be edited or disabled');
    $floorForm['id']=(string)$floorId;unset($floorForm['is_active']);$admin->save('admin-floors&facility_id='.$facilityId,$floorForm);assertPhase3($admin->status===422,'floor with commitments cannot be disabled');
    $facilityForm['id']=(string)$facilityId;unset($facilityForm['is_active']);$admin->save('admin-facilities',$facilityForm);assertPhase3($admin->status===422,'facility with commitments cannot be disabled');
    $admin->save($slotRoute,['id'=>(string)$slotIds[0],'floor_id'=>(string)$floorId,'label'=>'T-0','status'=>'disabled','position_x'=>'0','position_y'=>'0']);assertPhase3($admin->status===303,'uncommitted slot can be disabled');
    $guest->get('api.php?'.$query);$data=json_decode($guest->body,true,64,JSON_THROW_ON_ERROR)['data'];assertPhase3($data['counts']['disabled']===2&&count($data['slots'])===10,'slot disable preserves data and changes availability');
    $audit=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_id = ? AND subject_type IN ('facility','floor','slot')");$audit->execute([$users['admin']['id']]);assertPhase3((int)$audit->fetchColumn()>=13,'inventory changes audited with correct entity types');
    $safeForm=['id'=>'0','name'=>'Empty '.$run,'address'=>'No commitments','city'=>'Test City','description'=>'','is_active'=>'1'];
    $admin->save('admin-facilities',$safeForm);$find=$pdo->prepare('SELECT id FROM parking_facilities WHERE name=?');$find->execute([$safeForm['name']]);$emptyId=(int)$find->fetchColumn();
    $safeForm['id']=(string)$emptyId;$safeForm['name']='Renamed '.$run;$admin->save('admin-facilities',$safeForm);assertPhase3($admin->status===303,'edit existing facility');
    unset($safeForm['is_active']);$admin->save('admin-facilities',$safeForm);assertPhase3($admin->status===303,'disable uncommitted facility');
    $guest->get('index.php?page=facility&facility_id='.$emptyId);assertPhase3($guest->status===404,'disabled facility is not publicly accessible');
    $safeForm['is_active']='1';$admin->save('admin-facilities',$safeForm);$guest->get('index.php?page=facility&facility_id='.$emptyId);assertPhase3($guest->status===200,'facility can be re-enabled without deletion');
    $safeFloor=['id'=>'0','facility_id'=>(string)$emptyId,'label'=>'Empty floor','sort_order'=>'2','is_active'=>'1'];$admin->save('admin-floors&facility_id='.$emptyId,$safeFloor);
    $find=$pdo->prepare('SELECT id FROM parking_floors WHERE facility_id=?');$find->execute([$emptyId]);$emptyFloor=(int)$find->fetchColumn();
    $guest->get('api.php?resource=availability&facility_id='.$emptyId.'&floor_id='.$emptyFloor);$emptyData=json_decode($guest->body,true,64,JSON_THROW_ON_ERROR)['data'];assertPhase3($guest->status===200&&$emptyData['slots']===[]&&$emptyData['rate']===null,'empty floor returns no invented inventory or price');
    $safeFloor['id']=(string)$emptyFloor;unset($safeFloor['is_active']);$admin->save('admin-floors&facility_id='.$emptyId,$safeFloor);assertPhase3($admin->status===303,'disable uncommitted floor');
    $guest->get('api.php?resource=availability&facility_id='.$emptyId.'&floor_id='.$emptyFloor);assertPhase3($guest->status===404,'disabled floor unavailable through public API');
    $pdo->prepare('UPDATE parking_slots SET position_x=0,position_y=0 WHERE id=?')->execute([$slotIds[9]]);
    $guest->get('api.php?'.$query);$fallback=json_decode($guest->body,true,64,JSON_THROW_ON_ERROR)['data'];assertPhase3($fallback['layout_note']!==null&&count($fallback['slots'])===10,'legacy overlapping coordinates use explicit fallback without hiding slots');
    $pdo->prepare('UPDATE parking_slots SET position_x=3,position_y=1 WHERE id=?')->execute([$slotIds[9]]);
    $holdCount=(int)$pdo->query('SELECT COUNT(*) FROM reservation_holds')->fetchColumn();$guest->get($facilityUrl.'&slot_id='.$slotIds[5]);assertPhase3((int)$pdo->query('SELECT COUNT(*) FROM reservation_holds')->fetchColumn()===$holdCount,'inspecting a slot creates no hold');
    assertPhase3((int)$pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn()===0,'test fixtures create no fake payments');
    echo "\n$count Phase 3 integration checks passed. Fixtures exist only in the isolated test database.\n";
} catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
