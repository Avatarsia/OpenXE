"""Bundle the offline preview; does not build or connect to OpenXE."""
from pathlib import Path

root = Path(__file__).resolve().parent
source = root / 'source'
html = (source / 'index.html').read_text(encoding='utf-8')
css = (source / 'style.css').read_text(encoding='utf-8')
javascript = (source / 'app.js').read_text(encoding='utf-8')
html = html.replace('<link rel="stylesheet" href="style.css">', '<style>\n' + css + '\n</style>')
html = html.replace('<script src="app.js" defer></script>', '')
html = html.replace('</body>', '<script>\n' + javascript + '\n</script>\n</body>')
(root / 'index.html').write_text(html, encoding='utf-8')
print(f'Offline preview: {root / "index.html"}')
