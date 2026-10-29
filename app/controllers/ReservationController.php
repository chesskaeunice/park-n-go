<?php
declare(strict_types=1);
namespace ParkNGo\Controllers;

use ParkNGo\Services\{Auth, RateLimiter};
use ParkNGo\Services\Parking\{AvailabilityWindow, ReservationConflict, ReservationService};

final class ReservationController
{
    public function handle(string $page): array
    {
        $user=Auth::user();$service=new ReservationService();$config=require BASE_PATH.'/config/reservations.php';
        $errors=[];$quote=null;$reviewLink=url('facilities');$reservation=null;$rows=[];$pageNumber=1;
        try {
            if ($page==='reservations') {
                $pageNumber=ParkingController::id($_GET,'p',1);
                if ($pageNumber>10000) throw new \InvalidArgumentException('Choose a valid history page.');
                $rows=$service->history((int)$user['id'],$pageNumber);
            } elseif ($page==='reservation-checkout') {
                $reservation=$service->detail((int)$user['id'],ParkingController::id($_GET,'id'));
            } elseif ($page==='reservation-cancel') {
                if (!RateLimiter::consume('reservation-cancel',(string)$user['id'],40)) { http_response_code(429);header('Retry-After: 900');throw new \InvalidArgumentException('Too many attempts. Please try again later.'); }
                $id=ParkingController::id($_POST,'id');
                $service->cancel((int)$user['id'],$id,(int)$user['session_version']);
                flash('Reservation cancelled. The temporary hold has been released.');redirect('reservation-checkout',['id'=>$id]);
            } elseif ($_SERVER['REQUEST_METHOD']==='POST') {
                if (!RateLimiter::consume('reservation-checkout',(string)$user['id'],30)) { http_response_code(429);header('Retry-After: 900');throw new \InvalidArgumentException('Too many checkout attempts. Please try again later.'); }
                $key=input('request_key');
                if (!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new \InvalidArgumentException('Open a fresh reservation review before submitting.');
                if ($id=$service->byRequest((int)$user['id'],$key)) redirect('reservation-checkout',['id'=>$id]);
                $stored=$_SESSION['reservation_quotes'][$key]??null;
                if ($stored) $reviewLink=url('reservation-review',$stored['inputs']);
                if (!$stored || time()-$stored['created_at']>$config['quote_minutes']*60) throw new \InvalidArgumentException('Your review has expired. Open a fresh review.');
                $window=new AvailabilityWindow($stored['inputs']);
                $id=$service->create((int)$user['id'],(int)$stored['inputs']['slot_id'],$window,$key,$stored['quote_hash'],(int)$user['session_version']);
                flash('Temporary hold created. Your reservation is pending; no payment has been taken.');
                redirect('reservation-checkout',['id'=>$id]);
            } else {
                $window=new AvailabilityWindow($_GET);
                // Freeze a blank "now" search to an explicit minute before reviewing/submitting.
                if (!$window->explicit) {
                    $local=$window->start->setTimezone(new \DateTimeZone('Asia/Manila'));
                    $window=new AvailabilityWindow(['date'=>$local->format('Y-m-d'),'time'=>$local->format('H:i'),'hours'=>(string)$window->hours]);
                }
                $slotId=ParkingController::id($_GET,'slot_id');$quote=$service->quote($slotId,$window);
                $key=bin2hex(random_bytes(32));$inputs=['slot_id'=>(string)$slotId]+$window->query();
                $quoteSeconds=$config['quote_minutes']*60;
                $storedQuotes=array_filter($_SESSION['reservation_quotes']??[],static fn(array $item):bool=>time()-$item['created_at']<=$quoteSeconds);
                $storedQuotes=array_slice($storedQuotes,-9,null,true);
                $storedQuotes[$key]=['inputs'=>$inputs,'quote_hash'=>$quote['quote_hash'],'created_at'=>time()];$_SESSION['reservation_quotes']=$storedQuotes;
                $quote['request_key']=$key;
            }
        } catch (ReservationConflict $error) { http_response_code(409);$errors[]=$error->getMessage(); }
        catch (\InvalidArgumentException $error) { if (http_response_code()!==429) http_response_code(422);$errors[]=$error->getMessage(); }
        return compact('errors','quote','reviewLink','reservation','rows','pageNumber','config');
    }
}
