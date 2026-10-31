<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use ParkNGo\Services\{Auth,Database,Environment};
use ParkNGo\Services\Parking\{AvailabilityWindow,FeeEstimate,InventoryManager,ReservationService,ReservationConflict};

if (Environment::get('APP_ENV','local')!=='local'||!str_ends_with(Environment::get('DB_DATABASE'),'_test')) { fwrite(STDERR,"Use an isolated *_test database.\n");exit(1); }
$base=rtrim(Environment::get('TEST_BASE_URL','http://127.0.0.1:8088'),'/');
if (!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost'],true)) throw new RuntimeException('Loopback tests only.');
$checks=0;
function check4(bool $condition,string $label): void { global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks;echo "PASS $label\n"; }
function rejects4(callable $callback,string $class,string $label): void { try{$callback();}catch(Throwable $error){check4($error instanceof $class,$label);return;}check4(false,$label); }
final class ReservationBrowser
{
    private CurlHandle $curl;public string $body='';public int $status=0;public array $headers=[];
    public function __construct(private string $base) { $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_COOKIEFILE=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]); }
    public function request(string $page,?array $post=null): self {
        $this->headers=[];curl_setopt($this->curl,CURLOPT_URL,$this->base.'/index.php?page='.$page);
        curl_setopt($this->curl,CURLOPT_HEADERFUNCTION,function($curl,string $line):int{if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$this->headers[strtolower($key)]=trim($value);}return strlen($line);});
        if($post===null)curl_setopt($this->curl,CURLOPT_HTTPGET,true);else curl_setopt_array($this->curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
        $body=curl_exec($this->curl);if($body===false)throw new RuntimeException(curl_error($this->curl));$this->body=$body;$this->status=curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE);return $this;
    }
    public function field(string $name): string { if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$this->body,$m))throw new RuntimeException('Missing field '.$name);return html_entity_decode($m[1],ENT_QUOTES,'UTF-8'); }
    public function login(string $email,string $password): void { $this->request('login');$csrf=$this->field('csrf');$this->request('login',compact('csrf','email','password')); }
    public function cookie(): string { foreach(curl_getinfo($this->curl,CURLINFO_COOKIELIST) as $cookie){$parts=explode("\t",$cookie);if(($parts[5]??'')==='parkngo_session')return $parts[6];}throw new RuntimeException('No test session cookie.'); }
}
function race4(array $jobs): array {
    $barrier=bin2hex(random_bytes(8));$directory=BASE_PATH.'/storage/test-runtime/phase4';if(!is_dir($directory))mkdir($directory,0700,true);
    $processes=[];
    foreach($jobs as $index=>$job){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/fixtures/reservation-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH);if(!is_resource($process))throw new RuntimeException('Cannot start concurrency worker.');fwrite($pipes[0],json_encode($job+['barrier'=>$barrier,'worker'=>$index],JSON_THROW_ON_ERROR));fclose($pipes[0]);$processes[]=[$process,$pipes];}
    $deadline=microtime(true)+10;
    do{$ready=true;foreach(array_keys($jobs) as $index)if(!is_file($directory.'/'.$barrier.'-'.$index.'.ready'))$ready=false;if(!$ready)usleep(10000);}while(!$ready&&microtime(true)<$deadline);
    if(!$ready)throw new RuntimeException('Workers did not become ready.');file_put_contents($directory.'/'.$barrier.'.go','go');$results=[];
    foreach($processes as [$process,$pipes]){$text=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Worker failed: '.$text.' '.$stderr);$results[]=json_decode($text,true,64,JSON_THROW_ON_ERROR);}
    return $results;
}
try {
    $guest=new ReservationBrowser($base);$guest->request('home');
    $fingerprint=hash('sha256',Environment::get('DB_HOST','127.0.0.1').':'.Environment::get('DB_PORT','3306').':'.Environment::get('DB_DATABASE'));
    check4(hash_equals($fingerprint,$guest->headers['x-parkngo-test-database']??''),'matching isolated HTTP/database environment');
    $pdo=Database::connection();$run=bin2hex(random_bytes(5));$password='Test-'.bin2hex(random_bytes(9));$hash=Auth::hashPassword($password);$users=[];
    foreach(['a'=>'driver','b'=>'driver','admin'=>'admin','attendant'=>'attendant'] as $name=>$role){$email='phase4-'.$run.'-'.$name.'@parkngo.test';$pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)')->execute(['Phase 4 '.$name,$email,$hash,$role]);$users[$name]=['id'=>(int)$pdo->lastInsertId(),'email'=>$email];}
    $manager=new InventoryManager();$facility=$manager->save('facility',['name'=>'Phase 4 '.$run,'address'=>'Test-only address','city'=>'Test City','description'=>'Isolated fixture','is_active'=>'1'],$users['admin']['id']);
    $floor=$manager->save('floor',['facility_id'=>(string)$facility,'label'=>'Test floor','sort_order'=>'0','is_active'=>'1'],$users['admin']['id']);$slots=[];
    for($i=0;$i<16;$i++)$slots[]=$manager->save('slot',['floor_id'=>(string)$floor,'label'=>'R-'.$i,'status'=>'available','position_x'=>(string)($i%6),'position_y'=>(string)intdiv($i,6)],$users['admin']['id']);
    $pdo->prepare('INSERT INTO parking_rates(facility_id,initial_centavos,succeeding_hour_centavos,daily_max_centavos,additional_centavos,effective_from) VALUES(?,5000,2000,10000,500,UTC_TIMESTAMP())')->execute([$facility]);$rateId=(int)$pdo->lastInsertId();
    $find=$pdo->prepare('SELECT * FROM parking_rates WHERE id=?');$find->execute([$rateId]);$rate=$find->fetch();
    foreach([15=>0,60=>5500,61=>7500,120=>7500,121=>9500,1440=>10500] as $minutes=>$expected)check4(FeeEstimate::calculate($rate,$minutes)['total_centavos']===$expected,'fee estimate for '.$minutes.' minutes');
    rejects4(fn()=>FeeEstimate::calculate($rate,0),InvalidArgumentException::class,'invalid fee duration rejected');
    $large=$rate;$large['daily_max_centavos']=null;$large['initial_centavos']=4294967295;$large['additional_centavos']=1;rejects4(fn()=>FeeEstimate::calculate($large,60),InvalidArgumentException::class,'centavo storage overflow rejected');
    $arrival=new DateTimeImmutable('tomorrow 12:00',new DateTimeZone('Asia/Manila'));$inputs=['date'=>$arrival->format('Y-m-d'),'time'=>'12:00','hours'=>'2'];$window=new AvailabilityWindow($inputs);$service=new ReservationService();
    $quote=$service->quote($slots[0],$window);$key=bin2hex(random_bytes(32));$id=$service->create($users['a']['id'],$slots[0],$window,$key,$quote['quote_hash']);$record=$service->detail($users['a']['id'],$id);
    check4($record['status']==='pending'&&$record['confirmed_at']===null,'new reservation remains pending without fake confirmation');
    check4((int)$record['estimated_centavos']===7500,'server stores integer estimate');
    $snapshot=json_decode($record['rate_snapshot'],true,64,JSON_THROW_ON_ERROR);check4($snapshot['rate']['initial_centavos']===5000&&$snapshot['cancellation_cutoff_minutes']===60,'rate and cancellation policy snapshots persisted');
    check4($record['expires_at']>$record['server_now']&&strtotime($record['expires_at'].' UTC')-strtotime($record['server_now'].' UTC')<=600,'checkout has a bounded ten-minute hold');
    check4($service->create($users['a']['id'],$slots[0],$window,$key,$quote['quote_hash'])===$id,'idempotent service retry returns same reservation');
    check4(count($service->history($users['a']['id']))===1,'idempotent retry creates no extra record');
    rejects4(fn()=>$service->create($users['a']['id'],$slots[1],$window,$key,$quote['quote_hash']),ReservationConflict::class,'reusing checkout key for another slot rejected');
    rejects4(fn()=>$service->detail($users['b']['id'],$id),OutOfBoundsException::class,'reservation details are owner-scoped');
    rejects4(fn()=>$service->cancel($users['b']['id'],$id),OutOfBoundsException::class,'other driver cannot cancel reservation');
    rejects4(fn()=>$service->create($users['b']['id'],$slots[0],$window,bin2hex(random_bytes(32)),$quote['quote_hash']),ReservationConflict::class,'overlapping live hold rejected');
    $service->cancel($users['a']['id'],$id);$service->cancel($users['a']['id'],$id);check4($service->detail($users['a']['id'],$id)['status']==='cancelled','pending cancellation releases hold and is idempotent');
    $hold=$pdo->prepare('SELECT released_at FROM reservation_holds WHERE reservation_id=?');$hold->execute([$id]);check4($hold->fetchColumn()!==null,'cancelled hold is released');
    $id=$service->create($users['b']['id'],$slots[0],$window,bin2hex(random_bytes(32)),$quote['quote_hash']);
    $pdo->prepare('UPDATE reservation_holds SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE reservation_id=?')->execute([$id]);
    $service->expire();check4($service->detail($users['b']['id'],$id)['status']==='expired','expiry marks unpaid reservation expired');
    $replacement=$service->create($users['a']['id'],$slots[0],$window,bin2hex(random_bytes(32)),$quote['quote_hash']);check4($replacement!==$id,'expired hold does not block a fresh reservation');$service->cancel($users['a']['id'],$replacement);
    $changed=$service->quote($slots[1],$window);$pdo->prepare('UPDATE parking_rates SET succeeding_hour_centavos=2100 WHERE id=?')->execute([$rateId]);
    rejects4(fn()=>$service->create($users['a']['id'],$slots[1],$window,bin2hex(random_bytes(32)),$changed['quote_hash']),ReservationConflict::class,'changed rate requires a new review');$pdo->prepare('UPDATE parking_rates SET succeeding_hour_centavos=2000 WHERE id=?')->execute([$rateId]);
    $slotForm=['id'=>(string)$slots[1],'floor_id'=>(string)$floor,'label'=>'R-1','status'=>'disabled','position_x'=>'1','position_y'=>'0'];$manager->save('slot',$slotForm,$users['admin']['id']);
    rejects4(fn()=>$service->create($users['a']['id'],$slots[1],$window,bin2hex(random_bytes(32)),$changed['quote_hash']),ReservationConflict::class,'disabled inventory is rechecked under lock');$slotForm['status']='available';$manager->save('slot',$slotForm,$users['admin']['id']);
    foreach([2,3,4] as $index){$q=$service->quote($slots[$index],$window);$caps[]=$service->create($users['a']['id'],$slots[$index],$window,bin2hex(random_bytes(32)),$q['quote_hash']);}
    $q=$service->quote($slots[5],$window);rejects4(fn()=>$service->create($users['a']['id'],$slots[5],$window,bin2hex(random_bytes(32)),$q['quote_hash']),ReservationConflict::class,'per-driver active hold cap enforced');
    foreach($caps as $cap)$service->cancel($users['a']['id'],$cap);
    $guest->request('reservation-review&slot_id='.$slots[0].'&'.http_build_query($inputs));check4($guest->status===303,'guest redirected before driver checkout');
    foreach(['admin','attendant'] as $name){$staff=new ReservationBrowser($base);$staff->login($users[$name]['email'],$password);$staff->request('reservations');check4($staff->status===403,$name.' denied driver booking pages');$staff->request('profile');$staffCsrf=$staff->field('csrf');$staff->request('reservation-review',['csrf'=>$staffCsrf,'request_key'=>bin2hex(random_bytes(32))]);check4($staff->status===403,$name.' cannot POST driver checkout');}
    $browser=new ReservationBrowser($base);$browser->login($users['a']['email'],$password);$route='reservation-review&slot_id='.$slots[6].'&'.http_build_query($inputs);$browser->request($route);check4($browser->status===200&&str_contains($browser->body,'Estimated parking fee'),'HTTP review renders server quote');$csrf=$browser->field('csrf');$requestKey=$browser->field('request_key');
    $before=(int)$pdo->query('SELECT COUNT(*) FROM reservation_holds')->fetchColumn();check4($before>0,'history fixtures retained');
    $browser->request('reservation-review',['request_key'=>$requestKey]);check4($browser->status===419,'checkout requires CSRF');
    $browser->request('reservation-review',['csrf'=>$csrf,'request_key'=>$requestKey,'estimated_centavos'=>'1','user_id'=>$users['b']['id'],'status'=>'confirmed']);check4($browser->status===303,'HTTP checkout creates pending hold');
    parse_str(parse_url($browser->headers['location'],PHP_URL_QUERY),$location);$httpId=(int)$location['id'];$saved=$service->detail($users['a']['id'],$httpId);check4((int)$saved['estimated_centavos']===7500&&$saved['status']==='pending'&&(int)$saved['user_id']===$users['a']['id'],'tampered amount, owner, and confirmation ignored');
    $browser->request('reservation-review',['csrf'=>$csrf,'request_key'=>$requestKey]);check4($browser->status===303&&str_contains($browser->headers['location'],'id='.$httpId),'repeated HTTP submission reuses reservation');
    $browser->request('reservation-checkout&id='.$httpId);check4($browser->status===200&&str_contains($browser->body,'Maya Checkout sandbox')&&str_contains($browser->body,'payment-start'),'checkout exposes the Maya payment action without trusting browser payment data');
    $browser->request('reservation-cancel&id='.$httpId);check4($browser->status===405,'GET cannot cancel reservation');
    $browser->request('reservation-cancel',['csrf'=>$csrf,'id'=>(string)$httpId]);check4($browser->status===303,'owner cancellation works through HTTP');
    $browser->request('reservations');check4(str_contains($browser->body,$saved['reference']),'reservation history includes own reference');
    $other=new ReservationBrowser($base);$other->login($users['b']['email'],$password);$other->request('reservation-checkout&id='.$httpId);check4($other->status===404,'HTTP checkout does not expose another driver reservation');
    $browser->request($route);$expiredKey=$browser->field('request_key');$expiredCsrf=$browser->field('csrf');$sessionPath=BASE_PATH.'/storage/sessions/sess_'.$browser->cookie();$session=file_get_contents($sessionPath);$session=preg_replace('/"created_at";i:\d+;/','"created_at";i:'.(time()-1000).';',$session);file_put_contents($sessionPath,$session);
    $browser->request('reservation-review',['csrf'=>$expiredCsrf,'request_key'=>$expiredKey]);check4($browser->status===422&&str_contains($browser->body,'review has expired'),'expired server-side quote cannot create a hold');
    $browser->request('reservation-review',['csrf'=>$expiredCsrf,'request_key'=>bin2hex(random_bytes(32))]);check4($browser->status===422,'unknown checkout key cannot create a hold');
    $service->quote($slots[0],$window);
    for($race=0;$race<3;$race++){
        $slot=$slots[8+$race];$q=$service->quote($slot,$window);$jobs=[];
        foreach(['a','b'] as $who)$jobs[]=['user_id'=>$users[$who]['id'],'slot_id'=>$slot,'window'=>$inputs,'key'=>bin2hex(random_bytes(32)),'quote_hash'=>$q['quote_hash']];
        $results=race4($jobs);check4(count(array_filter($results,fn($r)=>$r['result']==='created'))===1&&count(array_filter($results,fn($r)=>$r['result']==='conflict'))===1,'independent connections: one winner for overlapping race '.($race+1));
        foreach($results as $result)if(isset($result['id'])){$find=$pdo->prepare('SELECT user_id FROM reservations WHERE id=?');$find->execute([$result['id']]);$service->cancel((int)$find->fetchColumn(),$result['id']);}
    }
    $slot=$slots[11];$q=$service->quote($slot,$window);$sameKey=bin2hex(random_bytes(32));$job=['user_id'=>$users['a']['id'],'slot_id'=>$slot,'window'=>$inputs,'key'=>$sameKey,'quote_hash'=>$q['quote_hash']];$results=race4([$job,$job]);check4($results[0]['result']==='created'&&$results[1]['result']==='created'&&$results[0]['id']===$results[1]['id'],'simultaneous same-key requests create exactly one reservation');$service->cancel($users['a']['id'],$results[0]['id']);
    $slot=$slots[12];$q=$service->quote($slot,$window);$later=$inputs;$later['time']='14:00';$laterWindow=new AvailabilityWindow($later);$q2=$service->quote($slot,$laterWindow);
    $results=race4([['user_id'=>$users['a']['id'],'slot_id'=>$slot,'window'=>$inputs,'key'=>bin2hex(random_bytes(32)),'quote_hash'=>$q['quote_hash']],['user_id'=>$users['b']['id'],'slot_id'=>$slot,'window'=>$later,'key'=>bin2hex(random_bytes(32)),'quote_hash'=>$q2['quote_hash']]]);check4($results[0]['result']==='created'&&$results[1]['result']==='created','touching non-overlapping time windows can both hold');foreach($results as $index=>$result)$service->cancel($users[$index===0?'a':'b']['id'],$result['id']);
    $slot=$slots[13];$q=$service->quote($slot,$window);$results=race4([['user_id'=>$users['a']['id'],'slot_id'=>$slot,'window'=>$inputs,'key'=>bin2hex(random_bytes(32)),'quote_hash'=>$q['quote_hash']],['mode'=>'inventory','user_id'=>$users['admin']['id'],'slot_form'=>['id'=>(string)$slot,'floor_id'=>(string)$floor,'label'=>'R-13','status'=>'disabled','position_x'=>'1','position_y'=>'2']]]);
    check4(count(array_filter($results,fn($r)=>in_array($r['result'],['created','updated'],true)))===1,'booking versus admin disable has one valid winner');if(isset($results[0]['id']))$service->cancel($users['a']['id'],$results[0]['id']);
    $id=$service->create($users['a']['id'],$slots[14],$window,bin2hex(random_bytes(32)),$service->quote($slots[14],$window)['quote_hash']);$pdo->prepare("UPDATE reservations SET status='confirmed',confirmed_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);$pdo->prepare('UPDATE reservation_holds SET released_at=UTC_TIMESTAMP() WHERE reservation_id=?')->execute([$id]);$service->cancel($users['a']['id'],$id);check4($service->detail($users['a']['id'],$id)['status']==='cancelled','confirmed test fixture can cancel before its saved deadline');
    $id=$service->create($users['a']['id'],$slots[15],$window,bin2hex(random_bytes(32)),$service->quote($slots[15],$window)['quote_hash']);$pdo->prepare("UPDATE reservations SET status='confirmed',starts_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE),ends_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 HOUR) WHERE id=?")->execute([$id]);$pdo->prepare('UPDATE reservation_holds SET released_at=UTC_TIMESTAMP() WHERE reservation_id=?')->execute([$id]);rejects4(fn()=>$service->cancel($users['a']['id'],$id),ReservationConflict::class,'confirmed fixture cannot cancel after saved deadline');
    $currentArrival=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->modify('+1 hour');$overlapWindow=new AvailabilityWindow(['date'=>$currentArrival->format('Y-m-d'),'time'=>$currentArrival->format('H:i'),'hours'=>'1']);
    $q=$service->quote($slots[15],$overlapWindow);$confirmedCount=(int)$pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();rejects4(fn()=>$service->create($users['b']['id'],$slots[15],$overlapWindow,bin2hex(random_bytes(32)),$q['quote_hash']),ReservationConflict::class,'confirmed reservation blocks overlap');check4((int)$pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn()===$confirmedCount,'rejected overlap leaves no partial reservation');
    $pdo->prepare("INSERT INTO parking_sessions(reservation_id,entered_by,entry_at,status) VALUES(?,?,UTC_TIMESTAMP(),'active')")->execute([$id,$users['attendant']['id']]);rejects4(fn()=>$service->cancel($users['a']['id'],$id),ReservationConflict::class,'active parking session blocks cancellation');
    $tomorrow=$inputs;$tomorrow['date']=$arrival->modify('+2 days')->format('Y-m-d');$future=new AvailabilityWindow($tomorrow);$q=$service->quote($slots[15],$future);rejects4(fn()=>$service->create($users['b']['id'],$slots[15],$future,bin2hex(random_bytes(32)),$q['quote_hash']),ReservationConflict::class,'active session conservatively blocks future booking');
    check4((int)$pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM qr_tickets')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn()===0,'no payment success, receipt, or QR fabricated');
    echo "\n$checks Phase 4 checks passed. Concurrency workers used independent PHP processes and database connections.\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
