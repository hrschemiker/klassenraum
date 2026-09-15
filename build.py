#!/usr/bin/env python3
"""Create deterministic distribution archives."""
from pathlib import Path
import hashlib
import zipfile

root = Path(__file__).resolve().parent
dist = root / "dist"; dist.mkdir(exist_ok=True)


def write_archive(target: Path, files: list[tuple[Path, str]]) -> None:
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        for path, member in sorted(files, key=lambda item: item[1]):
            info = zipfile.ZipInfo(member, (2026, 1, 1, 0, 0, 0))
            executable = path.suffix == ".sh" or path.name == "bcpctl"
            info.external_attr = (0o755 if executable else 0o644) << 16
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)


plugin = root / "wordpress" / "gtbp-recording-bridge"
plugin_target = dist / "gtbp-recording-bridge-1.2.2.zip"
plugin_files = [(path, path.relative_to(plugin.parent).as_posix()) for path in plugin.rglob("*") if path.is_file()]
write_archive(plugin_target, plugin_files)

source_target = dist / "bbb-control-plane-source-1.4.0.zip"
excluded_parts = {".git", "dist", "__pycache__"}
source_files = [
    (path, path.relative_to(root).as_posix())
    for path in root.rglob("*")
    if path.is_file()
    and not excluded_parts.intersection(path.relative_to(root).parts)
    and path.suffix not in {".pyc", ".zip"}
]
write_archive(source_target, source_files)

targets = [source_target, plugin_target]
checksums = "".join(f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}\n" for path in targets)
