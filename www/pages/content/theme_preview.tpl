<style>
.theme-preview-container {
    width: 100%;
    height: 100vh;
    display: flex;
    flex-direction: column;
}

.preview-header {
    padding: 12px 20px;
    background: #2196f3;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.preview-header h2 {
    margin: 0;
    font-size: 18px;
}

.preview-actions {
    display: flex;
    gap: 12px;
}

.preview-actions .button {
    padding: 8px 16px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 14px;
}

.preview-actions .button-apply {
    background: #4caf50;
    color: white;
}

.preview-actions .button-close {
    background: white;
    color: #2196f3;
}

.preview-iframe {
    flex: 1;
    border: none;
    width: 100%;
}
</style>

<div class="theme-preview-container">
    <div class="preview-header">
        <h2>Theme Vorschau: [PREVIEW_THEME]</h2>
        <div class="preview-actions">
            <form method="post" action="index.php?module=themes&action=activate" style="display:inline;">
                <input type="hidden" name="theme" value="[PREVIEW_THEME]">
                <input type="hidden" name="scope" value="user">
                <button type="submit" class="button button-apply">Theme übernehmen</button>
            </form>
            <button class="button button-close" onclick="window.close()">Schließen</button>
        </div>
    </div>
    <iframe src="[PREVIEW_URL]" class="preview-iframe"></iframe>
</div>
