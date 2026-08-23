#!/usr/bin/env python3
"""Render an artboard body as plain HTML, purely to eyeball the layout."""
import sys, pathlib, re
name = sys.argv[1]
theme = pathlib.Path('_theme.html').read_text()
body = pathlib.Path(f'body-{name}.html').read_text().replace('{{theme}}', sys.argv[2] if len(sys.argv) > 2 else '')
css = re.search(r'<style>(.*?)</style>', theme, re.S).group(1)
link = re.search(r'<link[^>]*>', theme).group(0)
pathlib.Path(f'preview-{name}.html').write_text(
    f'<!doctype html><html><head><meta charset="utf-8">{link}<style>{css}</style></head>'
    f'<body style="margin:0;background:#e8e6e3">{body}</body></html>')
print(f'preview-{name}.html')
