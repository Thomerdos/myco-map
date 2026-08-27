#!/usr/bin/env python3
"""Download IGN LiDAR HD MNH tiles from a WMS GetMap link list.

By default rewrites WIDTH/HEIGHT to a coarse grid (~25 m for 1 km tiles) so the
full Chartreuse/Vercors set fits in tens of megabytes instead of ~25 GiB at 0.5 m.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

FILENAME_RE = re.compile(r"FILENAME=([^&]+)", re.I)
SIZE_RE = re.compile(r"(WIDTH|HEIGHT)=\d+", re.I)


def filename_from_url(url: str) -> str:
    match = FILENAME_RE.search(url)
    if match:
        return urllib.parse.unquote(match.group(1))
    digest = abs(hash(url)) % (10**12)
    return f"tile_{digest}.tif"


def downsample_url(url: str, size: int) -> str:
    """Force WMS raster size (pixels per 1 km tile)."""
    if size <= 0:
        return url
    rewritten, n = SIZE_RE.subn(lambda m: f"{m.group(1).upper()}={size}", url)
    if n < 2:
        # Append if missing
        sep = "&" if "?" in rewritten else "?"
        rewritten = f"{rewritten}{sep}WIDTH={size}&HEIGHT={size}"
    return rewritten


def download_one(url: str, dest: Path, timeout: int, min_bytes: int, retries: int = 6) -> tuple[str, str]:
    if dest.is_file() and dest.stat().st_size >= min_bytes:
        return dest.name, "skip"
    tmp = dest.with_suffix(dest.suffix + ".part")
    last = "error:unknown"
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "myco-map-lidar/1.0"})
            with urllib.request.urlopen(req, timeout=timeout) as response:
                data = response.read()
            if len(data) < min_bytes:
                last = f"too-small:{len(data)}"
            elif data[:3] == b"II*" or data[:2] == b"MM":
                tmp.write_bytes(data)
                tmp.replace(dest)
                return dest.name, "ok"
            else:
                preview = data[:200].decode("utf-8", errors="replace")
                last = f"not-tiff:{preview!r}"
        except urllib.error.HTTPError as exc:
            last = f"error:HTTP Error {exc.code}: {exc.reason}"
            if exc.code == 429:
                time.sleep(2.0 * (attempt + 1))
                continue
        except Exception as exc:  # noqa: BLE001
            last = f"error:{exc}"
        time.sleep(0.4 * (attempt + 1))
    return dest.name, last


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("links_file", type=Path)
    parser.add_argument("out_dir", type=Path)
    parser.add_argument("--workers", type=int, default=6)
    parser.add_argument("--timeout", type=int, default=120)
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument(
        "--pixel-size",
        type=int,
        default=40,
        help="WMS WIDTH/HEIGHT per 1 km tile (40 ≈ 25 m, 20 ≈ 50 m, 0 = keep original)",
    )
    args = parser.parse_args()

    min_bytes = 500
    if args.pixel_size and args.pixel_size > 0:
        # GeoTIFF ≈ a few bytes/pixel; keep a floor so empty error XML fails.
        min_bytes = max(500, (args.pixel_size * args.pixel_size) // 2)
    else:
        min_bytes = 1_000_000

    urls = [
        line.strip().strip("\r")
        for line in args.links_file.read_text(encoding="utf-8", errors="replace").splitlines()
        if line.strip().startswith("http")
    ]
    if args.limit > 0:
        urls = urls[: args.limit]
    args.out_dir.mkdir(parents=True, exist_ok=True)

    jobs = [
        (downsample_url(url, args.pixel_size), args.out_dir / filename_from_url(url))
        for url in urls
    ]
    print(
        f"{len(jobs)} dalles → {args.out_dir} "
        f"(workers={args.workers}, pixel-size={args.pixel_size or 'native'})",
        flush=True,
    )

    ok = skip = fail = 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = [
            pool.submit(download_one, url, dest, args.timeout, min_bytes) for url, dest in jobs
        ]
        for i, fut in enumerate(concurrent.futures.as_completed(futures), 1):
            name, status = fut.result()
            if status == "ok":
                ok += 1
            elif status == "skip":
                skip += 1
            else:
                fail += 1
                print(f"FAIL {name}: {status}", file=sys.stderr, flush=True)
            if i % 100 == 0 or i == len(futures):
                print(f"… {i}/{len(futures)} (ok={ok} skip={skip} fail={fail})", flush=True)

    print(f"Terminé : ok={ok} skip={skip} fail={fail}", flush=True)
    if fail and ok + skip == 0:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
