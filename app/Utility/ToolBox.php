<?php


namespace App\Utility;


use App\Model\Campane\Campane;
use App\Model\Order\OrderStatus;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ToolBox
{
    public static function toPersianNumber($string)
    {
        $en_num = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa_num = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        return str_replace($en_num, $fa_num, $string);
    }

    public static function toEnglishNumber($string)
    {
        $en_num = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa_num = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        return str_replace($fa_num, $en_num, $string);
    }

    public static function formatCreditCard($cc)
    {
        // REMOVE EXTRA DATA IF ANY
        $cc = str_replace(array('-', ' '), '', $cc);

        // GET THE CREDIT CARD LENGTH
        $cc_length = strlen($cc);


        $newCreditCard = substr($cc, -4);

        for ($i = $cc_length - 5; $i >= 0; $i--) {
            // ADDS HYPHEN HERE
            if ((($i + 1) - $cc_length) % 4 == 0) {
                $newCreditCard = '-' . $newCreditCard;
            }
            $newCreditCard = $cc[$i] . $newCreditCard;
        }

        // RETURN THE FINAL FORMATED AND MASKED CREDIT CARD NO
        return $newCreditCard;
    }


    public static function countDigit($n)
    {
        return floor(log10($n) + 1);
    }

    // check if a string starts with specific substring
    public static function startsWith($haystack, $needle)
    {
        return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
    }

    // check if a string ends with specific substring
    public static function endsWith($haystack, $needle)
    {
        return substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }


    // extract attributeName of productuser_value
    public static function extractAttributeNames($data)
    {
        $data = json_decode($data);
        $attributeName = array();

        foreach ($data as $obj) {
            $attributeName[] = $obj->attributeName;
        }

        return $attributeName;
    }

    public static function createRandomString()
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < 10; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
            ($i == 5) ? $randomString .= \Carbon\Carbon::now()->timestamp : '';
        }
        return $randomString;
    }

    public static function getIpAddress()
    {
        return $userIP = request()->ip();
    }


    public function sortArrayElementsBySpecificKey($item1,$item2, $keyName)
    {
        if ($item1[$keyName] == $item2[$keyName]) return 0;
        return ($item1[$keyName] < $item2[$keyName]) ? 1 : -1;
    }

    public static function detectDeviceType()
    {
        $detect = new \Detection\MobileDetect;
        return $detect->isMobile() ? 'mobile' : ($detect->isTablet() ? 'tablet' : 'desktop');

    }

    public static function getUserAgent()
    {
        $detect = new \Detection\MobileDetect;
        return $detect->getUserAgent();
    }

    public static function getUserBasicInfo()
    {
        $deviceType = self::detectDeviceType();
        $userIp = self::getIpAddress();
        $userAgent = self::getUserAgent();

        // initialize data
        $requestInfo[] = [
            "user_agent"  => $userAgent,
            "ip"          => $userIp,
            "device_type" => $deviceType,
        ];

        $today = Carbon::today()->format('Y-m-d');
        $currentDateExists = DB::table('usersrequests_reports')
                                ->where('date', $today)
                                ->exists();

        try{
            // insert new ip to `ip_lists` column
            if($currentDateExists)
            {
                $targetRecord = DB::table('usersrequests_reports')
                    ->where('date', $today)
                    ->select('requests_total_info_notprocessed')
                    ->first();

                $alreadySaved = false;
                if(!is_null($targetRecord->requests_total_info_notprocessed)) {

                    $oldRequestInfo = json_decode($targetRecord->requests_total_info_notprocessed, true);
                    foreach($oldRequestInfo as $item) {

                        if( ($item["ip"] == $requestInfo[0]["ip"]) && ($item["device_type"] == $requestInfo[0]["device_type"])) {
                            $alreadySaved = true;
                            break;
                        }
                    }
                    if(!$alreadySaved) {
                        array_push($oldRequestInfo, $requestInfo[0]);
                    }
                }

                DB::table('usersrequests_reports')
                    ->where('date', $today)
                    ->update([
                        "requests_total_info_notprocessed"   => json_encode($oldRequestInfo),
                    ]);

            }
            else
            {
                DB::table('usersrequests_reports')
                    ->insert([
                        "date" => $today,
                        "requests_total_info_notprocessed"   => json_encode($requestInfo),
                    ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            Log::Error('exception: ' . $e->getMessage() . 'in class: ' . __CLASS__ . ' on line: ' . __LINE__);
        } catch (\Exception $e) {
            Log::Error('exception: ' . $e->getMessage() . 'in class: ' . __CLASS__ . ' on line: ' . __LINE__);
        }

    }

    //$arr => original array
    //$set => array containing old keys as keys and new keys as values
    public static function changeKeysOfArray($arr, $set)
    {
        if (is_array($arr) && is_array($set)) {
            $newArr = array();
            foreach ($arr as $k => $v) {
                $key = array_key_exists( $k, $set) ? $set[$k] : $k;
                $newArr[$key] = $v;
            }
            return $newArr;
        }
    }

    // عدد ماه را به نام متناظرش تبدیل میکند.
    public static function mapMonthNumberToName($originalArray)
    {
        $replaceArray = [
            "01"  => "فروردین",
            "02"  => "اردیبهشت",
            "03"  => "خرداد",
            "04"  => "تیر",
            "05"  => "مرداد",
            "06"  => "شهریور",
            "07"  => "مهر",
            "08"  => "آبان",
            "09"  => "آذر",
            "10" => "دی",
            "11" => "بهمن",
            "12" => "اسفند",
        ];

        if (is_array($originalArray) && is_array($replaceArray)) {
            $newArr = array();
            foreach ($originalArray as $k => $v) {
                $key = array_key_exists( $k, $replaceArray) ? $replaceArray[$k] : $k;
                $newArr[$key] = $v;
            }
           return $newArr;
        }
        throw new Exception('Input is not array. error from class: ' . __CLASS__ . ' line: ' . __LINE__);
    }

    //prettify orderdetail attribute value
    public static function prettifyAttribute($content)
    {
        if($content == null)
            return $content;

        $result = null;
        $decodedContent =  (is_string($content)) ? json_decode($content,true) : json_decode(json_encode($content),true);
        foreach($decodedContent as $key=>$item){
            $result .= array_keys($item)[0] . ":". array_values($item)[0] .  " ";
        }

        return $result;
    }

    public static function convertOrderStatus($order_status_number)
    {
        if($order_status_number == OrderStatus::SABT) return "ثبت";
        if($order_status_number == OrderStatus::BAAZ) return "باز";
        if($order_status_number == OrderStatus::DAR_HALE_EGHDAM) return "در حال اقدام";
        if($order_status_number == OrderStatus::ERSAL) return "ارسال";
        if($order_status_number == OrderStatus::BASTE) return "بسته";
        if($order_status_number == OrderStatus::CANCEL) return "کنسل";
        return "نامشخص";
    }

    public static function convertOrderPaymentStatus($rezerv_flag, $aghsati_flag)
    {
        if($rezerv_flag=="1" && $aghsati_flag=="1") return "اقساط";
        if($rezerv_flag=="1" && $aghsati_flag!="1") return "رزرو";
        if($rezerv_flag!="1" && $aghsati_flag!="1") return "پرداخت کامل";
        return "نامشخص";
    }

    // find active campaign, if any exists
    public static function checkExistsActiveCampaign()
    {
        $result = Campane::where(["active"=>"1", "confirm"=>"1"])
                    ->where("startdate", "<=", now())
                    ->where("enddate", ">=", now())
                    ->exists();

        if($result) {
            $campane = Campane::where(["active"=>"1", "confirm"=>"1"])
                                ->where("startdate", "<=", now())
                                ->where("enddate", ">=", now())
                                ->first();
            return [
                "status"    => true,
                "pic_addr"  => $campane->pic_addr,
                "campane_id"=> $campane->id,
            ];
        }

        return [
            "status"    => false,
            "pic_addr"  => null,
            "campane_id"=> null,
        ];
    }

    // used for `walletTransaction` and `totalFinsTransaction`
    public static function convertTransactionStatus($status)
    {
        if($status=="variz") {
            return $wallet_trans_status = "واریز";
        }
        if($status=="bardasht") {
            return $wallet_trans_status = "برداشت";
        }
        if($status=="afzayesh_mojoodi") {
            return $wallet_trans_status = "افزایش موجودی";
        }
        return null;
    }

    // to render view with given parameters
    public static function bladeCompile($rawMessage, array $args = array())
    {
        $message = preg_replace('/\{\{(.*?)\}\}/', "{{\$$1}}", $rawMessage);
        $generated = Blade::compileString($message);

        ob_start() and extract($args, EXTR_SKIP);

        // We'll include the view contents for parsing within a catcher
        // so we can avoid any WSOD errors. If an exception occurs we
        // will throw it out to the exception handler.
        try
        {
            eval('?>'.$generated);
        }

            // If we caught an exception, we'll silently flush the output
            // buffer so that no partially rendered views get thrown out
            // to the client and confuse the user with junk.
        catch (\Exception $e)
        {
            ob_get_clean(); throw $e;
        }

        return ob_get_clean();
    }



    // make `userlevel_id` column in `users` table null for given user_id
    // archive old userlevel for this user in `userlevelarchives` table
    public static function archiveUserlevelAndRemoveUserLevel($user_id)
    {
        $query = DB::table("users")->where("id", $user_id);
        if($query->exists()) {

            try{
                $user = $query->first();
                if(is_null($user->userlevel_id))
                    return;

                $userlevel_id = $user->userlevel_id;
                $query->update([
                   "userlevel_id" => null
                ]);

                // archive userlevel_id for this user in `userlevelarchives` table
                DB::table("userlevelarchives")->insert([
                    "user_id"      => $user_id,
                    "userlevel_id" => $userlevel_id,
                    "enddate"      => Carbon::now(),
                ]);
            }
            catch(\Exception $e) {
                Log::Error("Trying to remove userlevel for given user by id: " . $user_id . " but error occured with message: " . $e->getMessage() . " at line: " . $e->getLine() . " in class: " . __CLASS__ . " at line: " . __LINE__);
                return "user-notFound";
            }

        }

        Log::Error("Trying to remove userlevel for given user by id: " . $user_id . " but user not Found in Users table.");
        return "user-notFound";
    }

}
