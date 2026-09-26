<?php

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;

use function Castor\finder;
use function Castor\io;

// ──────────────────────────────────────────────────────────────────────────────
// iPod management — libgpod via PHP FFI (libgpod 0.8.3 / 64-bit Linux)
// ──────────────────────────────────────────────────────────────────────────────

function ipodFfi(): \FFI
{
    static $ffi = null;
    if ($ffi !== null) {
        return $ffi;
    }

    $lib = '/usr/lib/x86_64-linux-gnu/libgpod.so.4';
    if (!file_exists($lib)) {
        io()->error('libgpod not found. Install with: sudo apt install libgpod4');
        exit(1);
    }

    // Partial struct declarations — only the fields we need.
    //
    // Itdb_Track layout on x86-64 (all pointer fields are 8 bytes):
    //   offset  0: itdb*       offset  8: title*
    //   offset 16: ipod_path*  offset 24: album*
    //   offset 32: artist*
    //
    // Itdb_iTunesDB: tracks GList* is the first field (offset 0).
    // TrackList.data is void* in the real GList; typing it as Itdb_Track* avoids casts.
    $ffi = FFI::cdef(<<<'C'
        typedef struct {
            void *itdb;
            char *title;
            char *ipod_path;
            char *album;
            char *artist;
        } Itdb_Track;

        typedef struct _TrackList {
            Itdb_Track        *data;
            struct _TrackList *next;
            struct _TrackList *prev;
        } TrackList;

        typedef struct {
            TrackList *tracks;
        } Itdb_DB;

        typedef struct { void *_unused; } Itdb_Playlist;

        // Read
        Itdb_DB      *itdb_parse(const char *mountpoint, void *error);
        void          itdb_free(Itdb_DB *itdb);

        // Write
        Itdb_Track   *itdb_track_new(void);
        void          itdb_track_add(Itdb_DB *itdb, Itdb_Track *track, int pos);
        void          itdb_track_remove(Itdb_Track *track);
        void          itdb_track_unlink(Itdb_Track *track);
        int           itdb_cp_track_to_ipod(Itdb_Track *track, const char *filename, void *error);
        int           itdb_write(Itdb_DB *itdb, void *error);

        // Playlists
        Itdb_Playlist *itdb_playlist_mpl(Itdb_DB *itdb);
        void           itdb_playlist_add_track(Itdb_Playlist *playlist, Itdb_Track *track, int pos);
    C, $lib);

    return $ffi;
}

function ipodOpenDb(string $mount, \FFI $ffi): \FFI\CData
{
    if (!is_dir($mount)) {
        io()->error(sprintf('Mount point "%s" does not exist — did you run: ifuse %s', $mount, $mount));
        exit(1);
    }

    $itdb = $ffi->itdb_parse($mount, null);
    if ($itdb === null) {
        io()->error('Could not parse iTunesDB — is the iPod mounted and the database intact?');
        exit(1);
    }

    return $itdb;
}

function ipodIterTracks(\FFI\CData $itdb): \Generator
{
    $node = $itdb->tracks;
    while ($node !== null) {
        if ($node->data !== null) {
            yield $node->data;
        }
        $node = $node->next;
    }
}

function ipodStr(\FFI\CData|null $charPtr): string
{
    return $charPtr !== null ? \FFI::string($charPtr) : '';
}

