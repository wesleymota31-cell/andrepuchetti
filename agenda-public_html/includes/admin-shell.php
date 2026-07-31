<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/seo.php';
app_start_session();

function admin_icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Z"/><path d="M14 20h6v-9h-6v9Z"/><path d="M4 20h6v-3H4v3Z"/><path d="M14 7h6V4h-6v3Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v3"/><path d="M17 3v3"/><path d="M4 9h16"/><path d="M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/><path d="M8 13h.01"/><path d="M12 13h.01"/><path d="M16 13h.01"/><path d="M8 17h.01"/><path d="M12 17h.01"/></svg>',
        'clients' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19c0-2.2-1.8-4-4-4H8c-2.2 0-4 1.8-4 4"/><path d="M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M20 18c0-1.8-1.2-3.3-2.8-3.8"/><path d="M16 3.4a4 4 0 0 1 0 7.2"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-3"/><path d="M20 16V6"/></svg>',
        'services' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M7 12h10"/><path d="M9 17h6"/><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/></svg>',
        'block' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/><path d="m5.7 5.7 12.6 12.6"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 6V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6a2 2 0 0 1-2-2v-1"/><path d="M15 12H3"/><path d="m6 8-4 4 4 4"/></svg>',
        'chevron' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function admin_shell_start(string $title, string $active = ''): void
{
    $userName = $_SESSION['usuario_nome'] ?? 'Admin';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
  <title><?= htmlspecialchars($title); ?></title>
<?php render_seo_meta(
    $title,
    'Painel administrativo do sistema de agenda do Salão André Puchetti.',
    ['favicon_path' => '../assets/logo-salao.png', 'robots' => 'noindex, nofollow']
); ?>
  <style>
    :root {
      --bg: #070707;
      --panel: rgba(255,255,255,0.06);
      --panel-soft: rgba(255,255,255,0.04);
      --border: rgba(255,255,255,0.08);
      --border-gold: rgba(212,175,55,0.24);
      --text: #f7f3ea;
      --text-soft: rgba(247,243,234,0.78);
      --text-muted: rgba(247,243,234,0.55);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --green: #20c997;
      --red: #ff5f6d;
      --shadow: 0 20px 60px rgba(0,0,0,0.45);
      --sidebar-open: 270px;
      --sidebar-closed: 92px;
    }

    * { box-sizing: border-box; }

    html, body {
      margin: 0;
      min-height: 100%;
      font-family: Inter, Arial, sans-serif;
      background:
        radial-gradient(circle at 15% 20%, rgba(212,175,55,0.10), transparent 22%),
        radial-gradient(circle at 85% 15%, rgba(212,175,55,0.08), transparent 20%),
        linear-gradient(180deg, #050505 0%, #090909 35%, #0d0d0d 100%);
      color: var(--text);
    }

    .admin-grid {
      display: grid;
      grid-template-columns: var(--sidebar-open) 1fr;
      min-height: 100vh;
      transition: grid-template-columns .28s ease;
    }

    .admin-grid.is-collapsed {
      grid-template-columns: var(--sidebar-closed) 1fr;
    }

    .sidebar {
      position: sticky;
      top: 0;
      height: 100vh;
      padding: 18px 14px;
      border-right: 1px solid rgba(255,255,255,0.06);
      background: rgba(8,8,8,0.88);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      display: flex;
      flex-direction: column;
      gap: 16px;
      z-index: 20;
    }

    .sidebar::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background-image:
        linear-gradient(rgba(212,175,55,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(212,175,55,0.03) 1px, transparent 1px);
      background-size: 36px 36px;
      opacity: 0.25;
    }

    .sidebar-top,
    .nav,
    .sidebar-footer {
      position: relative;
      z-index: 2;
    }

    .brand-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 14px;
    }

    .brand-wrap {
      min-width: 0;
      transition: opacity .22s ease, transform .22s ease;
    }

    .brand-wrap small {
      display: block;
      color: var(--gold-soft);
      letter-spacing: 0.16em;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      margin-bottom: 5px;
    }

    .brand-wrap strong {
      display: block;
      font-size: 1.08rem;
      line-height: 1.1;
      font-weight: 800;
      white-space: nowrap;
    }

    .sidebar-toggle,
    .mobile-toggle {
      width: 42px;
      height: 42px;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(255,255,255,0.075), rgba(255,255,255,0.035));
      color: var(--text);
      border-radius: 14px;
      cursor: pointer;
      transition: .22s ease;
      display: inline-grid;
      place-items: center;
    }

    .sidebar-toggle svg,
    .mobile-toggle svg {
      width: 18px;
      height: 18px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .sidebar-toggle:hover,
    .mobile-toggle:hover {
      transform: translateY(-1px);
      border-color: var(--border-gold);
      box-shadow: 0 0 18px rgba(212,175,55,0.10);
    }

    .user-box {
      border-radius: 18px;
      padding: 14px;
      background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
      border: 1px solid rgba(255,255,255,0.08);
    }

    .user-box small {
      display: block;
      color: var(--gold-soft);
      letter-spacing: 0.12em;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    .user-box strong {
      display: block;
      font-size: 0.98rem;
      color: var(--text);
    }

    .nav {
      display: grid;
      gap: 8px;
    }

    .nav-link,
    .quick-link {
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 52px;
      padding: 0 14px;
      border-radius: 16px;
      color: var(--text-soft);
      text-decoration: none;
      border: 1px solid transparent;
      transition: .22s ease;
      position: relative;
      overflow: hidden;
    }

    .quick-link {
      min-height: 52px;
      border-radius: 16px;
      background:
        linear-gradient(180deg, rgba(255,95,109,0.12), rgba(255,255,255,0.035)),
        radial-gradient(circle at top left, rgba(212,175,55,0.10), transparent 42%);
      border-color: rgba(255,95,109,0.18);
      color: #ffe8eb;
    }

    .nav-link::before {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(90deg, rgba(212,175,55,0.08), rgba(255,255,255,0.02));
      opacity: 0;
      transition: .22s ease;
    }

    .nav-link:hover,
    .quick-link:hover {
      color: var(--text);
      transform: translateY(-1px);
      border-color: var(--border-gold);
    }

    .quick-link:hover {
      border-color: rgba(255,95,109,0.32);
      box-shadow: 0 14px 28px rgba(255,95,109,0.08), 0 0 22px rgba(212,175,55,0.06);
    }

    .nav-link:hover::before,
    .nav-link.active::before {
      opacity: 1;
    }

    .nav-link.active {
      border-color: var(--border-gold);
      color: var(--text);
      box-shadow: 0 0 0 1px rgba(212,175,55,0.08);
      background: rgba(255,255,255,0.03);
    }

    .nav-icon {
      width: 24px;
      height: 24px;
      flex: 0 0 24px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      position: relative;
      z-index: 1;
      color: var(--gold-soft);
    }

    .nav-icon svg {
      width: 21px;
      height: 21px;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
      filter: drop-shadow(0 0 10px rgba(212,175,55,0.12));
    }

    .quick-link .nav-icon {
      color: #ffd5da;
    }

    .nav-text,
    .quick-text {
      position: relative;
      z-index: 1;
      font-weight: 700;
      white-space: nowrap;
      transition: opacity .18s ease, transform .18s ease;
    }

    .sidebar-footer {
      margin-top: auto;
      display: grid;
      gap: 8px;
    }

    .admin-grid.is-collapsed .brand-wrap,
    .admin-grid.is-collapsed .user-box,
    .admin-grid.is-collapsed .nav-text,
    .admin-grid.is-collapsed .quick-text {
      opacity: 0;
      transform: translateX(-8px);
      pointer-events: none;
      width: 0;
      overflow: hidden;
    }

    .admin-grid.is-collapsed .nav-link,
    .admin-grid.is-collapsed .quick-link {
      justify-content: center;
      padding-inline: 0;
    }

    .main {
      min-width: 0;
      padding: 22px 22px 40px;
    }

    .mobile-topbar {
      display: none;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 18px;
    }

    .mobile-brand small {
      display: block;
      color: var(--gold-soft);
      letter-spacing: 0.14em;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      margin-bottom: 4px;
    }

    .mobile-brand strong {
      display: block;
      font-size: 1rem;
      font-weight: 800;
    }

    .mobile-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 18;
    }

    @media (max-width: 980px) {
      .admin-grid {
        grid-template-columns: 1fr;
      }

      .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: min(84vw, 320px);
        transform: translateX(-110%);
        transition: transform .28s ease;
        box-shadow: 0 30px 80px rgba(0,0,0,0.5);
      }

      .admin-grid.mobile-open .sidebar {
        transform: translateX(0);
      }

      .mobile-topbar {
        display: flex;
      }

      .mobile-overlay {
        display: block;
        opacity: 0;
        visibility: hidden;
        transition: .22s ease;
      }

      .admin-grid.mobile-open .mobile-overlay {
        opacity: 1;
        visibility: visible;
      }

      .main {
        padding: 18px 14px 34px;
      }
    }
  </style>
