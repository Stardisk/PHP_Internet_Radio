<?php
require_once('mp3info.php');
require_once('config.php');
use wapmorgan\Mp3Info\Mp3Info;
const CHUNK_SIZE = 16384;

class radio{
    private $nowPlaying;        //какой файл сейчас воспроизводится
    private $chunkNumber;       //номер чанка этого файла
    private $totalChunks;       //всего чанков в файле
    private $lastUpdate;        //последнее обновление данных в памяти
    private $ID3Title;          //ID3-теги из файла
    private $radioHistory;      //история воспроизведения

    private $fileList;          //список файлов (используется при обновлении медиатеки)
    public $tracks;             //массив файлов (из него выбираются треки для воспроизведения
    public $forceNextTrack;     //трек, заданный из телеграм-бота
    private $isMaster = false;  //данный клиент - мастер (может менять треки при их окончании)
    private $withMetadata = false; //клиент запросил метаданные
    private $chunkCounter = 0;  // общий счетчик чанков (для корректной отправки метаданных внутри потока)

    private $debug = false;

    public function __construct($outside = false){
        $this->nowPlaying = shmop_open(100000, 'c', 0644, 512);
        $this->chunkNumber = shmop_open(100001, 'c', 0644, 4);
        $this->lastUpdate = shmop_open(100002, 'c', 0644, 10);
        $this->ID3Title = shmop_open(100003, 'c', 0644, 512);
        $this->totalChunks = shmop_open(100004, 'c', 0644, 4);
        $this->forceNextTrack = shmop_open(100005, 'c', 0644, 512);
        $this->radioHistory = shmop_open(100006, 'c', 0644, 1024);

        $this->debug = config::getSetting('debug');
        if(isset($_GET['refresh'])){    $this->refreshTrackList();}
        else{
            if(!isset($_GET['play']) and !$outside){
                $_RESPONSE['nowPlaying'] = $this->shmopRead($this->ID3Title);
                $_RESPONSE['History'] = unserialize($this->shmopRead($this->radioHistory));
                if($this->debug){
                    $_RESPONSE['File'] = $this->shmopRead($this->nowPlaying);
                    $_RESPONSE['Chunk'] = $this->shmopRead($this->chunkNumber);
                    $_RESPONSE['TotalChunks'] = $this->shmopRead($this->totalChunks);
                    $_RESPONSE['LastUpdated'] = $this->shmopRead($this->lastUpdate);
                    $_RESPONSE['CurrentTime'] = time();
                }
                if(isset($_GET['json'])){
                    header('Content-type: text/json');
                    echo json_encode($_RESPONSE); exit;
                }
                else{   include('index.phtml');}
            }
            else{
                $incomingHeaders = getallheaders();
                $this->tracks = explode(PHP_EOL,file_get_contents($_SERVER['DOCUMENT_ROOT'].'/radio/fileList.txt'));
                if(!$outside){
                    header('Connection: Keep-Alive');
                    header('Content-type: audio/mpeg');
                    header('Content-Transfer-Encoding: binary');
                    header('Pragma: no-cache');
                    header('icy-name: Stardisk PHP Radio Server');
                    header("icy-br: 128");
                    if(isset($incomingHeaders['Icy-MetaData'])){
                        header ("icy-metaint: ".CHUNK_SIZE);
                        $this->withMetadata = true;
                    }
                    sleep(1);
                    flush();
                    $this->play($this->shmopRead($this->forceNextTrack));
                }
            }
        }
    }

    private function refreshTrackList(){
        $this->listFolderFiles(config::getSetting('audioFiles'));
        file_put_contents('fileList.txt', substr($this->fileList, 0, -1));
        echo 'done'; exit;
    }

    private function listFolderFiles($dir){
        $ffs = scandir($dir);
        unset($ffs[array_search('.', $ffs, true)]);
        unset($ffs[array_search('..', $ffs, true)]);
        // prevent empty ordered elements
        if (count($ffs) < 1) return;
        foreach($ffs as $ff){
            if(is_dir($dir.'/'.$ff)) $this->listFolderFiles($dir.'/'.$ff);
            else{
                $this->fileList .= $dir.'/'.$ff.PHP_EOL;
            }
        }
    }

