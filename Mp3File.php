<?php
declare(strict_types=1);

namespace Stardisk\Radio;

use RuntimeException;

/**
 * Local MPEG audio reader for the radio. No subprocesses or output side effects.
 * ID3v1 text defaults to Windows-1251; ID3v2 uses its declared encoding.
 * Duration counts encoded samples (including encoder delay/padding).
 */
final class Mp3File
{
    public $fileName;
    public $fileSize = 0;
    public $audioSize = 0;
    public $audioOffset = 0;
    public $tags = [];
    public $bitRate = 0;
    public $sampleRate = 0;
    public $duration = 0.0;
    public $frameCount = 0;
    public $isVbr = false;
    public $hasCover = false;
    public $coverProperties = [];
    public $mpegVersion = '';
    public $layer = 0;
    public $channels = 0;
    public $channel = '';
    public $durationSource = '';

    private const MAX_TAG_SIZE = 33554432;
    private const TEXT_FRAMES = [
        'TT2' => 'song', 'TIT2' => 'song', 'TP1' => 'artist', 'TPE1' => 'artist',
        'TAL' => 'album', 'TALB' => 'album', 'TYE' => 'year', 'TYER' => 'year',
        'TDRC' => 'year', 'TRK' => 'track', 'TRCK' => 'track',
        'TCO' => 'genre', 'TCON' => 'genre',
    ];

    private $stream;
    private $audioEnd;
    private $legacyEncoding;
    private $buffer = '';
    private $bufferOffset = -1;

