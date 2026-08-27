#!/usr/bin/env python3
"""Prepare a LIDAR HD CHM manifest for ./dev.sh lidar.

IGN LIDAR HD is not a free anonymous API: download MNS + MNT (or a ready CHM)
for départements 26 / 38 / 73 from https://geoservices.ign.fr/lidarhd then either:

  ./dev.sh lidar /path/to/chm1.tif [/path/to/chm2.tif…]
  ./dev.sh lidar --manifest backend/var/lidar/source/manifest.json

This script writes an empty manifest scaffold under the given source directory.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source_dir", type=Path)
    args = parser.parse_args()
    args.source_dir.mkdir(parents=True, exist_ok=True)
    manifest = args.source_dir / "manifest.json"
    if not manifest.is_file():
        manifest.write_text(
            json.dumps(
                {
                    "source": "ign-lidar-hd",
                    "note": "Ajoutez {\"path\": \"ma-dalle-chm.tif\"} dans products après téléchargement IGN.",
                    "products": [],
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
    readme = args.source_dir / "README.md"
    if not readme.is_file():
        readme.write_text(
            "# LIDAR HD — hauteur de canopée\n\n"
            "1. Téléchargez MNS et MNT LIDAR HD (ou un CHM déjà dérivé) sur "
            "[geoservices.ign.fr/lidarhd](https://geoservices.ign.fr/lidarhd).\n"
            "2. CHM = MNS − MNT (GDAL `gdal_calc` / QGIS).\n"
            "3. Placez les GeoTIFF ici et listez-les dans `manifest.json`, puis "
            "`./dev.sh lidar --manifest …` ou passez les chemins en arguments.\n",
            encoding="utf-8",
        )
    print(f"Scaffold LIDAR prêt : {args.source_dir}")


if __name__ == "__main__":
    main()
