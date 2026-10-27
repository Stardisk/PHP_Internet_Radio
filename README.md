# PHP Radio
Streaming audio files like Icecast or Shoutcast, but with PHP only.<br/>
Media players are also supported.

### How it works
The script randomly chooses mp3 files from the folder and streams them to all connected clients.<br/>
All clients will hear the same.

### Installation
Just put files into any folder on your server.

### Requirements
Only PHP. FFMPEG is optional, but not necessary.<br/><br/>
No database or something else required.

All mp3 files should be converted to bitrate 128 kbps (prevents desynchronization on lower bitrates and stoppings on higher bitrates) and sampling rate 44KHz (prevents distortion while switching tracks). Also I recommend you to remove covers from the ID3 tags (to prevent pauses while script streams cover data).<br/><br/>
Run script with GET-parameter **?test** to view all incompatible files.<br/><br/>
If you have **ffmpeg** installed, you can click "Automatic conversion" and all incompatible files will be converted automatically

### Configuration
Open **config.php** and change the next parameters:<br/><br/>
`audioFiles` - the path to folder with your audio. All inner folders will be processed. Run script with GET-parameter ?refresh to scan folder.<br/><br/>
`debug` - this parameter will show you debugging info in the interface. Also the script will send debug info in stream metadata after the title of current track in format `123/456, d: 255, s: 4, t: 211`, which means "current chunk number / total chunks in current file, **d**uration of current file, **s**leep pauses remained, **t**otal chunks sent to you since your connection".<br/><br/>
`locale` - specify locale for supporting non-standard alphabets (such as cyrillic and etc.)

## License & Attribution
This project is licensed under the [GNU General Public License v3.0](LICENSE).

### Audio metadata

`Mp3File.php` is the project's own local MPEG audio and ID3 reader. It requires
PHP 7.4 or newer with `mbstring`; FFmpeg is not required for reading metadata.
It reads ID3v1/v1.1 and ID3v2.2/v2.3/v2.4 text and cover information, MPEG
version/layer, bitrate, sampling rate, channel mode and duration. Text is returned
as UTF-8. Legacy ID3v1 defaults to Windows-1251; the optional third constructor
argument can select ISO-8859-1 or UTF-8 instead.
For consecutive leading ID3 tags, later nonempty text values take precedence.

Xing/Info/VBRI frame counts provide duration without reading the whole audio
payload. Without them the reader counts MPEG frames using a buffered scan,
including variable bitrate files. Duration includes encoder delay/padding and
does not include ID3 or APE tags. The parser never prints diagnostics; invalid
files raise `RuntimeException`. Free-format MPEG, compressed/encrypted ID3 frames
and ID3v2.2 whole-tag compression are unsupported. ID3 parsing is limited to
32 MiB per tag; unknown frames are skipped. Cover bytes are not loaded separately
or exposed: the radio's compatibility check only needs cover presence.

Format references: [ID3 specifications](https://id3.org/Developer%20Information).
Run parser regression tests with `php tests/Mp3FileTest.php`.