#[AsTask(namespace: 'ipod', name: 'list', description: 'List tracks on the iPod')]
function ipodList(
    #[AsOption(description: 'Filter by artist (case-insensitive)')]
    ?string $artist = null,
    #[AsOption(description: 'Filter by album (case-insensitive)')]
    ?string $album = null,
    #[AsOption(description: 'iPod mount point')]
    string $mount = '/media/florian/IPOD',
): void {
    $ffi = ipodFfi();
    $itdb = ipodOpenDb($mount, $ffi);

    $tracks = [];
    foreach (ipodIterTracks($itdb) as $track) {
        $trackArtist = ipodStr($track->artist);
        $trackAlbum  = ipodStr($track->album);
        $trackTitle  = ipodStr($track->title);

        if ($artist !== null && strcasecmp($trackArtist, $artist) !== 0) {
            continue;
        }
        if ($album !== null && strcasecmp($trackAlbum, $album) !== 0) {
            continue;
        }

        $tracks[] = [$trackArtist, $trackAlbum, $trackTitle];
    }

    $ffi->itdb_free($itdb);

    usort($tracks, fn ($a, $b) => strcasecmp($a[0], $b[0]) ?: strcasecmp($a[1], $b[1]) ?: strcasecmp($a[2], $b[2]));

    io()->table(
        ['Artist', 'Album', 'Title'],
        array_map(fn ($t) => [$t[0] ?: '?', $t[1] ?: '?', $t[2] ?: '?'], $tracks),
    );

    io()->writeln(sprintf('%d track(s)', count($tracks)));
}

// ──────────────────────────────────────────────────────────────────────────────
// Sync helpers
// ──────────────────────────────────────────────────────────────────────────────

function ipodTrackKey(string $artist, string $album, string $title): string
{
    return strtolower($artist . "\x00" . $album . "\x00" . $title);
}

/**
 * Creates a C string allocated outside PHP's ownership so libgpod can safely
 * g_free() it. FFI::new with owned=false means PHP will not call free() on GC.
 */
function ipodCStr(\FFI $ffi, string $value): \FFI\CData
{
    $len = strlen($value);
    $buf = $ffi->new('char[' . ($len + 1) . ']', owned: false);
    \FFI::memcpy($buf, $value, $len);

    return $ffi->cast('char *', $buf);
}

/** @return array<string, array{artist: string, album: string, title: string, path: string}> */
function ipodBuildSourceIndex(string $source): array
{
    $files = finder()
        ->files()
        ->in($source)
        ->name('/\.(mp3|flac|ogg|m4a|wav|aac)$/i');

    $tracks  = [];
    $skipped = 0;

    io()->progressStart($files->count());

    foreach ($files as $file) {
        $tags   = getAudioTags($file);
        $artist = trim($tags['artist'] ?? '');
        $album  = trim($tags['album'] ?? '');
        $title  = trim($tags['title'] ?? '');

        io()->progressAdvance();

        if ($artist === '' || $album === '' || $title === '') {
            $skipped++;
            continue;
        }

        $tracks[ipodTrackKey($artist, $album, $title)] = [
            'artist' => $artist,
            'album'  => $album,
            'title'  => $title,
            'path'   => $file->getRealPath(),
        ];
    }

    io()->progressFinish();

    if ($skipped > 0) {
        io()->warning(sprintf('%d file(s) skipped — missing artist/album/title tag.', $skipped));
    }

    return $tracks;
}

/** @return array<string, array{track: \FFI\CData, artist: string, album: string, title: string}> */
function ipodBuildIpodIndex(\FFI\CData $itdb): array
{
    $tracks = [];

    foreach (ipodIterTracks($itdb) as $track) {
        $artist = ipodStr($track->artist);
        $album  = ipodStr($track->album);
        $title  = ipodStr($track->title);

        if ($artist === '' || $album === '' || $title === '') {
            continue;
        }

        $tracks[ipodTrackKey($artist, $album, $title)] = [
            'track'  => $track,
            'artist' => $artist,
            'album'  => $album,
            'title'  => $title,
        ];
    }

    return $tracks;
}

