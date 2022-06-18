<?php

class config{
    private static $config = [
        'audioFiles'=>'/media/usbhdd/music',    //path to folder storing audio files
        'debug'=>true,                          //show debug data in interface and stream metadata
        'locale'=>'C.UTF-8'                     //files locale (for supporting cyrillic and other non-standard alphabets
    ];

    public static function getSetting($settingName){
        return (isset(self::$config[$settingName])) ? self::$config[$settingName] : false;
    }
}