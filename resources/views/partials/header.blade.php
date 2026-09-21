@php
    $isAdmin       = (session('user_role') === 'admin');

    // Delegated Admins work only within their delegated CRM scope.
    // Settings remains available to normal Admins and Super Admins,
    // but is hidden from Delegated Admins.
    $canAccessSettings = ! app(\App\Services\DelegatedAccessService::class)
        ->hasProfile((int) session('user_id'));
    $isTeamManager = (session('user_role') === 'team_manager');
    $canManageTeam = ($isAdmin || $isTeamManager);
    // Keep the mobile drawer/user menu name reliable even if the legacy
    // user_name session value is missing or stale. The authenticated user id
    // is already the canonical session identity used throughout the CRM.
    $currentUser   = auth()->user() ?: \App\Models\User::find((int) session('user_id'));
    $userName      = trim((string) ($currentUser?->name ?? '')) ?: trim((string) session('user_name')) ?: 'User';
    $userRole      = $currentUser?->role?->value ?: (string) session('user_role', '');
    $roleLabel     = [
        'admin'        => '👑 Admin',
        'team_manager' => '🎯 Manager',
        'agent'        => '👤 Agent',
    ][$userRole] ?? $userRole;
    $canSeeContacts = app(\App\Services\AccessService::class)->canAccessContacts();
@endphp

{{-- Desktop navigation: work-first, with the CRM organised behind it. --}}
<aside class="desktop-sidebar" aria-label="CRM navigation">
    <div class="desktop-sidebar-brand">
        <a href="{{ url('/') }}" class="sidebar-logo">🏢 <span>NPO CRM</span></a>
    </div>

    <nav class="sidebar-nav">
        <div class="sidebar-group-label">WORK</div>
        <a href="{{ url('/') }}" class="sidebar-link {{ request()->is('/') ? 'active' : '' }}">
            <span>🏠</span><span>Dashboard</span>
        </a>
        @if ($userRole !== 'admin')
            <a href="{{ url('/tasks') }}" class="sidebar-link {{ request()->is('tasks*') ? 'active' : '' }}">
                <span>⚡</span><span>My Work</span>
            </a>
        @endif

        <div class="sidebar-group-label">CRM</div>
        <a href="{{ url('/leads') }}" class="sidebar-link {{ request()->is('leads') || request()->is('leads?*') ? 'active' : '' }}">
            <span>🎯</span><span>All Leads</span>
        </a>
        <a href="{{ url('/my-leads') }}" class="sidebar-link {{ request()->is('my-leads*') ? 'active' : '' }}">
            <span>👤</span><span>My Leads</span>
        </a>
        <a href="{{ url('/projects') }}" class="sidebar-link {{ request()->is('projects*') ? 'active' : '' }}">
            <span>🏗️</span><span>Projects</span>
        </a>

        @if ($canSeeContacts)
            <a href="{{ url('/contacts') }}" class="sidebar-link {{ request()->is('contacts') || request()->is('contacts/*') ? 'active' : '' }}">
                <span>📇</span><span>Contacts</span>
            </a>
            <a href="{{ url('/contacts/dialer') }}" class="sidebar-link {{ request()->is('contacts/dialer') ? 'active' : '' }}">
                <span>📞</span><span>Dialer</span>
            </a>
        @endif

        @if ($canManageTeam && ! app(\App\Services\DelegatedAccessService::class)->hasProfile((int) session('user_id')))
    <div class="sidebar-group-label">TEAM</div>
    <a href="{{ url('/my-team') }}" class="sidebar-link {{ request()->is('my-team*') ? 'active' : '' }}">
        <span>👥</span><span>My Team</span>
    </a>
    <a href="{{ url('/attendance') }}" class="sidebar-link {{ request()->is('attendance*') ? 'active' : '' }}">
        <span>🕒</span><span>Attendance</span>
    </a>
