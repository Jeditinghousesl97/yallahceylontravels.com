<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$activePage = 'colors';
$msg = $_GET['msg'] ?? '';

$s = [];
$res = $conn->query("SELECT skey, sval FROM settings");
if ($res) while ($row = $res->fetch_assoc()) $s[$row['skey']] = $row['sval'];

function saveSetting($conn, $key, $val) {
    $k = $conn->real_escape_string($key);
    $v = $conn->real_escape_string($val);
    $conn->query("INSERT INTO settings (skey,sval) VALUES ('$k','$v') ON DUPLICATE KEY UPDATE sval='$v'");
}

function normalizeAdminHex($value, $default) {
    $value = trim((string)($value ?? ''));
    if ($value === '') return strtoupper($default);
    if ($value[0] !== '#') $value = '#' . $value;
    if (!preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value)) return strtoupper($default);
    if (strlen($value) === 4) {
        $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
    }
    return strtoupper($value);
}

function cv($s, $key, $default) {
    return htmlspecialchars(normalizeAdminHex($s[$key] ?? '', $default), ENT_QUOTES, 'UTF-8');
}

$palette = [
    'theme_teal_dark'  => ['label' => 'Primary Dark', 'default' => '#0A3D3D'],
    'theme_teal'       => ['label' => 'Primary', 'default' => '#0F5252'],
    'theme_teal_mid'   => ['label' => 'Primary Mid', 'default' => '#156060'],
    'theme_teal_light' => ['label' => 'Primary Light', 'default' => '#1A7575'],
    'theme_sticky_header_bg' => ['label' => 'Sticky Header Background', 'default' => '#0A3D3D'],
    'theme_gold'       => ['label' => 'Secondary', 'default' => '#C9A84C'],
    'theme_gold_dark'  => ['label' => 'Secondary Dark', 'default' => '#A8782A'],
    'theme_gold_light' => ['label' => 'Secondary Light', 'default' => '#E2C97E'],
    'theme_gold_pale'  => ['label' => 'Secondary Pale', 'default' => '#F5ECD4'],
    'theme_white'      => ['label' => 'White', 'default' => '#FFFFFF'],
    'theme_off_white'  => ['label' => 'Soft Background', 'default' => '#F8F6F0'],
    'theme_text_dark'  => ['label' => 'Text Dark', 'default' => '#1A1A1A'],
    'theme_text_mid'   => ['label' => 'Text Mid', 'default' => '#444444'],
    'theme_text_light' => ['label' => 'Text Light', 'default' => '#777777'],
    'theme_footer_bg'  => ['label' => 'Footer Background', 'default' => '#05272A'],
    'theme_whatsapp'   => ['label' => 'WhatsApp / Success', 'default' => '#25D366'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($palette as $key => $meta) {
        saveSetting($conn, $key, normalizeAdminHex($_POST[$key] ?? '', $meta['default']));
    }
    header('Location: colors.php?msg=saved');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Colors | Yallah Ceylon Travels Admin</title>
<link rel="icon" type="image/png" href="../images/favicon.png"/>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>"/>
<style>
.page-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:24px;align-items:start}
.settings-section{background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.settings-section-head{padding:18px 22px;border-bottom:1px solid var(--border)}
.settings-section-head h2{font-size:15px;font-weight:700;color:var(--text-dark);margin:0 0 4px}
.settings-section-head p{font-size:12px;color:var(--text-light);margin:0}
.settings-section-body{padding:22px}
.palette-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.color-field{display:flex;gap:12px;align-items:center;padding:14px;border:1px solid var(--border);border-radius:12px;background:#fff}
.color-swatch{width:52px;height:52px;border-radius:14px;border:1px solid rgba(0,0,0,.08);flex-shrink:0}
.color-meta{min-width:0;flex:1}
.color-meta label{display:block;font-size:12px;font-weight:700;color:var(--text-dark);margin-bottom:4px}
.color-meta small{display:block;font-size:11px;color:var(--text-light);margin-bottom:8px}
.color-inputs{display:flex;gap:10px;align-items:center}
.color-picker{width:44px;height:38px;padding:0;border:none;background:none;cursor:pointer}
.hex-input{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;font-family:inherit}
.hex-input:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(15,82,82,.08)}
.preview-wrap{display:flex;flex-direction:column;gap:18px;position:sticky;top:92px}
.site-preview{border-radius:20px;overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow);background:var(--preview-white)}
.preview-hero{padding:28px;background:linear-gradient(145deg,var(--preview-teal-dark),var(--preview-teal));color:var(--preview-white)}
.preview-header{padding:14px 22px;background:var(--preview-sticky-header-bg);color:var(--preview-white);border-bottom:2px solid rgba(255,255,255,.12)}
.preview-header-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
.preview-header-brand{font-size:14px;font-weight:700;letter-spacing:.4px}
.preview-header-nav{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:rgba(255,255,255,.82);text-transform:uppercase;letter-spacing:1px}
.preview-kicker{display:inline-flex;align-items:center;gap:8px;font-size:11px;letter-spacing:1.4px;text-transform:uppercase;color:var(--preview-gold-light);margin-bottom:12px}
.preview-kicker::before,.preview-kicker::after{content:'';width:18px;height:1px;background:var(--preview-gold)}
.preview-hero h3{font-family:'Cormorant Garamond',serif;font-size:34px;line-height:1.1;margin:0 0 10px}
.preview-hero p{font-size:13px;line-height:1.7;color:rgba(255,255,255,.78);margin:0 0 18px}
.preview-actions{display:flex;gap:10px;flex-wrap:wrap}
.preview-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;border-radius:999px;font-size:12px;font-weight:700}
.preview-btn.primary{background:linear-gradient(135deg,var(--preview-gold),var(--preview-gold-dark));color:var(--preview-white)}
.preview-btn.secondary{background:transparent;color:var(--preview-white);border:1px solid rgba(255,255,255,.45)}
.preview-body{padding:22px;background:var(--preview-off-white)}
.preview-card{background:var(--preview-white);border-radius:16px;padding:18px;border:1px solid rgba(0,0,0,.06);box-shadow:0 10px 28px rgba(0,0,0,.05)}
.preview-card h4{font-size:18px;font-weight:700;color:var(--preview-text-dark);margin:0 0 8px}
.preview-card p{font-size:13px;color:var(--preview-text-mid);line-height:1.7;margin:0 0 14px}
.preview-pill-row{display:flex;gap:8px;flex-wrap:wrap}
.preview-pill{padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700}
.preview-pill.primary{background:rgba(15,82,82,.12);color:var(--preview-teal)}
.preview-pill.accent{background:var(--preview-gold-pale);color:var(--preview-teal-dark)}
.preview-pill.success{background:rgba(37,211,102,.12);color:var(--preview-whatsapp)}
.preview-footer{padding:16px 22px;background:var(--preview-footer-bg);color:rgba(255,255,255,.72);font-size:12px}
.role-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.role-chip{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;background:var(--off-white);font-size:12px;color:var(--text-mid)}
.role-chip span{width:18px;height:18px;border-radius:6px;display:block;flex-shrink:0}
.save-bar{position:sticky;bottom:0;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border-top:1px solid var(--border);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;z-index:100;margin:0 -22px -22px}
.save-bar-hint{font-size:12px;color:var(--text-light)}
@media(max-width:1100px){.page-grid{grid-template-columns:1fr}.preview-wrap{position:static}.palette-grid,.role-list{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="admin-wrapper">
<?php include 'includes/sidebar.php'; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="admin-main">
  <div class="admin-topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
      <div>
        <div class="topbar-title">Colors</div>
        <div class="topbar-breadcrumb">Admin / Colors</div>
      </div>
    </div>
  </div>

  <div class="admin-content">
    <div class="admin-content-inner">
      <?php if ($msg === 'saved'): ?>
        <div class="alert alert-success" style="margin-bottom:20px">
          <i class="fas fa-check-circle"></i>
          Color palette saved successfully.
        </div>
      <?php endif; ?>

      <div class="page-header" style="margin-bottom:20px">
        <div class="page-header-left">
          <h1>Website Color Palette</h1>
          <p>Manage the brand colors used across the public website from one place.</p>
        </div>
      </div>

      <form method="POST">
        <div class="page-grid">
          <div class="settings-section">
            <div class="settings-section-head">
              <h2>Palette Controls</h2>
              <p>These values override the public site CSS variables used by the header, buttons, cards, footer, and text styles.</p>
            </div>
            <div class="settings-section-body">
              <div class="palette-grid">
                <?php foreach ($palette as $key => $meta): $value = cv($s, $key, $meta['default']); ?>
                  <div class="color-field">
                    <div class="color-swatch" id="swatch-<?= $key ?>" style="background:<?= $value ?>"></div>
                    <div class="color-meta">
                      <label for="<?= $key ?>"><?= htmlspecialchars($meta['label']) ?></label>
                      <small>Default: <?= htmlspecialchars($meta['default']) ?></small>
                      <div class="color-inputs">
                        <input type="color" class="color-picker" id="<?= $key ?>_picker" value="<?= $value ?>" data-target="<?= $key ?>"/>
                        <input type="text" class="hex-input" id="<?= $key ?>" name="<?= $key ?>" value="<?= $value ?>" maxlength="7" data-swatch="swatch-<?= $key ?>"/>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="save-bar">
                <span class="save-bar-hint">Tip: keep enough contrast between primary backgrounds and text for readability.</span>
                <button type="submit" class="btn btn-primary" style="padding:11px 32px">
                  <i class="fas fa-save"></i> Save Palette
                </button>
              </div>
            </div>
          </div>

          <div class="preview-wrap">
            <div class="settings-section">
              <div class="settings-section-head">
                <h2>Live Preview</h2>
                <p>This mockup updates as you change the colors.</p>
              </div>
              <div class="settings-section-body">
                  <div class="site-preview" id="sitePreview">
                  <div class="preview-header">
                    <div class="preview-header-row">
                      <div class="preview-header-brand">Sticky Header</div>
                      <div class="preview-header-nav">
                        <span>Home</span>
                        <span>Tours</span>
                        <span>Contact</span>
                      </div>
                    </div>
                  </div>
                  <div class="preview-hero">
                    <div class="preview-kicker">Yallah Ceylon Travels</div>
                    <h3>Luxury journeys with calm tropical elegance</h3>
                    <p>See how your hero section, CTA buttons, and supporting content will feel with the selected palette.</p>
                    <div class="preview-actions">
                      <div class="preview-btn primary">Book Now</div>
                      <div class="preview-btn secondary">Explore Tours</div>
                    </div>
                  </div>
                  <div class="preview-body">
                    <div class="preview-card">
                      <h4>Curated travel experiences</h4>
                      <p>Primary and secondary colors shape headings, cards, pills, buttons, subtle surfaces, and content emphasis.</p>
                      <div class="preview-pill-row">
                        <span class="preview-pill primary">Primary</span>
                        <span class="preview-pill accent">Secondary</span>
                        <span class="preview-pill success">WhatsApp</span>
                      </div>
                    </div>
                  </div>
                  <div class="preview-footer">Footer background and soft text preview</div>
                </div>
              </div>
            </div>

            <div class="settings-section">
              <div class="settings-section-head">
                <h2>Color Roles</h2>
                <p>A quick guide for how this website’s palette is structured.</p>
              </div>
              <div class="settings-section-body">
                <div class="role-list">
                  <div class="role-chip"><span id="role-primary"></span>Primary</div>
                  <div class="role-chip"><span id="role-primary-dark"></span>Primary Dark</div>
                  <div class="role-chip"><span id="role-secondary"></span>Secondary</div>
                  <div class="role-chip"><span id="role-secondary-dark"></span>Secondary Dark</div>
                  <div class="role-chip"><span id="role-surface"></span>Surface</div>
                  <div class="role-chip"><span id="role-footer"></span>Footer</div>
                  <div class="role-chip"><span id="role-text-dark"></span>Text Dark</div>
                  <div class="role-chip"><span id="role-text-light"></span>Text Light</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
</div>

<script src="js/admin.js?v=<?= filemtime(__DIR__ . '/js/admin.js') ?>"></script>
<script>
function normalizeHex(value) {
  let hex = String(value || '').trim().toUpperCase();
  if (!hex) return '';
  if (!hex.startsWith('#')) hex = '#' + hex;
  if (/^#[0-9A-F]{3}$/.test(hex)) {
    hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
  }
  return /^#[0-9A-F]{6}$/.test(hex) ? hex : '';
}

function colorValue(id, fallback) {
  const input = document.getElementById(id);
  return normalizeHex(input ? input.value : '') || fallback;
}

function updatePreview() {
  const preview = document.getElementById('sitePreview');
  if (!preview) return;

  const vars = {
    '--preview-teal-dark': colorValue('theme_teal_dark', '#0A3D3D'),
    '--preview-teal': colorValue('theme_teal', '#0F5252'),
    '--preview-sticky-header-bg': colorValue('theme_sticky_header_bg', '#0A3D3D'),
    '--preview-gold': colorValue('theme_gold', '#C9A84C'),
    '--preview-gold-dark': colorValue('theme_gold_dark', '#A8782A'),
    '--preview-gold-light': colorValue('theme_gold_light', '#E2C97E'),
    '--preview-gold-pale': colorValue('theme_gold_pale', '#F5ECD4'),
    '--preview-white': colorValue('theme_white', '#FFFFFF'),
    '--preview-off-white': colorValue('theme_off_white', '#F8F6F0'),
    '--preview-text-dark': colorValue('theme_text_dark', '#1A1A1A'),
    '--preview-text-mid': colorValue('theme_text_mid', '#444444'),
    '--preview-footer-bg': colorValue('theme_footer_bg', '#05272A'),
    '--preview-whatsapp': colorValue('theme_whatsapp', '#25D366')
  };

  Object.entries(vars).forEach(([name, value]) => preview.style.setProperty(name, value));

  const roleMap = {
    'role-primary': vars['--preview-teal'],
    'role-primary-dark': vars['--preview-teal-dark'],
    'role-secondary': vars['--preview-gold'],
    'role-secondary-dark': vars['--preview-gold-dark'],
    'role-surface': vars['--preview-off-white'],
    'role-footer': vars['--preview-footer-bg'],
    'role-text-dark': vars['--preview-text-dark'],
    'role-text-light': colorValue('theme_text_light', '#777777')
  };

  Object.entries(roleMap).forEach(([id, value]) => {
    const el = document.getElementById(id);
    if (el) el.style.background = value;
  });
}

document.querySelectorAll('.color-picker').forEach((picker) => {
  picker.addEventListener('input', () => {
    const target = document.getElementById(picker.dataset.target);
    const swatch = document.getElementById(target.dataset.swatch);
    if (target) target.value = picker.value.toUpperCase();
    if (swatch) swatch.style.background = picker.value;
    updatePreview();
  });
});

document.querySelectorAll('.hex-input').forEach((input) => {
  input.addEventListener('input', () => {
    const normalized = normalizeHex(input.value);
    const swatch = document.getElementById(input.dataset.swatch);
    const picker = document.getElementById(input.id + '_picker');
    if (normalized) {
      if (swatch) swatch.style.background = normalized;
      if (picker) picker.value = normalized;
    }
    updatePreview();
  });
});

updatePreview();
</script>
</body>
</html>
