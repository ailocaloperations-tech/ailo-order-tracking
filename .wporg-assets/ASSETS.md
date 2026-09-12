# wordpress.org directory assets

These files are NOT part of the plugin zip. They go into the `assets/` folder of the
wordpress.org SVN repository (next to `trunk/` and `tags/`), after the plugin is approved.

## Files to export

| File                  | Size (px)  | Source                | Notes |
|-----------------------|------------|-----------------------|-------|
| `icon-128x128.png`    | 128 x 128  | `icon.svg`            | Plugin search results and the plugin card. |
| `icon-256x256.png`    | 256 x 256  | `icon.svg`            | Retina icon. |
| `banner-772x250.png`  | 772 x 250  | `banner-772x250.svg`  | Header on the plugin page. |
| `banner-1544x500.png` | 1544 x 500 | `banner.svg`          | Retina header. |
| `screenshot-1.png` .. `screenshot-6.png` | any, ideally 1280 px wide | real screenshots | Order must match the numbered list in `readme.txt` under `== Screenshots ==`. |

Optional: `icon.svg` itself may also be committed as-is (wordpress.org accepts an SVG icon
next to the PNGs). Banners must be PNG or JPG; SVG banners are not accepted.

All PNGs: sRGB, no alpha needed (the backgrounds are solid), under 1 MB each. Filenames are
case-sensitive and must match exactly.

## Screenshots (what each one must show)

1. Order edit screen, the tracking box: number field + carrier dropdown, saved value linked.
2. Orders list with the tracking column.
3. WooCommerce > Settings > Shipping > Order tracking, carriers table with a `{tracking}` template.
4. Block editor with the Order Tracking Lookup block and its sidebar options.
5. Front end: the block after a successful lookup (number, carrier, link), theme-styled.
6. Customer order email with the tracking line.

Take them on a clean test store (default theme, no other plugins visible, sample data only,
no real customer names).

## Exporting the SVGs

### Inkscape (command line, Inkscape 1.x)

    inkscape icon.svg           -w 128  -h 128 -o icon-128x128.png
    inkscape icon.svg           -w 256  -h 256 -o icon-256x256.png
    inkscape banner-772x250.svg -w 772  -h 250 -o banner-772x250.png
    inkscape banner.svg         -w 1544 -h 500 -o banner-1544x500.png

On Windows use the full path to `inkscape.exe`.

### Inkscape (GUI)

File > Open the SVG, then File > Export (Shift+Ctrl+E), Export area: Page, set Width/Height
to the values above, file type PNG, Export.

### Browser (no extra software)

1. Open the SVG in Chrome or Edge (drag the file into a tab).
2. Press F12, select the `<svg>` element in the Elements panel, press Ctrl+Shift+P and run
   "Capture node screenshot". The PNG is taken at the SVG's declared width/height
   (256x256, 772x250 or 1544x500).
3. For the 128 px icon either resize the 256 px PNG in any image editor, or set the browser
   zoom to 50% before capturing.

### Anything else

Any tool that rasterises SVG works (GIMP, Figma, Affinity, rsvg-convert,
`magick icon.svg -resize 128x128 icon-128x128.png`). The SVGs use only basic shapes and the
generic Helvetica/Arial/sans-serif stack, so no fonts need to be installed.

## Design notes

Brand-neutral: a parcel and a location pin, no logo, no trademarked marks, no emoji, no
embedded fonts and no external references. Safe to release under GPLv2 or later with the plugin.
