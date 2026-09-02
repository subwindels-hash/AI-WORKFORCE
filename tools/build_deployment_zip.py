#!/usr/bin/env python3
"""Build or verify the cPanel deployment archive.

The production archive deliberately contains only the PHP/CodeIgniter release:
application, assets, database, docs, runtime and system.  Development-only
workspaces, tests, tools, local databases, logs, sessions, and user uploads
must never be shipped to a hosting account.

Usage:
    python3 tools/build_deployment_zip.py
    python3 tools/build_deployment_zip.py --check

The generated archive is deterministic: its entries are ordered and use a
fixed ZIP timestamp.  This keeps release changes reviewable and makes a stale
application-deployment.zip detectable before it is published.
"""

from __future__ import annotations

import argparse
import hashlib
import os
import stat
import sys
import zipfile
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parent.parent
ARCHIVE = ROOT / "application-deployment.zip"
ROOT_FILES = (".env.example", ".gitignore", ".htaccess", "index.php")
RELEASE_DIRECTORIES = ("application", "assets", "database", "docs", "runtime", "system")
EXCLUDED_DIRECTORY_NAMES = {
    ".git",
    ".next",
    ".venv",
    "__pycache__",
    "build",
    "coverage",
    "dist",
    "node_modules",
    "out",
}
ZIP_TIMESTAMP = (1980, 1, 1, 0, 0, 0)


