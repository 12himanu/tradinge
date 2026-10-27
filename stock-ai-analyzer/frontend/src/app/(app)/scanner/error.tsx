"use client";

import { useEffect } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

/**
 * Keeps one bad row from blanking the scanner.
 *
 * This page renders whatever shape the engine and the vendors happen to send. A field that turns
 * out to be absent should cost you that panel and a retry button, not the whole screen with a
 * stack trace on it — which is exactly what happened when the trade-plan renderer read a field the
 * engine does not emit.
 *
 * `retry` re-renders the segment. It is worth pressing: most failures here are one malformed
 * reading, and the rest of the market is still fine.
 */
export default function ScannerError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  useEffect(() => {
    console.error("scanner", error);
  }, [error]);

  return (
    <div className="grid gap-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Scanner</h1>
      </div>

      <Alert variant="destructive">
        <AlertTitle>This reading could not be displayed</AlertTitle>
        <AlertDescription className="grid gap-3">
          <p>
            Something in the scan data did not render. Your price bars and analyses are untouched —
            this is a display fault, not a data one.
          </p>
          {error.message ? (
            <code className="block overflow-x-auto rounded bg-muted px-2 py-1 text-xs">
              {error.message}
            </code>
          ) : null}
          {error.digest ? (
            <p className="text-xs text-muted-foreground">Digest {error.digest}</p>
          ) : null}
          <div>
            <Button variant="outline" size="sm" onClick={retry}>
              Try again
            </Button>
          </div>
        </AlertDescription>
      </Alert>
    </div>
  );
}
