# PHP Radio

Stream audio files like Icecast or Shoutcast, using PHP. Listen in a browser or a
media player. No database is required.

## How it works

The script randomly chooses MP3 files from the music library. Connected clients
share the current track and playback position through shared memory, so listeners
hear the same radio program. A new listener joins the current track rather than
starting it from the beginning. Player buffering can cause a small delay between
listeners.

## Requirements

- A web server with PHP 7.4 or newer.
- The PHP `mbstring` and `shmop` extensions, with System V shared memory support
  on the server.
- FFmpeg is optional and is only needed for automatic audio conversion. Its
  executable must be available to PHP through `PATH`, and PHP must allow `exec()`.

## Installation

1. Put the project files into a directory served by your web server.
2. Edit `config.php`: set the music directory and add your administration IP
   address to `service_ips`.
3. Give the web server user read access to the music directory and permission to
   create and update `fileList.txt` and `fileCount.txt` in the project directory.
   Automatic conversion also requires write access to the music files and their
   directories.
4. Open `service.php?action=refresh` from an allowed IP address to build the
   library index.
5. Check audio compatibility using `service.php?action=test`, then open the
   project directory in your browser to listen.

All addresses below are relative to the project directory. Refresh the library
after adding, removing or moving music files.

## Audio compatibility

Use MP3 files with the following audio settings:

- Bitrate: **128000 bit/s**.
- Sampling rate: **44100 Hz**.
- Channels: **2 (stereo)**.

These settings keep stream timing consistent and help avoid playback problems
when switching tracks. Mono-to-stereo transitions can break audio playback in
some VLC versions. Removing embedded covers is also recommended because cover
data is otherwise sent as part of the file and can cause pauses.

Open `service.php?action=test` or click **Test mp3 files** in the web interface
to list incompatible or unreadable files. Values are displayed in bit/s and Hz.

If FFmpeg is installed, use the automatic conversion link, or open
`service.php?action=test&test=convert`. It converts incompatible audio to
128000 bit/s, 44100 Hz and stereo, and removes embedded covers. If a cover is
the only incompatibility, the audio is copied without re-encoding. Successful
conversion replaces the original file; keep backups if you need the originals.

## Configuration

Edit the settings in `config.php`:

| Setting | Description |
| --- | --- |
| `audioFiles` | Absolute path to the music directory. Subdirectories are scanned recursively. Refresh the index after changing the library or this path. |
| `debug` | Show debugging information in the web interface and append it to ICY track metadata. |
| `locale` | Server locale used for filenames containing non-ASCII characters, such as Cyrillic. The locale must be available on the server. |
| `metadata` | Enable ICY metadata for players that request it. Set to `false` to send audio without ICY metadata. |
| `radioName` | Station name shown on the web page, in downloaded playlists and in the `icy-name` response header. |
| `logo` | Path to the station logo relative to the project directory, for example `logo.png` or `images/logo.png`. Included in XSPF playlists; use an empty string to omit it. |
| `service_ips` | IP addresses allowed to refresh or check the library, run conversion and clear shared memory. Compared against `REMOTE_ADDR`. An empty array allows these actions from any IP address. |

Listening and downloading playlists do not require an address in `service_ips`.

With `debug` enabled, track metadata includes values such as
`123/456, d: 255, s: 4, t: 211`:

- `123/456`: current chunk number and total chunks in the current file.
- `d`: current file duration in seconds.
- `s`: remaining additional sleep pauses used for timing adjustment.
- `t`: total chunks sent over the current listener connection.

## Listening and playlists

Use the web page's **Play** button and volume slider, or open the stream address
`?play` in a media player.

Playlist downloads are also available on the web page:

- M3U: `service.php?action=playlist&type=m3u`.
- XSPF: `service.php?action=playlist&type=xspf`.

`service.php?action=playlist` defaults to M3U. Both playlists point to the live
stream. XSPF additionally includes the configured station logo. Support for XSPF
and its artwork depends on the player; loading network artwork may need to be
enabled in the player's settings. The logo is downloaded separately, so opening
the stream URL directly does not supply the logo from the playlist.

Players may cache the logo. If it changes, use a new image filename and download
and reopen the updated playlist.

### ICY metadata

When `metadata` is enabled and a player requests ICY metadata, the stream includes
the current artist and song title, or the filename when those tags are missing.
The player must support ICY to display track updates.

VLC 3.x's HTTPS input path does not handle ICY metadata like its HTTP input path:
audio can play over HTTPS without track title updates. Enabling `metadata` in
the server configuration does not override this client limitation.

## Shared memory

The radio uses shared memory to store the current track, position and history.
These values persist between PHP requests. Independent radio installations on
the same server need different shared memory keys in `radio.php`.

To reset the radio state, disconnect all listeners and open
`service.php?action=clear_memory` from an allowed IP address. The segments are
recreated on the next request. This can also be needed after changing segment
sizes in the code. Disconnect listeners first so old streaming requests do not
continue using the previous segments while new requests create replacements.

## Audio metadata

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

## License & Attribution

This project is licensed under the [GNU General Public License v3.0](LICENSE).
