#!/usr/bin/env python3
"""Assemble a .dc.html artboard from the shared theme block plus a body file."""
import sys, pathlib

name, w, h = sys.argv[1], sys.argv[2], sys.argv[3]
theme = pathlib.Path('_theme.html').read_text()
body = pathlib.Path(f'body-{name}.html').read_text()

props = (
    '{"dark":{"editor":"boolean","default":false,"section":"Theme"},'
    f'"$preview":{{"width":{w},"height":{h}}}}}'
)

out = f'''<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <script src="./support.js"></script>
</head>
<body>
<x-dc>
{theme}
{body}
</x-dc>
<script data-dc-script data-props='{props}'>
class Component extends DCLogic {{
  renderVals() {{
    return {{ theme: this.props.dark ? 'dark' : '' }};
  }}
}}
</script>
</body>
</html>
'''
pathlib.Path(f'{name}.dc.html').write_text(out)
print(f'{name}.dc.html  {len(out)} bytes')
