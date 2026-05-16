"""
Generate WP-org icon + banner assets for opensalestax-for-woocommerce.

SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

Run:
    python tools/make-assets.py

Output: assets/icon-128x128.png, icon-256x256.png,
        banner-772x250.png, banner-1544x500.png
"""

from __future__ import annotations

import os
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets"
OUT.mkdir(exist_ok=True)

# WC-adjacent deep purple per spec.
PURPLE = (127, 84, 179)        # #7f54b3 — primary
PURPLE_DK = (95, 60, 138)      # #5f3c8a — shadow / accent
PURPLE_LT = (157, 116, 207)    # #9d74cf — highlight
WHITE = (255, 255, 255)
NEAR_WHITE = (248, 247, 251)
INK = (32, 26, 48)             # heading ink
SUB_INK = (74, 65, 99)         # subtitle ink

SEGOE_BOLD = "C:/Windows/Fonts/segoeuib.ttf"
SEGOE_SEMI = "C:/Windows/Fonts/seguisb.ttf"
SEGOE = "C:/Windows/Fonts/segoeui.ttf"


def font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size)


def text_size(draw: ImageDraw.ImageDraw, text: str, f: ImageFont.FreeTypeFont) -> tuple[int, int]:
    bbox = draw.textbbox((0, 0), text, font=f)
    return bbox[2] - bbox[0], bbox[3] - bbox[1]


def rounded_square(size: int, radius: int, fill) -> Image.Image:
    """Return an RGBA square with rounded corners."""
    im = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle((0, 0, size - 1, size - 1), radius=radius, fill=fill)
    return im


