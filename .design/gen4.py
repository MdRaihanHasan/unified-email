#!/usr/bin/env python3
import pathlib
HERE = pathlib.Path(__file__).resolve().parent
import sys
sys.path.insert(0, str(HERE))
from _parts import ico, avatar, PROVIDERS
from gen2 import frame

AW, AH = 1140, 660

SAMPLE = [
    ('work',    'Ops Team',       'O', 'Deploy window moved to Thursday', True),
    ('gmail',   'হিসাব বিভাগ',     'হ', 'চালান ৪২ — ধন্যবাদ',              True),
    ('outlook', 'Anna Bergström', 'A', 'Invoice 2418 — payment terms',   False),
]

def sample_rows(kind):
    out = []
    for key, who, initial, subject, unread in SAMPLE:
        p = PROVIDERS[key]
        bold = '600' if unread else '400'
        bg = 'var(--panel)' if unread else 'transparent'

        stripe = f'border-left:3px solid {p["var"]};'
        ring = f'box-shadow:0 0 0 2px var(--panel),0 0 0 3.5px {p["var"]};'

        av = avatar(initial, p['var'] if kind in ('stripe', 'dot') else 'var(--ink-3)', 26)
        if kind == 'ring':
            av = (f'<span style="display:inline-flex;border-radius:999px;{ring}flex:0 0 auto">'
                  f'{avatar(initial, "var(--ink-3)", 26)}</span>')

        lead = ''
        if kind == 'dot':
            lead = (f'<span style="width:7px;height:7px;border-radius:999px;'
                    f'background:{p["var"]};flex:0 0 auto"></span>')

        badge = ''
        if kind == 'badge':
            badge = (f'<span style="flex:0 0 auto;padding:1px 6px;border-radius:4px;'
                     f'background:{p["var"]};color:#fff;font-size:10px;font-weight:600;'
                     f'letter-spacing:.05em;text-transform:uppercase">{p["label"]}</span>')

        out.append(f'''
          <div style="display:flex;align-items:center;gap:10px;height:40px;padding:0 12px 0 0;
                      border-bottom:1px solid var(--line);background:{bg};
                      {stripe if kind == 'stripe' else ''}">
            <span style="padding-left:{9 if kind == 'stripe' else 12}px;display:flex;
                         align-items:center;gap:9px;flex:0 0 auto">{lead}{av}</span>
            <span class="truncate" style="width:116px;flex:0 0 auto;font-weight:{bold}">{who}</span>
            {badge}
            <span class="truncate" style="flex:1;font-weight:{bold}">{subject}</span>
            <span style="font-size:12px;color:var(--ink-3);flex:0 0 auto">Aug 21</span>
          </div>''')
    return ''.join(out)

def card(letter, title, rows, why, cost, recommended=False):
    tag = ('<span style="margin-left:auto;padding:2px 9px;border-radius:999px;'
           'background:var(--sel);color:var(--accent);font-size:11px;font-weight:600">'
           'used in the mockups</span>') if recommended else ''
    return f'''
      <div style="display:flex;flex-direction:column;border:1px solid var(--line);
                  border-radius:10px;background:var(--panel);overflow:hidden">
        <div style="display:flex;align-items:center;gap:10px;padding:13px 16px 12px;
                    border-bottom:1px solid var(--line)">
          <span style="display:flex;align-items:center;justify-content:center;width:22px;height:22px;
                       border-radius:6px;background:var(--panel-2);font-size:12px;font-weight:600;
                       color:var(--ink-2);flex:0 0 auto">{letter}</span>
          <span style="font-weight:600">{title}</span>
          {tag}
        </div>
        <div>{rows}</div>
        <div style="padding:12px 16px 14px;display:flex;flex-direction:column;gap:5px">
          <div style="display:flex;gap:8px;font-size:12.5px">
            <span style="color:var(--ink-3);width:44px;flex:0 0 auto">Works</span>
            <span style="color:var(--ink-2)">{why}</span>
          </div>
          <div style="display:flex;gap:8px;font-size:12.5px">
            <span style="color:var(--ink-3);width:44px;flex:0 0 auto">Costs</span>
            <span style="color:var(--ink-2)">{cost}</span>
          </div>
        </div>
      </div>'''

cards = ''.join([
    card('A', 'Colour stripe down the row', sample_rows('stripe'),
         'Reads at a glance without occupying a column; scales to 500 rows.',
         'Three more hues to keep straight, and a stripe is easy to miss on a dense screen.',
         recommended=True),
    card('B', 'Tinted ring on the avatar', sample_rows('ring'),
         'Ties the mailbox to the person, and leaves the row edge clean.',
         'Thin ring at 26px; hard to tell apart in dark mode or at a glance.'),
    card('C', 'Text badge (what the app does today)', sample_rows('badge'),
         'Unambiguous — it says the mailbox name outright.',
         'Eats subject width on every row and turns to visual noise past ~30 rows.'),
    card('D', 'Dot before the sender', sample_rows('dot'),
         'Quiet, cheap, familiar from calendar apps.',
         'One more thing in the busiest part of the row, and easy to confuse with the unread dot.'),
])

body = frame(f'''
  <div style="flex:1;padding:26px 28px;display:flex;flex-direction:column;gap:18px;min-height:0">
    <div>
      <h1 style="margin:0 0 5px;font-size:19px;font-weight:600;letter-spacing:-.015em">
        Which mailbox did this arrive in?</h1>
      <p style="margin:0;color:var(--ink-2);max-width:760px">
        A problem a single-mailbox client never has to solve: merging three inboxes hides the one
        thing that decides how you reply. Four ways to put it back, all using the same three hues
        as the sidebar.</p>
    </div>
    <div style="flex:1;min-height:0;display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));
                gap:18px">{cards}</div>
  </div>''', w=AW, h=AH)

(HERE / 'body-AccountOptions.html').write_text(body)
print('options body written')
