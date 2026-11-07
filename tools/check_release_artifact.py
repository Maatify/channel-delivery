#!/usr/bin/env python3
"""Verify the deployable ZIP and exact-source identity; no network, extraction or publication."""
import argparse
import datetime
import hashlib
import io
import json
import re
import stat
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path, PurePosixPath

REQUIRED = {
    "README.md", "LICENSE", "CHANGELOG.md", "SECURITY.md", "CONTRIBUTING.md", "CODE_OF_CONDUCT.md",
    "composer.json", "composer.lock", ".env.example", ".gitignore", "llms.txt", "public/index.php",
    "src/Application/Application.php", "scripts/email_worker.php", "scripts/create_api_key.php",
    "scripts/purge_synthetic_email.php", "docs/index.md", "docs/guides/USAGE_GUIDE.md",
    "docs/CHANNEL_DELIVERY_API.md", "docs/api/email-atomic.md", "docs/api/email-synthetic.md",
    "docs/reference/configuration.md", "docs/releases/RELEASE_CHECKLIST.md", "examples/README.md",
    "examples/enqueue-v1.sh", "examples/enqueue-v2.sh", "examples/receipt-v2.sh", "examples/synthetic.sh",
    "examples/payloads/welcome.json", "docs/examples/basic-usage.php", "tools/check_release_artifact.py",
}
FORBIDDEN = ("tests/", ".github/", "vendor/", "var/", "storage/", "logs/", ".git/")


def check(archive, target, source=None, commit=None, package="maatify/channel-delivery"):
    if package not in ("maatify/channel-delivery", "maatify/athar-channel-delivery"):
        raise ValueError("Unsupported project identity")
    upstream = package == "maatify/channel-delivery"
    if upstream and (target is None or not re.fullmatch(r"\d+\.\d+\.\d+", target)):
        raise ValueError("An exact stable SemVer target is required for the upstream release")
    if not upstream and target is not None:
        raise ValueError("Athar source checks do not select a release target")
    with zipfile.ZipFile(archive) as z:
        items = [i for i in z.infolist() if not i.is_dir()]
        if sum(i.file_size for i in items) > 64 * 1024 * 1024:
            raise ValueError("Archive exceeds the project qualification size bound")
        names = [i.filename for i in items]
        if len(names) != len(set(names)):
            raise ValueError("Duplicate ZIP entries")
        for i in items:
            path = PurePosixPath(i.filename)
            if str(path) != i.filename or path.is_absolute() or ".." in path.parts or "\\" in i.filename or stat.S_ISLNK(i.external_attr >> 16):
                raise ValueError("Unsafe archive entry")
        roots = [n[:-len("composer.json")] for n in names if n == "composer.json" or (n.endswith("/composer.json") and n.count("/") == 1)]
        if len(roots) != 1:
            raise ValueError("Expected one root Composer manifest")
        prefix = roots[0]
        if not all(n.startswith(prefix) for n in names):
            raise ValueError("Entries outside package root")
        files = {i.filename[len(prefix):]: z.read(i) for i in items}
    guide = target if upstream else "README"
    missing = REQUIRED | {f"docs/releases/{guide}.md", f"docs/upgrade/{guide}.md"}
    if not upstream:
        missing |= {"scripts/deploy_clear_cache.php", "templates/emails/order_confirmation/ar.twig", "templates/emails/order_delivered/en.twig"}
    if not missing.issubset(files):
        raise ValueError("Missing required consumer files: " + ", ".join(sorted(missing - files.keys())))
    for name in files:
        if name in (".env", ".git") or name.startswith(FORBIDDEN) or name.startswith(".env.") and name != ".env.example":
            raise ValueError("Forbidden deployment archive entry: " + name)
    manifest = json.loads(files["composer.json"])
    lock = json.loads(files["composer.lock"])
    if manifest.get("name") != package or manifest.get("type") != "project" or manifest.get("license") != "MIT":
        raise ValueError("Composer identity/type/license mismatch")
    if manifest["require"].get("php") != "^8.4" or "ext-pdo_mysql" not in manifest["require"] or manifest["require"].get("maatify/email-delivery") != "^1.2":
        raise ValueError("Runtime prerequisites mismatch")
    if "MIT License" not in files["LICENSE"].decode():
        raise ValueError("Repository license mismatch")
    packages = {p["name"]: p["version"] for p in lock["packages"]}
    if packages.get("maatify/email-delivery") not in ("1.2.0", "v1.2.0"):
        raise ValueError("Unexpected email engine in qualified project lock")
    readme = files["README.md"].decode()
    identity = f"Artifact target: `{target}`" if upstream else f"Artifact identity: `{package}`"
    if identity not in readme or "Publication snapshot at preparation" not in readme:
        raise ValueError("Artifact identity or time-scoped publication snapshot missing")
    if upstream and f"maatify/channel-delivery channel-delivery {target}" not in readme:
        raise ValueError("Exact-target install guidance missing")
    if "Modern PHP Libraries" not in readme or not readme.rstrip().endswith("</div>"):
        raise ValueError("Canonical footer missing/not final")
    changelog = files["CHANGELOG.md"].decode()
    if upstream:
        allocation = re.search(r"^## \[" + re.escape(target) + r"\](?: - (\d{4}-\d{2}-\d{2}))?$", changelog, re.M)
        if not allocation:
            raise ValueError("Exact-target changelog allocation missing")
        if allocation.group(1):
            datetime.date.fromisoformat(allocation.group(1))
    unreleased = re.search(r"^## \[Unreleased\]\s*(.*?)^## ", changelog, re.M | re.S)
    if len(re.findall(r"^## \[Unreleased\]$", changelog, re.M)) != 1:
        raise ValueError("Expected exactly one Unreleased section")
    if upstream and (not unreleased or re.search(r"^[-*] ", unreleased.group(1), re.M)):
        raise ValueError("Allocated changes remain under Unreleased")
    if "mailto:mohamed@maatify.dev" not in files["SECURITY.md"].decode():
        raise ValueError("Private security-reporting route missing")
    payload = json.loads(files["examples/payloads/welcome.json"])
    if not {"user_name", "activation_link"}.issubset(payload["context"]):
        raise ValueError("Welcome example misses actual template fields")
    credential = re.compile(rb"(?:CD_API_KEY\s*=|Key\s*:|X-Api-Key:\s*)[\"']?[a-fA-F0-9]{64}(?![a-fA-F0-9])")
    for name, data in files.items():
        if credential.search(data):
            raise ValueError("Complete credential-like example in: " + name)
    if source:
        source = Path(source).resolve()
        if commit:
            if not re.fullmatch(r"[a-f0-9]{40}", commit):
                raise ValueError("Full exact commit SHA required")
            head = subprocess.check_output(["git", "-C", str(source), "rev-parse", "HEAD"], text=True).strip()
            dirty = subprocess.check_output(["git", "-C", str(source), "status", "--porcelain"], text=True)
            if head != commit or dirty:
                raise ValueError("Source is not the clean exact qualification commit")
            expected_zip = subprocess.check_output(["git", "-C", str(source), "archive", "--format=zip", commit])
            with zipfile.ZipFile(io.BytesIO(expected_zip)) as expected:
                expected_files = {i.filename: expected.read(i) for i in expected.infolist() if not i.is_dir()}
            if files != expected_files:
                raise ValueError("Archive inventory/content differs from exact Git export")
        for name, data in files.items():
            path = (source / name).resolve()
            if not path.is_relative_to(source) or not path.is_file() or path.read_bytes() != data:
                raise ValueError("Archive/source content mismatch: " + name)
    elif commit:
        raise ValueError("--commit requires --source")
    return {"target": target, "package": manifest["name"], "file_count": len(files),
            "archive_sha256": hashlib.sha256(Path(archive).read_bytes()).hexdigest(),
            "exact_clean_commit": commit, "qualification": "exact-source-artifact" if commit else "snapshot-only", "result": "PASS"}


