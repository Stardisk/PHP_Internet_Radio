<?php

class config{
    private static $config = [
        'audioFiles'=>'/media/usbhdd/music',    //path to folder storing audio files
        'debug'=>true,                          //show debug data in interface and stream metadata
        'locale'=>'C.UTF-8',                    //files locale (for supporting cyrillic and other non-standard alphabets)
        'metadata'=>true,                       //enable ICY-metadata for media players
        'service_ips'=>['192.168.88.99','127.0.0.1'],   //IP-addresses allowed to refresh and test the library
    ];

    public static function getSetting($settingName){
        return (isset(self::$config[$settingName])) ? self::$config[$settingName] : false;
    }
}
