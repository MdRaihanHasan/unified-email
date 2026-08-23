#!/usr/bin/env python3
import pathlib
from _parts import ico, iconbtn, avatar, PROVIDERS, THREADS
from gen import topbar, sidebar, mail_list
from gen2 import frame, desktop, W, H

def field(label, value, extra=''):
    return f'''
        <div style="display:flex;align-items:center;gap:10px;padding:8px 15px;
                    border-bottom:1px solid var(--line)">
          <span style="width:34px;flex:0 0 auto;font-size:12px;color:var(--ink-3)">{label}</span>
          {value}{extra}
        </div>'''

def chip(name, tint):
    return (f'<span style="display:inline-flex;align-items:center;gap:6px;height:24px;'
            f'padding:0 4px 0 3px;border-radius:999px;background:var(--panel-2);font-size:12.5px">'
            f'{avatar(name[0], tint, 18)}<span>{name}</span>'
            f'<span style="color:var(--ink-3);padding-right:5px">&#215;</span></span>')

def toolbtn(label, style=''):
    return (f'<span style="display:flex;align-items:center;justify-content:center;width:28px;'
            f'height:28px;border-radius:5px;color:var(--ink-2);font-size:13px;{style}">{label}</span>')

# ---------------------------------------------------------------- composer
dot_work = f'<span style="width:8px;height:8px;border-radius:999px;background:{PROVIDERS["work"]["var"]}"></span>'

from_val = (f'<span style="display:inline-flex;align-items:center;gap:7px;font-size:13px">'
            f'{dot_work} me@company.com</span>')
to_val = (f'<span style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;flex:1">'
          f'{chip("Anna Bergström", PROVIDERS["outlook"]["var"])}'
          f'{chip("Bob Ek", PROVIDERS["work"]["var"])}'
          f'<span style="color:var(--ink-3);font-size:13px">Add another&#8230;</span></span>')

composer_html = f'''
    <div style="position:absolute;right:20px;bottom:0;width:600px;height:552px;
                display:flex;flex-direction:column;background:var(--panel);
                border:1px solid var(--line);border-radius:10px 10px 0 0;
                box-shadow:var(--shadow);overflow:hidden">
      <div style="display:flex;align-items:center;gap:8px;height:42px;padding:0 8px 0 15px;
                  background:var(--panel-2);border-bottom:1px solid var(--line)">
        <span style="font-size:13px;font-weight:600">New message</span>
        <span style="margin-left:auto;display:flex;gap:1px">
          {iconbtn('minimize','Minimise',16)}{iconbtn('expand','Full screen',16)}
          {iconbtn('close','Close',16)}
        </span>
      </div>

      {field('From', from_val, '<span style="margin-left:auto">' + ico('chevdown',16,'var(--ink-3)') + '</span>')}
      {field('To', to_val, '<span style="margin-left:auto;font-size:12px;color:var(--accent);font-weight:600">Cc Bcc</span>')}
      {field('Subj', '<span style="font-size:13px">Statement 2418 &#8212; signed copy</span>')}

      <div style="flex:1;min-height:0;display:flex;flex-direction:column">
        <div style="display:flex;align-items:center;gap:1px;padding:5px 10px;
                    border-bottom:1px solid var(--line)">
          {toolbtn('B','font-weight:700')}{toolbtn('I','font-style:italic')}
          {toolbtn('S','text-decoration:line-through')}
          <span style="width:1px;height:18px;background:var(--line);margin:0 6px"></span>
          {toolbtn('&#8220;&#8221;')}{toolbtn('&#8226;','font-size:15px')}{toolbtn('1.','font-size:12px')}
        </div>

        <div style="flex:1;padding:14px 16px;font-size:13.5px">
          <p style="margin:0 0 11px">Hi Anna,</p>
          <p style="margin:0 0 11px">Net-30 works for us &#8212; signed statement attached.</p>
          <p style="margin:0">Thanks,<br>Raihan<span style="display:inline-block;width:1.5px;
             height:16px;background:var(--accent);vertical-align:-3px;margin-left:1px"></span></p>
        </div>

        <div style="padding:0 16px 12px">
          <div style="display:flex;align-items:center;gap:9px;width:fit-content;padding:6px 11px;
                      border:1px solid var(--line);border-radius:6px;background:var(--panel-2)">
            {ico('clip',15,'var(--ink-2)')}
            <span style="font-size:12.5px;font-weight:500">statement-2418-signed.pdf</span>
            <span style="font-size:12px;color:var(--ink-3)">204 KB</span>
            <span style="color:var(--ink-3);font-size:14px">&#215;</span>
          </div>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;
                  border-top:1px solid var(--line);background:var(--panel-2)">
        <button type="button" style="display:flex;align-items:center;gap:8px;height:36px;
                padding:0 19px;border:0;border-radius:999px;background:var(--ink);
                color:var(--panel);font-family:inherit;font-size:13.5px;font-weight:600">
          {ico('send',16)} Send
        </button>
        {iconbtn('attach','Attach',18)}
        <span style="margin-left:auto;display:flex;align-items:center;gap:10px">
          <span style="font-size:12px;color:var(--ink-3)">Draft saved</span>
          {iconbtn('trash','Discard',18)}
        </span>
      </div>
    </div>'''

pathlib.Path('body-Composer.html').write_text(desktop(extra=composer_html))