    public function __construct(string $fileName, bool $parseTags = true, string $legacyEncoding = 'Windows-1251')
    {
        if (strpos($fileName, '://') !== false || strpos($fileName, "\0") !== false) {
            throw new RuntimeException('Only local audio files are supported.');
        }
        if (!function_exists('mb_convert_encoding')) {
            throw new RuntimeException('The mbstring PHP extension is required.');
        }
        if (!in_array($legacyEncoding, ['Windows-1251', 'ISO-8859-1', 'UTF-8'], true)) {
            throw new RuntimeException('Unsupported ID3v1 encoding.');
        }
        $this->fileName = $fileName;
        $this->legacyEncoding = $legacyEncoding;
        $this->stream = @fopen($fileName, 'rb');
        if ($this->stream === false) {
            throw new RuntimeException('Cannot open audio file: '.$fileName);
        }
        try {
            $stat = @fstat($this->stream);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Audio source must be a regular file.');
            }
            $this->fileSize = (int)$stat['size'];
            $this->audioEnd = $this->fileSize;
            $this->readTrailingTags($parseTags);
            $offset = $this->readLeadingTags($parseTags);
            [$this->audioOffset, $first] = $this->findFirstFrame($offset);
            $this->sampleRate = $first['rate'];
            $this->bitRate = $first['bitrate'];
            $this->mpegVersion = $first['version'];
            $this->layer = $first['layer'];
            $this->channels = $first['channels'];
            $this->channel = $first['channel'];
            $this->audioSize = $this->audioEnd - $this->audioOffset;
            if (!$this->readFrameCount($first)) {
                $this->scanFrames($first);
            }
        } finally {
            fclose($this->stream);
            $this->stream = null;
            $this->buffer = '';
        }
    }

    /** Buffered positional reads keep fallback frame counting inexpensive. */
    private function bytes(int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        if ($offset < 0 || $length < 0 || $offset + $length > $this->fileSize) {
            throw new RuntimeException('Truncated audio file or tag: '.$this->fileName);
        }
        $relative = $offset - $this->bufferOffset;
        if ($relative < 0 || $relative + $length > strlen($this->buffer)) {
            if (@fseek($this->stream, $offset) !== 0) {
                throw new RuntimeException('Cannot seek in audio file.');
            }
            $wanted = min(max(65536, $length), $this->fileSize - $offset);
            $this->buffer = '';
            $this->bufferOffset = $offset;
            while (strlen($this->buffer) < $wanted) {
                $part = @fread($this->stream, $wanted - strlen($this->buffer));
                if ($part === false || $part === '') {
                    throw new RuntimeException('Cannot read audio file: '.$this->fileName);
                }
                $this->buffer .= $part;
            }
            $relative = 0;
        }
        return substr($this->buffer, $relative, $length);
    }

    private static function integer(string $bytes): int
    {
        $value = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        return $value;
    }

    private static function synchsafe(string $bytes): int
    {
        $value = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $byte = ord($bytes[$i]);
            if ($byte & 0x80) {
                throw new RuntimeException('Invalid ID3 synchsafe size.');
            }
            $value = ($value << 7) | $byte;
        }
        return $value;
    }

    private function readTrailingTags(bool $parse): void
    {
        if ($this->audioEnd >= 128 && $this->bytes($this->audioEnd - 128, 3) === 'TAG') {
            $raw = $this->bytes($this->audioEnd - 128, 128);
            $this->audioEnd -= 128;
            if ($parse) {
                foreach (['song' => [3, 30], 'artist' => [33, 30], 'album' => [63, 30], 'year' => [93, 4]] as $key => $range) {
                    $this->tags[$key] = $this->legacyText(substr($raw, $range[0], $range[1]));
                }
                $v11 = $raw[125] === "\0" && $raw[126] !== "\0";
                $this->tags['comment'] = $this->legacyText(substr($raw, 97, $v11 ? 28 : 30));
                if ($v11) {
                    $this->tags['track'] = (string)ord($raw[126]);
                }
                $this->tags['genre'] = (string)ord($raw[127]);
            }
        }
        // Exclude an optional APEv2 tag from MPEG frame counting.
        if ($this->audioEnd >= 32 && $this->bytes($this->audioEnd - 32, 8) === 'APETAGEX') {
            $footer = $this->bytes($this->audioEnd - 32, 32);
            $size = (int)unpack('Vsize', substr($footer, 12, 4))['size'];
            if ($size < 32 || $size > $this->audioEnd) {
                throw new RuntimeException('Invalid APE tag size.');
            }
            $this->audioEnd -= $size;
            if ($this->audioEnd >= 32 && $this->bytes($this->audioEnd - 32, 8) === 'APETAGEX') {
                $this->audioEnd -= 32;
            }
        }
    }

    private function readLeadingTags(bool $parse): int
    {
        $offset = 0;
        while ($offset + 10 <= $this->audioEnd && $this->bytes($offset, 3) === 'ID3') {
            $header = $this->bytes($offset, 10);
            $version = ord($header[3]);
            $flags = ord($header[5]);
            $size = self::synchsafe(substr($header, 6, 4));
            $footerSize = $version === 4 && ($flags & 0x10) ? 10 : 0;
            if ($offset + 10 + $size + $footerSize > $this->audioEnd) {
                throw new RuntimeException('ID3 tag extends beyond the audio file.');
            }
            if ($parse && $version >= 2 && $version <= 4) {
                if ($size > self::MAX_TAG_SIZE) {
                    throw new RuntimeException('ID3 tag exceeds the 32 MiB parsing limit.');
                }
                $this->readId3Frames($this->bytes($offset + 10, $size), $version, $flags);
            }
            if ($footerSize && substr($this->bytes($offset + 10 + $size, 10), 0, 3) !== '3DI') {
                throw new RuntimeException('Missing ID3v2.4 footer.');
            }
            $offset += 10 + $size + $footerSize;
        }
        return $offset;
    }

    private function readId3Frames(string $body, int $version, int $tagFlags): void
    {
        if ($version === 2 && ($tagFlags & 0x40)) {
            return; // ID3v2.2 whole-tag compression is deliberately unsupported.
        }
        if ($version < 4 && ($tagFlags & 0x80)) {
            $body = str_replace("\xff\0", "\xff", $body);
        }
        $end = strlen($body);
        $offset = 0;
        $commentSeen = false;
        if ($version >= 3 && ($tagFlags & 0x40)) {
            if ($end < 4) {
                throw new RuntimeException('Truncated ID3 extended header.');
            }
            $offset = $version === 4 ? self::synchsafe(substr($body, 0, 4)) : 4 + self::integer(substr($body, 0, 4));
            if ($offset < ($version === 4 ? 6 : 10) || $offset > $end) {
                throw new RuntimeException('Invalid ID3 extended header size.');
            }
        }
        $headerSize = $version === 2 ? 6 : 10;
        while ($offset + $headerSize <= $end) {
            $idSize = $version === 2 ? 3 : 4;
            $id = substr($body, $offset, $idSize);
            if ($id[0] === "\0") {
                break; // Padding.
            }
            if (!preg_match('/^[A-Z0-9]+$/D', $id)) {
                throw new RuntimeException('Invalid ID3 frame identifier.');
            }
            $rawSize = substr($body, $offset + $idSize, $version === 2 ? 3 : 4);
            $size = $version === 4 ? self::synchsafe($rawSize) : self::integer($rawSize);
            $format = $version === 2 ? 0 : ord($body[$offset + 9]);
            $offset += $headerSize;
            if ($size > $end - $offset) {
                throw new RuntimeException('ID3 frame extends beyond its tag.');
            }
            $data = substr($body, $offset, $size);
            $offset += $size;
            // Skip compressed/encrypted frames, whose payload is not plain text.
            if (($version === 3 && ($format & 0xc0)) || ($version === 4 && ($format & 0x0c))) {
                continue;
            }
            if ($version === 4 && (($tagFlags & 0x80) || ($format & 0x02))) {
                $data = str_replace("\xff\0", "\xff", $data);
            }
            $prefix = ($version === 3 && ($format & 0x20)) || ($version === 4 && ($format & 0x40)) ? 1 : 0;
            if ($version === 4 && ($format & 0x01)) {
                $prefix += 4; // Data length indicator (not a second frame size).
            }
            if ($prefix > strlen($data)) {
                throw new RuntimeException('Truncated ID3 frame prefix.');
            }
            $data = substr($data, $prefix);
            if ($data === '') {
                continue;
            }
            if (isset(self::TEXT_FRAMES[$id])) {
                $value = self::decodeText(substr($data, 1), ord($data[0]));
                if ($value !== '') {
                    $this->tags[self::TEXT_FRAMES[$id]] = $value;
                }
            } elseif ($id === 'COMM' || $id === 'COM') {
                if (strlen($data) < 4) {
                    continue;
                }
                $encoding = ord($data[0]);
                [$description, $text] = self::splitText(substr($data, 4), $encoding);
                // Some UTF-16 writers put a BOM only in the description.
                if ($encoding === 1 && substr($description, 0, 2) === "\xff\xfe" && substr($text, 0, 2) !== "\xff\xfe" && substr($text, 0, 2) !== "\xfe\xff") {
                    $text = "\xff\xfe".$text;
                }
                $value = self::decodeText($text, $encoding);
                if (!$commentSeen || $this->tags['comment'] === '') {
                    $this->tags['comment'] = $value;
                }
                $commentSeen = true;
            } elseif ($id === 'APIC' || $id === 'PIC') {
                $this->readPicture($data, $id === 'PIC');
            }
        }
    }

    private function legacyText(string $text): string
    {
        $text = rtrim($text, "\0 ");
        return self::cleanText(mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', $this->legacyEncoding));
    }

    private static function decodeText(string $text, int $encoding): string
    {
        if ($encoding < 0 || $encoding > 3) {
            return '';
        }
        $source = ['ISO-8859-1', 'UTF-16BE', 'UTF-16BE', 'UTF-8'][$encoding];
        if ($encoding === 1) {
            $bom = substr($text, 0, 2);
            if ($bom === "\xff\xfe" || $bom === "\xfe\xff") {
                $source = $bom === "\xff\xfe" ? 'UTF-16LE' : 'UTF-16BE';
                $text = substr($text, 2);
            }
        }
        return self::cleanText(mb_convert_encoding($text, 'UTF-8', $source));
    }

    private static function cleanText(string $text): string
    {
        $text = trim(str_replace("\xef\xbb\xbf", '', $text), "\0 \t\n\r\x0b");
        return implode(' / ', explode("\0", $text));
    }

    /** Split on an encoding-aligned terminator, including empty strings. */
    private static function splitText(string $data, int $encoding): array
    {
        $width = $encoding === 1 || $encoding === 2 ? 2 : 1;
        $zero = str_repeat("\0", $width);
        for ($i = 0, $end = strlen($data); $i + $width <= $end; $i += $width) {
            if (substr($data, $i, $width) === $zero) {
                return [substr($data, 0, $i), substr($data, $i + $width)];
            }
        }
        return [$data, ''];
    }

    private function readPicture(string $data, bool $v22): void
    {
        $encoding = ord($data[0]);
        if ($v22) {
            if (strlen($data) < 5) {
                return;
            }
            $format = strtoupper(substr($data, 1, 3));
            $mime = $format === 'PNG' ? 'image/png' : ($format === 'JPG' ? 'image/jpeg' : $format);
            $remaining = substr($data, 4);
        } else {
            [$mime, $remaining] = self::splitText(substr($data, 1), 0);
        }
        if ($remaining === '') {
            return;
        }
        $type = ord($remaining[0]);
        [$description, $picture] = self::splitText(substr($remaining, 1), $encoding);
        $this->hasCover = true;
        if (!$this->coverProperties || $type === 3) {
            $this->coverProperties = ['mime_type' => $mime, 'picture_type' => $type,
                'description' => self::decodeText($description, $encoding), 'size' => strlen($picture)];
        }
    }

    private static function frameHeader(string $raw): ?array
    {
        if (strlen($raw) !== 4 || ord($raw[0]) !== 255 || (ord($raw[1]) & 0xe0) !== 0xe0) {
            return null;
        }
        $b = ord($raw[1]); $c = ord($raw[2]); $d = ord($raw[3]);
        $versionBits = ($b >> 3) & 3;
        $layerBits = ($b >> 1) & 3;
        $bitrateIndex = $c >> 4;
        $rateIndex = ($c >> 2) & 3;
        if ($versionBits === 1 || !$layerBits || !$bitrateIndex || $bitrateIndex === 15 || $rateIndex === 3 || ($d & 3) === 2) {
            return null; // Free-format MPEG (bitrate index 0) is unsupported.
        }
        $version = [0 => '2.5', 2 => '2', 3 => '1'][$versionBits];
        $layer = 4 - $layerBits;
        $tables = $version === '1' ? [
            1 => [32,64,96,128,160,192,224,256,288,320,352,384,416,448],
            2 => [32,48,56,64,80,96,112,128,160,192,224,256,320,384],
            3 => [32,40,48,56,64,80,96,112,128,160,192,224,256,320],
        ] : [
            1 => [32,48,56,64,80,96,112,128,144,160,176,192,224,256],
            2 => [8,16,24,32,40,48,56,64,80,96,112,128,144,160],
            3 => [8,16,24,32,40,48,56,64,80,96,112,128,144,160],
        ];
        $bitrate = $tables[$layer][$bitrateIndex - 1] * 1000;
        $rate = intdiv([44100,48000,32000][$rateIndex], $version === '1' ? 1 : ($version === '2' ? 2 : 4));
        $padding = ($c >> 1) & 1;
        $samples = $layer === 1 ? 384 : ($layer === 3 && $version !== '1' ? 576 : 1152);
        $size = $layer === 1 ? (intdiv(12 * $bitrate, $rate) + $padding) * 4
            : intdiv(($samples === 576 ? 72 : 144) * $bitrate, $rate) + $padding;
        $mode = $d >> 6;
        return ['version' => $version, 'layer' => $layer, 'bitrate' => $bitrate,
            'rate' => $rate, 'samples' => $samples, 'size' => $size,
            'channels' => $mode === 3 ? 1 : 2,
            'channel' => ['stereo','joint_stereo','dual_mono','mono'][$mode]];
    }

    private static function compatible(array $a, array $b): bool
    {
        return $a['version'] === $b['version'] && $a['layer'] === $b['layer'] && $a['rate'] === $b['rate'];
    }

    private function findFirstFrame(int $start): array
    {
        $limit = min($this->audioEnd - 4, $start + 1048576);
        for ($offset = $start; $offset <= $limit; $offset++) {
            $frame = self::frameHeader($this->bytes($offset, 4));
            if ($frame === null || $offset + $frame['size'] > $this->audioEnd) {
                continue;
            }
            $next = $offset + $frame['size'];
            if ($next === $this->audioEnd) {
                return [$offset, $frame];
            }
            if ($next + 4 <= $this->audioEnd) {
                $second = self::frameHeader($this->bytes($next, 4));
                if ($second !== null && self::compatible($frame, $second) && $next + $second['size'] <= $this->audioEnd) {
                    return [$offset, $frame];
                }
            }
        }
        throw new RuntimeException('No complete MPEG audio frames found: '.$this->fileName);
    }

    private function readFrameCount(array $first): bool
    {
        if ($first['layer'] !== 3) {
            return false;
        }
        $raw = $this->bytes($this->audioOffset, $first['size']);
        $side = $first['version'] === '1' ? ($first['channels'] === 1 ? 17 : 32) : ($first['channels'] === 1 ? 9 : 17);
        $offset = 4 + $side;
        $marker = substr($raw, $offset, 4);
        $frames = 0;
        if (($marker === 'Xing' || $marker === 'Info') && strlen($raw) >= $offset + 12) {
            $flags = self::integer(substr($raw, $offset + 4, 4));
            if ($flags & 1) {
                $frames = self::integer(substr($raw, $offset + 8, 4));
                $this->durationSource = strtolower($marker);
                $this->isVbr = $marker === 'Xing';
            }
        } elseif (substr($raw, 36, 4) === 'VBRI' && strlen($raw) >= 54 && self::integer(substr($raw, 40, 2)) === 1) {
            $frames = self::integer(substr($raw, 50, 4));
            $this->durationSource = 'vbri';
            $this->isVbr = true;
        }
        if ($frames < 1 || $frames > intdiv($this->audioSize, 24)) {
            return false;
        }
        $this->frameCount = $frames;
        $this->duration = $frames * $first['samples'] / $first['rate'];
        if ($this->isVbr) {
            $this->bitRate = (int)round(max(0, $this->audioSize - $first['size']) * 8 / $this->duration);
        }
        return true;
    }

    private function scanFrames(array $first): void
    {
        $offset = $this->audioOffset;
        $samples = 0;
        $size = 0;
        $this->isVbr = false;
        while ($offset + 4 <= $this->audioEnd) {
            $frame = self::frameHeader($this->bytes($offset, 4));
            if ($frame === null || !self::compatible($first, $frame) || $offset + $frame['size'] > $this->audioEnd) {
                // Ignore a damaged/truncated tail; never count its bytes as audio.
                break;
            }
            $this->isVbr = $this->isVbr || $frame['bitrate'] !== $first['bitrate'];
            $this->frameCount++;
            $samples += $frame['samples'];
            $size += $frame['size'];
            $offset += $frame['size'];
        }
        $this->audioSize = $size;
        $this->duration = $samples / $this->sampleRate;
        $this->durationSource = 'frames';
        if ($this->isVbr) {
            $this->bitRate = (int)round($size * 8 / $this->duration);
        }
    }
}
