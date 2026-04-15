#!/usr/bin/env python3
"""Manage an iPod classic from the terminal — libgpod via ctypes, no extra Python packages needed."""

import argparse
import ctypes
import json
import os
import subprocess
import sys
from pathlib import Path

# ──────────────────────────────────────────────────────────────────────────────
# Config
# ──────────────────────────────────────────────────────────────────────────────

AUDIO_EXTENSIONS = {'.mp3', '.flac', '.ogg', '.m4a', '.wav', '.aac'}
DEFAULT_MOUNT = os.environ.get('IPOD_MOUNT', os.path.expanduser('/media/florian/IPOD'))

# ──────────────────────────────────────────────────────────────────────────────
# Library loading
# ──────────────────────────────────────────────────────────────────────────────

def _load(name):
    try:
        return ctypes.CDLL(name)
    except OSError:
        sys.exit(f"error: cannot load {name} — is libgpod4 installed?")

_gpod = _load('libgpod.so.4')
_glib = _load('libglib-2.0.so.0')

# ──────────────────────────────────────────────────────────────────────────────
# Function signatures
# ──────────────────────────────────────────────────────────────────────────────

_gpod.itdb_parse.restype              = ctypes.c_void_p
_gpod.itdb_parse.argtypes             = [ctypes.c_char_p, ctypes.c_void_p]
_gpod.itdb_write.restype              = ctypes.c_int
_gpod.itdb_write.argtypes             = [ctypes.c_void_p, ctypes.c_void_p]
_gpod.itdb_free.restype               = None
_gpod.itdb_free.argtypes              = [ctypes.c_void_p]
_gpod.itdb_tracks_number.restype      = ctypes.c_int
_gpod.itdb_tracks_number.argtypes     = [ctypes.c_void_p]
_gpod.itdb_track_new.restype          = ctypes.c_void_p
_gpod.itdb_track_new.argtypes         = []
_gpod.itdb_track_add.restype          = None
_gpod.itdb_track_add.argtypes         = [ctypes.c_void_p, ctypes.c_void_p, ctypes.c_int]
_gpod.itdb_track_remove.restype       = None
_gpod.itdb_track_remove.argtypes      = [ctypes.c_void_p, ctypes.c_void_p]
_gpod.itdb_filename_on_ipod.restype   = ctypes.c_char_p
_gpod.itdb_filename_on_ipod.argtypes  = [ctypes.c_void_p]
_gpod.itdb_cp_track_to_ipod.restype   = ctypes.c_int
_gpod.itdb_cp_track_to_ipod.argtypes  = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_void_p]
_gpod.itdb_playlist_mpl.restype       = ctypes.c_void_p
_gpod.itdb_playlist_mpl.argtypes      = [ctypes.c_void_p]
_gpod.itdb_playlist_add_track.restype = None
_gpod.itdb_playlist_add_track.argtypes= [ctypes.c_void_p, ctypes.c_void_p, ctypes.c_int]

_glib.g_strdup.restype  = ctypes.c_void_p
_glib.g_strdup.argtypes = [ctypes.c_char_p]
_glib.g_free.restype    = None
_glib.g_free.argtypes   = [ctypes.c_void_p]

# ──────────────────────────────────────────────────────────────────────────────
# Struct field offsets — libgpod 0.8.3, 64-bit Linux
#
# Itdb_Track starts with 27 pointer-sized fields (8 bytes each on x86-64),
# followed by integer fields. Fields listed in header order:
#   [0]  itdb*          0
#   [1]  title          8
#   [2]  ipod_path     16
#   [3]  album         24
#   [4]  artist        32
#   [5–26] genre … sort_tvshow  (not used here)
#   [27] id (guint32) 216
#   …
#   [32] track_nr     236
#   [36] transferred  296  (gboolean = gint, 4 bytes)
#
# Itdb_iTunesDB: first field is GList *tracks at offset 0.
# GList on 64-bit: data (8) | next (8) | prev (8).
# ──────────────────────────────────────────────────────────────────────────────

