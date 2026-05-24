<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$activePage = 'gallery';
$msg        = $_GET['msg'] ?? '';
$filterCat  = trim($_GET['cat'] ?? '');

function galleryRedirectUrl($msg = '', $cat = '') {
    $params = [];
    if ($msg !== '') $params['msg'] = $msg;
    if ($cat !== '') $params['cat'] = $cat;
    $query = http_build_query($params);
    return 'gallery.php' . ($query ? '?' . $query : '');
}

function galleryFilePath($filename) {
    return dirname(__DIR__) . '/' . ltrim($filename, '/');
}

/* delete single */
if (isset($_GET['delete'])) {
    $id        = (int)$_GET['delete'];
    $returnCat = trim($_GET['cat'] ?? '');
    $res       = $conn->query("SELECT filename FROM gallery WHERE id=$id");
    $row       = $res ? $res->fetch_assoc() : null;

    if ($row) {
        $path = galleryFilePath($row['filename']);
        if (is_file($path)) @unlink($path);
        $conn->query("DELETE FROM gallery WHERE id=$id");
    }

    header('Location: ' . galleryRedirectUrl('deleted', $returnCat));
    exit;
}

/* toggle active */
if (isset($_GET['toggle'])) {
    $id        = (int)$_GET['toggle'];
    $returnCat = trim($_GET['cat'] ?? '');
    $conn->query("UPDATE gallery SET is_active = 1 - is_active WHERE id=$id");
    header('Location: ' . galleryRedirectUrl('toggled', $returnCat));
    exit;
}

/* bulk delete */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $ids       = $_POST['selected'] ?? [];
    $returnCat = trim($_POST['return_cat'] ?? '');

    foreach ($ids as $id) {
        $id  = (int)$id;
        $res = $conn->query("SELECT filename FROM gallery WHERE id=$id");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            $path = galleryFilePath($row['filename']);
            if (is_file($path)) @unlink($path);
            $conn->query("DELETE FROM gallery WHERE id=$id");
        }
    }

    header('Location: ' . galleryRedirectUrl('bulk_deleted', $returnCat));
    exit;
}

/* upload */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
    $cat      = trim($_POST['category'] ?? '');
    $caption  = trim($_POST['caption'] ?? '');
    $altText  = trim($_POST['alt_text'] ?? '');
    $sort     = (int)($_POST['sort_order'] ?? 0);
    $uploaded = 0;
    $errors   = [];
    $files    = $_FILES['images'] ?? null;

    if (!$files || empty($files['name'])) {
        $_SESSION['upload_errors'] = ['Please choose at least one image to upload.'];
        header('Location: ' . galleryRedirectUrl('upload_err', $cat));
        exit;
    }

    $count = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $count; $i++) {
        $file = [
            'name'     => is_array($files['name']) ? $files['name'][$i] : $files['name'],
            'type'     => is_array($files['type']) ? $files['type'][$i] : $files['type'],
            'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
            'error'    => is_array($files['error']) ? $files['error'][$i] : $files['error'],
            'size'     => is_array($files['size']) ? $files['size'][$i] : $files['size'],
        ];

        if ($file['error'] !== UPLOAD_ERR_OK) continue;

        $up = uploadImage($file, 'uploads/gallery');
        if (!$up['ok']) {
            $errors[] = $file['name'] . ': ' . $up['msg'];
            continue;
        }

        $fnE  = $conn->real_escape_string($up['path']);
        $catE = $conn->real_escape_string($cat);
        $capE = $conn->real_escape_string($caption);
        $altE = $conn->real_escape_string($altText ?: $caption);

        $conn->query("INSERT INTO gallery (filename, caption, category, alt_text, sort_order)
            VALUES ('$fnE', '$capE', '$catE', '$altE', $sort)");
        $uploaded++;
    }

    if ($errors) $_SESSION['upload_errors'] = $errors;
    $redir = $uploaded ? 'uploaded_' . $uploaded : 'upload_err';
    header('Location: ' . galleryRedirectUrl($redir, $cat));
    exit;
}

/* update item */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $id        = (int)($_POST['item_id'] ?? 0);
    $returnCat = trim($_POST['return_cat'] ?? '');
    $cat       = $conn->real_escape_string(trim($_POST['category'] ?? ''));
    $cap       = $conn->real_escape_string(trim($_POST['caption'] ?? ''));
    $alt       = $conn->real_escape_string(trim($_POST['alt_text'] ?? ''));
    $sort      = (int)($_POST['sort_order'] ?? 0);

    $conn->query("UPDATE gallery SET caption='$cap', alt_text='$alt', category='$cat', sort_order=$sort WHERE id=$id");
    header('Location: ' . galleryRedirectUrl('updated', $returnCat));
    exit;
}

