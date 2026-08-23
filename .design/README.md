# Design source

Working files for the redesign canvas (the Gmail-vibe pass on the inbox). The
canvas itself lives as an Artifact; these are what it is seeded from, so every
later change starts here rather than in the published page.

## What is what

| File | Role |
|---|---|
| `_theme.html` | The shared `<helmet>` block: theme tokens lifted from the app's compiled CSS (Instrument Sans, the stone scale, sky accent, 6px radii) plus the three mailbox hues |
| `_parts.py` | Icon set, avatar/badge helpers, and the seven sample threads every artboard shares |
| `gen.py` | Desktop chrome — top bar, sidebar, list rows, list header |
| `gen2.py` | Reading pane, inline reply, and the three desktop bodies |
| `gen3.py` | Floating composer and the two phone bodies |
| `gen4.py` | The "which mailbox did this arrive in?" comparison |
| `wrap.py` | Wraps a body into a `.dc.html` artboard with the dark tweak |
| `preview.py` | Renders one body as plain HTML, purely to eyeball layout in a browser |
| `canvas.json` | Artboard positions, the two pages, and the sticky notes |
| `*.dc.html` | The artboards themselves — what gets seeded |

## Rebuild

```bash
python3 gen2.py && python3 gen3.py && python3 gen4.py
for n in Main Selection Thread Composer Mobile MobileDrawer AccountOptions; do
  python3 wrap.py $n 1440 900   # 390 844 for the two Mobile artboards
done
```

`body-*.html`, `preview-*.html` and the seeded payload are derived and not tracked.

## Decisions the artboards encode

- **Split pane, not a separate page.** Every open is currently a navigation and
  every back another one.
- **Three-line list rows.** A single line was tried first; in a 420px pane it
  truncated every subject after three words and left no room for the snippet at
  all. Split-pane clients stack them for exactly this reason.
- **Reply stays in the thread**, new mail composes in a floating panel — neither
  takes you off the screen you were reading.
- **A colour stripe marks the mailbox.** Three alternatives sit beside it in
  `AccountOptions` with their costs written out; the text badge the app uses today
  is one of them, and it visibly eats subject width.
