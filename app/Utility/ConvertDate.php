<?php


namespace App\Utility;


use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Hekmatinasser\Verta\Verta;

class ConvertDate
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

    public static function datetimeToPersianTime(string $datetime)
    {
        return static::toPersianNumber(date_format(date_create($datetime), 'H:i'));
    }

    public static function datetimeToPersianDate(string $datetime)
    {
        $v = new Verta($datetime);
        $jalaliDateTime = $v->formatDate();
        return static::toPersianNumber($jalaliDateTime);
    }

    public static function datetimeToPersianDateWithMonthName(string $datetime)
    {
        $v = new Verta($datetime);
        $jalaliDateTime = $v->format('%d %B %Y');;
        return static::toPersianNumber($jalaliDateTime);
    }

    public static function datetimeToJalaliDatetime(string $datetime)
    {
        $v = new Verta($datetime);
        $jalaliDateTime = $v->formatJalaliDatetime();
        return self::toPersianNumber($jalaliDateTime);
    }

    public static function datetimeToJalaliDate(string $datetime)
    {
        $v = new Verta($datetime);
        $jalaliDateTime = $v->formatJalaliDate();
        return self::toPersianNumber($jalaliDateTime);
    }

    public static function datetimeToPersianDatetime(string $datetime)
    {
        return Verta::instance($datetime);
    }

    public static function datetimeToTime(string $datetime)
    {
        return date('H:i', strtotime($datetime));
    }

    public static function persianDateToDatetime(string $string)
    {
        //String Process For Convert Persian Date To Mysql DateTime
        $en_num = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa_num = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $date = str_replace($fa_num, $en_num, $string);
        $a = new Verta();
        $b = $a->parse($date);
        return $b->formatGregorian('Y-m-d H:i:s');
    }


    public static function persianDateToDate(string $string,$delimiter="-")
    {
        //String Process For Convert Persian Date To Mysql DateTime
        $en_num = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa_num = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $date = str_replace($fa_num, $en_num, $string);
        $a = new Verta();
        $b = $a->parse($date);
//        return $b->formatGregorian('Y-m-d');
        return $b->formatGregorian("Y" . $delimiter . "m" . $delimiter . "d");
    }

    public static function getMonthNameFromDatetime(string $datetime)
    {
        if(!is_null($datetime)){
            $date = new verta($datetime);
            return $date->month;
        }
        return null;
    }
    /*
     * Created By Aryan Sartippour
     */
    public static function persianDateToUnixMillisecond($string)
    {
        //String Process For Convert Persian Date To Mysql DateTime
        $en_num = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa_num = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $date = str_replace($fa_num, $en_num, $string);
        $a = new Verta();
        $b = $a->parse($date);
        $gregorian = $b->formatGregorian('Y-m-d H:i:s');
        return strtotime($gregorian) * 1000;
    }

    public static function persianDateToTimestamp($string)
    {
        //String Process For Convert Persian Date To Mysql DateTime
        $en_num = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa_num = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $date = str_replace($fa_num, $en_num, $string);
        $a = new Verta();
        $b = $a->parse($date);
        $gregorian = $b->formatGregorian('Y-m-d H:i:s');
        return strtotime($gregorian);
    }

    public static function getNextYear($date)
    {
        return Verta::instance($date)->addYears(1)->formatJalaliDate();
    }

    public static function today()
    {
        $v = new Verta();
        return static::toPersianNumber($v->formatJalaliDate());
    }

    public static function countDigit($n)
    {
        return floor(log10($n)+1);
    }

    public static function datetimeToPersianDateWithEnglishDigits(string $datetime)
    {
        $v = new Verta($datetime);
        return $v->formatDate();
    }

    public static function GregorianDateToPersianDate($date, $delimiter="-",$persianNumber=false)
    {
        $v = new Verta($date);
        $result = $v->format("Y" . $delimiter . "m" . $delimiter . "d");
        if($persianNumber) $result = ToolBox::toPersianNumber($result);
        return $result;
    }

    public static function getMonthNameFromJalaliDate($date)
    {
        $v = new Verta($date);
        return $v->format('%B %d، %Y');
    }

    // در رنچ خواسته شده، روز تولید شود.
    public static function createGregorianDatesArrayInGivenRange($starttime, $endtime,$delimiter="-")
    {
        $period = CarbonPeriod::create($starttime, $endtime);

        // Iterate over the period
        foreach ($period as $date) {
            $dates[] = $date->format("Y" . $delimiter . "m" . $delimiter . "d");
        }
        return $dates;
    }

    public static function convertGregorianDatesArrayToJalaliDatesArray(array $dates, $delimiter="-",$persianNumber=false)
    {
        $jalaliDate = array();
        foreach($dates as $date){
            $jalaliDate[] =  self::GregorianDateToPersianDate($date, $delimiter,$persianNumber);
        }

        return $jalaliDate;
    }

    public static function dateTimeToPersiaDateTimeFlipedFormat(string $datetime)
    {
        $v = new Verta($datetime);
        $jalaliDateTime = $v->format('H:i:s Y/n/j');
        return self::toPersianNumber($jalaliDateTime);
    }

    // add one day to given date, sample date: '2023-2-18 00:00:00'
    public static function GoToNextDay($dateAsString)
    {
        $today = Carbon::createFromFormat('Y-m-d H:i:s',  $dateAsString);
        return $today->addDays(1);
    }
}