/* fetch categories from gallery itself */
$categories = [];
$rc = $conn->query("SELECT DISTINCT category FROM gallery WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
if ($rc) {
    while ($row = $rc->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}

/* counts */
$totalCount  = (int)($conn->query("SELECT COUNT(*) AS cnt FROM gallery")->fetch_assoc()['cnt'] ?? 0);
$activeTotal = (int)($conn->query("SELECT COUNT(*) AS cnt FROM gallery WHERE is_active=1")->fetch_assoc()['cnt'] ?? 0);
$hiddenTotal = $totalCount - $activeTotal;

/* images */
$images = [];
$where = '';
if ($filterCat !== '') {
    $where = "WHERE category='" . $conn->real_escape_string($filterCat) . "'";
}
$r = $conn->query("SELECT * FROM gallery $where ORDER BY sort_order ASC, id DESC");
if ($r) {
    while ($row = $r->fetch_assoc()) $images[] = $row;
}

$viewCount    = count($images);
$viewActive   = 0;
$viewHidden   = 0;
$categoryFreq = [];

foreach ($images as $img) {
    if (!empty($img['is_active'])) $viewActive++;
    else $viewHidden++;
}

$freqRes = $conn->query("SELECT category, COUNT(*) AS cnt FROM gallery GROUP BY category");
if ($freqRes) {
    while ($row = $freqRes->fetch_assoc()) {
        $categoryFreq[$row['category']] = (int)$row['cnt'];
    }
}

$uploadErrors = $_SESSION['upload_errors'] ?? [];
unset($_SESSION['upload_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Gallery | Good Shepherd Tours & Travels Admin</title>
<link rel="icon" type="image/png" href="../images/favicon.png"/>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>"/>
<style>
.gallery-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
.gallery-stat{background:#fff;border:1px solid var(--border);border-radius:14px;padding:16px 18px;box-shadow:var(--shadow)}
.gallery-stat-label{font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-light);margin-bottom:8px}
.gallery-stat-value{font-size:28px;font-weight:800;color:var(--text-dark);line-height:1}
.gallery-stat-sub{font-size:12px;color:var(--text-light);margin-top:6px}
.upload-card{margin-bottom:20px}
.upload-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}
.upload-zone{border:2px dashed var(--border);border-radius:12px;padding:26px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:#fafafa;margin-top:14px}
.upload-zone:hover,.upload-zone.drag-over{border-color:var(--teal);background:var(--teal-pale)}
.upload-zone i{font-size:34px;color:var(--teal);opacity:.55;margin-bottom:8px}
.upload-zone p{font-size:13px;color:var(--text-light);margin:0}
.upload-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}
.file-pill{display:none;align-items:center;gap:8px;background:var(--teal-pale);color:var(--teal);padding:10px 12px;border-radius:10px;font-size:12px;font-weight:600}
.file-pill.show{display:inline-flex}
.upload-note{font-size:12px;color:var(--text-light);margin-top:10px}
.cat-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.cat-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;color:var(--text-mid);background:#fff;border:1.5px solid var(--border);transition:all .2s}
.cat-tab:hover,.cat-tab.active{background:var(--teal);color:#fff;border-color:var(--teal)}
.ctbadge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;border-radius:9px;background:rgba(255,255,255,.22);font-size:10px;font-weight:700;padding:0 5px}
.cat-tab:not(.active) .ctbadge{background:var(--teal-pale);color:var(--teal)}
.gallery-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.gallery-search{position:relative;min-width:280px}
.gallery-search input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:10px;background:#fff;font-size:13px;color:var(--text-dark)}
.gallery-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:12px}
.gallery-helper{font-size:12px;color:var(--text-light)}
.bulk-bar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:16px;box-shadow:var(--shadow)}
.bulk-bar p{margin:0;font-size:12px;color:var(--text-light)}
.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
.gallery-card{background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);display:flex;flex-direction:column}
.gallery-thumb{position:relative;background:#000}
.gallery-thumb img{width:100%;height:170px;object-fit:cover;display:block;transition:opacity .2s}
.gallery-card.inactive .gallery-thumb img{opacity:.4}
.gallery-check{position:absolute;top:10px;left:10px;z-index:2;width:18px;height:18px;accent-color:var(--teal)}
.gallery-status{position:absolute;top:10px;right:10px;z-index:2;background:rgba(0,0,0,.65);color:#fff;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:700}
.gallery-body{padding:14px;display:flex;flex-direction:column;gap:10px}
.gallery-title{font-size:14px;font-weight:700;color:var(--text-dark);line-height:1.35;word-break:break-word}
.gallery-meta{display:grid;gap:5px}
.gallery-meta div{font-size:12px;color:var(--text-mid)}
.gallery-meta strong{color:var(--text-dark)}
.gallery-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:4px}
.gal-btn{min-height:38px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--border);cursor:pointer;font-size:12px;font-weight:700;transition:all .2s;text-decoration:none;background:#fff;color:var(--text-mid)}
.gal-edit{background:var(--teal-pale);color:var(--teal);border-color:rgba(15,82,82,.15)}
.gal-edit:hover{background:var(--teal);color:#fff}
.gal-toggle{background:var(--off-white);color:var(--text-mid)}
.gal-toggle:hover{border-color:var(--teal);color:var(--teal)}
.gal-del{background:var(--red-pale);color:var(--red);border-color:rgba(231,76,60,.15)}
.gal-del:hover{background:var(--red);color:#fff}
.modal-back{display:none;position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:9999;align-items:center;justify-content:center;padding:20px}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:560px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.modal-hdr{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-hdr h3{font-size:15px;font-weight:700;margin:0;color:var(--text-dark)}
.modal-bdy{padding:20px;display:flex;flex-direction:column;gap:13px}
.modal-img-prev{width:100%;height:220px;object-fit:cover;border-radius:8px}
.modal-help{font-size:12px;color:var(--text-light);line-height:1.5}
.modal-ftr{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
@media(max-width:980px){.gallery-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.upload-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.gallery-stats{grid-template-columns:1fr}.upload-grid{grid-template-columns:1fr}.gallery-actions{grid-template-columns:1fr}.gallery-search{min-width:100%}.bulk-bar{align-items:flex-start}}
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
        <div class="topbar-title">Gallery</div>
        <div class="topbar-breadcrumb">Media / Gallery</div>
      </div>
    </div>
  </div>

  <div class="admin-content">
    <div class="admin-content-inner">

      <?php
      $alerts = [
        'deleted'      => ['warning', 'Image deleted.'],
        'bulk_deleted' => ['warning', 'Selected images deleted.'],
        'updated'      => ['success', 'Image updated.'],
        'toggled'      => ['success', 'Image visibility updated.'],
        'upload_err'   => ['danger', 'No images were uploaded.'],
      ];
      if (str_starts_with($msg, 'uploaded_')) {
          echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . (int)substr($msg, 9) . ' image(s) uploaded successfully.</div>';
      } elseif (isset($alerts[$msg])) {
          [$type, $text] = $alerts[$msg];
          $icon = $type === 'success' ? 'check-circle' : ($type === 'danger' ? 'exclamation-circle' : 'trash');
          echo "<div class=\"alert alert-$type\"><i class=\"fas fa-$icon\"></i> $text</div>";
      }
      if ($uploadErrors): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= implode('<br>', array_map('htmlspecialchars', $uploadErrors)) ?></div>
      <?php endif; ?>

      <div class="page-header">
        <div class="page-header-left">
          <h1>Photo Gallery <span style="font-size:14px;font-weight:400;color:var(--text-light)">(<?= $totalCount ?> total)</span></h1>
          <p>Fresh, simple gallery management without a separate category page</p>
        </div>
      </div>

      <div class="gallery-stats">
        <div class="gallery-stat">
          <div class="gallery-stat-label">Showing</div>
          <div class="gallery-stat-value"><?= $viewCount ?></div>
          <div class="gallery-stat-sub"><?= $filterCat !== '' ? 'Filtered to "' . htmlspecialchars($filterCat) . '"' : 'All gallery photos' ?></div>
        </div>
        <div class="gallery-stat">
          <div class="gallery-stat-label">Visible</div>
          <div class="gallery-stat-value"><?= $viewActive ?></div>
          <div class="gallery-stat-sub">Visible in current view</div>
        </div>
        <div class="gallery-stat">
          <div class="gallery-stat-label">Hidden</div>
          <div class="gallery-stat-value"><?= $viewHidden ?></div>
          <div class="gallery-stat-sub">Hidden in current view</div>
        </div>
        <div class="gallery-stat">
          <div class="gallery-stat-label">All Visible</div>
          <div class="gallery-stat-value"><?= $activeTotal ?></div>
          <div class="gallery-stat-sub"><?= $hiddenTotal ?> hidden across gallery</div>
        </div>
      </div>

      <div class="card upload-card">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-upload"></i> Upload Photos</span>
        </div>
        <div style="padding:20px">
          <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="upload" value="1"/>
            <div class="upload-grid">
              <div class="fgrp">
                <label>Category</label>
                <input type="text" name="category" class="form-control" list="galleryCategoryList" value="<?= htmlspecialchars($filterCat) ?>" placeholder="Optional category"/>
                <datalist id="galleryCategoryList">
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= htmlspecialchars($category) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="fgrp">
                <label>Caption</label>
                <input type="text" name="caption" class="form-control" placeholder="Optional caption"/>
              </div>
              <div class="fgrp">
                <label>Alt Text</label>
                <input type="text" name="alt_text" class="form-control" placeholder="Optional alt text"/>
              </div>
              <div class="fgrp">
                <label>Sort Order</label>
                <input type="number" name="sort_order" class="form-control" value="0" min="0"/>
              </div>
            </div>
            <div class="upload-zone" id="dropZone">
              <i class="fas fa-cloud-upload-alt"></i>
              <p>Drag &amp; drop photos here, or <strong style="color:var(--teal)">click to choose files</strong></p>
              <p style="font-size:11px;margin-top:4px">JPG, PNG, WebP, GIF · Max 25 MB each · Multiple files supported</p>
            </div>
            <div class="upload-actions">
              <label class="btn btn-outline" style="cursor:pointer">
                <i class="fas fa-images"></i> Choose Photos
                <input type="file" name="images[]" id="fileInput" multiple accept="image/*" style="display:none"/>
              </label>
              <button type="submit" id="uploadSubmitBtn" class="btn btn-primary" disabled>
                <i class="fas fa-upload"></i> Upload Selected Files
              </button>
              <div class="file-pill" id="filePill"><i class="fas fa-check-circle"></i> <span id="filePillText">No files selected</span></div>
            </div>
            <div class="upload-note">The caption, alt text, category and sort order will be applied to every selected file in this upload.</div>
          </form>
        </div>
      </div>

      <div class="cat-tabs">
        <a href="gallery.php" class="cat-tab <?= $filterCat === '' ? 'active' : '' ?>">
          <i class="fas fa-images"></i> All <span class="ctbadge"><?= $totalCount ?></span>
        </a>
        <?php foreach ($categories as $category): ?>
          <a href="gallery.php?cat=<?= urlencode($category) ?>" class="cat-tab <?= $filterCat === $category ? 'active' : '' ?>">
            <?= htmlspecialchars($category) ?>
            <span class="ctbadge"><?= $categoryFreq[$category] ?? 0 ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="gallery-toolbar">
        <div class="gallery-search">
          <i class="fas fa-search"></i>
          <input type="text" id="gallerySearchInput" placeholder="Search by caption, alt text, category or filename"/>
        </div>
        <div class="gallery-helper">Edit, hide, delete, upload and bulk delete are handled only on this page.</div>
      </div>

      <?php if ($images): ?>
      <div class="bulk-bar">
        <div>
          <strong><span id="selCount">0</span> image(s) selected</strong>
          <p>Select photos with the checkbox on each card, then delete them together if needed.</p>
        </div>
        <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" onclick="bulkDelete()" disabled>
          <i class="fas fa-trash"></i> Delete Selected
        </button>
      </div>

      <form method="POST" id="bulkForm">
        <input type="hidden" name="bulk_delete" value="1"/>
        <input type="hidden" name="return_cat" value="<?= htmlspecialchars($filterCat) ?>"/>
        <div class="gallery-grid" id="galleryGrid">
          <?php foreach ($images as $img): ?>
            <?php
              $title = trim($img['caption'] ?? '') ?: basename($img['filename']);
              $cat   = trim($img['category'] ?? '');
              $searchText = strtolower(trim(($img['caption'] ?? '') . ' ' . ($img['alt_text'] ?? '') . ' ' . $cat . ' ' . basename($img['filename'])));
            ?>
            <div class="gallery-card <?= !empty($img['is_active']) ? '' : 'inactive' ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
              <div class="gallery-thumb">
                <input type="checkbox" name="selected[]" value="<?= $img['id'] ?>" class="gallery-check sel-check" onchange="updateSelCount()"/>
                <span class="gallery-status"><?= !empty($img['is_active']) ? 'Live' : 'Hidden' ?></span>
                <img src="<?= SITE_URL . '/' . htmlspecialchars($img['filename']) ?>" alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>"/>
              </div>
              <div class="gallery-body">
                <div class="gallery-title"><?= htmlspecialchars($title) ?></div>
                <div class="gallery-meta">
                  <div><strong>Category:</strong> <?= htmlspecialchars($cat !== '' ? $cat : 'Uncategorized') ?></div>
                  <div><strong>Sort:</strong> <?= (int)$img['sort_order'] ?></div>
                  <div><strong>File:</strong> <?= htmlspecialchars(basename($img['filename'])) ?></div>
                </div>
                <div class="gallery-actions">
                  <button
                    type="button"
                    class="gal-btn gal-edit edit-trigger"
                    data-id="<?= (int)$img['id'] ?>"
                    data-caption="<?= htmlspecialchars($img['caption'] ?? '', ENT_QUOTES) ?>"
                    data-alt="<?= htmlspecialchars($img['alt_text'] ?? '', ENT_QUOTES) ?>"
                    data-category="<?= htmlspecialchars($img['category'] ?? '', ENT_QUOTES) ?>"
                    data-sort="<?= (int)$img['sort_order'] ?>"
                    data-src="<?= htmlspecialchars(SITE_URL . '/' . $img['filename'], ENT_QUOTES) ?>"
                  ><i class="fas fa-pen"></i> Edit</button>

                  <a href="<?= htmlspecialchars(galleryRedirectUrl('', $filterCat) . (strpos(galleryRedirectUrl('', $filterCat), '?') !== false ? '&' : '?')) ?>toggle=<?= (int)$img['id'] ?>" class="gal-btn gal-toggle">
                    <i class="fas <?= !empty($img['is_active']) ? 'fa-eye-slash' : 'fa-eye' ?>"></i> <?= !empty($img['is_active']) ? 'Hide' : 'Show' ?>
                  </a>

                  <button type="button" class="gal-btn gal-del delete-trigger" data-id="<?= (int)$img['id'] ?>" data-cat="<?= htmlspecialchars($filterCat, ENT_QUOTES) ?>">
                    <i class="fas fa-trash"></i> Delete
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
      <?php else: ?>
      <div style="text-align:center;padding:60px;color:var(--text-light)">
        <i class="fas fa-images" style="font-size:48px;opacity:.15;display:block;margin-bottom:14px"></i>
        <p><?= $filterCat !== '' ? 'No images in this category.' : 'No images yet. Upload your first photo above.' ?></p>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
</div>

<div class="modal-back" id="editModal">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3><i class="fas fa-pen" style="color:var(--teal);margin-right:8px"></i> Edit Image</h3>
      <button type="button" id="closeEditBtn" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-light)"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="update_item" value="1"/>
      <input type="hidden" name="item_id" id="editItemId"/>
      <input type="hidden" name="return_cat" id="editReturnCat" value="<?= htmlspecialchars($filterCat) ?>"/>
      <div class="modal-bdy">
        <img id="editPreview" src="" alt="" class="modal-img-prev"/>
        <div class="modal-help">Update the image details here. Use Hide on the card if you only want to remove it from the public site without deleting the file.</div>
        <div class="fgrp">
          <label>Category</label>
          <input type="text" name="category" id="editCat" class="form-control" list="galleryCategoryList" placeholder="Optional category"/>
        </div>
        <div class="fgrp">
          <label>Caption</label>
          <input type="text" name="caption" id="editCap" class="form-control" placeholder="Caption"/>
        </div>
        <div class="fgrp">
          <label>Alt Text</label>
          <input type="text" name="alt_text" id="editAlt" class="form-control" placeholder="Descriptive alt text"/>
        </div>
        <div class="fgrp">
          <label>Sort Order</label>
          <input type="number" name="sort_order" id="editSort" class="form-control" min="0"/>
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn btn-outline" id="cancelEditBtn">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<div id="deleteModal" class="modal-back">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-hdr">
      <h3><i class="fas fa-trash" style="color:var(--red);margin-right:8px"></i> Delete Image</h3>
      <button type="button" id="closeDeleteBtn" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-light)"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-bdy">
      <div class="modal-help">This will permanently delete the image file and its gallery record.</div>
    </div>
    <div class="modal-ftr">
      <button type="button" class="btn btn-outline" id="cancelDeleteBtn">Cancel</button>
      <a id="delImgBtn" href="#" class="btn btn-danger">Delete</a>
    </div>
  </div>
</div>

<script src="js/admin.js?v=<?= filemtime(__DIR__ . '/js/admin.js') ?>"></script>
<script>
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
const filePill = document.getElementById('filePill');
const filePillText = document.getElementById('filePillText');
const gallerySearchInput = document.getElementById('gallerySearchInput');
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
const editModal = document.getElementById('editModal');
const deleteModal = document.getElementById('deleteModal');

function syncSelectedFiles(files) {
  const count = files && typeof files.length !== 'undefined' ? files.length : 0;
  if (uploadSubmitBtn) uploadSubmitBtn.disabled = count === 0;
  if (filePill && filePillText) {
    filePill.classList.toggle('show', count > 0);
    filePillText.textContent = count === 1 ? files[0].name : count + ' files selected';
  }
}

if (dropZone && fileInput) {
  dropZone.addEventListener('click', function () {
    fileInput.click();
  });
  dropZone.addEventListener('dragover', function (e) {
    e.preventDefault();
    dropZone.classList.add('drag-over');
  });
  dropZone.addEventListener('dragleave', function () {
    dropZone.classList.remove('drag-over');
  });
  dropZone.addEventListener('drop', function (e) {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    fileInput.files = e.dataTransfer.files;
    syncSelectedFiles(fileInput.files);
  });
}

if (fileInput) {
  fileInput.addEventListener('change', function () {
    syncSelectedFiles(fileInput.files);
  });
}

function updateSelCount() {
  const count = document.querySelectorAll('.sel-check:checked').length;
  const selCount = document.getElementById('selCount');
  if (selCount) selCount.textContent = count;
  if (bulkDeleteBtn) bulkDeleteBtn.disabled = count === 0;
}

function bulkDelete() {
  const count = document.querySelectorAll('.sel-check:checked').length;
  if (!count) return;
  if (confirm('Delete ' + count + ' image(s)? This cannot be undone.')) {
    document.getElementById('bulkForm').submit();
  }
}

if (gallerySearchInput) {
  gallerySearchInput.addEventListener('input', function () {
    const q = gallerySearchInput.value.trim().toLowerCase();
    document.querySelectorAll('#galleryGrid .gallery-card').forEach(function (card) {
      const haystack = card.dataset.search || '';
      card.style.display = !q || haystack.indexOf(q) !== -1 ? '' : 'none';
    });
  });
}

function openEditFromButton(button) {
  document.getElementById('editItemId').value = button.getAttribute('data-id') || '';
  document.getElementById('editCap').value = button.getAttribute('data-caption') || '';
  document.getElementById('editAlt').value = button.getAttribute('data-alt') || '';
  document.getElementById('editCat').value = button.getAttribute('data-category') || '';
  document.getElementById('editSort').value = button.getAttribute('data-sort') || 0;
  document.getElementById('editPreview').src = button.getAttribute('data-src') || '';
  editModal.style.display = 'flex';
}

function closeEdit() {
  if (editModal) editModal.style.display = 'none';
}

function openDeleteFromButton(button) {
  const id = button.getAttribute('data-id') || '';
  const cat = button.getAttribute('data-cat') || '';
  let url = 'gallery.php?delete=' + encodeURIComponent(id);
  if (cat) url += '&cat=' + encodeURIComponent(cat);
  document.getElementById('delImgBtn').href = url;
  deleteModal.style.display = 'flex';
}

function closeDelete() {
  if (deleteModal) deleteModal.style.display = 'none';
}

document.querySelectorAll('.edit-trigger').forEach(function (button) {
  button.addEventListener('click', function () {
    openEditFromButton(button);
  });
});

document.querySelectorAll('.delete-trigger').forEach(function (button) {
  button.addEventListener('click', function () {
    openDeleteFromButton(button);
  });
});

if (editModal) {
  editModal.addEventListener('click', function (e) {
    if (e.target === editModal) closeEdit();
  });
}

if (deleteModal) {
  deleteModal.addEventListener('click', function (e) {
    if (e.target === deleteModal) closeDelete();
  });
}

document.getElementById('closeEditBtn').addEventListener('click', closeEdit);
document.getElementById('cancelEditBtn').addEventListener('click', closeEdit);
document.getElementById('closeDeleteBtn').addEventListener('click', closeDelete);
document.getElementById('cancelDeleteBtn').addEventListener('click', closeDelete);
</script>
</body>
</html>
