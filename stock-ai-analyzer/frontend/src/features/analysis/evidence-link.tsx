"use client";

/**
 * A clickable `[E14]` citation (P5-09).
 *
 * Every number in the report traces to an evidence row; clicking a citation opens the Evidence tab
 * and scrolls to it. Without this the ids would be decoration.
 */
export function EvidenceLink({ id, className }: { id?: string | null; className?: string }) {
  if (!id) return null;

  return (
    <a
      href={`#evidence-${id}`}
      onClick={(event) => {
        event.preventDefault();
        openEvidence(id);
      }}
      className={`ml-1 rounded px-1 align-baseline font-mono text-[0.7rem] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring ${className ?? ""}`}
      title="Show the evidence behind this number"
    >
      [{id}]
    </a>
  );
}

/** Switches to the Evidence tab and highlights one row. */
export function openEvidence(id: string): void {
  const tab = document.querySelector<HTMLElement>('[data-evidence-tab="true"]');
  tab?.click();

  // The tab panel mounts on click, so wait a frame before scrolling to the row.
  requestAnimationFrame(() => {
    const row = document.getElementById(`evidence-${id}`);
    row?.scrollIntoView({ behavior: "smooth", block: "center" });
    row?.setAttribute("data-highlight", "true");
    window.setTimeout(() => row?.removeAttribute("data-highlight"), 2000);
  });
}
