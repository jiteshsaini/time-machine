<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="robots" content="noindex, nofollow">
    <title>Resizing images…</title>
    <style>
        :root {
            --accent: #007bff;
            --accent-soft: #e7f1ff;
            --bg: #f7f7f7;
            --card: #ffffff;
            --text: #222;
            --muted: #6c757d;
            --border: #e5e7eb;
            --ok: #28a745;
            --err: #dc3545;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            color: var(--text);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .card {
            width: 100%;
            max-width: 460px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.06);
            padding: 28px 22px;
            text-align: center;
        }
        h1 {
            margin: 0 0 4px;
            font-size: 19px;
            font-weight: 600;
        }
        .subtitle {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 22px;
        }
        .path {
            background: var(--accent-soft);
            color: #1a4f8b;
            border: 1px solid #cdd9ec;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            margin-bottom: 22px;
            word-break: break-all;
            text-align: left;
            line-height: 1.4;
        }
        .spinner-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            padding: 12px 0 4px;
        }
        .ring {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            border: 5px solid var(--accent-soft);
            border-top-color: var(--accent);
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .status {
            font-size: 15px;
            color: var(--text);
            min-height: 22px;
        }
        .hint {
            color: var(--muted);
            font-size: 12px;
        }
        .current-file {
            color: var(--muted);
            font-size: 12px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            margin-top: 4px;
            min-height: 16px;
            word-break: break-all;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            direction: rtl;          /* keep filename visible when path is long */
            text-align: center;
        }

        /* Determinate progress bar — fills as files are processed. */
        .progress {
            margin-top: 18px;
            height: 8px;
            background: var(--accent-soft);
            border-radius: 999px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 999px;
            width: 0%;
            transition: width 250ms ease;
        }
        .progress-fill.indeterminate {
            width: 35% !important;
            animation: slide 1.5s ease-in-out infinite;
        }
        @keyframes slide {
            0%   { transform: translateX(-100%); }
            100% { transform: translateX(300%); }
        }
        .progress-label {
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
            font-variant-numeric: tabular-nums;
        }

        #result {
            margin-top: 8px;
            font-size: 14px;
            line-height: 1.5;
        }
        #result.ok  { color: var(--ok); }
        #result.err { color: var(--err); }
        #result h3 { margin: 0 0 6px; font-size: 17px; }

        .back-btn {
            display: inline-block;
            margin-top: 18px;
            padding: 10px 20px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .back-btn:hover { background: #0069d9; }

        @media (max-width: 480px) {
            .card { padding: 22px 16px; border-radius: 10px; }
            h1 { font-size: 17px; }
            .ring { width: 56px; height: 56px; border-width: 4px; }
        }
    </style>
</head>
<body>

<?php
include_once __DIR__ . '/../var.php';
$fullPath     = $_GET["p"] ?? '';
$docRoot      = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$relativePath = str_replace($docRoot . '/', '', $fullPath);
$batchSize    = defined('RESIZE_BATCH_SIZE') ? RESIZE_BATCH_SIZE : 500;
?>

<div class="wrap">
  <div class="card">
    <h1>Resizing images</h1>
    <div class="subtitle">Processes up to <?php echo $batchSize ?> images per batch. Images larger than 1920&times;1080 are scaled down; smaller files are left alone.</div>

    <div class="path"><?php echo htmlspecialchars($relativePath); ?></div>

    <div id="loader" class="spinner-area">
      <div class="ring"></div>
      <div id="status" class="status">Scanning…</div>
      <div id="current-file" class="current-file"></div>

      <div class="progress" aria-hidden="true">
        <div id="progress-fill" class="progress-fill indeterminate"></div>
      </div>
      <div id="progress-label" class="progress-label">&nbsp;</div>

      <div class="hint">Please don't close this tab</div>
    </div>

    <div id="result"></div>
  </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const loader     = document.getElementById("loader");
        const result     = document.getElementById("result");
        const statusEl   = document.getElementById("status");
        const currentEl  = document.getElementById("current-file");
        const fillEl     = document.getElementById("progress-fill");
        const labelEl    = document.getElementById("progress-label");

        const path       = <?php echo json_encode($fullPath); ?>;
        const returnPath = <?php echo json_encode($relativePath); ?>;
        const BATCH_SIZE = <?php echo (int)$batchSize ?>;
        let pollTimer    = null;
        let batchStart   = 0;
        let lastBatchSecs = 0;
        let totalProcessed = 0;

        function pollProgress() {
            fetch("get_resize_progress.php?folder=" + encodeURIComponent(path))
                .then(r => r.json())
                .then(p => {
                    if (!p || p.error) return;
                    if (p.phase === "scanning") {
                        statusEl.textContent = "Scanning folders…";
                        currentEl.textContent = "";
                        fillEl.classList.add("indeterminate");
                        labelEl.innerHTML = "&nbsp;";
                        return;
                    }
                    if (p.total > 0) {
                        const pct = Math.min(100, Math.round((p.done / p.total) * 100));
                        fillEl.classList.remove("indeterminate");
                        fillEl.style.width = pct + "%";
                        const remainStr = (p.remaining != null && p.remaining > 0)
                            ? "  ·  " + p.remaining.toLocaleString() + " queued for next batch" : "";
                        labelEl.textContent = p.done + " / " + p.total + " (" + pct + "%)" + remainStr;
                    } else if (p.finished) {
                        fillEl.classList.remove("indeterminate");
                        fillEl.style.width = "100%";
                        labelEl.textContent = "Nothing to resize";
                    }
                    statusEl.textContent  = p.finished ? "Done" : "Resizing…";
                    currentEl.textContent = p.finished ? "" : (p.current || "");
                })
                .catch(() => {});
        }

        function backLink() {
            return '<a class="back-btn" href="../manage.php?p=' + encodeURIComponent(returnPath) + '">&larr; Back to folder</a>';
        }

        function showDone(remaining) {
            loader.style.display = "none";
            if (remaining > 0) {
                // More batches needed — let the user keep going.
                const etaMin = lastBatchSecs > 0
                    ? Math.ceil((remaining / BATCH_SIZE) * lastBatchSecs / 60) : null;
                const etaStr = etaMin != null
                    ? " (~" + etaMin + " min if you run them back-to-back)" : "";
                result.className = "ok";
                result.innerHTML =
                    "<h3>✅ Batch complete</h3>" +
                    "<p><b>" + totalProcessed.toLocaleString() + "</b> processed so far · " +
                    "<b>" + remaining.toLocaleString() + "</b> still need resizing" + etaStr + "</p>" +
                    '<button type="button" class="back-btn" id="nextBatch">Resize next ' + Math.min(BATCH_SIZE, remaining) + '</button> ' +
                    backLink();
                document.getElementById("nextBatch").addEventListener("click", runBatch);
            } else {
                // Fully done — auto-redirect like before.
                result.className = "ok";
                result.innerHTML =
                    "<h3>✅ All done</h3>" +
                    "<p>" + totalProcessed.toLocaleString() + " image(s) processed across all batches.</p>" +
                    backLink();
                setTimeout(() => {
                    window.location.href = "../manage.php?p=" + encodeURIComponent(returnPath);
                }, 2500);
            }
        }

        function runBatch() {
            // Reset visible state for the new batch.
            loader.style.display = "";
            result.innerHTML = "";
            statusEl.textContent = "Scanning…";
            currentEl.textContent = "";
            fillEl.classList.add("indeterminate");
            fillEl.style.width = "0%";
            labelEl.innerHTML = "&nbsp;";
            batchStart = Date.now();
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(pollProgress, 600);
            pollProgress();

            fetch("img_resize_worker.php?p=" + encodeURIComponent(path) + "&batch=" + BATCH_SIZE)
                .then(r => r.text())
                .then(() => {
                    lastBatchSecs = (Date.now() - batchStart) / 1000;
                    if (pollTimer) clearInterval(pollTimer);
                    pollProgress();
                    // Read the final progress JSON so we know if more remain.
                    fetch("get_resize_progress.php?folder=" + encodeURIComponent(path))
                        .then(r => r.json())
                        .then(p => {
                            const doneThisBatch = (p && p.done) ? p.done : 0;
                            totalProcessed += doneThisBatch;
                            showDone((p && p.remaining) ? p.remaining : 0);
                        })
                        .catch(() => showDone(0));
                })
                .catch(err => {
                    if (pollTimer) clearInterval(pollTimer);
                    loader.style.display = "none";
                    result.className = "err";
                    result.innerHTML = "<h3>❌ Error</h3>" + err + "<br>" + backLink();
                });
        }

        // First batch kicks off automatically.
        runBatch();
    });
</script>

</body>
</html>
