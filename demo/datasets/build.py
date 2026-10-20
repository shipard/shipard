#!/usr/bin/env python3
"""Generátor demo datových sad z příběhu firmy (tasks/demo-dataset-phase1.md).

    python3 demo/datasets/build.py                       # stories/it-servis → it-servis/
    python3 demo/datasets/build.py --anchor 2027-03-15 --out /tmp/it-servis-posun
    python3 demo/datasets/build.py --verify-identities   # ARES / VIES (síť), zapíše verifiedAt

Příběh (`stories/<název>/`) jsou ručně psaná pravidla; výstup
(`<název>/`) se needituje — druhý běh nic nezmění. PDF se renderují přes
Gotenberg (env GOTENBERG_URL) jen při změně obsahu; stav drží
`stories/<název>/build-state.json` (pro `--out` soubor vedle výstupu).
Exit 1 = chyba příběhu nebo sestavení, 2 = registr nedostupný.
"""

from __future__ import annotations

import argparse
import sys
from datetime import date
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
REPO_ROOT = BASE_DIR.parents[1]
sys.path.insert(0, str(REPO_ROOT / "tools" / "invoice-generator"))

from invoicegen.datasetio import DatasetBuildError  # noqa: E402
from invoicegen.demo import build_demo_dataset  # noqa: E402
from invoicegen.expand import ExpandError  # noqa: E402
from invoicegen.jsonc import load_jsonc  # noqa: E402
from invoicegen.registries import RegistryUnavailable, format_check, update_verified_at, verify_identities  # noqa: E402
from invoicegen.story import StoryError, identities_for_checks  # noqa: E402


def verify(story_dir: Path) -> int:
    story = load_jsonc(story_dir / "story.jsonc")
    partners = load_jsonc(story_dir / "partners.jsonc")
    identities = identities_for_checks(story["own"], {**partners["customers"], **partners["suppliers"]}, partners["verifiedAt"])
    try:
        checks = verify_identities(identities, pause=1.5)
    except RegistryUnavailable as e:
        print(f"registr nedostupný: {e} — identity neoznačeny jako ověřené", file=sys.stderr)
        return 2
    for check in checks:
        print(format_check(check))
    failed = [c for c in checks if not c.ok]
    if failed:
        print(f"identity: {len(checks)} kontrol, {len(failed)} selhání — identitu vyměň a spusť znovu", file=sys.stderr)
        return 1
    today = date.today().isoformat()
    path = story_dir / "partners.jsonc"
    path.write_text(update_verified_at(path.read_text(encoding="utf-8"), today), encoding="utf-8")
    print(f"identity: {len(checks)} kontrol, 0 selhání → verifiedAt {today}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Sestavení demo datové sady z příběhu firmy.")
    parser.add_argument("--story", default="it-servis", help="název příběhu ve stories/ (default it-servis)")
    parser.add_argument("--anchor", type=date.fromisoformat, help="kotva YYYY-MM-DD místo kotvy z story.jsonc")
    parser.add_argument("--out", type=Path, help="cílová složka (default demo/datasets/<název>)")
    parser.add_argument("--state", type=Path, help="soubor stavu PDF cache (default podle výstupu)")
    parser.add_argument("--force", action="store_true", help="přegenerovat všechna PDF")
    parser.add_argument("--verify-identities", action="store_true",
                        help="ověřit identity v ARES a VIES (síť) a zapsat verifiedAt; sadu nestaví")
    args = parser.parse_args(argv)

    story_dir = BASE_DIR / "stories" / args.story
    if not story_dir.is_dir():
        print(f"příběh {story_dir} neexistuje", file=sys.stderr)
        return 1
    if args.verify_identities:
        return verify(story_dir)

    out_dir = args.out.resolve() if args.out else BASE_DIR / args.story
    state_path = args.state
    if state_path is None and args.out is not None:
        state_path = out_dir.parent / f"{out_dir.name}.build-state.json"
    try:
        summary = build_demo_dataset(story_dir, out_dir, repo_root=REPO_ROOT, anchor=args.anchor,
                                     state_path=state_path, force=args.force)
    except (StoryError, ExpandError, DatasetBuildError) as e:
        print(str(e), file=sys.stderr)
        return 1

    counts = ", ".join(f"{k} {v}" for k, v in summary.counts.items())
    print(f"{summary.story.name}: kotva {summary.story.anchor}, období {summary.story.period.start} → {summary.story.anchor}")
    print(f"  sekce: {counts}")
    print(f"  PDF: {summary.rendered} renderováno, {summary.reused} beze změny")
    print(f"  soubory: {len(summary.sync.written)} zapsáno, {len(summary.sync.unchanged)} beze změny, "
          f"{len(summary.sync.removed)} smazáno → {summary.out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