    private function play($nextTrack = false){
        //если не задан принудительный переход на другой трек
        if(!$nextTrack){
            //проверяем, доиграла ли текущая песня
            $chunkNumber = $this->shmopRead($this->chunkNumber);
            $totalChunks = $this->shmopRead($this->totalChunks);
            $lastUpdate = $this->shmopRead($this->lastUpdate);
            $time = time();
            //осталось меньше 5 чанков до конца
            if($totalChunks - $chunkNumber < 5){
                if($time - $lastUpdate > 10){ $this->isMaster = true;}  //обновлений не было более 10 сек - этот клиент становится мастером
            }
            //больше 5 чанков до конца
            else{
                if($time - $lastUpdate > 60){ $this->isMaster = true;}  //обновлений не было более 60 сек - этот клиент становится мастером
            }
            //если клиент - мастер, то он выбирает трек)
            if($this->isMaster){
                $selected = rand(0, count($this->tracks)-1);
                $selectedMP3 = $this->tracks[$selected];
            }
            //а если не мастер - берем то, что сейчас воспроизводится
            else{   $selectedMP3 = $this->shmopRead($this->nowPlaying);}
        }
        //принудительный переход на трек:
        else{
            $selectedMP3 = $nextTrack;
            $this->shmopWrite($this->forceNextTrack, '');
            $chunkNumber = 0;
        }
        //загружаем данные об этом треке
        $mp3file = new Mp3Info($selectedMP3, true);
        //определяем название песни по ее id3-тегам
        $song = (isset($mp3file->tags['song'])) ? $mp3file->tags['song'] : '';
        $artist = (isset($mp3file->tags['artist'])) ? $mp3file->tags['artist'] : '';
        if($song and $artist){ $title = $artist.' - '.$song;}
        else{
            if($locale = config::getSetting('locale')){ setlocale(LC_ALL, $locale);}
            $title = basename($selectedMP3);
        }
        $this->updateHistory($title);
        $totalChunks = ceil($mp3file->_fileSize / CHUNK_SIZE);              //всего чанков в файле
        $exceedSeconds = floor($mp3file->duration - $totalChunks);          //разница между длительностью и числом чанков
        $oneSecSleepAfterChunkNumber = floor($totalChunks / $exceedSeconds);//определяем после какого чанка (например, после каждого 50-го) вставляем еще один сон 1 сек

        //открываем файл
        $fpOrigin = fopen($selectedMP3, 'rb');
        //если клиент - мастер или принудительная смена трека, обновляем общие данные в памяти
        if($this->isMaster or $nextTrack){
            $this->shmopWrite($this->nowPlaying, $selectedMP3);
            $this->shmopWrite($this->chunkNumber, 0);
            $this->shmopWrite($this->lastUpdate, time());
            $this->shmopWrite($this->ID3Title, $title);
            $this->shmopWrite($this->totalChunks, $totalChunks);
            $chunkNumber = 0;
        }
        //если это просто клиент, то начинаем читать файл с того места, где сейчас слушает мастер
        else{ fseek($fpOrigin, CHUNK_SIZE * $chunkNumber);}
        //посылаем файл по чанкам
        //fread($fpOrigin, CHUNK_SIZE * 4 - 4); $chunkNumber+=4; $this->chunkCounter+=4;*/
        while(!feof($fpOrigin)){
            //читаем чанк
            $buffer = fread($fpOrigin, CHUNK_SIZE);
            //увеличиваем счетчики чанков
            $chunkNumber++; $this->chunkCounter++;
            //если до конца остается совсем немного чанков
            if($totalChunks - $chunkNumber < 5){
                //проверяем его размер
                $bufferLength = strlen($buffer);
                //если размер чанка меньше стандартного, добиваем недостающее нулями, ибо все чанки должны быть одинакового размера
                if($bufferLength < CHUNK_SIZE){  $buffer .= str_repeat("\xff", CHUNK_SIZE - $bufferLength);}
            }
            //посылаем чанк клиенту
            echo $buffer;
            flush();
            //если номер чанка кратен 5, обновляем статус на сервере и посылаем метаданные
            //if($this->chunkCounter % 5 == 0){
                //если статус радио не обновлялся более 5 секунд, теперь этот клиент - мастер
                if(!$this->isMaster and (time() - $this->shmopRead($this->lastUpdate) > 5)){ $this->isMaster = true;}
                //если клиент - мастер -он обновляет общие данные
                if($this->isMaster){
                    $this->shmopWrite($this->chunkNumber, $chunkNumber);
                    $this->shmopWrite($this->lastUpdate, time());
                }
                //если клиент запросил метаданные, посылаем их ему
                if($this->withMetadata){
                    $debug = ($this->debug) ? ' ('.$chunkNumber.'/'.$this->shmopRead($this->totalChunks).', d: '.floor($mp3file->duration).', s: '.$exceedSeconds.', t: '.$this->chunkCounter.')' : '';
                    $this->setTitle($title.$debug);
                }
            //}
            //спим секунду до отправки следующего чанка
            if($chunkNumber > 0 and $chunkNumber % $oneSecSleepAfterChunkNumber == 0){ sleep(2); $exceedSeconds--;}
            else {sleep(1);}
            //если запрошен переход на следующий трек
            if($nextTrack = $this->shmopRead($this->forceNextTrack)){
                //закрываем текущий файл и играем запрошенный
                fclose($fpOrigin);
                $this->play($nextTrack);
                return;
            }
        }
        //дослали файл чанками - закрыли
        fclose($fpOrigin);
        //если сон после воспроизведения трека больше нуля, то спим это время, т.к. кол-во чанков обычно не равно числу секунд файла и отличается на 3-9
        if($exceedSeconds > 0) sleep($exceedSeconds);
        //играем следующий трек
        $this->play();
    }

    //отправка метаданных
    //они выглядят так: [1 байт, означающий размер метаданных, число байт / 16, например для 48 байт он должен быть 0x03]StreamTitle='автор - песня';
    private function setTitle($title){
        $title = "StreamTitle='$title';";
        $titleLength = strlen($title);
        $requiredLength = ceil($titleLength / 16);      //определяем размер метаданных
        $title .= str_repeat("\0", $requiredLength * 16 - $titleLength); //если не хватает до кратного 16 - добиваем нулями
        echo pack('c', $requiredLength).$title;  //пресловутый байт в чистом виде
    }

    private function updateHistory($trackName){
        $history = unserialize($this->shmopRead($this->radioHistory));
        if(!$history){ $history = [];}
        if(end($history) != $trackName)
        $history[] = $trackName;
        if(count($history) > 10){ unset($history[0]);}
        $this->shmopWrite($this->radioHistory, serialize(array_values($history)));
    }

    private function shmopRead($block){
        $size = shmop_size($block);
        return trim(shmop_read($block,0,$size));
    }

    public function shmopWrite($block, $data){
        $size = shmop_size($block);
        $emptyBytes = $size - strlen($data);
        if($emptyBytes > 0){ $data .= str_repeat(' ', $emptyBytes);}
        shmop_write($block, $data, 0);
    }
}