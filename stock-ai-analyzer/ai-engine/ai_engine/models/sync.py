"""Calling an async model client from the synchronous pipeline.

The pipeline runs in a worker thread with no event loop of its own, so `asyncio.run` is the normal
path. The thread fallback covers a caller that already has a loop — a test, or a future async entry
point — where `asyncio.run` would raise rather than nest.
"""

from __future__ import annotations

import asyncio
from collections.abc import Coroutine
from concurrent.futures import ThreadPoolExecutor
from typing import Any


def run_blocking[T](coro: Coroutine[Any, Any, T]) -> T:
    try:
        asyncio.get_running_loop()
    except RuntimeError:
        return asyncio.run(coro)

    with ThreadPoolExecutor(max_workers=1) as pool:
        return pool.submit(asyncio.run, coro).result()


__all__ = ["run_blocking"]