@endif

        @if ($isAdmin)
            <div class="sidebar-group-label">MANAGE</div>
            @if (app(\App\Services\SuperAdminService::class)->isSuperAdmin())
                <a href="{{ url('/admin/timeline-intelligence') }}" class="sidebar-link {{ request()->is('admin/timeline-intelligence*') ? 'active' : '' }}">
                    <span>🧠</span><span>Timeline Intelligence</span>
                </a>
            @endif
            @if (app(\App\Services\SuperAdminService::class)->isSuperAdmin())
                <a href="{{ url('/settings/delegated-access') }}" class="sidebar-link {{ request()->is('settings/delegated-access*') ? 'active' : '' }}">
                    <span>🛡️</span><span>Delegated Access</span>
                </a>
            @endif
            @if (app(\App\Services\SuperAdminService::class)->isSuperAdmin())
                <a href="{{ url('/admin/audit-logs') }}" class="sidebar-link {{ request()->is('admin/audit-logs*') ? 'active' : '' }}">
                    <span>🛡️</span><span>Vigilance Audit</span>
                </a>
            @endif
            @if ((app(\App\Services\AccessService::class)->can('incentives.view') || app(\App\Services\AccessService::class)->can('incentives.manage')))
                <a href="{{ url('/admin/incentives') }}" class="sidebar-link {{ request()->is('admin/incentives*') ? 'active' : '' }}">
                    <span>💰</span><span>Incentives</span>
                </a>
            @endif
            @if (app(\App\Services\AccessService::class)->isUnrestrictedAdmin())
                <a href="{{ url('/admin/booking-control') }}" class="sidebar-link {{ request()->is('admin/booking-control*') ? 'active' : '' }}">
                    <span>🎯</span><span>Booking Control</span>
                </a>
            @endif
            <a href="{{ url('/reports') }}" class="sidebar-link {{ request()->is('reports*') ? 'active' : '' }}">
                <span>📊</span><span>Reports</span>
            </a>
            <a href="{{ url('/import') }}" class="sidebar-link {{ request()->is('import*') ? 'active' : '' }}">
                <span>📤</span><span>Import Leads</span>
            </a>
            <a href="{{ url('/export/leads') }}" class="sidebar-link">
                <span>📥</span><span>Export Leads</span>
            </a>
            @if ($canAccessSettings)
    <a href="{{ url('/settings') }}" class="sidebar-link {{ request()->is('settings*') ? 'active' : '' }}">
        <span>⚙️</span><span>Settings</span>
    </a>
@endif
        @endif
    </nav>

    <div class="sidebar-bottom">
        <a href="{{ url('/notifications') }}" class="sidebar-link {{ request()->is('notifications*') ? 'active' : '' }}">
            <span>🔔</span><span>Notifications</span>
        </a>
        <a href="{{ url('/search') }}" class="sidebar-link {{ request()->is('search*') ? 'active' : '' }}">
            <span>🔍</span><span>Search</span>
        </a>
        <a href="{{ url('/agent-guide.html') }}" class="sidebar-link" target="_blank" rel="noopener">
            <span>📖</span><span>Guide</span>
        </a>
    </div>
</aside>