def draw_icon(size: int) -> Image.Image:
    """Square icon: rounded purple tile with stylized $ + small 'OST' tag."""
    im = Image.new("RGBA", (size, size), (0, 0, 0, 0))

    # Rounded-corner purple base.
    base = rounded_square(size, radius=int(size * 0.22), fill=PURPLE)
    im.alpha_composite(base)

    # Diagonal highlight sweep across the upper-left, clipped by the
    # rounded-corner mask so it doesn't bleed past the tile shape.
    sweep = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    sd = ImageDraw.Draw(sweep)
    sd.polygon(
        [(0, 0), (int(size * 0.85), 0), (0, int(size * 0.85))],
        fill=(*PURPLE_LT, 110),
    )
    corner_mask_l = rounded_square(size, radius=int(size * 0.22), fill=(255, 255, 255, 255)).split()[-1]
    sweep_alpha = sweep.split()[-1]
    # Multiply the sweep's alpha by the rounded-corner mask so the
    # highlight stays inside the tile shape.
    clipped_alpha = ImageDraw.Draw(Image.new("L", (size, size), 0))  # placeholder so linter is happy
    a_pixels = sweep_alpha.load()
    m_pixels = corner_mask_l.load()
    out = Image.new("L", (size, size), 0)
    out_pixels = out.load()
    for y in range(size):
        for x in range(size):
            out_pixels[x, y] = (a_pixels[x, y] * m_pixels[x, y]) // 255
    sweep.putalpha(out)
    im.alpha_composite(sweep)

    d = ImageDraw.Draw(im)

    # Big $ glyph dead center.
    dollar_size = int(size * 0.66)
    f_dollar = font(SEGOE_BOLD, dollar_size)
    dw, dh = text_size(d, "$", f_dollar)
    # Drop-shadow.
    sx, sy = (size - dw) // 2, int((size - dh) // 2 - size * 0.04)
    d.text((sx + max(2, size // 96), sy + max(2, size // 96)), "$", fill=(0, 0, 0, 90), font=f_dollar)
    d.text((sx, sy), "$", fill=WHITE, font=f_dollar)

    # Small 'OST' chip across the bottom.
    chip_h = int(size * 0.18)
    chip_w = int(size * 0.62)
    chip_x = (size - chip_w) // 2
    chip_y = int(size * 0.78)
    chip = Image.new("RGBA", (chip_w, chip_h), (0, 0, 0, 0))
    cd = ImageDraw.Draw(chip)
    cd.rounded_rectangle((0, 0, chip_w - 1, chip_h - 1), radius=int(chip_h * 0.45), fill=(255, 255, 255, 235))
    f_ost = font(SEGOE_BOLD, int(chip_h * 0.62))
    ow, oh = text_size(cd, "OST", f_ost)
    cd.text(((chip_w - ow) // 2, (chip_h - oh) // 2 - max(1, chip_h // 14)), "OST", fill=PURPLE_DK, font=f_ost)
    im.alpha_composite(chip, dest=(chip_x, chip_y))

    return im


def draw_banner(width: int, height: int) -> Image.Image:
    """WP-org banner: brand panel left, title + subtitle right."""
    im = Image.new("RGB", (width, height), NEAR_WHITE)

    d = ImageDraw.Draw(im)
    # Solid purple band on the left ~28% of the canvas.
    band_w = int(width * 0.28)
    d.rectangle((0, 0, band_w, height), fill=PURPLE)
    # Subtle lighter wedge inside the band for visual depth.
    wedge = Image.new("RGBA", (band_w, height), (0, 0, 0, 0))
    wd = ImageDraw.Draw(wedge)
    wd.polygon(
        [(0, 0), (band_w, 0), (0, int(height * 0.85))],
        fill=(*PURPLE_LT, 110),
    )
    im.paste(wedge, (0, 0), wedge)

    # Icon tile in the band.
    tile_size = int(height * 0.66)
    tile_x = (band_w - tile_size) // 2
    tile_y = (height - tile_size) // 2
    icon = draw_icon(tile_size)
    im.paste(icon, (tile_x, tile_y), icon)

    # Title + subtitle on the right, vertically centered, with a
    # generous left pad after the band.
    text_left = band_w + int(width * 0.04)
    text_right = width - int(width * 0.04)
    avail_w = text_right - text_left

    title = "OpenSalesTax for WooCommerce"
    subtitle = "Free, self-hosted US sales tax for WooCommerce."
    tag = "Apache-2.0  ·  destination-based  ·  no per-transaction fees"

    # Pick the largest size for each line that still fits in avail_w.
    def fit(text: str, font_path: str, ideal_px: int) -> tuple[ImageFont.FreeTypeFont, int, int]:
        size = ideal_px
        while size >= 8:
            f = font(font_path, size)
            tw, th = text_size(d, text, f)
            if tw <= avail_w:
                return f, tw, th
            size -= 1
        f = font(font_path, 8)
        tw, th = text_size(d, text, f)
        return f, tw, th

    ft, tw, th = fit(title, SEGOE_BOLD, int(height * 0.20))
    fs, sw, sh = fit(subtitle, SEGOE_SEMI, int(height * 0.10))
    fg, gw, gh = fit(tag, SEGOE, int(height * 0.072))

    gap1 = int(height * 0.05)
    gap2 = int(height * 0.05)
    block_h = th + gap1 + sh + gap2 + gh
    y = (height - block_h) // 2

    d.text((text_left, y), title, fill=INK, font=ft)
    d.text((text_left, y + th + gap1), subtitle, fill=PURPLE_DK, font=fs)
    d.text((text_left, y + th + gap1 + sh + gap2), tag, fill=SUB_INK, font=fg)

    return im


def main() -> None:
    for s in (128, 256):
        icon = draw_icon(s)
        path = OUT / f"icon-{s}x{s}.png"
        icon.save(path, "PNG", optimize=True)
        print(f"  wrote {path} ({path.stat().st_size:,} bytes)")

    for w, h in ((772, 250), (1544, 500)):
        banner = draw_banner(w, h)
        path = OUT / f"banner-{w}x{h}.png"
        banner.save(path, "PNG", optimize=True)
        print(f"  wrote {path} ({path.stat().st_size:,} bytes)")


if __name__ == "__main__":
    main()