#[AsTask(namespace: 'ipod', name: 'sync', description: 'Sync a source directory to the iPod (add missing, remove extra)')]
function ipodSync(
    #[AsArgument(description: 'Source music directory organized as Artist/Album/file.ext')]
    string $source,
    #[AsOption(mode: InputOption::VALUE_NONE, description: 'Preview changes without writing')]
    bool $dryRun,
    #[AsOption(mode: InputOption::VALUE_NONE, description: 'Skip removing tracks not present in source')]
    bool $noDelete,
    #[AsOption(description: 'iPod mount point')]
    string $mount = '/media/florian/IPOD',
): void {
    if (!is_dir($source) || !is_readable($source)) {
        io()->error(sprintf('Source "%s" does not exist or is not readable.', $source));

        return;
    }

    $ffi  = ipodFfi();
    $itdb = ipodOpenDb($mount, $ffi);

    io()->section('Scanning source directory...');
    $sourceTracks = ipodBuildSourceIndex($source);
    io()->writeln(sprintf('%d track(s) in source.', count($sourceTracks)));

    io()->section('Reading iPod database...');
    $ipodTracks = ipodBuildIpodIndex($itdb);
    io()->writeln(sprintf('%d track(s) on iPod.', count($ipodTracks)));

    $toAdd    = array_diff_key($sourceTracks, $ipodTracks);
    $toRemove = $noDelete ? [] : array_diff_key($ipodTracks, $sourceTracks);

    if ($toAdd === [] && $toRemove === []) {
        io()->success('Already in sync.');
        $ffi->itdb_free($itdb);

        return;
    }

    io()->writeln(sprintf('To add: %d | To remove: %d', count($toAdd), count($toRemove)));

    if ($dryRun) {
        foreach ($toRemove as ['artist' => $a, 'album' => $al, 'title' => $t]) {
            io()->writeln(sprintf('<fg=red>- %s / %s / %s</>', $a, $al, $t));
        }
        foreach ($toAdd as ['artist' => $a, 'album' => $al, 'title' => $t]) {
            io()->writeln(sprintf('<fg=green>+ %s / %s / %s</>', $a, $al, $t));
        }
        io()->success('Dry run complete — no changes made.');
        $ffi->itdb_free($itdb);

        return;
    }

    if (!io()->confirm(sprintf('Proceed? (%d to add, %d to remove)', count($toAdd), count($toRemove)))) {
        $ffi->itdb_free($itdb);

        return;
    }

    // Remove
    if ($toRemove !== []) {
        io()->section('Removing tracks...');
        io()->progressStart(count($toRemove));

        foreach ($toRemove as ['track' => $track]) {
            $ffi->itdb_track_unlink($track);
            io()->progressAdvance();
        }

        io()->progressFinish();
    }

    // // Add
    // if ($toAdd !== []) {
    //     io()->section('Adding tracks...');
    //     $mpl    = $ffi->itdb_playlist_mpl($itdb);
    //     $failed = 0;
    //
    //     io()->progressStart(count($toAdd));
    //
    //     foreach ($toAdd as ['artist' => $artist, 'album' => $album, 'title' => $title, 'path' => $path]) {
    //         $track = $ffi->itdb_track_new();
    //         $track->title  = ipodCStr($ffi, $title);
    //         $track->artist = ipodCStr($ffi, $artist);
    //         $track->album  = ipodCStr($ffi, $album);
    //
    //         // itdb_track_add sets track->itdb, which itdb_cp_track_to_ipod needs
    //         $ffi->itdb_track_add($itdb, $track, -1);
    //
    //         if (!$ffi->itdb_cp_track_to_ipod($track, $path, null)) {
    //             $ffi->itdb_track_remove($track);
    //             $failed++;
    //             io()->progressAdvance();
    //             continue;
    //         }
    //
    //         $ffi->itdb_playlist_add_track($mpl, $track, -1);
    //         io()->progressAdvance();
    //     }
    //
    //     io()->progressFinish();
    //
    //     if ($failed > 0) {
    //         io()->warning(sprintf('%d track(s) could not be copied to the iPod.', $failed));
    //     }
    // }

    io()->section('Writing iPod database...');

    if (!$ffi->itdb_write($itdb, null)) {
        io()->error('Failed to write the iPod database — changes may be lost.');
    } else {
        io()->success('Sync complete.');
    }

    $ffi->itdb_free($itdb);
}