# ---------------------------------------------------------------- mobile
MW, MH = 390, 844

def m_row(t):
    p = PROVIDERS[t['p']]
    bold = '600' if t['unread'] else '400'
    star = ico('star',18,'var(--star)','fill:var(--star)') if t['star'] else ico('star',18,'var(--ink-3)')
    clip = ico('clip',15,'var(--ink-3)') if t['clip'] else ''
    bg = 'var(--panel)' if t['unread'] else 'transparent'
    return f'''
      <div style="display:flex;align-items:flex-start;gap:12px;padding:11px 14px 11px 0;
                  border-bottom:1px solid var(--line);border-left:3px solid {p['var']};
                  background:{bg}">
        <div style="padding-left:11px;flex:0 0 auto">{avatar(t['initial'], p['var'], 40)}</div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:baseline;gap:8px">
            <span class="truncate" style="font-weight:{bold};flex:1">{t['who']}</span>
            <span style="font-size:12px;color:var(--ink-3);flex:0 0 auto">{t['when']}</span>
          </div>
          <div class="truncate" style="font-weight:{bold};margin-top:1px">{t['subject']}</div>
          <div class="truncate" style="font-size:13px;color:var(--ink-3);margin-top:1px">{t['snippet']}</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:9px;padding-top:2px;
                    flex:0 0 auto;width:24px">{star}{clip}</div>
      </div>'''

m_header = f'''
    <header style="flex:0 0 auto;padding:10px 12px;background:var(--panel);
                   border-bottom:1px solid var(--line)">
      <div style="display:flex;align-items:center;gap:10px;height:46px;padding:0 6px 0 12px;
                  border-radius:999px;background:var(--panel-2)">
        {ico('menu',22,'var(--ink-2)')}
        <span style="flex:1;color:var(--ink-3)">Search all mailboxes</span>
        {avatar('R','var(--accent)',32)}
      </div>
    </header>'''

m_fab = f'''
    <button type="button" style="position:absolute;right:18px;bottom:26px;display:flex;
            align-items:center;gap:10px;height:56px;padding:0 22px;border:0;border-radius:999px;
            background:var(--ink);color:var(--panel);font-family:inherit;font-size:15px;
            font-weight:600;box-shadow:var(--shadow)">
      {ico('pencil',21)} Compose
    </button>'''

m_rows = ''.join(m_row(t) for t in THREADS[:6])

pathlib.Path('body-Mobile.html').write_text(frame(f'''{m_header}
  <div style="flex:1;min-height:0;position:relative;overflow:hidden">
    <div>{m_rows}</div>
{m_fab}
  </div>''', w=MW, h=MH))

def d_item(i, l, c, on=False):
    bg = 'var(--sel)' if on else 'transparent'
    col = 'var(--accent)' if on else 'var(--ink-2)'
    fw = '600' if on else '400'
    count = f'<span style="margin-left:auto;font-size:13px;font-weight:600">{c}</span>' if c else ''
    return f'''
        <div style="display:flex;align-items:center;gap:15px;height:48px;padding:0 18px 0 17px;
                    border-radius:0 999px 999px 0;background:{bg};color:{col};font-weight:{fw};
                    font-size:15px">{ico(i,20)}<span class="truncate">{l}</span>{count}</div>'''

def d_acct(p):
    return f'''
        <div style="display:flex;align-items:center;gap:15px;height:46px;padding:0 18px;
                    color:var(--ink-2);font-size:15px">
          <span style="width:10px;height:10px;border-radius:999px;background:{p['var']};
                       margin-left:5px;flex:0 0 auto"></span>
          <span class="truncate">{p['label']}</span>
        </div>'''

drawer = f'''
    <div style="position:absolute;inset:0;background:oklch(0% 0 0 / .45)"></div>
    <aside style="position:absolute;left:0;top:0;bottom:0;width:306px;display:flex;
                  flex-direction:column;padding:18px 0 14px;background:var(--panel);
                  box-shadow:var(--shadow)">
      <div style="display:flex;align-items:baseline;gap:7px;padding:0 18px 18px">
        <span style="font-size:19px;font-weight:600;letter-spacing:-.01em">Unified</span>
        <span style="font-size:19px;color:var(--ink-3)">mail</span>
      </div>
      {d_item('inbox','Inbox','3',True)}{d_item('mailopen','Unread','3')}
      {d_item('star','Starred','2')}{d_item('send','Sent',None)}{d_item('archive','All mail',None)}
      <div style="height:1px;background:var(--line);margin:12px 18px"></div>
      <div style="padding:0 18px 8px;font-size:11px;font-weight:600;letter-spacing:.06em;
                  text-transform:uppercase;color:var(--ink-3)">Mailboxes</div>
      {''.join(d_acct(p) for p in PROVIDERS.values())}
      <div style="margin-top:auto;padding:0 18px">
        <div style="height:1px;background:var(--line);margin-bottom:12px"></div>
        <div style="display:flex;align-items:center;gap:13px;height:44px;color:var(--ink-2);
                    font-size:15px">{ico('settings',20)} Settings</div>
      </div>
    </aside>'''

pathlib.Path('body-MobileDrawer.html').write_text(frame(f'''{m_header}
  <div style="flex:1;min-height:0;position:relative;overflow:hidden">
    <div>{m_rows}</div>
{drawer}
  </div>''', w=MW, h=MH))

print('composer + mobile bodies written')
