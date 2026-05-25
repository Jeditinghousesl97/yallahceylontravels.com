<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$activePage = 'slider';
$editItem   = null;
$formError  = '';
$msg        = $_GET['msg'] ?? '';
$editId     = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

adminEnsureColumn($conn, 'slider', 'video', "VARCHAR(255) DEFAULT NULL AFTER image");
adminEnsureColumn($conn, 'slider', 'video_url', "VARCHAR(500) DEFAULT NULL AFTER video");
adminEnsureColumn($conn, 'slider', 'overlay_color', "VARCHAR(7) DEFAULT NULL AFTER video_url");
adminEnsureColumn($conn, 'slider', 'overlay_opacity', "DECIMAL(4,2) DEFAULT NULL AFTER overlay_color");

function normalizeOverlayColor($value, $default = '#0A3D3D') {
    $value = trim((string)$value);
    if ($value === '') return $default;
    if ($value[0] !== '#') $value = '#' . $value;
    if (!preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value)) return $default;
    if (strlen($value) === 4) {
        $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
    }
    return strtoupper($value);
}
function normalizeOverlayOpacity($value, $default = 0.55) {
    if ($value === '' || $value === null) return $default;
    $num = (float)$value;
    if ($num < 0) $num = 0;
    if ($num > 1) $num = 1;
    return round($num, 2);
}

/* ── DELETE ── */
if (isset($_GET['delete'])) {
    $id  = (int)$_GET['delete'];
    $row = $conn->query("SELECT image, video FROM slider WHERE id=$id")->fetch_assoc();
    if ($row) {
        deleteUploadedFile($row['image'] ?? '');
        deleteUploadedFile($row['video'] ?? '');
    }
    $conn->query("DELETE FROM slider WHERE id=$id");
    header('Location: slider.php?msg=deleted'); exit;
}

/* ── TOGGLE ── */
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE slider SET is_active = 1 - is_active WHERE id=$id");
    header('Location: slider.php'); exit;
}

