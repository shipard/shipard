"""Chyby rozvinutí příběhu sdílené `expand.py` a `bank.py` (bez kruhového
importu). `StoryError` loaderu žije ve `story.py`."""

from __future__ import annotations


class ExpandError(ValueError):
    pass
