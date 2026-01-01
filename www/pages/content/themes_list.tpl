<style>
.theme-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
    padding: 20px;
}

.theme-card {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
    background: white;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
}

.theme-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.theme-card.theme-active {
    border: 2px solid #2196f3;
}

.theme-preview {
    position: relative;
    width: 100%;
    height: 200px;
    overflow: hidden;
    background: #f5f5f5;
}

.theme-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.active-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: #2196f3;
    color: white;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.theme-info {
    padding: 16px;
}

.theme-info h3 {
    margin: 0 0 8px 0;
    font-size: 18px;
    color: #333;
}

.theme-description {
    margin: 0 0 12px 0;
    font-size: 14px;
    color: #666;
    line-height: 1.5;
}

.theme-meta {
    margin: 0;
    font-size: 12px;
    color: #999;
}

.theme-actions {
    padding: 16px;
    border-top: 1px solid #f0f0f0;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.theme-actions .button {
    flex: 1;
    min-width: 80px;
    text-align: center;
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.theme-actions .button-primary {
   background: #2196f3;
    color: white;
}

.theme-actions .button-primary:hover {
    background: #1976d2;
}

.theme-actions .button-secondary {
    background: #64b5f6;
    color: white;
    text-decoration: none;
    display: inline-block;
}

.theme-actions .button-secondary:hover {
    background: #42a5f5;
}

.theme-actions .button-ghost {
    background: transparent;
    border: 1px solid #2196f3;
    color: #2196f3;
    text-decoration: none;
    display: inline-block;
}

.theme-actions .button-ghost:hover {
    background: #e3f2fd;
}

.theme-header {
    padding: 24px 20px;
    background: white;
    border-bottom: 1px solid #e0e0e0;
    margin-bottom: 20px;
}

.theme-header h1 {
    margin: 0 0 8px 0;
    font-size: 28px;
}

.theme-header p {
    margin: 0;
    color: #666;
}

.current-theme-badge {
    display: inline-block;
    background: #4caf50;
    color: white;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 14px;
    margin-left: 12px;
}
</style>

<div class="theme-header">
    <h1>Theme Verwaltung <span class="current-theme-badge">Aktuell: [CURRENT_THEME]</span></h1>
    <p>Wählen Sie ein Theme für Ihr OpenXE System</p>
</div>

<div class="theme-grid">
    [THEME_GRID]
</div>

<script>
// Confirmation for theme activation
document.querySelectorAll('.theme-actions form').forEach(form => {
    form.addEventListener('submit', (e) => {
        const themeName = form.querySelector('input[name="theme"]').value;
        if (!confirm(`Theme "${themeName}" aktivieren?`)) {
            e.preventDefault();
        }
    });
});
</script>