<header class="header">
    {{-- Desktop: compact header because the sidebar owns navigation. --}}
    <a href="{{ url('/') }}" class="hdr-logo desktop-brand-small">🏢 NPO CRM</a>

    <form method="GET" action="{{ url('/search') }}" class="hdr-search" role="search">
        <span class="hdr-search-icon">🔍</span>
        <input type="search" name="q"
               value="{{ request()->routeIs('search.index') ? request('q') : '' }}"
               placeholder="Search leads, contacts, projects…"
               autocomplete="off" spellcheck="false" aria-label="Search">
    </form>

    <div class="hdr-right desktop-only">
        @if ($userRole !== 'admin')<a href="{{ url('/tasks') }}" class="hdr-work-link {{ request()->is('tasks*') ? 'active' : '' }}">⚡ My Work</a>@endif
        <div class="notif-wrapper" id="notif-wrapper">
            <button type="button" class="notif-bell" id="notif-bell" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">🔔<span class="notif-badge" id="notif-badge" hidden>0</span></button>
            <div class="notif-panel" id="notif-panel" hidden>
                <div class="notif-panel-head"><strong>🔔 Notifications</strong><button type="button" class="btn-small btn-ghost" id="notif-mark-all" style="font-size:11px;padding:4px 8px;">Mark all read</button></div>
                <div class="notif-list" id="notif-list"><p class="muted" style="padding:16px;text-align:center;">Loading…</p></div>
                <div class="notif-panel-foot"><a href="{{ url('/notifications') }}">See all →</a></div>
            </div>
        </div>

        <div class="hdr-user" id="hdr-user">
            <button type="button" class="hdr-user-btn" id="hdr-user-btn" aria-haspopup="true" aria-expanded="false">
                <span class="hdr-user-avatar">{{ strtoupper(substr($userName ?: 'U', 0, 1)) }}</span>
                <span class="hdr-user-name">{{ explode(' ', $userName)[0] ?? 'User' }}</span>
                <span class="hdr-user-chevron">▾</span>
            </button>
            <div class="hdr-menu" id="hdr-menu" hidden>
                <div class="hdr-menu-head"><div class="hdr-menu-name">{{ $userName }}</div><div class="hdr-menu-role">{{ $roleLabel }}</div></div>
                <div class="hdr-menu-body">
                    <a href="{{ url('/notifications') }}" class="hdr-menu-item">🔔 Notifications</a>
                    <a href="{{ url('/my-whatsapp') }}" class="hdr-menu-item">💬 My WhatsApp</a>
                    <a href="{{ url('/agent-guide.html') }}" class="hdr-menu-item" target="_blank" rel="noopener">📖 Guide</a>
                    <a href="{{ url('/sop.html') }}" class="hdr-menu-item" target="_blank" rel="noopener">📋 SOP</a>
                    @if ($isAdmin)
                        <div class="hdr-menu-sep"></div>
                        <a href="{{ url('/admin/paste-lead') }}" class="hdr-menu-item">📋 Paste Lead</a>
                        <a href="{{ url('/admin/delete-lead') }}" class="hdr-menu-item hdr-menu-danger">🗑️ Delete Lead</a>
                    @endif
                </div>
                <div class="hdr-menu-foot">
                    <form method="POST" action="{{ route('logout') }}" style="margin:0" onsubmit="return confirm('Log out of NPO CRM?');">
                        @csrf
                        <button type="submit" class="hdr-menu-logout">🚪 Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="mobile-brand-wrap mobile-only"><a href="{{ url('/') }}" class="hdr-logo mobile-brand">🏢 <span>NPO CRM</span></a></div>

    <div class="hdr-right mobile-only">
        <a href="{{ url('/search') }}" class="hamburger-btn" aria-label="Search" style="text-decoration:none;">🔍</a>
        <div class="notif-wrapper" id="notif-wrapper-m">
            <button type="button" class="notif-bell" id="notif-bell-m" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">🔔<span class="notif-badge" id="notif-badge-m" hidden>0</span></button>
            <div class="notif-panel" id="notif-panel-m" hidden>
                <div class="notif-panel-head"><strong>🔔 Notifications</strong><button type="button" class="btn-small btn-ghost" id="notif-mark-all-m" style="font-size:11px;padding:4px 8px;">Mark all read</button></div>
                <div class="notif-list" id="notif-list-m"><p class="muted" style="padding:16px;text-align:center;">Loading…</p></div>
                <div class="notif-panel-foot"><a href="{{ url('/notifications') }}">See all →</a></div>
            </div>
        </div>
        <button type="button" class="hamburger-btn" id="hamburger-btn" aria-label="Menu"><span></span><span></span><span></span></button>
    </div>
</header>

