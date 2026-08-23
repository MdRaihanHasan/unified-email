#!/usr/bin/env python3
"""Shared desktop chrome. One source of truth so seven artboards stay one design."""
from _parts import ico, iconbtn, avatar, PROVIDERS, THREADS

W, H = 1440, 900
TOPBAR, SIDEBAR, LISTW = 60, 240, 420

def topbar():
    return f'''
  <header style="height:{TOPBAR}px;flex:0 0 auto;display:flex;align-items:center;gap:12px;
                 padding:0 12px 0 8px;background:var(--panel);border-bottom:1px solid var(--line)">
    {iconbtn('menu','Menu')}
    <div style="display:flex;align-items:baseline;gap:7px;width:{SIDEBAR-58}px;flex:0 0 auto">
      <span style="font-size:15px;font-weight:600;letter-spacing:-.01em">Unified</span>
      <span style="font-size:15px;font-weight:400;color:var(--ink-3)">mail</span>
    </div>
    <div style="flex:1;max-width:720px;display:flex;align-items:center;gap:10px;height:40px;
                padding:0 14px;border-radius:999px;background:var(--panel-2)">
      {ico('search',20,'var(--ink-2)')}
      <span style="color:var(--ink-3)">Search all mailboxes</span>
      <span style="margin-left:auto;color:var(--ink-3);font-size:12px">
        <kbd style="padding:1px 5px;border:1px solid var(--line);border-radius:4px;
                    font-family:inherit">/</kbd>
      </span>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:2px">
      {iconbtn('refresh','Sync now')}
      {iconbtn('settings','Light / dark')}
      {avatar('R','var(--accent)',30)}
    </div>
  </header>'''

def sidebar(active='Inbox'):
    views = [('inbox','Inbox','3'), ('mailopen','Unread','3'),
             ('star','Starred','2'), ('send','Sent',None), ('archive','All mail',None)]
    rows = []
    for name, label, count in views:
        on = label == active
        bg = 'var(--sel)' if on else 'transparent'
        col = 'var(--accent)' if on else 'var(--ink-2)'
        fw = '600' if on else '400'
        badge = f'<span style="margin-left:auto;font-size:12px;font-weight:600">{count}</span>' if count else ''
        rows.append(f'''
      <div style="display:flex;align-items:center;gap:12px;height:34px;padding:0 12px 0 11px;
                  border-radius:0 999px 999px 0;background:{bg};color:{col};font-weight:{fw}">
        {ico(name,18)}<span class="truncate">{label}</span>{badge}
      </div>''')

    accts = ''.join(f'''
      <div style="display:flex;align-items:center;gap:12px;height:32px;padding:0 12px 0 11px;
                  color:var(--ink-2)">
        <span style="width:8px;height:8px;border-radius:999px;background:{p['var']};
                     margin-left:5px;flex:0 0 auto"></span>
        <span class="truncate">{p['label']}</span>
      </div>''' for p in PROVIDERS.values())

    tri = ('linear-gradient(135deg,var(--p-work) 0 33%,var(--p-gmail) 33% 66%,'
           'var(--p-outlook) 66%)')

    return f'''
    <aside style="width:{SIDEBAR}px;flex:0 0 auto;display:flex;flex-direction:column;
                  padding:12px 8px 10px;background:var(--panel);border-right:1px solid var(--line)">
      <button type="button" style="display:flex;align-items:center;justify-content:center;gap:9px;
              height:42px;margin:0 4px 14px;border:0;border-radius:999px;background:var(--ink);
              color:var(--panel);font-family:inherit;font-size:14px;font-weight:600;
              box-shadow:var(--shadow)">
        {ico('pencil',18)} Compose
      </button>
      {''.join(rows)}
      <div style="height:1px;background:var(--line);margin:12px 12px 10px"></div>
      <div style="padding:0 12px 6px;font-size:11px;font-weight:600;letter-spacing:.06em;
                  text-transform:uppercase;color:var(--ink-3)">Mailboxes</div>
      <div style="display:flex;align-items:center;gap:12px;height:32px;padding:0 12px 0 11px;
                  color:var(--accent);font-weight:600">
        <span style="width:8px;height:8px;border-radius:999px;margin-left:5px;background:{tri}"></span>
        <span class="truncate">All mailboxes</span>
      </div>
      {accts}
      <div style="margin-top:auto;display:flex;align-items:center;gap:10px;padding:8px 12px 0;
                  font-size:12px;color:var(--ink-3)">
        {ico('check',15,'var(--p-gmail)')} <span>Synced 40s ago</span>
      </div>
    </aside>'''