def negative_controls(archive, target, package="maatify/channel-delivery"):
    # Mutate the actual input ZIP, not a synthetic recreation of its documentation.
    with zipfile.ZipFile(archive) as z:
        original = {i.filename: z.read(i) for i in z.infolist() if not i.is_dir()}
    readme = next(n for n in original if n == "README.md" or n.count("/") == 1 and n.endswith("/README.md"))
    prefix = readme[:-len("README.md")]
    with tempfile.TemporaryDirectory(prefix="channel-release-check-") as directory:
        for case in ("missing-readme", "secret-env", "git-pointer", "stale-identity"):
            changed = dict(original)
            if case == "missing-readme":
                del changed[readme]
            elif case == "secret-env":
                changed[prefix + ".env"] = b"fixture only\n"
            elif case == "git-pointer":
                changed[prefix + ".git"] = b"gitdir: /fixture-only\n"
            else:
                identity = f"Artifact target: `{target}`" if target else f"Artifact identity: `{package}`"
                changed[readme] = changed[readme].replace(identity.encode(), b"Artifact identity: `incorrect-fixture`")
            path = Path(directory) / (case + ".zip")
            with zipfile.ZipFile(path, "w") as z:
                for name, data in changed.items():
                    z.writestr(name, data)
            try:
                check(path, target, package=package)
            except (ValueError, KeyError):
                continue
            raise ValueError("Negative control was not rejected: " + case)
    return 4


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--target", help="Exact upstream release target; omit for Athar source checks")
    parser.add_argument("--package", default="maatify/channel-delivery", choices=["maatify/channel-delivery", "maatify/athar-channel-delivery"])
    parser.add_argument("--archive", required=True, type=Path)
    parser.add_argument("--source", type=Path)
    parser.add_argument("--commit")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        result = check(args.archive, args.target, args.source, args.commit, args.package)
        if args.self_test:
            result["negative_controls_rejected"] = negative_controls(args.archive, args.target, args.package)
        print(json.dumps(result, sort_keys=True))
        return 0
    except (ValueError, KeyError, OSError, zipfile.BadZipFile, subprocess.CalledProcessError) as error:
        print("Artifact verification FAILED: " + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
