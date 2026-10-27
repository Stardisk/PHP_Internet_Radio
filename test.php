<?php
require_once('config.php');
require_once(__DIR__.'/Mp3File.php');
use Stardisk\Radio\Mp3File;

class test{
    public function __construct($autoConvert = false){
        header('Content-Type: text/html; charset=UTF-8');
        echo '<style>table {border-collapse: collapse;} tr, td{ border: 1px solid #000;}</style>';
        echo '<table>';
        echo '<caption>Checks the saved library index. After adding, deleting or moving files, <a href="?refresh">refresh the library</a> first.</caption>';
        echo '<tr>
                <td>File</td>
                <td>Bitrate (bit/s)</td>
                <td>Sampling rate (Hz)</td>
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
                $wrongBitrate = false; $wrongRate = false; $hasCover = false;
                if($mp3->bitRate != 128000) { $wrongBitrate = true;}
                if($mp3->sampleRate != 44100){ $wrongRate = true;}
                if($mp3->hasCover){ $hasCover = true;}

                if($wrongBitrate or $wrongRate or $hasCover){
                    $wrong++;
                    echo "<tr>
                            <td>{$displayFile}</td>
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
                $message = !file_exists($file) ? 'File not found. Refresh the library index.' : $e->getMessage();
                $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $columns = $autoConvert ? 4 : 3;
                echo "<tr><td>{$displayFile}</td><td colspan=\"{$columns}\" style=\"{$errorStyle}\">{$message}</td></tr>";
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
