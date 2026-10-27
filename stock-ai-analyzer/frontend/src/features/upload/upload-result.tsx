"use client";

import { CheckCircle2, Info } from "lucide-react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import type { ChartUpload } from "@/lib/api/types";

import { AnalyzeButton } from "./analyze-button";
import { formatBytes, formatDateTime } from "@/lib/format";

type Props = { upload: ChartUpload; onReset: () => void };

export function UploadResult({ upload, onReset }: Props) {
  const { declared, stock, image } = upload;
  const details: [string, string][] = [
    ["Symbol", stock ? `${stock.symbol} · ${stock.name}` : (declared.symbol ?? "Not specified")],
    ["Exchange", declared.exchange ?? "Not specified"],
    ["Timeframe", declared.timeframe ?? "Detect from chart"],
    ["Style", declared.trading_style ?? "Not specified"],
    ["Stored image", `${image.width}×${image.height} px PNG · ${formatBytes(image.size_bytes)}`],
  ];

  return (
    <div className="grid gap-6">
      {upload.deduplicated ? (
        <Alert>
          <Info aria-hidden />
          <AlertTitle>You already uploaded this chart</AlertTitle>
          <AlertDescription>
            It was uploaded on {formatDateTime(upload.created_at)}. The existing upload was reused
            instead of storing a copy.
            {upload.analysis && ` It already has an analysis (${upload.analysis.status}).`}
          </AlertDescription>
        </Alert>
      ) : (
        <Alert>
          <CheckCircle2 aria-hidden />
          <AlertTitle>Chart uploaded</AlertTitle>
          <AlertDescription>
            Stored privately and re-encoded. Analyse it now, or come back to it from History.
          </AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle>{stock?.symbol ?? declared.symbol ?? "Untitled chart"}</CardTitle>
          <CardDescription>{upload.original_filename ?? "Pasted screenshot"}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
          {/* eslint-disable-next-line @next/next/no-img-element -- private image behind the session cookie; the Next optimizer can't fetch it */}
          <img
            src={image.url}
            alt={`Chart for ${stock?.symbol ?? declared.symbol ?? "unknown symbol"}`}
            className="w-full rounded-md border"
          />
          <dl className="grid content-start gap-3 text-sm">
            {details.map(([term, value]) => (
              <div key={term} className="grid gap-0.5">
                <dt className="text-muted-foreground">{term}</dt>
                <dd className="font-medium">{value}</dd>
              </div>
            ))}
          </dl>
        </CardContent>
        <CardFooter className="gap-2">
          <AnalyzeButton upload={upload} />
          <Button variant="outline" onClick={onReset}>
            Upload another chart
          </Button>
        </CardFooter>
      </Card>
    </div>
  );
}
