#!/usr/bin/env python3
"""Scaffold for IGN RGE ALTI downloads (no anonymous bulk API)."""

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
                    "source": "ign-rge-alti",
                    "note": "Ajoutez les dalles GeoTIFF téléchargées dans products[].path",
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
            "# RGE ALTI — MNT IGN\n\n"
            "1. Téléchargez les dalles sur [geoservices.ign.fr/rgealti](https://geoservices.ign.fr/rgealti).\n"
            "2. Listez-les dans `manifest.json` puis `./dev.sh rgealti --manifest …`.\n"
            "3. Relancez `./dev.sh precompute` : le relief préférera RGE ALTI à Terrarium.\n",
            encoding="utf-8",
        )
    print(f"Scaffold RGE ALTI prêt : {args.source_dir}")


if __name__ == "__main__":
    main()