</head>
<body>
  <div class="admin-grid" id="adminGrid">
    <aside class="sidebar" id="adminSidebar">
      <div class="sidebar-top">
        <div class="brand-row">
          <div class="brand-wrap">
            <small>Painel administrativo</small>
            <strong>André Puchetti</strong>
          </div>
          <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Recolher menu"><?= admin_icon('chevron'); ?></button>
        </div>

        <div class="user-box">
          <small>Logado como</small>
          <strong><?= htmlspecialchars($userName); ?></strong>
        </div>
      </div>

      <nav class="nav">
        <a class="nav-link <?= $active === 'agenda' ? 'active' : ''; ?>" href="index.php">
          <span class="nav-icon"><?= admin_icon('dashboard'); ?></span>
          <span class="nav-text">Dashboard</span>
        </a>

        <a class="nav-link <?= $active === 'agenda_visual' ? 'active' : ''; ?>" href="agenda-visual.php">
  <span class="nav-icon"><?= admin_icon('calendar'); ?></span>
  <span class="nav-text">Agenda visual</span>
</a>

<a class="nav-link <?= $active === 'clientes' ? 'active' : ''; ?>" href="clientes.php">
  <span class="nav-icon"><?= admin_icon('clients'); ?></span>
  <span class="nav-text">Clientes</span>
