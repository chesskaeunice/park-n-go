<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__,2).'/app/bootstrap.php';
use ParkNGo\Services\{Database,Environment};
use ParkNGo\Services\Parking\{AvailabilityWindow,InventoryManager,ReservationService,ReservationConflict};

if (Environment::get('APP_ENV','local')!=='local'||!str_ends_with(Environment::get('DB_DATABASE'),'_test')) exit(2);
try {
    $input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    Database::connection()->exec('SET SESSION innodb_lock_wait_timeout=5');
    $directory=BASE_PATH.'/storage/test-runtime/phase4';
    if (!preg_match('/^[a-f0-9-]+$/D',$input['barrier'])) throw new RuntimeException('Invalid test barrier.');
    file_put_contents($directory.'/'.$input['barrier'].'-'.$input['worker'].'.ready','ready');
    $deadline=microtime(true)+10;
    while (!is_file($directory.'/'.$input['barrier'].'.go')) { if (microtime(true)>$deadline) throw new RuntimeException('Test barrier timed out.'); usleep(10000); }
    if (($input['mode']??'create')==='inventory') {
        (new InventoryManager())->save('slot',$input['slot_form'],(int)$input['user_id']);
        echo json_encode(['result'=>'updated'],JSON_THROW_ON_ERROR);
    } else {
        $id=(new ReservationService())->create((int)$input['user_id'],(int)$input['slot_id'],new AvailabilityWindow($input['window']),$input['key'],$input['quote_hash']);
        echo json_encode(['result'=>'created','id'=>$id],JSON_THROW_ON_ERROR);
    }
} catch (ReservationConflict|InvalidArgumentException $error) { echo json_encode(['result'=>'conflict'],JSON_THROW_ON_ERROR); }
catch (Throwable $error) { echo json_encode(['result'=>'error','class'=>get_class($error),'code'=>$error->getCode()],JSON_THROW_ON_ERROR);exit(1); }
