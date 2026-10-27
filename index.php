<?php
/**
 * Created by PhpStorm.
 * User: Stardisk
 * Date: 28.05.22
 * Time: 1:53
 */
set_time_limit(0);

//send playlist file
if(isset($_GET['playlist'])){
    $url = parse_url($_SERVER['REQUEST_URI']);
    header('Content-Disposition: attachment; filename=listen.m3u');
    header('Content-Type: application/octet-stream');
    echo "#EXTM3U\n#EXTINF:-1,Stardisk PHP Radio\n{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['SERVER_NAME']}{$url['path']}?play";
    exit;
}
//test files
if(isset($_GET['test'])){
    require_once('test.php');
    new test($_GET['test'] == 'convert');
    exit;
}
require_once('radio.php');
new radio();