def archive_name(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def is_release_file(path: Path) -> bool:
    """Return whether a file is safe and useful in the deployment archive."""
    rel = archive_name(path)
    parts = path.relative_to(ROOT).parts

    if any(part in EXCLUDED_DIRECTORY_NAMES for part in parts):
        return False
    if path == ARCHIVE or path.name == ".DS_Store":
        return False
    if path.suffix in {".sqlite", ".log", ".pyc", ".tsbuildinfo"} or rel.endswith(".sqlite-journal"):
        return False

    # Do not ship data created by a local runtime, PHP logs, web sessions, or
    # customer avatars.  Their placeholder/control files keep the directories
    # present and protected after extraction.
    if rel.startswith("application/data/"):
        return path.name == ".gitkeep"
    if rel.startswith("runtime/sessions/"):
        return path.name == ".gitkeep"
    if rel.startswith("assets/uploads/avatars/"):
        return path.name in {".gitkeep", "index.html", ".htaccess"}

    return True


def release_files() -> list[Path]:
    files: list[Path] = []
    for name in ROOT_FILES:
        path = ROOT / name
        if not path.is_file():
            raise RuntimeError(f"Required release file is missing: {name}")
        files.append(path)

    for directory in RELEASE_DIRECTORIES:
        base = ROOT / directory
        if not base.is_dir():
            raise RuntimeError(f"Required release directory is missing: {directory}")
        files.extend(path for path in base.rglob("*") if path.is_file() and is_release_file(path))

    return sorted(set(files), key=archive_name)


def release_directories(files: Iterable[Path]) -> list[str]:
    """Return all required directory entries, parent-first and sorted."""
    directories: set[str] = set()
    for path in files:
        parent = path.parent
        while parent != ROOT:
            directories.add(archive_name(parent) + "/")
            parent = parent.parent
    return sorted(directories, key=lambda value: (value.count("/"), value))


def zip_info(name: str, mode: int) -> zipfile.ZipInfo:
    info = zipfile.ZipInfo(name, date_time=ZIP_TIMESTAMP)
    info.create_system = 3  # Unix permissions when cPanel extracts the archive.
    info.external_attr = (mode & 0xFFFF) << 16
    # Passing a ZipInfo to writestr overrides ZipFile's default compression, so
    # set this explicitly instead of accidentally creating a stored-only ZIP.
    info.compress_type = zipfile.ZIP_DEFLATED
    return info


def write_archive(output: Path, files: list[Path]) -> None:
    temporary = output.with_name(output.name + ".tmp")
    try:
        with zipfile.ZipFile(
            temporary,
            mode="w",
            compression=zipfile.ZIP_DEFLATED,
            compresslevel=9,
            strict_timestamps=True,
        ) as bundle:
            for name in release_directories(files):
                directory = zip_info(name, stat.S_IFDIR | 0o755)
                directory.external_attr |= 0x10  # MS-DOS directory attribute.
                bundle.writestr(directory, b"")
            for path in files:
                mode = stat.S_IFREG | (path.stat().st_mode & 0o777)
                bundle.writestr(zip_info(archive_name(path), mode), path.read_bytes())
        os.replace(temporary, output)
    finally:
        if temporary.exists():
            temporary.unlink()


def required_language_progress_columns(sql: str) -> bool:
    """Validate the corrected table rather than only checking a marker."""
    start = sql.find("CREATE TABLE IF NOT EXISTS language_progress")
    if start < 0:
        return False
    end = sql.find(";", start)
    statement = sql[start : end + 1 if end >= 0 else None]
    required = (
        "value_pct          DECIMAL(5,2) NULL",
        "source             VARCHAR(24) NOT NULL",
        "updated_at         VARCHAR(32) NOT NULL",
        "UNIQUE KEY uq_progress (profile_id, skill, source)",
        "KEY idx_progress_user (user_id)",
    )
    forbidden = (
        "-nt|lesson (Phase 2)",
        "KEY idx_attempts_profile (profile_id, created_at)",
        "score_pct      DECIMAL(5,2) NULL",
        "passed         TINYINT(1) NULL",
    )
    return all(item in statement for item in required) and not any(item in statement for item in forbidden)


def verify_archive() -> None:
    if not ARCHIVE.is_file():
        raise RuntimeError(f"Deployment archive is missing: {ARCHIVE.name}")

    files = release_files()
    expected_files = {archive_name(path) for path in files}
    expected_directories = set(release_directories(files))
    with zipfile.ZipFile(ARCHIVE) as bundle:
        bad = bundle.testzip()
        if bad is not None:
            raise RuntimeError(f"Corrupt ZIP entry: {bad}")
        actual_files = {entry for entry in bundle.namelist() if not entry.endswith("/")}
        actual_directories = {entry for entry in bundle.namelist() if entry.endswith("/")}
        if actual_files != expected_files:
            missing = sorted(expected_files - actual_files)
            extra = sorted(actual_files - expected_files)
            raise RuntimeError(
                "Deployment archive contents are stale "
                f"(missing: {', '.join(missing) or 'none'}; extra: {', '.join(extra) or 'none'})."
            )
        if actual_directories != expected_directories:
            raise RuntimeError("Deployment archive directory entries are stale.")

        for path in files:
            name = archive_name(path)
            if bundle.read(name) != path.read_bytes():
                raise RuntimeError(f"Deployment archive differs from source: {name}")

        production_sql = bundle.read("database/production.sql").decode("utf-8")
        if production_sql.count("CREATE TABLE IF NOT EXISTS language_progress") != 1:
            raise RuntimeError("Deployment production SQL must define language_progress exactly once.")
        if not required_language_progress_columns(production_sql):
            raise RuntimeError("Deployment production SQL has an invalid language_progress definition.")


def main() -> int:
    parser = argparse.ArgumentParser(description="Build or verify application-deployment.zip")
    parser.add_argument("--check", action="store_true", help="verify the checked-in archive without rebuilding it")
    args = parser.parse_args()

    if args.check:
        verify_archive()
        print(f"OK — {ARCHIVE.name} matches {len(release_files())} release files.")
        return 0

    files = release_files()
    write_archive(ARCHIVE, files)
    verify_archive()
    digest = hashlib.sha256(ARCHIVE.read_bytes()).hexdigest()
    print(f"Built {ARCHIVE.name} with {len(files)} release files.")
    print(f"SHA-256: {digest}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, RuntimeError, zipfile.BadZipFile) as error:
        print(f"ERROR: {error}", file=sys.stderr)
        raise SystemExit(1)
