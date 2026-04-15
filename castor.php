<?php

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;

use function Castor\context;
use function Castor\finder;
use function Castor\io;
use function Castor\capture;
use function Castor\run;

#[AsTask(namespace: 'music', name: 'organize', description: 'Organize music files into a target directory by artist and album')]
function organize(
    #[AsArgument(description: 'Directory containing the music files to organize')]
    string $sourceDirectory,
    #[AsArgument(description: 'Directory where organized files will be written')]
    string $targetDirectory,
    #[AsOption(mode: InputOption::VALUE_NONE, description: 'Move files instead of copying them')]
    bool $move,
    #[AsOption(mode: InputOption::VALUE_NONE, description: 'Preview changes without writing anything')]
    bool $dryRun,
): void {
    if (!is_dir($sourceDirectory) || !is_readable($sourceDirectory)) {
        io()->error(sprintf('Source directory "%s" does not exist or is not readable.', $sourceDirectory));

        return;
    }

    if (!$dryRun && (!is_writable($targetDirectory))) {
        io()->error(sprintf('Target directory "%s" does not exist or is not writable.', $targetDirectory));

        return;
    }

    io()->title(sprintf('Organizing music files%s', $dryRun ? ' (dry run)' : ''));

    $targetDirectory = rtrim($targetDirectory, '/');

    $files = finder()
        ->files()
        ->in($sourceDirectory)
        ->name('/\.(mp3|flac|ogg|m4a|wav|aac)$/i');

    io()->progressStart($files->count());
    foreach ($files as $file) {
        handleFile($file, $targetDirectory, $move, $dryRun);

        io()->progressAdvance();
    }
    io()->progressFinish();

    io()->success('Done');
}

function handleFile(\SplFileInfo $file, string $targetDirectory, bool $move, bool $dryRun): void
{
    $tags = getAudioTags($file);

    if ($tags === null) {
        io()->warning(sprintf('Skipping "%s": could not read metadata.', $file->getPathname()));

        return;
    }

    $album = $tags['album'] ?? null;
    $artist = $tags['artist'] ?? null;

    if ($album === null || $artist === null) {
        io()->warning(sprintf('Skipping "%s": missing artist or album tag.', $file->getPathname()));

        return;
    }

    $destDir = sprintf('%s/%s/%s', $targetDirectory, sanitizePath($artist), sanitizePath($album));
    $dest = $destDir . '/' . $file->getFilename();

    if (file_exists($dest)) {
        io()->warning(sprintf('Skipping "%s": destination already exists.', $file->getPathname()));

        return;
    }

    if ($dryRun) {
        io()->writeln(sprintf('%s → %s', $file->getPathname(), $dest));

        return;
    }

    if (!is_dir($destDir)) {
        mkdir($destDir, recursive: true);
    }

    if ($move) {
        if (!rename($file->getRealpath(), $dest)) {
            io()->warning(sprintf('Failed to move "%s".', $file->getPathname()));
        }
    } else {
        copy($file->getRealpath(), $dest);
    }
}

function getAudioTags(\SplFileInfo $file): ?array
{
    $result = capture([
        'ffprobe',
        '-v', 'quiet',
        '-print_format', 'json',
        '-show_format',
        $file->getRealpath(),
    ]);

    $metadata = json_decode($result, true);

    if (!isset($metadata['format'])) {
        return null;
    }

    return $metadata['format']['tags'] ?? [];
}

function sanitizePath(string $value): string
{
    return trim(preg_replace('/[\/\\\:*?"<>|]/', '_', $value));
}

#[AsTask(namespace: 'music', name: 'extract-covers', description: 'Extract album art from music files and save as cover.jpg in each album directory')]
function extractCovers(
    #[AsArgument(description: 'Root music directory')]
    string $directory,
    #[AsOption(mode: InputOption::VALUE_NONE, description: 'Preview changes without writing anything')]
    bool $dryRun,
    #[AsOption(mode: InputOption::VALUE_NONE, description: 'Overwrite existing cover images')]
    bool $force,
): void {
    if (!is_dir($directory) || !is_readable($directory)) {
        io()->error(sprintf('Directory "%s" does not exist or is not readable.', $directory));

        return;
    }

    $files = finder()
        ->files()
        ->in($directory)
        ->name('/\.(mp3|flac|ogg|m4a|wav|aac)$/i')
        ->sortByName();

    $albumFirstFiles = [];
    foreach ($files as $file) {
        $albumFirstFiles[$file->getPath()] ??= $file;
    }

    io()->title(sprintf('Extracting album art%s', $dryRun ? ' (dry run)' : ''));
    io()->progressStart(count($albumFirstFiles));

    foreach ($albumFirstFiles as $dir => $firstFile) {
        $coverPath = $dir . '/cover.jpg';

        if (!$force && file_exists($coverPath)) {
            io()->progressAdvance();
            continue;
        }

        if ($dryRun) {
            io()->writeln(sprintf('%s → %s', $firstFile->getPathname(), $coverPath));
            io()->progressAdvance();
            continue;
        }

        if (!extractCoverArt($firstFile, $coverPath)) {
            io()->warning(sprintf('No cover art found in "%s".', $firstFile->getPathname()));
        }

        io()->progressAdvance();
    }

    io()->progressFinish();
    io()->success('Done');
}

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

function extractCoverArt(\SplFileInfo $file, string $destination): bool
{
    $probeResult = capture([
        'ffprobe',
        '-v', 'quiet',
        '-print_format', 'json',
        '-show_streams',
        $file->getRealpath(),
    ]);

    $streams = json_decode($probeResult, true)['streams'] ?? [];
    $videoStreams = array_filter($streams, fn($s) => ($s['codec_type'] ?? '') === 'video');

    if ($videoStreams === []) {
        return false;
    }

    run(
        [
            'ffmpeg',
            '-i', $file->getRealpath(),
            '-map', '0:v:0',
            '-frames:v', '1',
            '-vf', 'scale=500:500:force_original_aspect_ratio=decrease,format=yuv420p',
            '-q:v', '2',
            '-y',
            $destination,
        ],
        context: context()->withQuiet(true),
    );

    return true;
}
