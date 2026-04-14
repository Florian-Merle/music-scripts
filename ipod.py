#!/usr/bin/env python3
"""Manage an iPod classic from the terminal via libgpod."""

import argparse
import os
import sys
from pathlib import Path

AUDIO_EXTENSIONS = {'.mp3', '.flac', '.ogg', '.m4a', '.wav', '.aac'}
DEFAULT_MOUNT = os.environ.get('IPOD_MOUNT', os.path.expanduser('~/ipod'))


def import_gpod():
    try:
        import gpod
        return gpod
    except ImportError:
        sys.exit("error: python3-gpod is not installed (sudo apt install python3-gpod)")


def import_mutagen():
    try:
        from mutagen import File as MutagenFile
        return MutagenFile
    except ImportError:
        sys.exit("error: python3-mutagen is not installed (sudo apt install python3-mutagen)")


def open_db(mountpoint):
    gpod = import_gpod()
    if not os.path.isdir(mountpoint):
        sys.exit(f"error: mount point {mountpoint!r} does not exist — did you run ifuse?")
    return gpod.Database(mountpoint)


def read_tags(filepath):
    MutagenFile = import_mutagen()
    audio = MutagenFile(filepath, easy=True)
    if audio is None:
        return None

    def get(*keys):
        for key in keys:
            values = audio.get(key)
            if values:
                value = values[0].strip()
                if value:
                    return value
        return None

    return {
        'artist': get('artist', 'albumartist'),
        'album': get('album'),
        'title': get('title'),
    }


def track_key(artist, album, title):
    return (
        (artist or '').lower().strip(),
        (album or '').lower().strip(),
        (title or '').lower().strip(),
    )


def fmt_key(key):
    return ' / '.join(part or '?' for part in key)


def fmt_track(track):
    return track_key(track['artist'], track['album'], track['title'])


def scan_source(directory):
    """Scan directory recursively; returns ({key: (filepath, tags)}, [skipped])."""
    MutagenFile = import_mutagen()
    source, skipped = {}, []

    for root, _, files in os.walk(directory):
        for filename in sorted(files):
            if Path(filename).suffix.lower() not in AUDIO_EXTENSIONS:
                continue
            filepath = os.path.join(root, filename)
            tags = read_tags(filepath)
            if not tags or not all(tags.values()):
                skipped.append(filepath)
                continue
            key = track_key(tags['artist'], tags['album'], tags['title'])
            source[key] = (filepath, tags)

    return source, skipped


def cmd_sync(args):
    print("Scanning source directory...")
    source_tracks, skipped = scan_source(args.directory)

    for path in skipped:
        print(f"warning: incomplete tags, skipping: {path}", file=sys.stderr)

    print(f"Found {len(source_tracks)} track(s) in source.\n")

    db = open_db(args.mount)
    ipod_tracks = {fmt_track(t): t for t in db}

    to_add = {k: v for k, v in source_tracks.items() if k not in ipod_tracks}
    to_remove = {k: v for k, v in ipod_tracks.items() if k not in source_tracks}

    if not to_add and not to_remove:
        print("Already in sync.")
        return

    for key in sorted(to_remove):
        print(f"- {fmt_key(key)}")
    for key in sorted(to_add):
        print(f"+ {fmt_key(key)}")

    print(f"\n{len(to_add)} to add, {len(to_remove)} to remove.")

    if args.dry_run:
        print("Dry run — no changes written.")
        return

    for track in to_remove.values():
        db.remove(track)

    for filepath, _ in to_add.values():
        db.new_Track(filename=filepath)

    db.copy_delayed_files()
    del db
    print("Done.")


def cmd_list(args):
    db = open_db(args.mount)
    tracks = [fmt_track(t) for t in db]
    if args.artist:
        tracks = [k for k in tracks if k[0] == args.artist.lower()]
    if args.album:
        tracks = [k for k in tracks if k[1] == args.album.lower()]
    for key in sorted(tracks):
        print(fmt_key(key))
    print(f"\n{len(tracks)} track(s)")


def cmd_add(args):
    db = open_db(args.mount)
    added = 0
    for path in args.files:
        path = os.path.abspath(path)
        if not os.path.isfile(path):
            print(f"warning: not found: {path}", file=sys.stderr)
            continue
        track = db.new_Track(filename=path)
        print(f"+ {fmt_key(fmt_track(track))}")
        added += 1
    db.copy_delayed_files()
    del db
    print(f"\nAdded {added} track(s)")


def cmd_remove(args):
    if not any([args.artist, args.album, args.title]):
        sys.exit("error: specify at least one of --artist, --album, --title")

    db = open_db(args.mount)
    tracks = [t for t in db if (
        (not args.artist or (t['artist'] or '').lower() == args.artist.lower()) and
        (not args.album  or (t['album']  or '').lower() == args.album.lower())  and
        (not args.title  or (t['title']  or '').lower() == args.title.lower())
    )]

    if not tracks:
        print("No matching tracks.")
        return

    for t in tracks:
        print(f"- {fmt_key(fmt_track(t))}")
        db.remove(t)

    del db
    print(f"\nRemoved {len(tracks)} track(s)")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        '--mount', default=DEFAULT_MOUNT, metavar='PATH',
        help='iPod mount point (default: $IPOD_MOUNT or ~/ipod)',
    )
    sub = parser.add_subparsers(dest='command', required=True)

    p_sync = sub.add_parser('sync', help='Sync a source directory to the iPod')
    p_sync.add_argument('directory', metavar='DIR', help='Source music directory')
    p_sync.add_argument('--dry-run', action='store_true', help='Preview without writing')
    p_sync.set_defaults(func=cmd_sync)

    p_list = sub.add_parser('list', help='List tracks on the iPod')
    p_list.add_argument('--artist', help='Filter by artist (case-insensitive)')
    p_list.add_argument('--album', help='Filter by album (case-insensitive)')
    p_list.set_defaults(func=cmd_list)

    p_add = sub.add_parser('add', help='Add one or more audio files')
    p_add.add_argument('files', nargs='+', metavar='FILE')
    p_add.set_defaults(func=cmd_add)

    p_remove = sub.add_parser('remove', help='Remove tracks matching all given filters')
    p_remove.add_argument('--artist', help='Match artist (case-insensitive)')
    p_remove.add_argument('--album', help='Match album (case-insensitive)')
    p_remove.add_argument('--title', help='Match title (case-insensitive)')
    p_remove.set_defaults(func=cmd_remove)

    args = parser.parse_args()
    args.func(args)


if __name__ == '__main__':
    main()
