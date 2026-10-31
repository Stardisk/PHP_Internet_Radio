<?php
require_once (__DIR__.'/config.php');
use Stardisk\Radio\Mp3File;

class service{

    public static function isAllowed(){
        $allowedIPs = config::getSetting('service_ips');
        return (!$allowedIPs or in_array($_SERVER['REMOTE_ADDR'], $allowedIPs));
    }
    public static function action($action = ''){
        switch ($action){
            case 'playlist':{
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : ($_SERVER['REQUEST_SCHEME'] ?? 'http');
                $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
                $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                $url = $scheme.'://'.$host.rtrim($path, '/').'/?play';
                header('Content-Disposition: attachment; filename=listen.m3u');
                header('Content-Type: audio/x-mpegurl; charset=UTF-8');
                echo "#EXTM3U\n#EXTINF:-1,Stardisk PHP Radio\n".$url."\n";
                exit;
            }
            case 'refresh':{
                $fileList = rtrim(self::listFolderFiles(config::getSetting('audioFiles')), "\r\n");
                file_put_contents(__DIR__.'/fileList.txt', $fileList);
                file_put_contents(__DIR__.'/fileCount.txt', $fileList === '' ? 0 : count(explode(PHP_EOL, $fileList)));
                return 'Refresh done';
            }
            case 'test':{
                new test(($_GET['test'] ?? '') === 'convert');
                break;
            }
            default:{
                return 'unknown action';
            }
        }
    }

    private static function listFolderFiles($dir){
        $filelist = '';
        $ffs = scandir($dir);
        unset($ffs[array_search('.', $ffs, true)]);
        unset($ffs[array_search('..', $ffs, true)]);
        // prevent empty ordered elements
        if (count($ffs) < 1) return '';
        foreach($ffs as $ff){
            if(is_dir($dir.'/'.$ff)) $filelist .= self::listFolderFiles($dir.'/'.$ff);
            else{
                $filelist .= $dir.'/'.$ff.PHP_EOL;
            }
        }
        return $filelist;
    }
}

$action = (isset($_GET['action'])) ? $_GET['action'] : '';
if($action){
    if($action !== 'playlist' && !service::isAllowed()){
        http_response_code(403);
        echo 'Access denied';
    }
    else{ echo service::action($action);}
    exit;
}

class test{
    public function __construct($autoConvert = false){
        require_once (__DIR__.'/Mp3File.php');
        set_time_limit(0);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<style>table {border-collapse: collapse;} tr, td{ border: 1px solid #000;}</style>';
        echo '<table>';
        echo '<caption>Checks the saved library index. After adding, deleting or moving files, <a href="service.php?action=refresh">refresh the library</a> first.</caption>';
        echo '<tr>
                <td>File</td>
                <td>Bitrate (bit/s)</td>
                <td>Sampling rate (Hz)</td>
                <td>Channels</td>
                <td>Has cover</td>';
        if($autoConvert){ echo '<td>Convertion status</td>';}
        echo '</tr>';

        $fileList = file(__DIR__.'/fileList.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if($fileList === false){ echo '</table>Cannot read fileList.txt'; return;}
        $wrong = 0; $errors = 0; $errorStyle = 'color: #f00';
        foreach($fileList as $file){
            $displayFile = htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            try{
                $mp3 = new Mp3File($file, true);
                $wrongBitrate = false; $wrongRate = false; $wrongChannels = false; $hasCover = false;
                if($mp3->bitRate != 128000) { $wrongBitrate = true;}
                if($mp3->sampleRate != 44100){ $wrongRate = true;}
                if($mp3->channels != 2){ $wrongChannels = true;}
                if($mp3->hasCover){ $hasCover = true;}

                if($wrongBitrate or $wrongRate or $wrongChannels or $hasCover){
                    $wrong++;
                    echo "<tr>
                            <td>{$displayFile}</td>
                            <td style=\"",($wrongBitrate) ? $errorStyle : '',"\">{$mp3->bitRate}</td>
                            <td style=\"",($wrongRate) ? $errorStyle : '',"\">{$mp3->sampleRate}</td>
                            <td style=\"",($wrongChannels) ? $errorStyle : '',"\">{$mp3->channels}</td>
                            <td style=\"",($hasCover) ? $errorStyle : '',"\">",($hasCover) ? "yes" : "no","</td>";
                    if($autoConvert){
                        $convertStatus = $this->convert($file, (!$wrongBitrate and !$wrongRate and !$wrongChannels and $hasCover));
                        echo "<td>", (($convertStatus) ? 'Success' : 'Failed'), "</td>";
                    }
                    echo "</tr>";
                }
            }
            catch(\Exception $e){
                $errors++;
                $message = !file_exists($file) ? 'File not found. Refresh the library index.' : $e->getMessage();
                $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $columns = $autoConvert ? 5 : 4;
                echo "<tr><td>{$displayFile}</td><td colspan=\"{$columns}\" style=\"{$errorStyle}\">{$message}</td></tr>";
            }
        }
        $total = count($fileList);
        echo "<tr>
                <td>Total Files: {$total}</td>
                <td>Required conversion: {$wrong}</td>
                <td>Errors: {$errors}</td><td colspan=\"2\"></td>",
                ($autoConvert) ? '<td></td>' : '',
            "</tr>
            </table>";
        echo '<a href="service.php?action=test&amp;test=convert">Automatic convertion (requires ffmpeg)</a>';
    }

    private function convert($mp3, $removeOnlyCover = false){
        if($locale = config::getSetting('locale')){ setlocale(LC_ALL, $locale);}
        $pathinfo = pathinfo($mp3);
        $newFileName = $pathinfo['dirname'].'/'.$pathinfo['filename'].'_128'.'.mp3';
        $encodingParams = ($removeOnlyCover) ? '-c:a copy' : '-b:a 128k -ar 44100 -ac 2';
        exec("ffmpeg -i \"$mp3\" $encodingParams -map 0:a \"$newFileName\" -y");

        if(file_exists($newFileName) and filesize($newFileName) > 0){
            unlink($mp3); rename($newFileName, $mp3);
            return true;
        }
        else{ return false;}
    }
}