def row(t, *, selected=False, hovered=False, checked=False):
    """Three lines, not one.

    A 420px pane cannot hold sender, subject and snippet on one line — every
    subject truncated after three words. Split-pane clients stack them, so this does.
    """
    p = PROVIDERS[t['p']]
    bold = '600' if t['unread'] else '400'
    bg = ('var(--sel)' if selected else
          'var(--hover)' if hovered else
          'var(--panel)' if t['unread'] else 'transparent')

    star = (ico('star',15,'var(--star)','fill:var(--star)') if t['star']
            else ico('star',15,'var(--ink-3)'))
    check = (f'<span style="width:15px;height:15px;border-radius:3px;flex:0 0 auto;'
             f'display:flex;align-items:center;justify-content:center;background:var(--accent);'
             f'color:#fff">{ico("check",11)}</span>' if checked else
             '<span style="width:15px;height:15px;border-radius:3px;'
             'border:1.6px solid var(--ink-3);flex:0 0 auto"></span>')

    if hovered:
        tail = ('<div style="display:flex;align-items:center;gap:1px;margin-left:auto;flex:0 0 auto">'
                + iconbtn('archive','Archive',16) + iconbtn('trash','Delete',16)
                + iconbtn('mailopen','Mark read',16) + iconbtn('clock','Snooze',16) + '</div>')
    else:
        tail = (f'<span style="margin-left:auto;font-size:12px;color:var(--ink-2);'
                f'font-weight:{bold};flex:0 0 auto">{t["when"]}</span>')

    count = (f'<span style="flex:0 0 auto;font-size:11px;font-weight:600;color:var(--ink-3);'
             f'padding:0 4px;border:1px solid var(--line);border-radius:4px">{t["count"]}</span>'
             if t['count'] else '')

    # A thread stitched from two mailboxes carries the second hue as a second dot.
    second = (f'<span style="width:6px;height:6px;border-radius:999px;'
              f'background:{PROVIDERS["work"]["var"]};flex:0 0 auto"></span>'
              if t.get('both') else '')

    clip = ico('clip',14,'var(--ink-3)') if t['clip'] else ''

    return f'''
        <div style="display:flex;align-items:flex-start;gap:10px;padding:9px 10px 10px 0;
                    border-bottom:1px solid var(--line);background:{bg};
                    border-left:3px solid {p['var']}">
          <div style="display:flex;align-items:center;gap:9px;padding:2px 0 0 9px;flex:0 0 auto">
            {check}{star}
          </div>
          {avatar(t['initial'], p['var'], 30)}
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:6px">
              <span class="truncate" style="font-weight:{bold};color:var(--ink)">{t['who']}</span>
              {second}{count}{tail}
            </div>
            <div class="truncate" style="margin-top:1px;font-weight:{bold};color:var(--ink)">
              {t['subject']}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:1px">
              <span class="truncate" style="flex:1;font-size:12.5px;color:var(--ink-3)">{t['snippet']}</span>
              {clip}
            </div>
          </div>
        </div>'''

def list_header(selected_count=0):
    if selected_count:
        acts = ''.join(iconbtn(i, l, 18, 'var(--accent)') for i, l in
                       [('archive','Archive'),('trash','Delete'),
                        ('mailopen','Mark read'),('clock','Snooze'),('more','More')])
        return f'''
        <div style="height:44px;flex:0 0 auto;display:flex;align-items:center;gap:2px;
                    padding:0 8px 0 15px;background:var(--sel);border-bottom:1px solid var(--line)">
          <span style="width:15px;height:15px;border-radius:3px;background:var(--accent);color:#fff;
                       display:flex;align-items:center;justify-content:center;
                       margin-right:9px">{ico('check',11)}</span>
          {acts}
          <span style="margin-left:auto;font-size:12px;font-weight:600;color:var(--accent)">
            {selected_count} selected</span>
        </div>'''
    older = '<span style="transform:rotate(180deg);display:flex">' + iconbtn('chevleft','Older',18) + '</span>'
    return f'''
        <div style="height:44px;flex:0 0 auto;display:flex;align-items:center;gap:2px;
                    padding:0 8px 0 15px;border-bottom:1px solid var(--line)">
          <span style="width:15px;height:15px;border-radius:3px;border:1.6px solid var(--ink-3);
                       margin-right:9px;flex:0 0 auto"></span>
          {iconbtn('refresh','Sync',18)}{iconbtn('more','More',18)}
          <span style="margin-left:auto;font-size:12px;color:var(--ink-3)">1–7 of 7</span>
          {iconbtn('chevleft','Newer',18)}{older}
        </div>'''

def mail_list(*, hover_index=None, checked=(), open_index=None, width=LISTW):
    rows = ''.join(row(t, hovered=(i == hover_index), checked=(i in checked),
                       selected=(i in checked) or i == open_index)
                   for i, t in enumerate(THREADS))
    return f'''
      <section style="width:{width}px;flex:0 0 auto;display:flex;flex-direction:column;
                      background:var(--bg);border-right:1px solid var(--line);overflow:hidden">
        {list_header(len(checked))}
        <div style="flex:1;min-height:0">{rows}</div>
      </section>'''
