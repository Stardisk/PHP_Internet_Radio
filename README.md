# PHP Radio
Streaming audio files like Icecast or Shoutcast, but with PHP only.
Media players are also supported.

### How it works
The script randomly chooses mp3 files from the folder and streams them to all connected clients.
All clients will hear the same.

### Installation
Just put files into any folder on your server.

### Requirements
Only PHP. No database or something else required.

All mp3 files should be converted to bitrate 128 kbps (prevents desynchronization on lower bitrates and stoppings on higher bitrates) and sampling rate 44KHz (prevents distortion while switching tracks). Also I recommend you to remove covers from the ID3 tags (to prevent pauses while script streams cover data).
Run script with GET-parameter **?test** to view all incompatible files.
If you have **ffmpeg** installed, you can click "Automatic conversion" and all incompatible files will be converted automatically

### Configuration
Open **config.php** and change the next parameters:
audioFiles - the path to folder with your audio. All inner folders will be processed. Run script with GET-parameter ?refresh to scan folder.
debug - this parameter will show you debugging info in the interface. Also the script will send debug info in stream metadata after the title of current track in format "123/456, d: 255, s: 4, t: 211", which means "current chunk number / total chunks in current file, **d**uration of current file, **s**leep pauses remained, **t**otal chunks sent to you since your connection".
locale - specify locale for supporting non-standard alphabets (such as cyrillic and etc.)