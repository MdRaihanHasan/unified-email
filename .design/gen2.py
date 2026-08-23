#!/usr/bin/env python3
import pathlib
HERE = pathlib.Path(__file__).resolve().parent
import sys
sys.path.insert(0, str(HERE))
from _parts import ico, iconbtn, avatar, PROVIDERS
from gen import topbar, sidebar, mail_list, W, H, TOPBAR, SIDEBAR, LISTW

PANE = W - SIDEBAR - LISTW

def msg_collapsed(name, initial, tint, snippet, when):
    return f'''
        <div style="display:flex;align-items:center;gap:11px;padding:9px 20px;
                    border-bottom:1px solid var(--line)">
          {avatar(initial, tint, 26)}
          <span style="font-weight:600;flex:0 0 auto">{name}</span>
          <span class="truncate" style="flex:1;color:var(--ink-3)">{snippet}</span>
          <span style="font-size:12px;color:var(--ink-3);flex:0 0 auto">{when}</span>
        </div>'''

def blocked_notice():
    return f'''
          <div style="display:flex;align-items:center;gap:9px;margin:0 0 16px;padding:8px 12px;
                      border:1px solid var(--line);border-radius:6px;background:var(--panel-2);
                      font-size:12px">
            {ico('warn',15,'var(--ink-2)')}
            <span style="color:var(--ink-2)">1 remote image blocked — loading it tells the sender you opened this.</span>
            <button type="button" style="margin-left:auto;border:0;background:none;padding:0;
                    color:var(--accent);font-family:inherit;font-size:12px;font-weight:600">Show images</button>
          </div>'''

def msg_expanded(*, reply_open=False):
    p = PROVIDERS['work']
    return f'''
        <div style="padding:16px 20px 20px">
          <div style="display:flex;align-items:flex-start;gap:11px">
            {avatar('A', PROVIDERS['outlook']['var'], 34)}
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:baseline;gap:8px">
                <span style="font-weight:600">Anna Bergström</span>
                <span style="font-size:12px;color:var(--ink-3)">anna@client.test</span>
                <span style="margin-left:auto;font-size:12px;color:var(--ink-3);flex:0 0 auto">Aug 21, 8:44 PM</span>
              </div>
              <div style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink-3);margin-top:2px">
                <span>to me@company.com</span>
                <span style="width:1px;height:11px;background:var(--line)"></span>
                <span style="display:inline-flex;align-items:center;gap:5px">
                  <span style="width:7px;height:7px;border-radius:999px;background:{p['var']}"></span>Work
                </span>
              </div>
            </div>
            <div style="display:flex;gap:1px;flex:0 0 auto">
              {iconbtn('star','Star',18,'var(--star)')}{iconbtn('reply','Reply',18)}{iconbtn('more','More',18)}
            </div>
          </div>

          <div style="margin:16px 0 0 45px">
            {blocked_notice()}
            <p style="margin:0 0 12px">Forwarding to your work address as well, in case that is easier.</p>
            <blockquote style="margin:0 0 16px;padding-left:14px;border-left:2px solid var(--line);
                              color:var(--ink-2)">
              <p style="margin:0 0 10px">Could we move to <strong style="color:var(--ink)">net-30</strong>
                 for the next quarter? Our finance team is reworking the schedule.</p>
              <p style="margin:0">Attached is the current statement.</p>
            </blockquote>

            <div style="display:flex;align-items:center;gap:9px;width:fit-content;padding:7px 11px;
                        border:1px solid var(--line);border-radius:6px;background:var(--panel-2)">
              {ico('clip',15,'var(--ink-2)')}
              <span style="font-size:13px;font-weight:500">statement-2418.pdf</span>
              <span style="font-size:12px;color:var(--ink-3)">180 KB</span>
            </div>

            {reply_box() if reply_open else action_buttons()}
          </div>
        </div>'''

def action_buttons():
    btn = lambda i, l: f'''<button type="button" style="display:flex;align-items:center;gap:7px;
        height:34px;padding:0 15px;border:1px solid var(--line);border-radius:999px;
        background:var(--panel);color:var(--ink);font-family:inherit;font-size:13px;font-weight:500">
        {ico(i,16)} {l}</button>'''
    return f'''
            <div style="display:flex;gap:8px;margin-top:20px">
              {btn('reply','Reply')}{btn('replyall','Reply all')}{btn('forward','Forward')}
            </div>'''

