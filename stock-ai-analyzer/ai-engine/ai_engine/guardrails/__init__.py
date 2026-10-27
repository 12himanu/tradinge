"""Checks the analyst's draft must pass before a reader ever sees it (docs/04 §7).

The deterministic pipeline is trusted; the language model is not. These three modules are what
lets an LLM write in a product whose whole claim is that every number traces to evidence:

* `grounding` — no number the evidence does not hold
* `language` — no certainty, no contradicting the decision, no reading institutional intent
* `completeness` — every section written, every citation real, both scenarios present

A draft that fails goes back once with the violation list attached, and a draft that fails again is
discarded for the template. Nothing half-checked is published.
"""

from ai_engine.guardrails.grounding import Violation

__all__ = ["Violation"]