/* ── SAVE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId    = (int)($_POST['item_id']    ?? 0);
    $title     = trim($_POST['title']       ?? '');
    $subtitle  = trim($_POST['subtitle']    ?? '');
    $btn1_text = trim($_POST['btn1_text']   ?? '');
    $btn1_url  = trim($_POST['btn1_url']    ?? '#');
    $btn2_text = trim($_POST['btn2_text']   ?? '');
    $btn2_url  = trim($_POST['btn2_url']    ?? '#');
    $sort      = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active'])  ? 1 : 0;
    $overlayColor   = normalizeOverlayColor($_POST['overlay_color'] ?? '#0A3D3D');
    $overlayOpacity = normalizeOverlayOpacity($_POST['overlay_opacity'] ?? '0.55');
    $existing     = $postId ? ($conn->query("SELECT image, video, video_url FROM slider WHERE id=$postId")->fetch_assoc() ?: []) : [];
    $videoUrlRaw  = trim($_POST['video_url'] ?? '');
    $videoUrl     = normalizeVideoUrl($videoUrlRaw);

    if (!$title) {
        $formError = 'Slide title is required.';
        $editId    = $postId;
    } elseif (!$postId && empty($_FILES['media_file']['name']) && $videoUrlRaw === '') {
        $formError = 'Add a background image, video upload, or video URL for new slides.';
    } else {
        $imagePath = $postId ? ($existing['image'] ?? '') : '';
        $videoPath = $postId ? ($existing['video'] ?? '') : '';
        $videoUrlPath = $postId ? ($existing['video_url'] ?? '') : '';

        if (!empty($_FILES['media_file']['name'])) {
            $up = uploadMedia($_FILES['media_file'], 'uploads/slider', 100);
            if (!$up['ok']) {
                $formError = $up['msg'];
            } else {
                if (($up['kind'] ?? '') === 'image') {
                    $imagePath = $up['path'];
                    $videoPath = '';
                    $videoUrlPath = '';
                    if ($postId) {
                        deleteUploadedFile($existing['image'] ?? '');
                        deleteUploadedFile($existing['video'] ?? '');
                    }
                } else {
                    $videoPath = $up['path'];
                    $videoUrlPath = '';
                    if ($postId) {
                        deleteUploadedFile($existing['video'] ?? '');
                    }
                }
            }
        } elseif ($videoUrlRaw !== '') {
            if ($videoUrl === '') {
                $formError = 'Please enter a valid http or https direct video URL.';
            } else {
                $videoUrlPath = $videoUrl;
                $videoPath = '';
                if ($postId) {
                    deleteUploadedFile($existing['video'] ?? '');
                }
            }
        }

        if (!$formError) {
            $titleE  = $conn->real_escape_string($title);
            $subE    = $conn->real_escape_string($subtitle);
            $b1tE    = $conn->real_escape_string($btn1_text);
            $b1uE    = $conn->real_escape_string($btn1_url ?: '#');
            $b2tE    = $conn->real_escape_string($btn2_text);
            $b2uE    = $conn->real_escape_string($btn2_url ?: '#');
            $imgE    = $conn->real_escape_string($imagePath);
            $vidE    = $conn->real_escape_string($videoPath);
            $urlE    = $conn->real_escape_string($videoUrlPath);
            $ovColorE = $conn->real_escape_string($overlayColor);
            $ovOpacitySql = number_format($overlayOpacity, 2, '.', '');

            if ($postId) {
                $conn->query("UPDATE slider SET
                    title='$titleE', subtitle='$subE',
                    btn1_text='$b1tE', btn1_url='$b1uE',
                    btn2_text='$b2tE', btn2_url='$b2uE',
                    image='$imgE', video='$vidE', video_url='$urlE',
                    overlay_color='$ovColorE', overlay_opacity=$ovOpacitySql,
                    sort_order=$sort, is_active=$is_active
                    WHERE id=$postId");
                header('Location: slider.php?msg=updated'); exit;
            } else {
                $conn->query("INSERT INTO slider
                    (title, subtitle, image, video, video_url, overlay_color, overlay_opacity, btn1_text, btn1_url, btn2_text, btn2_url, sort_order, is_active)
                    VALUES ('$titleE','$subE','$imgE','$vidE','$urlE','$ovColorE',$ovOpacitySql,'$b1tE','$b1uE','$b2tE','$b2uE',$sort,$is_active)");
                header('Location: slider.php?msg=added'); exit;
            }
        }
    }
}

/* ── FETCH FOR EDIT ── */
if ($editId) {
    $r        = $conn->query("SELECT * FROM slider WHERE id=$editId");
    $editItem = $r ? $r->fetch_assoc() : null;
}

/* ── FETCH ALL ── */
$slides = [];
$r      = $conn->query("SELECT * FROM slider ORDER BY sort_order ASC, id ASC");
if ($r) while ($row = $r->fetch_assoc()) $slides[] = $row;

