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
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : ($_SERVER['REQUEST_SCHEME'] ?? 'http');
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $url = $scheme.'://'.$host.rtrim($path, '/').'/?play';
    header('Content-Disposition: attachment; filename=listen.m3u');
    header('Content-Type: audio/x-mpegurl; charset=UTF-8');
    echo "#EXTM3U\n#EXTINF:-1,Stardisk PHP Radio\n".$url."\n";
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




