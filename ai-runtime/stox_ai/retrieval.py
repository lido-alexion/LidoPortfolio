from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class DocumentationChunk:
    source_id: str
    title: str
    path: str
    section: str
    snippet: str
    url: str


class DocumentationRetriever:
    def __init__(self, root: str | None = None) -> None:
        self.root = Path(root or Path(__file__).resolve().parents[2])
        self._chunks: list[DocumentationChunk] | None = None

    def chunks(self) -> list[DocumentationChunk]:
        if self._chunks is not None:
            return self._chunks
        result: list[DocumentationChunk] = []
        for path in sorted((self.root / "docs" / "user-journeys").glob("*.md")):
            text = path.read_text(encoding="utf-8")
            title = next((line.removeprefix("# ").strip() for line in text.splitlines() if line.startswith("# ")), path.stem)
            parts = re.split(r"(?m)^## ", text)
            for index, part in enumerate(parts):
                section, _, body = part.partition("\n")
                section = title if index == 0 else section.strip()
                snippet = re.sub(r"\s+", " ", body).strip()[:900]
                if snippet:
                    relative = path.relative_to(self.root).as_posix()
                    slug = re.sub(r"[^a-z0-9]+", "-", section.lower()).strip("-")
                    result.append(DocumentationChunk(f"journey:{path.stem}:{slug or index}", title, relative, section, snippet, f"/{relative}#{slug}"))
        self._chunks = result
        return result

    def search(self, query: str, limit: int = 5) -> list[dict]:
        terms = {term for term in re.findall(r"[a-z0-9]{3,}", query.lower())}
        scored = []
        for chunk in self.chunks():
            haystack = f"{chunk.title} {chunk.section} {chunk.snippet}".lower()
            score = sum(term in haystack for term in terms)
            if score:
                scored.append((score, chunk))
        return [{"source_id": item.source_id, "title": item.title, "path": item.path, "section": item.section, "snippet": item.snippet, "url": item.url, "relevance": score} for score, item in sorted(scored, key=lambda row: (-row[0], row[1].source_id))[:limit]]
