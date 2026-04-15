<?php

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

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

        Itdb_DB *itdb_parse(const char *mountpoint, void *error);
        void     itdb_free(Itdb_DB *itdb);
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
