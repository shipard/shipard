"""PDF přes Gotenberg jen při změně obsahu: Chromium nedává bajtově stejné
PDF, proto se renderuje jen když se změní hash HTML (nebo soubor chybí)
a jinak se vezmou bajty z cílové složky — druhý běh `build.py` nic
nepřegeneruje a `git status` zůstane čistý. Stav (klíč záznamu → hash
HTML + relativní cesta souboru v sadě) leží mimo sadu, aby se neobjevil
v `diff -r` proti dumpu.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

from .render import pdf_options_from_html, render_hash, render_pdf

Renderer = Callable[..., bytes]


@dataclass
class PdfCache:
    state_path: Path
    force: bool = False
    render: Renderer = render_pdf
    rendered: list[str] = field(default_factory=list)
    reused: list[str] = field(default_factory=list)
    _state: dict[str, dict] = field(default_factory=dict)
    _next: dict[str, dict] = field(default_factory=dict)

    def __post_init__(self) -> None:
        if self.state_path.is_file():
            self._state = json.loads(self.state_path.read_text(encoding="utf-8"))

    def resolve(self, key: str, html: str, out_dir: Path, rel_path: str) -> bytes:
        """Bajty PDF pro záznam `key`: z cílové složky, když hash HTML sedí
        a soubor existuje; jinak render. Zapamatuje si novou cestu (soubor
        se po přečíslování jen přesune)."""
        fields = pdf_options_from_html(html)
        digest = render_hash(html, fields)
        entry = self._state.get(key)
        data: bytes | None = None
        if not self.force and entry is not None and entry.get("hash") == digest:
            path = out_dir / str(entry.get("file") or "")
            if path.is_file():
                data = path.read_bytes()
                self.reused.append(key)
        if data is None:
            data = self.render(html, fields=fields)
            self.rendered.append(key)
        self._next[key] = {"hash": digest, "file": rel_path}
        return data

    def save(self) -> None:
        """Zapíše stav jen pro záznamy tohoto běhu (staré klíče zmizí)."""
        self.state_path.parent.mkdir(parents=True, exist_ok=True)
        text = json.dumps(dict(sorted(self._next.items())), indent=2, ensure_ascii=False) + "\n"
        if not self.state_path.is_file() or self.state_path.read_text(encoding="utf-8") != text:
            self.state_path.write_text(text, encoding="utf-8")