$v = $editItem ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Homepage Slider | Yallah Ceylon Travels Admin</title>
<link rel="icon" type="image/png" href="../images/favicon.png"/>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>"/>
<style>
.split-layout{display:grid;grid-template-columns:420px 1fr;gap:24px;align-items:start}
.form-section{background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.form-sec-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.form-sec-head h3{font-size:14px;font-weight:700;color:var(--text-dark);margin:0}
.form-sec-head i{color:var(--teal);font-size:14px}
.form-sec-body{padding:20px;display:flex;flex-direction:column;gap:13px}
.frow{display:grid;gap:13px}
.frow.c2{grid-template-columns:1fr 1fr}
.fgrp{display:flex;flex-direction:column;gap:5px}
.fgrp label{font-size:11px;font-weight:600;color:#888;letter-spacing:.7px;text-transform:uppercase}
.form-control{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;color:var(--text-dark);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
.form-control:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(15,82,82,.07)}
.check-row{display:flex;align-items:center;gap:10px;padding:9px 13px;background:var(--off-white);border-radius:10px;cursor:pointer}
.check-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--teal)}
.check-row span{font-size:13px;color:var(--text-dark);font-weight:500}
/* slide cards */
.slides-list{display:flex;flex-direction:column;gap:14px}
.slide-card{background:#fff;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;display:grid;grid-template-columns:200px 1fr;transition:border-color .2s}
.slide-card:hover{border-color:var(--teal)}
.slide-card.inactive{opacity:.55}
.slide-thumb{width:200px;height:100%;min-height:130px;object-fit:cover;display:block}
.slide-thumb-ph{width:200px;min-height:130px;background:var(--off-white);display:flex;align-items:center;justify-content:center;color:var(--text-light);font-size:32px}
.slide-info{padding:14px 18px;display:flex;flex-direction:column;gap:6px;justify-content:center}
.slide-title{font-size:15px;font-weight:700;color:var(--text-dark);line-height:1.3}
.slide-subtitle{font-size:12px;color:var(--text-mid);line-height:1.5}
.slide-btns{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}
.slide-btn-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:600;background:var(--teal-pale);color:var(--teal)}
.slide-btn-tag.secondary{background:var(--off-white);color:var(--text-mid)}
.slide-meta{display:flex;align-items:center;gap:10px;margin-top:auto}
.order-badge{font-size:11px;background:var(--off-white);color:var(--text-light);padding:2px 8px;border-radius:5px}
.act-btn{width:30px;height:30px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;border:none;cursor:pointer;transition:background .2s,color .2s;text-decoration:none}
.btn-edit{background:var(--teal-pale);color:var(--teal)}.btn-edit:hover{background:var(--teal);color:#fff}
.btn-del{background:var(--red-pale);color:var(--red)}.btn-del:hover{background:var(--red);color:#fff}
.btn-tog{background:var(--off-white);color:var(--text-light)}.btn-tog:hover{background:var(--text-light);color:#fff}
.edit-mode .form-sec-head{background:var(--gold-pale)}
.edit-mode .form-sec-head h3,.edit-mode .form-sec-head i{color:#a8782a}
.img-preview{width:100%;height:120px;object-fit:cover;border-radius:8px;margin-top:6px;display:block}
.btn-divider{height:1px;background:var(--border);margin:4px 0}
.range-row{display:grid;grid-template-columns:1fr auto;align-items:center;gap:10px}
.range-val{font-size:12px;font-weight:700;color:var(--teal-dark);min-width:40px;text-align:right}
@media(max-width:960px){.split-layout{grid-template-columns:1fr}.slide-card{grid-template-columns:1fr}.slide-thumb{width:100%;height:160px}.slide-thumb-ph{width:100%;height:160px}.frow.c2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="admin-wrapper">
<?php include 'includes/sidebar.php'; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="admin-main">

  <!-- TOPBAR -->
  <div class="admin-topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
      <div>
        <div class="topbar-title">Homepage Slider</div>
        <div class="topbar-breadcrumb">Content / Slider</div>
      </div>
    </div>
  </div>

  <div class="admin-content">
    <div class="admin-content-inner">

      <?php if ($msg === 'added'):   ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> Slide added successfully.</div><?php endif; ?>
      <?php if ($msg === 'updated'): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> Slide updated successfully.</div><?php endif; ?>
      <?php if ($msg === 'deleted'): ?><div class="alert alert-warning"><i class="fas fa-trash"></i> Slide deleted.</div><?php endif; ?>
      <?php if ($formError):         ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($formError) ?></div><?php endif; ?>

      <div class="page-header">
        <div class="page-header-left">
          <h1>Homepage Slider</h1>
          <p>Manage hero slides and video backgrounds shown at the top of the homepage</p>
        </div>
      </div>

      <div class="split-layout">

        <!-- ── FORM ── -->
        <div class="form-section <?= $editItem ? 'edit-mode' : '' ?>">
          <div class="form-sec-head">
            <i class="fas <?= $editItem ? 'fa-pen' : 'fa-plus-circle' ?>"></i>
            <h3><?= $editItem ? 'Edit Slide' : 'Add New Slide' ?></h3>
          </div>
          <div class="form-sec-body">
            <form method="POST" enctype="multipart/form-data">
              <input type="hidden" name="item_id" value="<?= $v['id'] ?? 0 ?>"/>

              <!-- Content -->
              <div class="fgrp">
                <label>Slide Title <span style="color:var(--red)">*</span></label>
                <input type="text" name="title" class="form-control" required
                       placeholder="e.g. Discover *Sri Lanka*"
                       value="<?= htmlspecialchars($v['title'] ?? '') ?>"/>
                <small style="font-size:11px;color:var(--text-light)">Wrap a word in <strong>*asterisks*</strong> to display it in gold italic — e.g. <em>Discover *Sri Lanka*</em></small>
              </div>

              <div class="fgrp">
                <label>Subtitle / Tagline</label>
                <textarea name="subtitle" class="form-control" rows="2"
                          placeholder="e.g. Your gateway to paradise…"><?= htmlspecialchars($v['subtitle'] ?? '') ?></textarea>
              </div>

              <!-- Background Media -->
              <div class="fgrp">
                <label>Background Media <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-light)">(optional if you use Video URL)</span></label>
                <input type="file" name="media_file" class="form-control" accept="image/*,video/*" onchange="previewMedia(this)"/>
                <small style="font-size:11px;color:var(--text-light)">Upload an image or MP4/WebM video. Max 100 MB. Or paste a direct video URL below. Images stay static; videos autoplay muted on the homepage.</small>
                <div id="previewWrap" style="<?= empty($v['image']) && empty($v['video']) ? 'display:none' : '' ?>">
                  <?php if (!empty($v['video'])): ?>
                    <video id="mediaPreview" class="img-preview" controls playsinline preload="metadata" <?= !empty($v['image']) ? 'poster="'.SITE_URL.'/'.$v['image'].'"' : '' ?>>
                      <source src="<?= !empty($v['video']) ? SITE_URL.'/'.$v['video'] : '' ?>"/>
                    </video>
                  <?php else: ?>
                    <img id="mediaPreview"
                         src="<?= !empty($v['image']) ? SITE_URL.'/'.$v['image'] : '' ?>"
                         class="img-preview" alt=""/>
                  <?php endif; ?>
                </div>
                <?php if (!empty($v['video'])): ?>
                  <small style="font-size:11px;color:var(--teal)">Current background: video upload</small>
                <?php elseif (!empty($v['video_url'])): ?>
                  <small style="font-size:11px;color:var(--teal)">Current background: video URL</small>
                <?php elseif (!empty($v['image'])): ?>
                  <small style="font-size:11px;color:var(--teal)">Current background: image</small>
                <?php endif; ?>
              </div>

              <div class="fgrp">
                <label>Video URL</label>
                <input type="url" name="video_url" class="form-control"
                       placeholder="https://example.com/hero-video.mp4"
                       value="<?= htmlspecialchars($v['video_url'] ?? '') ?>"/>
                <small style="font-size:11px;color:var(--text-light)">Use a direct MP4/WebM file URL. Leave blank if you are uploading a video file.</small>
              </div>

              <div class="frow c2">
                <div class="fgrp">
                  <label>Overlay Color</label>
                  <input type="color" name="overlay_color" id="overlayColor" class="form-control"
                         value="<?= htmlspecialchars(normalizeOverlayColor($v['overlay_color'] ?? '#0A3D3D')) ?>"/>
                </div>
                <div class="fgrp">
                  <label>Overlay Opacity</label>
                  <div class="range-row">
                    <input type="range" name="overlay_opacity" id="overlayOpacity" class="form-control"
                           min="0" max="1" step="0.01"
                           value="<?= htmlspecialchars((string)normalizeOverlayOpacity($v['overlay_opacity'] ?? '0.55')) ?>"/>
                    <span class="range-val" id="overlayOpacityVal"></span>
                  </div>
                </div>
              </div>

              <div class="btn-divider"></div>

              <!-- Button 1 -->
              <div style="font-size:11px;font-weight:700;color:var(--teal);letter-spacing:.5px;text-transform:uppercase">Button 1 (Primary)</div>
              <div class="frow c2">
                <div class="fgrp">
                  <label>Button Text</label>
                  <input type="text" name="btn1_text" class="form-control"
                         placeholder="e.g. Explore Tours"
                         value="<?= htmlspecialchars($v['btn1_text'] ?? '') ?>"/>
                </div>
                <div class="fgrp">
                  <label>Button URL</label>
                  <input type="text" name="btn1_url" class="form-control"
                         placeholder="/tours.php"
                         value="<?= htmlspecialchars($v['btn1_url'] ?? '') ?>"/>
                </div>
              </div>

              <!-- Button 2 -->
              <div style="font-size:11px;font-weight:700;color:var(--text-light);letter-spacing:.5px;text-transform:uppercase">Button 2 (Secondary)</div>
              <div class="frow c2">
                <div class="fgrp">
                  <label>Button Text</label>
                  <input type="text" name="btn2_text" class="form-control"
                         placeholder="e.g. Contact Us"
                         value="<?= htmlspecialchars($v['btn2_text'] ?? '') ?>"/>
                </div>
                <div class="fgrp">
                  <label>Button URL</label>
                  <input type="text" name="btn2_url" class="form-control"
                         placeholder="/contact.php"
                         value="<?= htmlspecialchars($v['btn2_url'] ?? '') ?>"/>
                </div>
              </div>

              <div class="btn-divider"></div>

              <div class="frow c2">
                <div class="fgrp">
                  <label>Sort Order</label>
                  <input type="number" name="sort_order" class="form-control"
                         min="0" value="<?= (int)($v['sort_order'] ?? 0) ?>"/>
                </div>
                <div class="fgrp" style="justify-content:flex-end;padding-top:18px">
                  <label class="check-row">
                    <input type="checkbox" name="is_active" value="1" <?= ($v['is_active'] ?? 1) ? 'checked' : '' ?>/>
                    <i class="fas fa-eye" style="color:var(--teal)"></i>
                    <span>Active</span>
                  </label>
                </div>
              </div>

              <div style="display:flex;gap:10px;padding-top:4px">
                <button type="submit" class="btn btn-primary" style="flex:1">
                  <i class="fas <?= $editItem ? 'fa-save' : 'fa-plus' ?>"></i>
                  <?= $editItem ? 'Update Slide' : 'Add Slide' ?>
                </button>
                <?php if ($editItem): ?>
                  <a href="slider.php" class="btn btn-outline"><i class="fas fa-times"></i></a>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>

        <!-- ── SLIDES LIST ── -->
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h3 style="font-size:15px;font-weight:700;color:var(--text-dark);margin:0">
              <?= count($slides) ?> Slide<?= count($slides)!=1?'s':'' ?>
              <span style="font-size:12px;font-weight:400;color:var(--text-light);margin-left:6px">· ordered by Sort Order</span>
            </h3>
          </div>

          <?php if ($slides): ?>
          <div class="slides-list">
            <?php foreach ($slides as $slide): ?>
            <div class="slide-card <?= $slide['is_active'] ? '' : 'inactive' ?>">

              <!-- thumbnail -->
              <?php if ($slide['image']): ?>
                <img src="<?= SITE_URL . '/' . htmlspecialchars($slide['image']) ?>" class="slide-thumb" alt=""/>
              <?php elseif ($slide['video'] || $slide['video_url']): ?>
                <div class="slide-thumb-ph" style="background:linear-gradient(135deg, rgba(10,61,61,.9), rgba(15,82,82,.8));color:#fff">
                  <i class="fas fa-play-circle"></i>
                </div>
              <?php else: ?>
                <div class="slide-thumb-ph"><i class="fas fa-image"></i></div>
              <?php endif; ?>

              <!-- info -->
              <div class="slide-info">
                <div class="slide-title"><?= htmlspecialchars($slide['title']) ?></div>
                <?php if ($slide['subtitle']): ?>
                  <div class="slide-subtitle"><?= htmlspecialchars(mb_substr($slide['subtitle'], 0, 80)) ?><?= mb_strlen($slide['subtitle'])>80?'…':'' ?></div>
                <?php endif; ?>

                <div class="slide-btns">
                  <?php if (!empty($slide['overlay_color']) || $slide['overlay_opacity'] !== null): ?>
                    <span class="slide-btn-tag secondary">
                      <i class="fas fa-layer-group"></i>
                      <?= htmlspecialchars(normalizeOverlayColor($slide['overlay_color'] ?? '#0A3D3D')) ?>
                      @ <?= htmlspecialchars(number_format(normalizeOverlayOpacity($slide['overlay_opacity'] ?? '0.55'), 2)) ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($slide['video'])): ?>
                    <span class="slide-btn-tag"><i class="fas fa-video"></i> Video upload</span>
                  <?php elseif (!empty($slide['video_url'])): ?>
                    <span class="slide-btn-tag"><i class="fas fa-link"></i> Video URL</span>
                  <?php elseif (!empty($slide['image'])): ?>
                    <span class="slide-btn-tag"><i class="fas fa-image"></i> Image background</span>
                  <?php endif; ?>
                </div>

                <!-- button tags -->
                <?php if ($slide['btn1_text'] || $slide['btn2_text']): ?>
                <div class="slide-btns">
                  <?php if ($slide['btn1_text']): ?>
                    <span class="slide-btn-tag"><i class="fas fa-arrow-right"></i> <?= htmlspecialchars($slide['btn1_text']) ?></span>
                  <?php endif; ?>
                  <?php if ($slide['btn2_text']): ?>
                    <span class="slide-btn-tag secondary"><?= htmlspecialchars($slide['btn2_text']) ?></span>
                  <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="slide-meta">
                  <span class="order-badge">Order: <?= (int)$slide['sort_order'] ?></span>
                  <?php if (!$slide['is_active']): ?>
                    <span style="font-size:11px;background:var(--off-white);color:var(--text-light);padding:2px 8px;border-radius:4px"><i class="fas fa-eye-slash"></i> Hidden</span>
                  <?php endif; ?>
                  <div style="margin-left:auto;display:flex;gap:6px">
                    <a href="slider.php?toggle=<?= $slide['id'] ?>" class="act-btn btn-tog" title="<?= $slide['is_active']?'Hide':'Show' ?>">
                      <i class="fas <?= $slide['is_active']?'fa-eye-slash':'fa-eye' ?>"></i>
                    </a>
                    <a href="slider.php?edit=<?= $slide['id'] ?>" class="act-btn btn-edit" title="Edit">
                      <i class="fas fa-pen"></i>
                    </a>
                    <button onclick="confirmDelete(<?= $slide['id'] ?>, '<?= htmlspecialchars(addslashes($slide['title'])) ?>')"
                            class="act-btn btn-del" title="Delete"><i class="fas fa-trash"></i></button>
                  </div>
                </div>
              </div>

            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div style="text-align:center;padding:60px;color:var(--text-light);background:#fff;border:1px solid var(--border);border-radius:var(--radius)">
            <i class="fas fa-film" style="font-size:44px;opacity:.12;display:block;margin-bottom:12px"></i>
            <p>No slides yet. Add your first one with an image or video background.</p>
          </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</div>
</div>

<!-- DELETE MODAL -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:32px;max-width:380px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="width:56px;height:56px;background:var(--red-pale);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:22px;color:var(--red)"><i class="fas fa-trash"></i></div>
    <h3 style="margin-bottom:8px;color:var(--text-dark)">Delete Slide?</h3>
    <p id="delModalText" style="color:var(--text-light);font-size:13px;margin-bottom:24px;line-height:1.6"></p>
    <div style="display:flex;gap:10px;justify-content:center">
      <button onclick="document.getElementById('deleteModal').style.display='none'" class="btn btn-outline">Cancel</button>
      <a id="delConfirmBtn" href="#" class="btn" style="background:var(--red);color:#fff;min-width:100px">Delete</a>
    </div>
  </div>
</div>

<script src="js/admin.js?v=<?= filemtime(__DIR__ . '/js/admin.js') ?>"></script>
<script>
function previewMedia(input) {
  if (!(input.files && input.files[0])) return;

  const file = input.files[0];
  const wrap = document.getElementById('previewWrap');
  const reader = new FileReader();

  reader.onload = e => {
    const url = e.target.result;
    wrap.style.display = '';
    if (file.type && file.type.indexOf('video/') === 0) {
      wrap.innerHTML = `<video id="mediaPreview" class="img-preview" controls playsinline preload="metadata"><source src="${url}"></video>`;
    } else {
      wrap.innerHTML = `<img id="mediaPreview" src="${url}" class="img-preview" alt=""/>`;
    }
  };
  reader.readAsDataURL(file);
}
function confirmDelete(id, title) {
  document.getElementById('delModalText').textContent = 'Delete slide "' + title + '"? Any background image, video file, or video URL will also be removed where applicable. This cannot be undone.';
  document.getElementById('delConfirmBtn').href = 'slider.php?delete=' + id;
  document.getElementById('deleteModal').style.display = 'flex';
}
document.getElementById('deleteModal')?.addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
const overlayOpacityInput = document.getElementById('overlayOpacity');
const overlayOpacityVal = document.getElementById('overlayOpacityVal');
function syncOverlayOpacityLabel() {
  if (!overlayOpacityInput || !overlayOpacityVal) return;
  overlayOpacityVal.textContent = Number(overlayOpacityInput.value || 0).toFixed(2);
}
overlayOpacityInput?.addEventListener('input', syncOverlayOpacityLabel);
syncOverlayOpacityLabel();
</script>
</body>
</html>
