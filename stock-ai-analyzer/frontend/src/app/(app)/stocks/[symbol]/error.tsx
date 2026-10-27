"use client";

import { useEffect } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

/** Keeps a malformed reading from blanking the stock page. */
export default function StockError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  useEffect(() => {
    console.error("stock page", error);
  }, [error]);

  return (
    <Alert variant="destructive">
      <AlertTitle>This stock could not be displayed</AlertTitle>
      <AlertDescription className="grid gap-3">
        <p>Your price bars and analyses are untouched — this is a display fault.</p>
        {error.message ? (
          <code className="block overflow-x-auto rounded bg-muted px-2 py-1 text-xs">
            {error.message}
          </code>
        ) : null}
        <div>
          <Button variant="outline" size="sm" onClick={retry}>
            Try again
          </Button>
        </div>
      </AlertDescription>
    </Alert>
  );
}
