#!/usr/bin/env python3
"""Build a reviewable source+video ZIP from an explicit allowlist; never copy local secrets/data."""
from pathlib import Path
import hashlib, json, zipfile

root = Path(__file__).resolve().parents[1]
target = root / 'releases'
target.mkdir(exist_ok=True)
archive = target / 'gpt-redeem-php-orbitcard-20261022.zip'
files = [root / f for f in ['README.md', 'config.example.php', '.env.example', 'router.php', 'start.sh']]
for folder in ['app', 'public', 'database', 'deploy', 'scripts']:
    files.extend(p for p in (root / folder).rglob('*') if p.is_file() and not p.is_symlink() and p.suffix in ['.php', '.js', '.css', '.svg', '.jpg', '.mp4', '.sh', '.py', '.sql', '.example'])
for doc in ['mysql-deploy.md', 'orbitcard-deploy.md', 'lucide-LICENSE']:
    files.append(root / 'docs' / doc)
manifest = {}
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as z:
    for p in sorted(set(files)):
        relative = p.relative_to(root).as_posix()
        assert not any(part in ['.runtime', 'data', 'artifacts', 'releases'] for part in p.relative_to(root).parts)
        assert relative not in ['config.local.php', '.env', '.env.local']
        blob = p.read_bytes()
        manifest[relative] = hashlib.sha256(blob).hexdigest()
        z.writestr('gpt-redeem/' + relative, blob)
    z.writestr('gpt-redeem/bundle-manifest.json', json.dumps(manifest, ensure_ascii=False, indent=2))
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    for name, digest in manifest.items():
        assert hashlib.sha256(z.read('gpt-redeem/' + name)).hexdigest() == digest
    assert 'public/media/tutorial.mp4' in manifest
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
(target / (archive.name + '.sha256')).write_text(checksum + '  ' + archive.name + '\n')
print(f'{archive}\n{len(manifest)} files; {archive.stat().st_size / 1024 / 1024:.2f} MiB\nSHA256 {checksum}')