_TRACK_TITLE       = 8
_TRACK_ALBUM       = 24
_TRACK_ARTIST      = 32
_TRACK_TRANSFERRED = 296  # gboolean (4 bytes)

_ITDB_TRACKS       = 0    # GList* — first field of Itdb_iTunesDB

_GLIST_DATA = 0   # void*   — offset 0 in GList
_GLIST_NEXT = 8   # GList*  — offset 8 in GList


def _addr(ptr, offset=0):
    """Read a pointer-sized value from memory at ptr+offset. Returns int or None."""
    return ctypes.c_void_p.from_address(ptr + offset).value


def _read_str(ptr, offset):
    raw = ctypes.cast(_addr(ptr, offset), ctypes.c_char_p).value
    return raw.decode('utf-8', errors='replace') if raw else ''


def _write_str(ptr, offset, value):
    old = _addr(ptr, offset)
    if old:
        _glib.g_free(old)
    new = _glib.g_strdup(value.encode('utf-8')) if value else None
    ctypes.c_void_p.from_address(ptr + offset).value = new


def _iter_glist(addr):
    """Yield data pointer values from a GList starting at addr."""
    while addr:
        data = _addr(addr, _GLIST_DATA)
        if data:
            yield data
        addr = _addr(addr, _GLIST_NEXT)


def _iter_tracks(itdb):
    tracks_glist = _addr(itdb, _ITDB_TRACKS)
    yield from _iter_glist(tracks_glist)

# ──────────────────────────────────────────────────────────────────────────────
# High-level helpers
# ──────────────────────────────────────────────────────────────────────────────

def open_itdb(mountpoint):
    if not os.path.isdir(mountpoint):
        sys.exit(f"error: {mountpoint!r} does not exist — did you run ifuse?")
    itdb = _gpod.itdb_parse(mountpoint.encode(), None)
    if not itdb:
        sys.exit(f"error: could not parse iTunesDB at {mountpoint!r}")
    return itdb


def write_itdb(itdb):
    if not _gpod.itdb_write(itdb, None):
        sys.exit("error: itdb_write() failed — database not saved")


def track_fields(ptr):
    return {
        'artist': _read_str(ptr, _TRACK_ARTIST),
        'album':  _read_str(ptr, _TRACK_ALBUM),
        'title':  _read_str(ptr, _TRACK_TITLE),
    }


def key(d):
    return (d['artist'].lower(), d['album'].lower(), d['title'].lower())


def fmt(d):
    return f"{d['artist'] or '?'} / {d['album'] or '?'} / {d['title'] or '?'}"


def ipod_track_map(itdb):
    """Return {key: (ptr, fields)} for every track on the iPod."""
    result = {}
    for ptr in _iter_tracks(itdb):
        fields = track_fields(ptr)
        result[key(fields)] = (ptr, fields)
    return result


def add_track(itdb, filepath, tags):
    track = _gpod.itdb_track_new()
    _write_str(track, _TRACK_TITLE,  tags['title'])
    _write_str(track, _TRACK_ALBUM,  tags['album'])
    _write_str(track, _TRACK_ARTIST, tags['artist'])

    _gpod.itdb_track_add(itdb, track, -1)
    _gpod.itdb_playlist_add_track(_gpod.itdb_playlist_mpl(itdb), track, -1)

    ok = _gpod.itdb_cp_track_to_ipod(track, filepath.encode(), None)
    if not ok:
        _gpod.itdb_track_remove(itdb, track)
        print(f"  error: failed to copy {filepath}", file=sys.stderr)
        return False
    return True


def remove_track(itdb, ptr):
    # Grab the on-device path before itdb_track_remove frees the struct
    raw = _gpod.itdb_filename_on_ipod(ptr)
    filepath = raw.decode() if raw else None
    _gpod.itdb_track_remove(itdb, ptr)   # removes from all playlists, frees struct
    if filepath:
        try:
            os.remove(filepath)
        except OSError:
            pass

