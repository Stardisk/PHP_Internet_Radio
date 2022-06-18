<?php
require_once('config.php');
require_once('mp3info.php');
use wapmorgan\Mp3Info\Mp3Info;

class test{
    public function __construct($autoConvert = false){
        header('Content-Type: text/html');
        echo '<style>table {border-collapse: collapse;} tr, td{ border: 1px solid #000;}</style>';
        echo '<table>';
        echo '<tr>
                <td>File</td>
                <td>Bitrate</td>
                <td>Sampling rate</td>
                <td>Has cover</td>';
        if($autoConvert){ echo '<td>Convertion status</td>';}
        echo '</tr>';

        $fileList = explode("\n", file_get_contents('fileList.txt'));
        $wrong = 0; $errors = 0; $errorStyle = 'color: #f00';
        foreach($fileList as $file){
            try{
                $mp3 = new Mp3Info($file, true);
                $wrongBitrate = false; $wrongRate = false; $hasCover = false;
                if($mp3->bitRate != 128000) { $wrongBitrate = true;}
                if($mp3->sampleRate != 44100){ $wrongRate = true;}
                if(count($mp3->coverProperties)>0){ $hasCover = true;}

                if($wrongBitrate or $wrongRate or $hasCover){
                    $wrong++;
                    echo "<tr>
                            <td>{$file}</td>
                            <td style=\"",($wrongBitrate) ? $errorStyle : '',"\">{$mp3->bitRate}</td>
                            <td style=\"",($wrongRate) ? $errorStyle : '',"\">{$mp3->sampleRate}</td>
                            <td style=\"",($hasCover) ? $errorStyle : '',"\">",($hasCover) ? "yes" : "no","</td>";
                    if($autoConvert){
                        $convertStatus = $this->convert($file, (!$wrongBitrate and !$wrongRate and $hasCover));
                        echo "<td>", (($convertStatus) ? 'Success' : 'Failed'), "</td>";
                    }
                    echo "</tr>";
                }
            }
            catch(\Exception $e){
                $errors++;
                echo "<tr><td>{$file}</td><td>???</td><td>???</td></tr>";
            }
        }
        $total = count($fileList);
        echo "<tr>
                <td>Total Files: {$total}</td>
                <td>Required conversion: {$wrong}</td>
                <td>Errors: {$errors}</td>",
                ($autoConvert) ? '<td></td>' : '',
            "</tr>
            </table>";
        echo '<a href="?test=convert">Automatic convertion (requires ffmpeg)</a>';
    }

    private function convert($mp3, $removeOnlyCover = false){
        if($locale = config::getSetting('locale')){ setlocale(LC_ALL, $locale);}
        $pathinfo = pathinfo($mp3);
        $newFileName = $pathinfo['dirname'].'/'.$pathinfo['filename'].'_128'.'.mp3';
        $encodingParams = ($removeOnlyCover) ? '-c:a copy' : '-b:a 128k -ar 44100';
        exec("ffmpeg -i \"$mp3\" $encodingParams -map 0:a \"$newFileName\" -y");

        if(file_exists($newFileName) and filesize($newFileName) > 0){
            unlink($mp3); rename($newFileName, $mp3);
            return true;
        }
        else{ return false;}
    }
}

?>