</a>

<a class="nav-link <?= $active === 'relatorios' ? 'active' : ''; ?>" href="relatorios.php">
  <span class="nav-icon"><?= admin_icon('reports'); ?></span>
  <span class="nav-text">Relatórios</span>
</a>

<a class="nav-link <?= $active === 'servicos' ? 'active' : ''; ?>" href="servicos.php">
  <span class="nav-icon"><?= admin_icon('services'); ?></span>
  <span class="nav-text">Serviços</span>
</a>

<a class="nav-link <?= $active === 'bloqueios' ? 'active' : ''; ?>" href="bloquear.php">
  <span class="nav-icon"><?= admin_icon('block'); ?></span>
  <span class="nav-text">Bloquear horário</span>
</a>

        
      </nav>

      <div class="sidebar-footer">
        <a class="quick-link" href="../logout.php">
          <span class="nav-icon"><?= admin_icon('logout'); ?></span>
          <span class="quick-text">Sair</span>
        </a>
      </div>
    </aside>

    <div class="mobile-overlay" id="mobileOverlay"></div>

    <main class="main">
      <div class="mobile-topbar">
        <div class="mobile-brand">
          <small>Painel</small>
          <strong>André Puchetti</strong>
        </div>
        <button class="mobile-toggle" id="mobileToggle" type="button" aria-label="Abrir menu"><?= admin_icon('menu'); ?></button>
      </div>
<?php
}

