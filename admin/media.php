<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;
use Core\Router;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();$basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

// Handle media item deletion
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (Security::verifyCsrfToken($_GET['csrf'])) {
        $delId = (int)$_GET['delete'];
        $stmt =$db->prepare("SELECT filepath FROM media WHERE id = :id LIMIT 1");
        $stmt->execute([':id' =>$delId]);
        $fileRecord =$stmt->fetch();

        if ($fileRecord) {
            $physicalPath = __DIR__ . '/..' .$fileRecord['filepath'];
            if (file_exists($physicalPath)) {
                @unlink($physicalPath);
            }
            $db->prepare("DELETE FROM media WHERE id = :id")->execute([':id' => $delId]);
        }

        header('Location: media.php?deleted=1');
        exit;
    }
}

// Fetch all uploaded assets from database
$mediaItems =$db->query("
    SELECT m.*, u.username as uploader 
    FROM media m 
    LEFT JOIN users u ON m.user_id = u.id 
    ORDER BY m.created_at DESC
")->fetchAll();

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Media Library') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage site assets, upload images, and perform in-browser edits') ?></p>
    </div>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('file-upload-input').click()">
        <?= _e('+ Upload File') ?>
    </button>
</div>

<?php if (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Media asset removed successfully.') ?>
    </div>
<?php endif; ?>

<div id="drop-zone" class="card" style="border: 2px dashed var(--border-strong); text-align: center; padding: 32px 20px; cursor: pointer; transition: all 0.2s ease;">
    <input type="file" id="file-upload-input" multiple accept="image/*" style="display: none;">
    <svg style="width: 42px; height: 42px; color: var(--primary); margin: 0 auto 10px; display: block;" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
    </svg>
    <p style="font-weight: 600; font-size: 15px; margin-bottom: 4px;"><?= _e('Drag and drop images here, or click to browse') ?></p>
    <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Supports JPG, PNG, WEBP, GIF up to 8MB') ?></span>
    <div id="upload-progress-bar" style="display: none; width: 100%; max-width: 320px; height: 6px; background: #e2e8f0; border-radius: 9999px; margin: 16px auto 0; overflow: hidden;">
        <div id="upload-progress" style="width: 0%; height: 100%; background: var(--primary); transition: width 0.2s;"></div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-top: 24px;">
    <?php if (empty($mediaItems)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 48px; color: var(--text-muted);">
            <?= _e('No media assets found in library.') ?>
        </div>
    <?php else: ?>
        <?php foreach ($mediaItems as $item):$fullUrl = $basePrefix .$item['filepath'];
            $fileSizeKb = round((int)$item['file_size'] / 1024, 1);
        ?>
            <div class="card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--shadow-sm);">
                <div style="width: 100%; height: 140px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; position: relative;">
                    <img src="<?= htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= Security::sanitize($item['filename']) ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                </div>
                <div style="padding: 12px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <span style="display: block; font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= Security::sanitize($item['filename']) ?>">
                            <?= Security::sanitize($item['filename']) ?>
                        </span>
                        <span style="font-size: 11px; color: var(--text-muted);"><?= $fileSizeKb ?> KB</span>
                    </div>

                    <div style="display: flex; gap: 6px; margin-top: 12px;">
                        <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; flex-grow: 1;" onclick="openImageEditor('<?= htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= Security::sanitize($item['filename']) ?>')">
                            <?= _e('Edit') ?>
                        </button>
                        <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; flex-grow: 1;" onclick="copyMediaUrl('<?= htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') ?>', this)">
                            <?= _e('Copy URL') ?>
                        </button>
                        <a href="media.php?delete=<?= $item['id'] ?>&csrf=<?= Security::generateCsrfToken() ?>" class="btn btn-danger-ghost" style="padding: 4px 8px; font-size: 11px;" onclick="return confirm('<?= _e('Delete this image permanently?') ?>');">
                            &times;
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="image-editor-modal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.7); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-surface); border-radius: var(--radius-md); max-width: 900px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: var(--shadow-sm);">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 16px; font-weight: 700;"><?= _e('Canvas Image Editor') ?></h3>
            <button type="button" onclick="closeImageEditor()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <div style="padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid var(--border-subtle); display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <button type="button" class="btn btn-secondary" onclick="rotateImage(90)"><?= _e('Rotate 90°') ?></button>
            <button type="button" class="btn btn-secondary" onclick="flipImage('h')"><?= _e('Flip Horizontal') ?></button>
            <button type="button" class="btn btn-secondary" onclick="flipImage('v')"><?= _e('Flip Vertical') ?></button>
            <select id="aspect-ratio-selector" class="form-control" style="width: auto; padding: 6px 12px; font-size: 13px;" onchange="applyAspectCrop(this.value)">
                <option value="free"><?= _e('Original Ratio') ?></option>
                <option value="16:9">16:9 (Landscape)</option>
                <option value="4:3">4:3 (Standard)</option>
                <option value="1:1">1:1 (Square)</option>
            </select>
        </div>

        <div style="padding: 20px; overflow: auto; flex-grow: 1; display: flex; justify-content: center; align-items: center; background: #0b0f19;">
            <canvas id="editor-canvas" style="max-width: 100%; max-height: 55vh; box-shadow: 0 4px 12px rgba(0,0,0,0.4);"></canvas>
        </div>

        <div style="padding: 16px 20px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">
            <span id="canvas-resolution" style="font-size: 12px; color: var(--text-muted);"></span>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeImageEditor()"><?= _e('Cancel') ?></button>
                <button type="button" id="save-canvas-btn" class="btn btn-primary" onclick="saveEditedImage()"><?= _e('Save as New Image') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Copies the absolute full URL (including origin, port, and subdirectory) to clipboard.
 */
function copyMediaUrl(path, buttonElement = null) {
    const cleanPath = path.startsWith('/') ? path : '/' + path;
    const absoluteUrl = window.location.origin + cleanPath;

    navigator.clipboard.writeText(absoluteUrl).then(() => {
        if (buttonElement) {
            const originalText = buttonElement.innerText;
            buttonElement.innerText = '<?= _e('Copied!') ?>';
            buttonElement.style.color = 'var(--primary)';
            setTimeout(() => {
                buttonElement.innerText = originalText;
                buttonElement.style.color = '';
            }, 1800);
        } else {
            alert('<?= _e('Link copied to clipboard') ?>:\n' + absoluteUrl);
        }
    }).catch(() => {
        // Fallback for non-HTTPS or legacy browsers
        const textarea = document.createElement('textarea');
        textarea.value = absoluteUrl;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('<?= _e('Link copied to clipboard') ?>:\n' + absoluteUrl);
    });
}

// File Upload Handling
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-upload-input');
const progressBar = document.getElementById('upload-progress-bar');
const progress = document.getElementById('upload-progress');

dropZone.addEventListener('click', () => fileInput.click());

['dragenter', 'dragover'].forEach(e => {
    dropZone.addEventListener(e, (evt) => {
        evt.preventDefault();
        dropZone.style.borderColor = 'var(--primary)';
        dropZone.style.backgroundColor = 'var(--primary-subtle)';
    });
});

['dragleave', 'drop'].forEach(e => {
    dropZone.addEventListener(e, (evt) => {
        evt.preventDefault();
        dropZone.style.borderColor = 'var(--border-strong)';
        dropZone.style.backgroundColor = 'transparent';
    });
});

dropZone.addEventListener('drop', (evt) => {
    if (evt.dataTransfer.files.length) {
        uploadFiles(evt.dataTransfer.files);
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
        uploadFiles(fileInput.files);
    }
});

function uploadFiles(files) {
    progressBar.style.display = 'block';
    let uploadedCount = 0;

    Array.from(files).forEach((file) => {
        const formData = new FormData();
        formData.append('file', file);

        fetch('upload.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            uploadedCount++;
            progress.style.width = Math.round((uploadedCount / files.length) * 100) + '%';
            if (uploadedCount === files.length) {
                setTimeout(() => window.location.reload(), 300);
            }
        })
        .catch(() => alert('Failed to upload file.'));
    });
}

// Canvas Image Editor
const editorModal = document.getElementById('image-editor-modal');
const canvas = document.getElementById('editor-canvas');
const ctx = canvas.getContext('2d');
const resIndicator = document.getElementById('canvas-resolution');
let originalImg = new Image();
let currentAngle = 0;
let scaleH = 1;
let scaleV = 1;
let activeFilename = 'edited.jpg';

function openImageEditor(imageUrl, filename) {
    activeFilename = filename;
    originalImg.crossOrigin = 'anonymous';
    originalImg.src = imageUrl;
    originalImg.onload = () => {
        currentAngle = 0;
        scaleH = 1;
        scaleV = 1;
        document.getElementById('aspect-ratio-selector').value = 'free';
        renderCanvas();
        editorModal.style.display = 'flex';
    };
}

function closeImageEditor() {
    editorModal.style.display = 'none';
}

function renderCanvas() {
    const isRotated = currentAngle % 180 !== 0;
    const w = isRotated ? originalImg.naturalHeight : originalImg.naturalWidth;
    const h = isRotated ? originalImg.naturalWidth : originalImg.naturalHeight;

    canvas.width = w;
    canvas.height = h;

    ctx.save();
    ctx.translate(canvas.width / 2, canvas.height / 2);
    ctx.rotate((currentAngle * Math.PI) / 180);
    ctx.scale(scaleH, scaleV);
    ctx.drawImage(originalImg, -originalImg.naturalWidth / 2, -originalImg.naturalHeight / 2);
    ctx.restore();

    resIndicator.textContent = `${canvas.width} x ${canvas.height} px`;
}

function rotateImage(deg) {
    currentAngle = (currentAngle + deg) % 360;
    renderCanvas();
}

function flipImage(axis) {
    if (axis === 'h') scaleH *= -1;
    if (axis === 'v') scaleV *= -1;
    renderCanvas();
}

function applyAspectCrop(ratio) {
    if (ratio === 'free') {
        renderCanvas();
        return;
    }
    renderCanvas();
    const [rw, rh] = ratio.split(':').map(Number);
    let targetW = canvas.width;
    let targetH = Math.round(targetW * (rh / rw));

    if (targetH > canvas.height) {
        targetH = canvas.height;
        targetW = Math.round(targetH * (rw / rh));
    }

    const startX = Math.round((canvas.width - targetW) / 2);
    const startY = Math.round((canvas.height - targetH) / 2);

    const croppedData = ctx.getImageData(startX, startY, targetW, targetH);
    canvas.width = targetW;
    canvas.height = targetH;
    ctx.putImageData(croppedData, 0, 0);
    resIndicator.textContent = `${canvas.width} x ${canvas.height} px`;
}

function saveEditedImage() {
    const saveBtn = document.getElementById('save-canvas-btn');
    saveBtn.disabled = true;
    saveBtn.textContent = '<?= _e('Saving...') ?>';

    canvas.toBlob((blob) => {
        const formData = new FormData();
        formData.append('file', blob, 'edited_' + activeFilename.replace(/\.[^/.]+$/, '.jpg'));

        fetch('upload.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.location) {
                window.location.reload();
            } else {
                alert(data.error || 'Failed to save image.');
                saveBtn.disabled = false;
                saveBtn.textContent = '<?= _e('Save as New Image') ?>';
            }
        })
        .catch(() => {
            alert('Upload failed due to network error.');
            saveBtn.disabled = false;
            saveBtn.textContent = '<?= _e('Save as New Image') ?>';
        });
    }, 'image/jpeg', 0.92);
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>