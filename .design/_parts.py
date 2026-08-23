"""Shared markup fragments, so seven artboards stay one design rather than seven."""

ICONS = {
 'menu': '<path d="M4 7h16M4 12h16M4 17h16"/>',
 'search': '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
 'settings': '<circle cx="12" cy="12" r="3"/><path d="M12 3v2m0 14v2M5 12H3m18 0h-2M6.3 6.3 4.9 4.9m14.2 14.2-1.4-1.4M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4"/>',
 'refresh': '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v4h-4"/>',
 'archive': '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 13h4"/>',
 'trash': '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
 'clock': '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
 'mailopen': '<path d="M4 10v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9l-8-5z"/><path d="M4 10l8 5 8-5"/>',
 'star': '<path d="m12 4 2.5 5.3 5.5.7-4 3.9 1 5.6L12 16.8 7 19.5l1-5.6-4-3.9 5.5-.7z"/>',
 'clip': '<path d="M16 7 9.4 13.6a2.5 2.5 0 0 0 3.5 3.5l6.6-6.6a4.5 4.5 0 0 0-6.4-6.4L6 11.3a6.5 6.5 0 0 0 9.2 9.2l4.3-4.3"/>',
 'reply': '<path d="M9 7 4 12l5 5"/><path d="M4 12h9a6 6 0 0 1 6 6v1"/>',
 'replyall': '<path d="M8 7 3 12l5 5"/><path d="M13 7l-5 5 5 5"/><path d="M8 12h7a5 5 0 0 1 5 5v1"/>',
 'forward': '<path d="M15 7l5 5-5 5"/><path d="M20 12h-9a6 6 0 0 0-6 6v1"/>',
 'more': '<circle cx="12" cy="5.5" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="12" cy="18.5" r="1.3"/>',
 'send': '<path d="M4 12l16-7-7 16-2.5-6.5z"/>',
 'close': '<path d="M6 6l12 12M18 6L6 18"/>',
 'plus': '<path d="M12 5v14M5 12h14"/>',
 'chevdown': '<path d="M7 10l5 5 5-5"/>',
 'chevleft': '<path d="M14 7l-5 5 5 5"/>',
 'minimize': '<path d="M6 12h12"/>',
 'expand': '<path d="M9 5H5v4M15 19h4v-4"/>',
 'pencil': '<path d="M5 19h3l10-10-3-3L5 16z"/>',
 'attach': '<path d="M16 7 9.4 13.6a2.5 2.5 0 0 0 3.5 3.5l6.6-6.6a4.5 4.5 0 0 0-6.4-6.4L6 11.3a6.5 6.5 0 0 0 9.2 9.2l4.3-4.3"/>',
 'inbox': '<path d="M4 13h4l1.5 3h5L16 13h4"/><path d="M4 13 6.5 5h11L20 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/>',
 'check': '<path d="M5 12.5 10 17l9-10"/>',
 'warn': '<path d="M12 4 3 20h18z"/><path d="M12 10v4M12 17v.5"/>',
}

def ico(name, size=20, color='currentColor', extra=''):
    cls = 'ico' if size == 20 else 'ico-s'
    return (f'<svg viewBox="0 0 24 24" class="{cls}" '
            f'style="width:{size}px;height:{size}px;color:{color};{extra}">{ICONS[name]}</svg>')

def iconbtn(name, title, size=20, color='var(--ink-2)'):
    return (f'<button type="button" title="{title}" class="iconbtn" '
            f'style="color:{color}">{ico(name, size)}</button>')

# Provider identity. These three are ours, not the providers' — the point is only
# that three mailboxes stay tellable apart in one merged list.
PROVIDERS = {
    'work':    {'var': 'var(--p-work)',    'label': 'Work',       'short': 'W'},
    'gmail':   {'var': 'var(--p-gmail)',   'label': 'Old Gmail',  'short': 'G'},
    'outlook': {'var': 'var(--p-outlook)', 'label': 'Personal',   'short': 'P'},
}

def avatar(initial, tint, size=28):
    return (f'<div style="width:{size}px;height:{size}px;border-radius:999px;flex:0 0 auto;'
            f'display:flex;align-items:center;justify-content:center;'
            f'background:{tint};color:#fff;font-size:{int(size*0.43)}px;font-weight:600;'
            f'letter-spacing:.01em">{initial}</div>')

# The seven threads used across every artboard, so the mockups read as one mailbox.
# Bangla is in here deliberately: it has to survive the type ramp and truncation.
THREADS = [
    dict(p='work',    who='Ops Team',        initial='O', subject='Deploy window moved to Thursday',
         snippet='Moving tonight&#39;s release to Thursday 22:00 so the migration lands off-peak.',
         when='5:26 PM', unread=True,  star=False, clip=False, count=None),
    dict(p='work',    who='Priya Raman',     initial='P', subject='Q3 roadmap review — agenda',
         snippet='Draft agenda attached. Two open questions on the billing rework.',
         when='12:26 PM', unread=False, star=False, clip=True, count=None),
    dict(p='outlook', who='Anna Bergström',  initial='A', subject='Invoice 2418 — payment terms',
         snippet='Forwarding to your work address as well, in case that is easier.',
         when='Aug 21', unread=True,  star=True,  clip=True, count='2', both=True),
    dict(p='gmail',   who='Shop',            initial='S', subject='Your order has shipped',
         snippet='Tracking number 4188-22 — expected Tuesday.',
         when='Aug 21', unread=False, star=False, clip=False, count=None),
    dict(p='gmail',   who='হিসাব বিভাগ',      initial='হ', subject='চালান ৪২ — ধন্যবাদ',
         snippet='গত মাসের চালান পরিশোধিত হয়েছে। রসিদ সংযুক্ত করা হলো।',
         when='Aug 21', unread=True,  star=False, clip=True, count=None),
    dict(p='outlook', who='Registrar',       initial='R', subject='Renewal reminder: domain bixcel.com.au',
         snippet='Auto-renew is on. Nothing to do unless you want to change plan.',
         when='Aug 20', unread=False, star=True,  clip=False, count=None),
    dict(p='gmail',   who='Sam',             initial='S', subject='Re: Weekend plans',
         snippet='Saturday works. Shall we say eleven at the usual place?',
         when='Aug 19', unread=False, star=False, clip=False, count='4'),
]