function admin_shell_end(): void
{
    ?>
    </main>
  </div>

  <script>
    (function () {
      const grid = document.getElementById('adminGrid');
      const sidebarToggle = document.getElementById('sidebarToggle');
      const mobileToggle = document.getElementById('mobileToggle');
      const mobileOverlay = document.getElementById('mobileOverlay');
      const storageKey = 'adminSidebarCollapsed';

      if (window.innerWidth > 980) {
        const saved = localStorage.getItem(storageKey);
        if (saved === '1') grid.classList.add('is-collapsed');
      }

      if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
          grid.classList.toggle('is-collapsed');
          localStorage.setItem(storageKey, grid.classList.contains('is-collapsed') ? '1' : '0');
        });
      }

      function closeMobileMenu() {
        grid.classList.remove('mobile-open');
      }

      if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
          grid.classList.toggle('mobile-open');
        });
      }

      if (mobileOverlay) {
        mobileOverlay.addEventListener('click', closeMobileMenu);
      }

      window.addEventListener('resize', function () {
        if (window.innerWidth > 980) {
          grid.classList.remove('mobile-open');
        }
      });

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

      function ensureFormToken(form) {
        if (!csrfToken || !form || String(form.method || '').toLowerCase() !== 'post') return;
        let input = form.querySelector('input[name="csrf_token"]');
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'csrf_token';
          form.appendChild(input);
        }
        input.value = csrfToken;
      }

      document.querySelectorAll('form').forEach(ensureFormToken);
      document.addEventListener('submit', function (event) {
        ensureFormToken(event.target);
      }, true);

      if (window.fetch && csrfToken) {
        const originalFetch = window.fetch.bind(window);
        window.fetch = function (resource, options) {
          options = options || {};
          const method = String(options.method || 'GET').toUpperCase();

          if (method !== 'GET' && method !== 'HEAD') {
            if (options.body instanceof FormData) {
              if (!options.body.has('csrf_token')) options.body.append('csrf_token', csrfToken);
            } else if (options.body instanceof URLSearchParams) {
              if (!options.body.has('csrf_token')) options.body.append('csrf_token', csrfToken);
            } else if (typeof options.body === 'string') {
              const params = new URLSearchParams(options.body);
              if (!params.has('csrf_token')) {
                params.append('csrf_token', csrfToken);
                options.body = params.toString();
              }
            }
          }

          return originalFetch(resource, options);
        };
      }

      function parseWhatsappHref(href) {
        try {
          const url = new URL(href, window.location.href);
          const host = url.hostname.replace(/^www\./, '').toLowerCase();
          let phone = '';
          let text = '';

          if (host === 'wa.me') {
            phone = url.pathname.replace(/\D/g, '');
            text = url.searchParams.get('text') || '';
          } else if (host === 'api.whatsapp.com' || host === 'web.whatsapp.com') {
            phone = (url.searchParams.get('phone') || '').replace(/\D/g, '');
            text = url.searchParams.get('text') || '';
          }

          if (!phone) return null;
          return { phone, text };
        } catch (error) {
          return null;
        }
      }

      function buildWhatsappQuery(data) {
        const params = new URLSearchParams();
        params.set('phone', data.phone);
        if (data.text) params.set('text', data.text);
        return params.toString();
      }

      function openWhatsappWithFallback(data, originalHref, targetBlank) {
        const isAndroid = /Android/i.test(navigator.userAgent || '');
        const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
        const webFallback = originalHref || `https://wa.me/${data.phone}`;

        if (!isMobile) {
          if (targetBlank) {
            window.open(webFallback, '_blank', 'noopener');
          } else {
            window.location.href = webFallback;
          }
          return;
        }

        const query = buildWhatsappQuery(data);
        const attempts = isAndroid
          ? [
              `intent://send?${query}#Intent;scheme=whatsapp;package=com.whatsapp.w4b;end`,
              `intent://send?${query}#Intent;scheme=whatsapp;package=com.whatsapp;end`,
              webFallback
            ]
          : [
              `whatsapp-business://send?${query}`,
              `whatsapp://send?${query}`,
              webFallback
            ];

        let abandonedPage = false;
        const markAbandoned = function () {
          abandonedPage = true;
        };

        document.addEventListener('visibilitychange', function onVisibilityChange() {
          if (document.visibilityState === 'hidden') markAbandoned();
        }, { once: true });
        window.addEventListener('pagehide', markAbandoned, { once: true });

        const tryAttempt = function (index) {
          if (abandonedPage || !attempts[index]) return;
          window.location.href = attempts[index];

          if (index < attempts.length - 1) {
            window.setTimeout(function () {
              tryAttempt(index + 1);
            }, 900);
          }
        };

        tryAttempt(0);
      }

      window.openAdminWhatsapp = function (phone, text) {
        const digits = String(phone || '').replace(/\D+/g, '');
        if (!digits) return false;

        const href = `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
        openWhatsappWithFallback({ phone: digits, text: text || '' }, href, true);
        return true;
      };

      document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href]');
        if (!link) return;

        const whatsappData = parseWhatsappHref(link.href);
        if (!whatsappData) return;

        event.preventDefault();
        openWhatsappWithFallback(whatsappData, link.href, link.target === '_blank');
      }, true);
    })();
  </script>
</body>
</html>
<?php
}
