# Documentation

This folder contains the screenshots used in the main [README](../README.md).

## Screenshots

### Border

The original screenshots are given a white border of 50px with [ImageMagick](https://imagemagick.org/):

```
magick input.png -bordercolor white -border 50 output.png
```

### Screenshot Studio

The bordered screenshots are then styled with [Screenshot Studio](https://www.screenshot-studio.com/) using the following settings:

| Setting    | Value                                             |
| ---------- | ------------------------------------------------- |
| Style      | Glass Light                                       |
| Shadow     | None                                              |
| Background | Gradient `#ff0000` Red → `#fab816` Master Nacho   |

The background gradient colors are the SolveBeam branding colors.

### Compression

Finally, the styled screenshots are compressed with [pngquant](https://pngquant.org/), which writes the result to a file with the `-fs8.png` suffix:

```
pngquant input.png
```