def reply_box():
    return f'''
            <div style="margin-top:20px;border:1px solid var(--line);border-radius:8px;
                        background:var(--panel);box-shadow:var(--shadow);overflow:hidden">
              <div style="display:flex;align-items:center;gap:9px;padding:9px 13px;
                          border-bottom:1px solid var(--line);font-size:12px;color:var(--ink-2)">
                {ico('reply',15,'var(--ink-2)')}
                <span>Reply to <strong style="color:var(--ink);font-weight:600">Anna Bergström</strong></span>
                <span style="width:1px;height:11px;background:var(--line)"></span>
                <span style="display:inline-flex;align-items:center;gap:5px">
                  from <span style="width:7px;height:7px;border-radius:999px;background:{PROVIDERS['work']['var']}"></span>
                  <strong style="color:var(--ink);font-weight:600">me@company.com</strong>
                </span>
                <span style="margin-left:auto;display:flex;gap:1px">
                  {iconbtn('expand','Full screen',16)}{iconbtn('close','Discard',16)}
                </span>
              </div>

              <div style="padding:13px 14px 8px;min-height:96px">
                <p style="margin:0 0 4px">Net-30 works for us. I&#39;ll send the signed copy tomorrow.</p>
                <span style="display:inline-block;width:1.5px;height:17px;background:var(--accent);
                             vertical-align:-3px"></span>
                <div style="margin-top:12px;display:flex;align-items:center;gap:7px;font-size:12px;
                            color:var(--ink-3)">
                  {ico('chevdown',14,'var(--ink-3)')} <span>Quoted message</span>
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:8px;padding:9px 13px;
                          border-top:1px solid var(--line);background:var(--panel-2)">
                <button type="button" style="display:flex;align-items:center;gap:8px;height:34px;
                        padding:0 17px;border:0;border-radius:999px;background:var(--ink);
                        color:var(--panel);font-family:inherit;font-size:13px;font-weight:600">
                  {ico('send',16)} Send
                </button>
                {iconbtn('attach','Attach',18)}
                <span style="margin-left:auto;font-size:12px;color:var(--ink-3)">Draft saved</span>
              </div>
            </div>'''

def reading_pane(*, reply_open=False, width=PANE):
    return f'''
      <section style="width:{width}px;flex:1;display:flex;flex-direction:column;
                      background:var(--panel);overflow:hidden">
        <div style="display:flex;align-items:center;gap:10px;padding:14px 20px 12px;
                    border-bottom:1px solid var(--line);flex:0 0 auto">
          <div style="min-width:0;flex:1">
            <div style="display:flex;align-items:center;gap:9px">
              <h1 style="margin:0;font-size:17px;font-weight:600;letter-spacing:-.01em"
                  class="truncate">Invoice 2418 — payment terms</h1>
              <span style="font-size:12px;color:var(--ink-3);flex:0 0 auto">2 messages</span>
            </div>
            <div style="display:flex;align-items:center;gap:7px;margin-top:3px;font-size:12px;
                        color:var(--ink-3)">
              <span style="display:inline-flex;align-items:center;gap:5px">
                <span style="width:7px;height:7px;border-radius:999px;background:{PROVIDERS['outlook']['var']}"></span>Personal
              </span>
              {ico('plus',12,'var(--ink-3)')}
              <span style="display:inline-flex;align-items:center;gap:5px">
                <span style="width:7px;height:7px;border-radius:999px;background:{PROVIDERS['work']['var']}"></span>Work
              </span>
              <span style="color:var(--ink-3)">— stitched across two mailboxes</span>
            </div>
          </div>
          <div style="display:flex;gap:1px;flex:0 0 auto">
            {iconbtn('star','Star',19,'var(--star)')}{iconbtn('archive','Archive',19)}
            {iconbtn('trash','Delete',19)}{iconbtn('more','More',19)}
          </div>
        </div>
        <div style="flex:1;min-height:0;overflow:hidden">
          {msg_collapsed('Anna Bergström','A',PROVIDERS['outlook']['var'],
                         'Could we move to net-30 for the next quarter?','Aug 20')}
          {msg_expanded(reply_open=reply_open)}
        </div>
      </section>'''

def frame(inner, *, w=W, h=H):
    return f'''<div class="app {{{{theme}}}}" style="width:{w}px;height:{h}px;display:flex;
     flex-direction:column;background:var(--bg);color:var(--ink);overflow:hidden">
{inner}
</div>'''

def desktop(*, hover=None, checked=(), open_index=2, reply_open=False, extra=''):
    return frame(f'''{topbar()}
  <div style="flex:1;display:flex;min-height:0;position:relative">
{sidebar()}
{mail_list(hover_index=hover, checked=checked, open_index=open_index)}
{reading_pane(reply_open=reply_open)}
{extra}
  </div>''')

(HERE / 'body-Main.html').write_text(desktop(hover=3))
(HERE / 'body-Selection.html').write_text(desktop(checked=(0, 2, 4), open_index=None))
(HERE / 'body-Thread.html').write_text(desktop(reply_open=True))
print('desktop bodies written')
