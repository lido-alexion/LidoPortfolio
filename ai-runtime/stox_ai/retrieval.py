from __future__ import annotations

import re
import json
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

    def chunks(self) -> list[DocumentationChunk]:
        result: list[DocumentationChunk] = []
        for path in sorted((self.root / "docs" / "user-journeys").glob("*.md")):
            if not re.match(r"^\d{2}-", path.name):
                continue
            text = path.read_text(encoding="utf-8")
            title = next((line.removeprefix("# ").strip() for line in text.splitlines() if line.startswith("# ")), path.stem)
            parts = re.split(r"(?m)^## ", text)
            for index, part in enumerate(parts):
                if index == 0:
                    continue  # Intro navigation is not answer evidence or an anchored section.
                section, _, body = part.partition("\n")
                section = title if index == 0 else section.strip()
                snippet = re.sub(r"\s+", " ", body).strip()[:900]
                if snippet:
                    relative = path.relative_to(self.root).as_posix()
                    slug = re.sub(r"[^a-z0-9]+", "-", section.lower()).strip("-")
                    result.append(DocumentationChunk(f"journey:{path.stem}:{slug or index}", title, relative, section, snippet, f"/docs/journeys/{path.stem}.html#{slug}"))
        product_corpus = self.root / "docs" / "assistant-product-corpus.json"
        if product_corpus.exists():
            for item in json.loads(product_corpus.read_text()):
                result.append(DocumentationChunk(**item))
        return result

    def search(self, query: str, limit: int = 5) -> list[dict]:
        terms = {term for term in re.findall(r"[a-z0-9]{3,}", query.lower())}
        terms -= {"the", "how", "can", "what", "does", "this", "that", "with", "for", "and", "you", "from", "please"}
        scored = []
        for chunk in self.chunks():
            haystack = f"{chunk.title} {chunk.section} {chunk.snippet}".lower()
            score = sum(term in haystack for term in terms)
            if score:
                scored.append((score, chunk))
        return [{"source_id": item.source_id, "title": item.title, "path": item.path, "section": item.section, "snippet": item.snippet, "url": item.url, "relevance": score} for score, item in sorted(scored, key=lambda row: (not row[1].source_id.startswith("journey:"), -row[0], row[1].source_id))[:limit]]