{{-- Mobile navigation keeps the same information architecture as desktop. --}}
<div class="drawer-backdrop" id="drawer-backdrop" hidden></div>
<aside class="drawer" id="drawer" hidden>
    <div class="drawer-head">
        <div class="drawer-user-identity" aria-label="Signed in user">
            <div class="drawer-user-name">👋 {{ $userName }}</div>
            <div class="drawer-user-role">{{ $roleLabel }}</div>
        </div>
        <button type="button" class="drawer-close" id="drawer-close" aria-label="Close">✕</button>
    </div>
    <nav class="drawer-nav">
        <div class="drawer-group-label">WORK</div>
        <a href="{{ url('/') }}" class="drawer-link {{ request()->is('/') ? 'active' : '' }}">🏠 <span>Dashboard</span></a>
        @if ($userRole !== 'admin')
            <a href="{{ url('/tasks') }}" class="drawer-link {{ request()->is('tasks*') ? 'active' : '' }}">⚡ <span>My Work</span></a>
        @endif

        <div class="drawer-group-label">CRM</div>
        <a href="{{ url('/leads') }}" class="drawer-link {{ request()->is('leads') ? 'active' : '' }}">🎯 <span>All Leads</span></a>
        <a href="{{ url('/my-leads') }}" class="drawer-link {{ request()->is('my-leads*') ? 'active' : '' }}">👤 <span>My Leads</span></a>
        <a href="{{ url('/projects') }}" class="drawer-link {{ request()->is('projects*') ? 'active' : '' }}">🏗️ <span>Projects</span></a>
        @if ($canSeeContacts)
            <a href="{{ url('/contacts') }}" class="drawer-link {{ request()->is('contacts') ? 'active' : '' }}">📇 <span>Contacts</span></a>
            <a href="{{ url('/contacts/dialer') }}" class="drawer-link">📞 <span>Dialer</span></a>
        @endif

        @if ($canManageTeam && ! app(\App\Services\DelegatedAccessService::class)->hasProfile((int) session('user_id')))
    <div class="drawer-group-label">TEAM</div>
    <a href="{{ url('/my-team') }}" class="drawer-link {{ request()->is('my-team*') ? 'active' : '' }}">👥 <span>My Team</span></a>
    <a href="{{ url('/attendance') }}" class="drawer-link {{ request()->is('attendance*') ? 'active' : '' }}">🕒 <span>Attendance</span></a>
@endif

        @if ($isAdmin)
            <div class="drawer-group-label">MANAGE</div>
            @if (app(\App\Services\SuperAdminService::class)->isSuperAdmin())
                <a href="{{ url('/admin/timeline-intelligence') }}" class="sidebar-link {{ request()->is('admin/timeline-intelligence*') ? 'active' : '' }}">
                    <span>🧠</span><span>Timeline Intelligence</span>
                </a>
            @endif
            @if (app(\App\Services\SuperAdminService::class)->isSuperAdmin())
                <a href="{{ url('/settings/delegated-access') }}" class="drawer-link {{ request()->is('settings/delegated-access*') ? 'active' : '' }}">🛡️ <span>Delegated Access</span></a>
            @endif
            @if (app(\App\Services\SuperAdminService::class)->isSuperAdmin())
                <a href="{{ url('/admin/audit-logs') }}" class="drawer-link {{ request()->is('admin/audit-logs*') ? 'active' : '' }}">🛡️ <span>Vigilance Audit</span></a>
            @endif
            @if (app(\App\Services\AccessService::class)->isUnrestrictedAdmin())
                <a href="{{ url('/admin/booking-control') }}" class="drawer-link {{ request()->is('admin/booking-control*') ? 'active' : '' }}">🎯 <span>Booking Control</span></a>
            @endif
            <a href="{{ url('/reports') }}" class="drawer-link {{ request()->is('reports*') ? 'active' : '' }}">📊 <span>Reports</span></a>
            <a href="{{ url('/import') }}" class="drawer-link">📤 <span>Import Leads</span></a>
            <a href="{{ url('/export/leads') }}" class="drawer-link">📥 <span>Export Leads</span></a>
            @if ($canAccessSettings)
    <a href="{{ url('/settings') }}" class="drawer-link {{ request()->is('settings*') ? 'active' : '' }}">⚙️ <span>Settings</span></a>