# ──────────────────────────────────────────────────────────────────────────────
# Tag reading via ffprobe (already required by castor.php)
# ──────────────────────────────────────────────────────────────────────────────

def read_tags(filepath):
    try:
        result = subprocess.run(
            ['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', filepath],
            capture_output=True, text=True, check=True,
        )
    except (subprocess.CalledProcessError, FileNotFoundError):
        return None
    raw = json.loads(result.stdout).get('format', {}).get('tags', {})
    tags = {k.lower(): v.strip() for k, v in raw.items()}
    return {
        'artist': tags.get('artist') or tags.get('album_artist') or '',
        'album':  tags.get('album', ''),
        'title':  tags.get('title', ''),
    }


def scan_source(directory):
    source, skipped = {}, []
    for root, _, files in os.walk(directory):
        for name in sorted(files):
            if Path(name).suffix.lower() not in AUDIO_EXTENSIONS:
                continue
            filepath = os.path.join(root, name)
            tags = read_tags(filepath)
            if not tags or not all(tags.values()):
                skipped.append(filepath)
                continue
            source[key(tags)] = (filepath, tags)
    return source, skipped

# ──────────────────────────────────────────────────────────────────────────────
# Commands
# ──────────────────────────────────────────────────────────────────────────────

def cmd_sync(args):
    print("Scanning source directory...")
    source, skipped = scan_source(args.directory)
    for path in skipped:
        print(f"warning: incomplete tags, skipping: {path}", file=sys.stderr)
    print(f"Found {len(source)} track(s) in source.\n")

    itdb = open_itdb(args.mount)
    on_ipod = ipod_track_map(itdb)

    to_add    = {k: v for k, v in source.items()   if k not in on_ipod}
    to_remove = {k: v for k, v in on_ipod.items()  if k not in source}

    if not to_add and not to_remove:
        print("Already in sync.")
        _gpod.itdb_free(itdb)
        return

    for fields in (v for _, v in sorted(to_remove.items())):
        print(f"- {fmt(fields[1])}")
    for tags in (v for _, v in sorted(to_add.items())):
        print(f"+ {fmt(tags[1])}")

    print(f"\n{len(to_add)} to add, {len(to_remove)} to remove.")

    if args.dry_run:
        print("Dry run — no changes written.")
        _gpod.itdb_free(itdb)
        return

    for ptr, _ in to_remove.values():
        remove_track(itdb, ptr)

    added = sum(add_track(itdb, fp, tags) for fp, tags in to_add.values())

    write_itdb(itdb)
    _gpod.itdb_free(itdb)
    print(f"Done. +{added} / -{len(to_remove)}")


def cmd_list(args):
    itdb = open_itdb(args.mount)
    tracks = list(ipod_track_map(itdb).values())
    _gpod.itdb_free(itdb)

    if args.artist:
        tracks = [(p, f) for p, f in tracks if f['artist'].lower() == args.artist.lower()]
    if args.album:
        tracks = [(p, f) for p, f in tracks if f['album'].lower() == args.album.lower()]

    for _, fields in sorted(tracks, key=lambda x: key(x[1])):
        print(fmt(fields))
    print(f"\n{len(tracks)} track(s)")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mount', default=DEFAULT_MOUNT, metavar='PATH',
                        help='iPod mount point (default: $IPOD_MOUNT or ~/ipod)')
    sub = parser.add_subparsers(dest='command', required=True)

    p_sync = sub.add_parser('sync', help='Sync a source directory to the iPod')
    p_sync.add_argument('directory', metavar='DIR', help='Source music directory')
    p_sync.add_argument('--dry-run', action='store_true', help='Preview without writing')
    p_sync.set_defaults(func=cmd_sync)

    p_list = sub.add_parser('list', help='List tracks on the iPod')
    p_list.add_argument('--artist')
    p_list.add_argument('--album')
    p_list.set_defaults(func=cmd_list)

    args = parser.parse_args()
    args.func(args)


if __name__ == '__main__':
    main()
