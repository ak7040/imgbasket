#!/usr/bin/env python3
"""Build the ImgBasket icon set from the glossy master artwork.

Usage:
    python3 scripts/make-icons.py "Glossy Orange Basket Photo Icon.png"

Outputs (into the repo root):
    favicon.svg             SVG wrapper with an embedded 128px tile
    favicon.ico             16/32/48 px multi-size fallback
    icon-192.png            PWA icon, rounded tile
    icon-512.png            PWA icon, rounded tile
    icon-maskable-192.png   PWA maskable icon, full-bleed, safe area
    icon-maskable-512.png   PWA maskable icon, full-bleed, safe area
    apple-touch-icon.png    180px, full-bleed opaque (iOS applies its own mask)

The master has a transparent background and two decorative "motion" dashes in
the top-right. The small favicon variant drops those dashes so the basket keeps
a clean silhouette at 16-32 px; the larger icons keep the full composition.
"""

from __future__ import annotations

import argparse
import base64
import io
import os

from PIL import Image, ImageDraw, ImageFilter

BG_CENTER = (23, 28, 37)     # #171c25, subtle lift behind the artwork
BG_EDGE = (11, 13, 17)       # #0b0d11, matches theme-color
CORNER_RADIUS = 0.225        # fraction of the canvas, squircle-ish


def load_art(path: str) -> tuple[Image.Image, Image.Image]:
    """Return (full artwork, main subject without the motion dashes)."""
    art = Image.open(path).convert("RGBA")

    # Main subject = the largest connected opaque region. Falls back to the
    # whole image when scipy is unavailable.
    main = art
    try:
        import numpy as np
        from scipy import ndimage

        alpha = np.array(art.getchannel("A"))
        labels, count = ndimage.label(alpha > 16)
        if count > 1:
            sizes = ndimage.sum(alpha > 16, labels, range(1, count + 1))
            largest = int(np.argmax(sizes)) + 1
            keep = (labels == largest).astype("uint8") * 255
            mask = Image.fromarray(keep, "L")
            # Soften the mask edge by a hair so the cut isn't aliased.
            mask = mask.filter(ImageFilter.GaussianBlur(0.6))
            cut = art.copy()
            cut.putalpha(Image.composite(art.getchannel("A"), Image.new("L", art.size, 0), mask))
            main = cut
    except ImportError:
        pass

    def cropped(img: Image.Image) -> Image.Image:
        box = img.getchannel("A").getbbox()
        return img.crop(box) if box else img

    return cropped(art), cropped(main)


def background(size: int, radius_ratio: float | None) -> Image.Image:
    """Dark canvas with a soft radial glow; optionally clipped to a rounded square."""
    # Render the radial glow at low resolution and upscale — it's smooth.
    small = 128
    bg = Image.new("RGBA", (small, small))
    px = bg.load()
    cx, cy = small * 0.5, small * 0.42
    max_d = (small * 0.62) ** 2
    for y in range(small):
        for x in range(small):
            d = ((x - cx) ** 2 + (y - cy) ** 2) / max_d
            t = min(1.0, max(0.0, d)) ** 0.85
            px[x, y] = (
                round(BG_CENTER[0] + (BG_EDGE[0] - BG_CENTER[0]) * t),
                round(BG_CENTER[1] + (BG_EDGE[1] - BG_CENTER[1]) * t),
                round(BG_CENTER[2] + (BG_EDGE[2] - BG_CENTER[2]) * t),
                255,
            )
    bg = bg.resize((size, size), Image.LANCZOS)

    if radius_ratio:
        mask = Image.new("L", (size, size), 0)
        ImageDraw.Draw(mask).rounded_rectangle(
            (0, 0, size - 1, size - 1), radius=round(size * radius_ratio), fill=255
        )
        bg.putalpha(mask)
    return bg


def tile(art: Image.Image, size: int, fraction: float, rounded: bool = True) -> Image.Image:
    """Center `art` on a dark canvas, scaled so it fills `fraction` of the canvas."""
    sup = size * 3  # supersample, then downscale for crisp edges
    art_w, art_h = art.size
    scale = min((sup * fraction) / art_w, (sup * fraction) / art_h)
    art_sup = art.resize(
        (max(1, round(art_w * scale)), max(1, round(art_h * scale))), Image.LANCZOS
    )
    canvas = background(sup, CORNER_RADIUS if rounded else None)
    canvas.alpha_composite(art_sup, ((sup - art_sup.width) // 2, (sup - art_sup.height) // 2))
    return canvas.resize((size, size), Image.LANCZOS)


def save_png(img: Image.Image, path: str, opaque: bool = False) -> None:
    # Icons that must never be transparent are saved without an alpha channel.
    if opaque:
        img = img.convert("RGB")
    img.save(path, "PNG", optimize=True)
    print(f"  {os.path.basename(path):24} {img.width}x{img.height}  {os.path.getsize(path) / 1024:6.1f} KB")


def save_svg(tile_png: Image.Image, path: str) -> None:
    # Embed an @2x raster so the SVG stays sharp on 16-32 px tab icons.
    buf = io.BytesIO()
    tile_png.save(buf, "PNG", optimize=True)
    data = base64.b64encode(buf.getvalue()).decode("ascii")
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" '
        'aria-label="ImgBasket">'
        f'<image width="64" height="64" href="data:image/png;base64,{data}"/>'
        "</svg>\n"
    )
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(svg)
    print(f"  {os.path.basename(path):24} 64x64 (embedded {tile_png.width}px)  {os.path.getsize(path) / 1024:6.1f} KB")


def main() -> None:
    repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    ap = argparse.ArgumentParser()
    ap.add_argument("source", help="master artwork PNG")
    ap.add_argument("--out", default=repo, help="output directory (default: repo root)")
    args = ap.parse_args()
    out = args.out
    os.makedirs(out, exist_ok=True)

    full, main = load_art(args.source)
    print(f"Artwork: {full.size[0]}x{full.size[1]} (subject {main.size[0]}x{main.size[1]})")

    save_png(tile(full, 192, 0.78), os.path.join(out, "icon-192.png"))
    save_png(tile(full, 512, 0.78), os.path.join(out, "icon-512.png"))
    save_png(tile(full, 192, 0.66, rounded=False), os.path.join(out, "icon-maskable-192.png"), opaque=True)
    save_png(tile(full, 512, 0.66, rounded=False), os.path.join(out, "icon-maskable-512.png"), opaque=True)
    save_png(tile(full, 180, 0.76, rounded=False), os.path.join(out, "apple-touch-icon.png"), opaque=True)

    # Favicons use the simplified subject so the basket reads cleanly at 16-32 px.
    fav_tile = tile(main, 256, 0.84)
    save_svg(tile(main, 128, 0.84), os.path.join(out, "favicon.svg"))

    ico_path = os.path.join(out, "favicon.ico")
    fav_tile.save(ico_path, "ICO", sizes=[(16, 16), (32, 32), (48, 48)])
    print(f"  {'favicon.ico':24} 16/32/48               {os.path.getsize(ico_path) / 1024:6.1f} KB")


if __name__ == "__main__":
    main()