@endif
            <a href="{{ url('/admin/paste-lead') }}" class="drawer-link">📋 <span>Paste Lead</span></a>
            <a href="{{ url('/admin/delete-lead') }}" class="drawer-link drawer-link-danger">🗑️ <span>Delete Lead</span></a>
        @endif

        @if ((app(\App\Services\AccessService::class)->can('incentives.view') || app(\App\Services\AccessService::class)->can('incentives.manage')))
            <div class="drawer-group-label">INCENTIVES</div>
            <a href="{{ url('/admin/incentives') }}" class="drawer-link {{ request()->is('admin/incentives*') ? 'active' : '' }}">💰 <span>Incentives</span></a>
        @endif

        <div class="drawer-group-label">HELP</div>
        <a href="{{ url('/notifications') }}" class="drawer-link">🔔 <span>Notifications</span></a>
        <a href="{{ url('/my-whatsapp') }}" class="drawer-link">💬 <span>My WhatsApp</span></a>
        <a href="{{ url('/search') }}" class="drawer-link">🔍 <span>Search</span></a>
        <a href="{{ url('/agent-guide.html') }}" class="drawer-link" target="_blank" rel="noopener">📖 <span>Guide</span></a>
        <a href="{{ url('/sop.html') }}" class="drawer-link" target="_blank" rel="noopener">📋 <span>SOP</span></a>
        <button type="button" class="drawer-link" data-install-app hidden style="width:100%;text-align:left;background:transparent;border:0;cursor:pointer;font-family:inherit;">📱 <span>Install App</span></button>
        <form method="POST" action="{{ route('logout') }}" style="margin:0" onsubmit="return confirm('Log out of NPO CRM?');">
            @csrf
            <button type="submit" class="drawer-link drawer-link-danger" style="width:100%;text-align:left;background:transparent;border:0;cursor:pointer;font-family:inherit;">🚪 <span>Logout</span></button>
        </form>
    </nav>
</aside>

