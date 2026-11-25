#!/usr/bin/env python3
"""Deterministic release packager and verifier for Paymenter v1 Paymos gateway."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import re
import sys
import zipfile
from pathlib import Path

VERSION = "1.0.0"
ZIP_FILENAME = f"Paymos-Paymenter-v{VERSION}.zip"
ARCHIVE_ROOT = "Paymos"
FIXED_ZIP_TIMESTAMP = (2026, 1, 1, 0, 0, 0)

PLUGIN_ROOT = Path(__file__).resolve().parent.parent
DIST_DIR = PLUGIN_ROOT / "dist"
ZIP_PATH = DIST_DIR / ZIP_FILENAME

TOP_LEVEL_FILES = (
    "Paymos.php",
    "routes.php",
    "composer.json",
    "README.md",
    "LICENSE",
)

REQUIRED_INTERNAL_FILES = (
    "Autoloader.php",
    "Config.php",
    "DbDriverInterface.php",
    "PdoDbDriver.php",
    "PdoEventStore.php",
    "SnapshotStore.php",
    "GatewayService.php",
)

REQUIRED_SDK_FILES = (
    "Client.php",
    "ClientConfig.php",
    "Resources/Invoices.php",
    "Resources/Refunds.php",
    "Webhook/WebhookVerifier.php",
    "Webhook/WebhookEventProcessor.php",
    "Webhook/WebhookEvent.php",
    "Webhook/CommitAwareEventStoreInterface.php",
    "Plugin/StatusMapper.php",
    "Plugin/AmountGuard.php",
    "Plugin/InvoiceReverseVerifier.php",
    "Plugin/InvoiceRenewal.php",
)

# Constructed dynamically so no personal identity token appears literally in source.
PERSONAL_LEAK_TOKENS = (
    "anton" + "buik",
    "shock" + "k73",
)

SECRET_LEAK_PATTERNS = (
    re.compile(r"\b(?:pk_live|sk_live|rk_live|rk_test)_[A-Za-z0-9]{6,}\b"),
    re.compile(r"\bwhsec_[A-Za-z0-9]{6,}\b"),
)


def resolve_sdk_src() -> Path:
    monorepo_sdk = PLUGIN_ROOT.parent / "php-sdk" / "src"
    if monorepo_sdk.is_dir():
        return monorepo_sdk

    vendored_sdk = PLUGIN_ROOT / "vendor" / "paymos" / "php-sdk" / "src"
    if vendored_sdk.is_dir():
        return vendored_sdk

    raise FileNotFoundError(
        "Unable to locate paymos/php-sdk/src/ in monorepo (../php-sdk/src) or local vendor/."
    )


def collect_entries() -> list[tuple[str, Path]]:
    entries: list[tuple[str, Path]] = []

    for filename in TOP_LEVEL_FILES:
        path = PLUGIN_ROOT / filename
        if not path.is_file():
            raise FileNotFoundError(f"Missing required plugin file: {path}")
        entries.append((f"{ARCHIVE_ROOT}/{filename}", path))

    src_dir = PLUGIN_ROOT / "src"
    if not src_dir.is_dir():
        raise FileNotFoundError(f"Missing src/ directory: {src_dir}")
    for path in sorted(src_dir.rglob("*.php")):
        rel = path.relative_to(src_dir).as_posix()
        entries.append((f"{ARCHIVE_ROOT}/src/{rel}", path))

    sdk_src = resolve_sdk_src()
    for path in sorted(sdk_src.rglob("*.php")):
        rel = path.relative_to(sdk_src).as_posix()
        entries.append((f"{ARCHIVE_ROOT}/vendor/paymos/php-sdk/src/{rel}", path))

    entries.sort(key=lambda item: item[0])
    return entries


def build_zip_bytes(entries: list[tuple[str, Path]]) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, mode="w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for arcname, source in entries:
            if "\\" in arcname or arcname.startswith("/") or ".." in arcname.split("/"):
                raise ValueError(f"Invalid archive entry path: {arcname}")
            info = zipfile.ZipInfo(filename=arcname, date_time=FIXED_ZIP_TIMESTAMP)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3  # Unix
            info.external_attr = 0o100644 << 16
            zf.writestr(info, source.read_bytes())
    return buf.getvalue()


def build_archive() -> tuple[Path, str, int]:
    entries = collect_entries()
    zip_bytes = build_zip_bytes(entries)
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    ZIP_PATH.write_bytes(zip_bytes)
    digest = hashlib.sha256(zip_bytes).hexdigest()
    return ZIP_PATH, digest, len(entries)


def scan_text_for_leaks(label: str, text: str, check_secrets: bool) -> list[str]:
    findings: list[str] = []
    lowered = text.lower()
    for token in PERSONAL_LEAK_TOKENS:
        if token in lowered:
            findings.append(f"Personal identity token found in {label}")
    if check_secrets:
        for pattern in SECRET_LEAK_PATTERNS:
            if pattern.search(text):
                findings.append(f"Potential live/webhook secret found in {label}")
    return findings


def verify_archive() -> list[str]:
    errors: list[str] = []
    entries = collect_entries()
    first_bytes = build_zip_bytes(entries)
    second_bytes = build_zip_bytes(entries)
    if first_bytes != second_bytes:
        errors.append("ZIP build is not deterministic (two consecutive builds produced different bytes).")

    DIST_DIR.mkdir(parents=True, exist_ok=True)
    ZIP_PATH.write_bytes(first_bytes)

    with zipfile.ZipFile(ZIP_PATH, mode="r") as zf:
        bad_member = zf.testzip()
        if bad_member is not None:
            errors.append(f"ZIP CRC check failed on member: {bad_member}")

        names = zf.namelist()
        name_set = set(names)

        for name in names:
            if "\\" in name or name.startswith("/") or ".." in name.split("/"):
                errors.append(f"Non-POSIX or unsafe entry path in ZIP: {name}")
            if not name.startswith(f"{ARCHIVE_ROOT}/"):
                errors.append(f"Entry outside {ARCHIVE_ROOT}/ root in ZIP: {name}")
            lowered_parts = [part.lower() for part in name.split("/")]
            for forbidden in ("tests", "scripts", ".git", ".env", "dist"):
                if forbidden in lowered_parts:
                    errors.append(f"Forbidden path component '{forbidden}' found in ZIP entry: {name}")

        for filename in TOP_LEVEL_FILES:
            expected = f"{ARCHIVE_ROOT}/{filename}"
            if expected not in name_set:
                errors.append(f"Missing required top-level ZIP entry: {expected}")

        for filename in REQUIRED_INTERNAL_FILES:
            expected = f"{ARCHIVE_ROOT}/src/{filename}"
            if expected not in name_set:
                errors.append(f"Missing required internal class in ZIP: {expected}")

        for rel in REQUIRED_SDK_FILES:
            expected = f"{ARCHIVE_ROOT}/vendor/paymos/php-sdk/src/{rel}"
            if expected not in name_set:
                errors.append(f"Missing required vendored SDK file in ZIP: {expected}")

        # Verify routes.php CSRF exemption
        if f"{ARCHIVE_ROOT}/routes.php" in name_set:
            routes_text = zf.read(f"{ARCHIVE_ROOT}/routes.php").decode("utf-8")
            if "/extensions/gateways/paymos/webhook" not in routes_text:
                errors.append("routes.php is missing '/extensions/gateways/paymos/webhook' route.")
            if "withoutMiddleware([PreventRequestForgery::class])" not in routes_text:
                errors.append("routes.php is missing withoutMiddleware([PreventRequestForgery::class]).")
            if "PreventRequestForgery" not in routes_text:
                errors.append("routes.php does not reference PreventRequestForgery.")

        # Verify composer.json PSR-4 Internal\ mapping and PHP >=8.2
        if f"{ARCHIVE_ROOT}/composer.json" in name_set:
            try:
                composer = json.loads(zf.read(f"{ARCHIVE_ROOT}/composer.json").decode("utf-8"))
                php_req = str(composer.get("require", {}).get("php", ""))
                if "8.2" not in php_req:
                    errors.append(f"composer.json require.php must target >=8.2, got: {php_req!r}")
                psr4 = composer.get("autoload", {}).get("psr-4", {})
                if psr4.get("Paymenter\\Extensions\\Gateways\\Paymos\\Internal\\") != "src/":
                    errors.append("composer.json is missing PSR-4 mapping for Internal\\ -> src/.")
                if psr4.get("Paymenter\\Extensions\\Gateways\\Paymos\\") != ".":
                    errors.append("composer.json is missing PSR-4 mapping for root gateway namespace -> '.'.")
            except json.JSONDecodeError as exc:
                errors.append(f"composer.json is not valid JSON: {exc}")

        # Verify Paymos.php metadata, autoloader, encryption, refunds
        if f"{ARCHIVE_ROOT}/Paymos.php" in name_set:
            paymos_text = zf.read(f"{ARCHIVE_ROOT}/Paymos.php").decode("utf-8")
            for required_snippet in (
                "namespace Paymenter\\Extensions\\Gateways\\Paymos;",
                "Internal\\Autoloader::register()",
                "#[ExtensionMeta(",
                "class Paymos extends Gateway",
                "'encrypted' => true",
                "function supportsRefunds()",
                "function refund(",
            ):
                if required_snippet not in paymos_text:
                    errors.append(f"Paymos.php is missing required snippet: {required_snippet}")

        # Verify README copy accuracy
        if f"{ARCHIVE_ROOT}/README.md" in name_set:
            readme_text = zf.read(f"{ARCHIVE_ROOT}/README.md").decode("utf-8")
            if "instant settlement" in readme_text.lower():
                errors.append("README.md must not claim 'instant settlement'.")
            if re.search(r"\bxaut\b[^.\n]{0,20}\bstablecoin", readme_text, re.IGNORECASE):
                errors.append("README.md must not describe XAUT as a stablecoin.")
            for required_term in ("USDT", "USDC", "USD1", "DAI", "XAUT", "/extensions/gateways/paymos/webhook"):
                if required_term not in readme_text:
                    errors.append(f"README.md is missing required term: {required_term}")

        # Zero-leak scan across all ZIP contents
        for name in names:
            raw = zf.read(name)
            try:
                text = raw.decode("utf-8")
            except UnicodeDecodeError:
                continue
            errors.extend(scan_text_for_leaks(f"ZIP:{name}", text, check_secrets=True))

    # Zero-leak scan for personal identity tokens across plugin source tree (excluding dist/ and vendor/)
    for path in sorted(PLUGIN_ROOT.rglob("*")):
        if not path.is_file():
            continue
        rel_parts = path.relative_to(PLUGIN_ROOT).parts
        if rel_parts and rel_parts[0] in ("dist", "vendor"):
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        errors.extend(
            scan_text_for_leaks(
                f"SRC:{path.relative_to(PLUGIN_ROOT).as_posix()}",
                text,
                check_secrets=False,
            )
        )

    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description="Package and verify Paymos Paymenter v1 gateway ZIP.")
    parser.add_argument(
        "--verify",
        action="store_true",
        help="Build deterministic ZIP and run full layout, contract, and zero-leak verification.",
    )
    args = parser.parse_args()

    if args.verify:
        errors = verify_archive()
        if errors:
            for err in errors:
                print(f"[FAIL] {err}", file=sys.stderr)
            return 1
        digest = hashlib.sha256(ZIP_PATH.read_bytes()).hexdigest()
        with zipfile.ZipFile(ZIP_PATH, mode="r") as zf:
            count = len(zf.namelist())
        print(f"[OK] Verified {ZIP_PATH.name} ({count} entries, sha256={digest}, 0 leaks)")
        return 0

    zip_path, digest, count = build_archive()
    print(f"[OK] Built {zip_path} ({count} entries, sha256={digest})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
