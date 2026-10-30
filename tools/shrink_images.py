"""Prepares downloaded pictures for the bot: JPEG, longest side at most 1600 px, small file.

    pip install pillow
    python tools/shrink_images.py data/themes/paintings/images

Files keep their names (1.jpg, 2.jpg ...). A PNG/WebP named 1.jpg is converted to a real JPEG.
"""
import sys
from pathlib import Path

from PIL import Image

MAX_SIDE = 1600


def main(folder: str) -> None:
    done = 0
    for path in sorted(Path(folder).glob('*.jpg')):
        with Image.open(path) as im:
            im = im.convert('RGB')
            im.thumbnail((MAX_SIDE, MAX_SIDE), Image.LANCZOS)
            im.save(path, 'JPEG', quality=88, optimize=True)
        done += 1
    print(f'processed: {done}')


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else '.')