<script>
(function () {
    var userBtn = document.getElementById('hdr-user-btn');
    var menu = document.getElementById('hdr-menu');
    var wrapper = document.getElementById('hdr-user');
    if (!userBtn || !menu || !wrapper) return;
    function closeMenu(){ menu.hidden=true; userBtn.setAttribute('aria-expanded','false'); }
    function openMenu(){ menu.hidden=false; userBtn.setAttribute('aria-expanded','true'); }
    userBtn.addEventListener('click',function(e){e.stopPropagation(); menu.hidden ? openMenu() : closeMenu();});
    document.addEventListener('click',function(e){if(!wrapper.contains(e.target)) closeMenu();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape') closeMenu();});
})();
</script>

<style>
/* Mobile drawer identity: keep the signed-in user's name visible and stable. */
/* Mobile drawer must sit above the persistent mobile bottom navigation. */
.drawer-backdrop{z-index:2147483000!important;}
.drawer{z-index:2147483001!important;}
.drawer-head,.drawer-nav{position:relative;z-index:1;}
.drawer-user-identity{min-width:0;flex:1;padding-right:10px;display:block!important;visibility:visible!important;opacity:1!important;color:var(--c-text,#111)}
.drawer-user-name{font-weight:800;font-size:15px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block!important;visibility:visible!important}
.drawer-user-role{margin-top:3px;font-size:12px;line-height:1.2;color:var(--c-muted,#666);display:block!important;visibility:visible!important}
.npo-wa-picker-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:grid;place-items:center;padding:18px}
.npo-wa-picker{width:min(430px,100%);background:var(--c-surface,#fff);color:var(--c-text,#111);border:1px solid var(--c-border,#ddd);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);padding:18px}
.npo-wa-picker h3{margin:0 0 6px;font-size:17px}.npo-wa-picker p{margin:0 0 14px;color:var(--c-muted,#666);font-size:12px;line-height:1.45}
.npo-wa-choice{width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;margin-top:9px;border:1px solid var(--c-border,#ddd);border-radius:11px;background:var(--c-surface-2,#f7f7f7);cursor:pointer;text-align:left;color:inherit}
.npo-wa-choice:hover{border-color:var(--c-primary,#2563eb)}.npo-wa-choice strong{display:block}.npo-wa-choice small{display:block;margin-top:3px;color:var(--c-muted,#666)}
.npo-wa-close{margin-top:12px;width:100%;padding:10px;border:1px solid var(--c-border,#ddd);border-radius:10px;background:transparent;cursor:pointer;color:inherit}
.npo-wa-error{padding:10px 12px;background:#fff6e5;border:1px solid #efd79b;border-radius:10px;color:#725719;font-size:12px;line-height:1.45}
</style>
<script>
(function(){'use strict';var picker=null;
function esc(v){return String(v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function closePicker(){if(picker){picker.remove();picker=null;}}
async function choose(phone,text,onLaunch){
 try{
  var res=await fetch('{{ url('/my-whatsapp/accounts') }}',{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
  var data=await res.json();
  if(!res.ok||!data.ok)throw new Error(data.message||'Could not load WhatsApp accounts.');
  var accounts=data.accounts||[];
  if(!accounts.length){window.location.href='{{ url('/my-whatsapp') }}';return;}
  render(accounts,phone,text,onLaunch);
 }catch(e){alert(e.message||'Could not load WhatsApp accounts.');}
}
function render(accounts,phone,text,onLaunch){
 picker=document.createElement('div');picker.className='npo-wa-picker-backdrop';
 var box=document.createElement('div');box.className='npo-wa-picker';
 box.innerHTML='<h3>💬 Choose WhatsApp</h3><p>Select the WhatsApp identity you intend to use for this action.</p><div id="npo-wa-choices"></div><button type="button" class="npo-wa-close">Cancel</button>';
 picker.appendChild(box);document.body.appendChild(picker);
 var list=box.querySelector('#npo-wa-choices');
 accounts.forEach(function(a){
  var b=document.createElement('button');b.type='button';b.className='npo-wa-choice';
  b.innerHTML='<span><strong>'+esc(a.label)+'</strong><small>'+esc(a.phone)+'</small></span><span>→</span>';
  b.addEventListener('click',function(){openWith(a.type,phone,text,onLaunch);});
  list.appendChild(b);
 });
 box.querySelector('.npo-wa-close').addEventListener('click',closePicker);
 picker.addEventListener('click',function(e){if(e.target===picker)closePicker();});
}
function openWith(type,phone,text,onLaunch){
 try{
  var encodedText = text ? encodeURIComponent(text) : '';

  // Site-team sharing has no recipient. Open the normal WhatsApp send page
  // in a separate tab/window. This is deliberately NOT an intent:// launch:
  // intent handlers are not consistently registered on desktop browsers or
  // Android Chrome, while api.whatsapp.com reliably hands off to WhatsApp
  // when available and otherwise lets the user continue in WhatsApp Web.
  if (!phone) {
   var waWindow = null;
   var fallbackUrl = 'https://api.whatsapp.com/send' + (encodedText ? '?text=' + encodedText : '');
   try { waWindow = window.open(fallbackUrl, '_blank'); } catch (_) {}
   if (!waWindow) {
    // Popup blockers may reject a new tab. Preserve the CRM page where
    // possible; the user can still retry from the picker.
    alert('Please allow pop-ups for NPO CRM to open WhatsApp in a new tab.');
    return;
   }
   closePicker();
   if (typeof onLaunch === 'function') onLaunch(waWindow);
   return;
  }

  // Direct lead/client WhatsApp actions retain the existing server-authorized
  // destination. They are separate from the Site WhatsApp picker path.
  var fd=new FormData();fd.append('_token','{{ csrf_token() }}');fd.append('account_type',type);fd.append('phone',phone);fd.append('text',text||'');
  fetch('{{ url('/my-whatsapp/open') }}',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
   .then(function(res){return res.json().then(function(data){return {res:res,data:data};});})
   .then(function(result){
    if(!result.res.ok||!result.data.ok)throw new Error(result.data.message||'Could not open WhatsApp.');
    window.location.href=result.data.url;
   })
   .catch(function(e){
    var box=picker&&picker.querySelector('.npo-wa-picker');
    if(box)box.querySelector('#npo-wa-choices').innerHTML='<div class="npo-wa-error">'+esc(e.message||'Could not open WhatsApp.')+'</div>';
   });
 }catch(e){
  var box=picker&&picker.querySelector('.npo-wa-picker');
  if(box)box.querySelector('#npo-wa-choices').innerHTML='<div class="npo-wa-error">'+esc(e.message||'Could not open WhatsApp.')+'</div>';
 }
}
async function chooseNudge(targetType,targetId){
 try{
  var res=await fetch('{{ url('/my-whatsapp/accounts') }}',{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
  var data=await res.json();
  if(!res.ok||!data.ok)throw new Error(data.message||'Could not load WhatsApp accounts.');
  var accounts=data.accounts||[];
  if(!accounts.length){window.location.href='{{ url('/my-whatsapp') }}';return;}
  picker=document.createElement('div');picker.className='npo-wa-picker-backdrop';
  var box=document.createElement('div');box.className='npo-wa-picker';
  box.innerHTML='<h3>💬 Nudge on WhatsApp</h3><p>The CRM will prepare the lead name, reason and action link. Choose the WhatsApp identity to send it from.</p><div id="npo-wa-choices"></div><button type="button" class="npo-wa-close">Cancel</button>';
  picker.appendChild(box);document.body.appendChild(picker);
  var list=box.querySelector('#npo-wa-choices');
  accounts.forEach(function(a){
   var b=document.createElement('button');b.type='button';b.className='npo-wa-choice';
   b.innerHTML='<span><strong>'+esc(a.label)+'</strong><small>'+esc(a.phone)+'</small></span><span>→</span>';
   b.addEventListener('click',function(){openNudgeWith(a.type,targetType,targetId);});
   list.appendChild(b);
  });
  box.querySelector('.npo-wa-close').addEventListener('click',closePicker);
  picker.addEventListener('click',function(e){if(e.target===picker)closePicker();});
 }catch(e){alert(e.message||'Could not open Nudge.');}
}
function openNudgeWith(accountType,targetType,targetId){
 var waWindow=null;
 try{waWindow=window.open('about:blank','_blank');}catch(_){waWindow=null;}
 if(!waWindow){alert('Please allow pop-ups for NPO CRM to open WhatsApp in a new window.');return;}
 waWindow.document.title='Opening WhatsApp…';
 var fd=new FormData();
 fd.append('_token','{{ csrf_token() }}');
 fd.append('account_type',accountType);
 fd.append('target_type',targetType);
 fd.append('target_id',targetId);
 fetch('{{ url('/team-status/nudge') }}',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
  .then(function(res){return res.json().then(function(data){return {res:res,data:data};});})
  .then(function(result){
   if(!result.res.ok||!result.data.ok)throw new Error(result.data.message||'Could not open WhatsApp.');
   waWindow.location.href=result.data.url;
   closePicker();
   var btn=document.querySelector('[data-npo-nudge][data-nudge-target-type="'+CSS.escape(targetType)+'"][data-nudge-target-id="'+CSS.escape(String(targetId))+'"]');
   if(btn){var count=btn.querySelector('.npo-nudge-count');if(count)count.textContent=result.data.nudge_count+'×';}
  })
  .catch(function(e){try{waWindow.close();}catch(_){} var box=picker&&picker.querySelector('.npo-wa-picker');if(box)box.querySelector('#npo-wa-choices').innerHTML='<div class="npo-wa-error">'+esc(e.message||'Could not open WhatsApp.')+'</div>';});
}
window.NpoWhatsApp={choose:choose,chooseNudge:chooseNudge};
document.addEventListener('click',function(e){
 var nudge=e.target.closest?e.target.closest('[data-npo-nudge]'):null;
 if(nudge){
  e.preventDefault();
  chooseNudge(nudge.getAttribute('data-nudge-target-type')||'',nudge.getAttribute('data-nudge-target-id')||'');
  return;
 }
 var btn=e.target.closest?e.target.closest('[data-npo-whatsapp]'):null;if(!btn)return;
 e.preventDefault();
 var phone=btn.getAttribute('data-whatsapp-phone')||'';var enc=btn.getAttribute('data-whatsapp-text')||'';var text='';
 try{text=enc?atob(enc):'';}catch(_){text='';}
 choose(phone,text);
});
})();
</script>
