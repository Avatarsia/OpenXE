<style>
.upload-container {
    max-width: 600px;
    margin: 40px auto;
    padding: 30px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.upload-container h1 {
    margin: 0 0 24px 0;
    font-size: 24px;
    color: #333;
}

.upload-area {
    border: 2px dashed #2196f3;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    background: #f8f9fa;
    cursor: pointer;
    transition: all 0.3s;
}

.upload-area:hover {
    border-color: #1976d2;
    background: #e3f2fd;
}

.upload-area.dragover {
    border-color: #1976d2;
    background: #bbdefb;
}

.upload-icon {
    font-size: 48px;
    color: #2196f3;
    margin-bottom: 16px;
}

.upload-text {
    font-size: 16px;
    color: #666;
    margin-bottom: 8px;
}

.upload-hint {
    font-size: 14px;
    color: #999;
}

#theme_zip {
    display: none;
}

.upload-button {
    background: #2196f3;
    color: white;
    padding: 12px 32px;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    margin-top: 20px;
}

.upload-button:hover {
    background: #1976d2;
}

.upload-button:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.file-info {
    margin-top: 20px;
    padding: 12px;
    background: #e8f5e9;
    border-radius: 4px;
    display: none;
}

.file-info.show {
    display: block;
}

.requirements {
    margin-top: 30px;
    padding: 20px;
    background: #f5f5f5;
    border-radius: 4px;
}

.requirements h3 {
    margin: 0 0 12px 0;
    font-size: 16px;
}

.requirements ul {
    margin: 0;
    padding-left: 20px;
}

.requirements li {
    margin: 8px 0;
    color: #666;
}

.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #2196f3;
    text-decoration: none;
}

.back-link:hover {
    text-decoration: underline;
}
</style>

<div class="upload-container">
    <a href="index.php?module=themes&action=list" class="back-link">← Zurück zur Theme-Übersicht</a>
    
    <h1>Theme hochladen</h1>
    
    <form method="post" enctype="multipart/form-data" id="upload-form">
        <div class="upload-area" id="upload-area">
            <div class="upload-icon">📦</div>
            <div class="upload-text">ZIP-Datei hier ablegen oder klicken zum Auswählen</div>
            <div class="upload-hint">Maximale Größe: 10MB</div>
            <input type="file" name="theme_zip" id="theme_zip" accept=".zip" required>
        </div>
        
        <div class="file-info" id="file-info">
            <strong>Ausgewählte Datei:</strong> <span id="file-name"></span>
        </div>
        
        <button type="submit" class="upload-button" id="upload-btn" disabled>Theme hochladen</button>
    </form>
    
    <div class="requirements">
        <h3>Anforderungen für Theme-Upload:</h3>
        <ul>
            <li>Dateiformat: ZIP (max. 10MB)</li>
            <li>Enthält <code>theme.json</code> im Hauptverzeichnis</li>
            <li>Erlaubte Dateien: .css, .json, .svg, .png, .jpg, .gif, .tpl</li>
            <li>Maximale Anzahl Dateien: 500</li>
            <li>Theme-Name: Nur Buchstaben, Zahlen und Unterstriche</li>
            <li>Keine PHP-Dateien oder ausführbarer Code</li>
        </ul>
    </div>
</div>

<script>
const uploadArea = document.getElementById('upload-area');
const fileInput = document.getElementById('theme_zip');
const fileInfo = document.getElementById('file-info');
const fileName = document.getElementById('file-name');
const uploadBtn = document.getElementById('upload-btn');

// Click to select file
uploadArea.addEventListener('click', () => {
    fileInput.click();
});

// File selected
fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        const file = e.target.files[0];
        fileName.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        fileInfo.classList.add('show');
        uploadBtn.disabled = false;
    }
});

// Drag and drop
uploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadArea.classList.add('dragover');
});

uploadArea.addEventListener('dragleave', () => {
    uploadArea.classList.remove('dragover');
});

uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    
    if (e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        const file = e.dataTransfer.files[0];
        fileName.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        fileInfo.classList.add('show');
        uploadBtn.disabled = false;
    }
});

// Form validation
document.getElementById('upload-form').addEventListener('submit', (e) => {
    const file = fileInput.files[0];
    if (!file) {
        e.preventDefault();
        alert('Bitte wählen Sie eine ZIP-Datei aus');
        return;
    }
    
    if (file.size > 10 * 1024 * 1024) {
        e.preventDefault();
        alert('Die Datei ist zu groß (max. 10MB)');
        return;
    }
    
    if (!file.name.endsWith('.zip')) {
        e.preventDefault();
        alert('Nur ZIP-Dateien sind erlaubt');
        return;
    }
    
    uploadBtn.disabled = true;
    uploadBtn.textContent = 'Upload läuft...';
});
</script>
