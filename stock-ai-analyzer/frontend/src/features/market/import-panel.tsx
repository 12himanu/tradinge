"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle } from "lucide-react";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ApiError } from "@/lib/api/client";
import {
  TIMEFRAMES,
  type ImportSummary,
  type PriceCoverage,
  type Stock,
  type Timeframe,
} from "@/lib/api/types";
import { formatBytes, formatDate, formatDateTime } from "@/lib/format";

import { getStock, importPrices } from "./api";
import { SymbolAutocomplete } from "./symbol-autocomplete";

const MAX_CSV_BYTES = 10 * 1024 * 1024;

export function ImportPanel() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [stock, setStock] = useState<Stock | null>(null);
  const [timeframe, setTimeframe] = useState<Timeframe>("1D");
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [progress, setProgress] = useState<number | null>(null);
  const [summary, setSummary] = useState<ImportSummary | null>(null);

  const details = useQuery({
    queryKey: ["stocks", stock?.id],
    queryFn: ({ signal }) => getStock(stock!.id, signal),
    enabled: stock !== null,
  });

  function chooseFile(selected: File | null) {
    setSummary(null);
    setFile(selected);
    setFileError(
      selected && selected.size > MAX_CSV_BYTES
        ? `The file is ${formatBytes(selected.size)}; the limit is 10 MB. Split it and import the parts.`
        : null,
    );
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (!stock) return setFormError("Choose a stock first.");
    if (!file) return setFileError("Choose a CSV file.");
    if (fileError) return;

    setFormError(null);
    setProgress(0);
    try {
      const result = await importPrices(stock.id, file, timeframe, setProgress);
      setSummary(result);
      toast.success(
        `${stock.symbol}: ${result.inserted} new, ${result.updated} updated, ${result.skipped_total} skipped`,
      );
      await queryClient.invalidateQueries({ queryKey: ["stocks", stock.id] });
    } catch (error) {
      if (error instanceof ApiError && error.fieldErrors.file)
        setFileError(error.fieldErrors.file[0]);
      else setFormError(error instanceof ApiError ? error.message : "Import failed. Try again.");
    } finally {
      setProgress(null);
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
      <Card>
        <CardHeader>
          <CardTitle>Import a CSV</CardTitle>
          <CardDescription>
            One symbol and timeframe per file, with a header row: date, open, high, low, close and
            optional volume. TradingView and NEPSE-site exports work as they are.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={onSubmit} noValidate className="grid gap-5">
            {formError && (
              <Alert variant="destructive">
                <AlertDescription>{formError}</AlertDescription>
              </Alert>
            )}

            <div className="grid gap-2">
              <Label htmlFor="stock">Stock</Label>
              <SymbolAutocomplete
                id="stock"
                value={search}
                onChange={(value) => {
                  setSearch(value);
                  if (stock && value !== stock.symbol) setStock(null);
                }}
                onSelect={(selected) => {
                  setStock(selected);
                  setSummary(null);
                }}
                emptyHint="No matching stock. Add it to the stock list first."
              />
              <p className="text-sm text-muted-foreground">
                {stock ? `${stock.symbol} · ${stock.name}` : "Pick a stock from the suggestions."}
              </p>
            </div>

            <div className="grid gap-2">
              <Label htmlFor="import-timeframe">Timeframe of the file</Label>
              <Select value={timeframe} onValueChange={(value) => setTimeframe(value as Timeframe)}>
                <SelectTrigger id="import-timeframe" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {TIMEFRAMES.map((value) => (
                    <SelectItem key={value} value={value}>
                      {value}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid gap-2">
              <Label htmlFor="csv">CSV file</Label>
              <Input
                id="csv"
                type="file"
                accept=".csv,.txt,text/csv"
                aria-invalid={fileError ? true : undefined}
                aria-describedby={fileError ? "csv-error" : undefined}
                onChange={(event) => chooseFile(event.target.files?.[0] ?? null)}
              />
              {fileError && (
                <p id="csv-error" role="alert" className="text-sm text-destructive">
                  {fileError}
                </p>
              )}
            </div>

            {progress !== null ? (
              <div className="grid gap-2" aria-live="polite">
                <Progress value={Math.round(progress * 100)} aria-label="Import progress" />
                <p className="text-sm text-muted-foreground">
                  {progress < 1
                    ? `Uploading… ${Math.round(progress * 100)}%`
                    : "Checking and storing rows…"}
                </p>
              </div>
            ) : (
              <Button type="submit" disabled={!stock}>
                Import prices
              </Button>
            )}
          </form>
        </CardContent>
      </Card>

      <div className="grid content-start gap-6">
        {summary && <ImportSummaryView summary={summary} />}
        {stock && (
          <Card>
            <CardHeader>
              <CardTitle>Stored data for {stock.symbol}</CardTitle>
            </CardHeader>
            <CardContent>
              {details.isPending ? (
                <p className="text-sm text-muted-foreground">Loading…</p>
              ) : (
                <CoverageTable coverage={details.data?.price_coverage ?? []} />
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
}

function ImportSummaryView({ summary }: { summary: ImportSummary }) {
  const stats: [string, number][] = [
    ["New bars", summary.inserted],
    ["Updated", summary.updated],
    ["Skipped", summary.skipped_total],
  ];

  return (
    <Card>
      <CardHeader>
        <CardTitle>Import result</CardTitle>
        <CardDescription>
          {summary.source.rows_read ?? 0} rows read · {summary.source.delimiter ?? "comma"}
          -separated · dates as {summary.source.date_format ?? "unknown"}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        <dl className="grid grid-cols-3 gap-3">
          {stats.map(([label, value]) => (
            <div key={label} className="rounded-lg border p-3">
              <dt className="text-sm text-muted-foreground">{label}</dt>
              <dd className="text-2xl font-semibold tabular-nums">{value.toLocaleString()}</dd>
            </div>
          ))}
        </dl>

        {summary.source.date_format_assumed && (
          <Alert>
            <AlertTriangle aria-hidden />
            <AlertTitle>Check the dates</AlertTitle>
            <AlertDescription>
              No date in the file had a day above 12, so dates like 05/08/2026 were read as
              day/month/year. If the stored range below looks wrong, export with ISO dates
              (YYYY-MM-DD) and import again. Re-importing updates rows in place.
            </AlertDescription>
          </Alert>
        )}

        {summary.skipped.length > 0 && (
          <div className="grid gap-2">
            <h3 className="text-sm font-medium">Skipped rows</h3>
            <div className="max-h-72 overflow-auto rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-20">Row</TableHead>
                    <TableHead>Reason</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {summary.skipped.map((row, index) => (
                    <TableRow key={`${row.row}-${index}`}>
                      <TableCell className="tabular-nums">{row.row ?? "–"}</TableCell>
                      <TableCell className="whitespace-normal">{row.reason}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            {summary.skipped_total > summary.skipped.length && (
              <p className="text-sm text-muted-foreground">
                Showing the first {summary.skipped.length} of{" "}
                {summary.skipped_total.toLocaleString()} skipped rows.
              </p>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function CoverageTable({ coverage }: { coverage: PriceCoverage[] }) {
  if (coverage.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No price data yet. Analyses of this stock use the chart only.
      </p>
    );
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Timeframe</TableHead>
          <TableHead>From</TableHead>
          <TableHead>To</TableHead>
          <TableHead className="text-right">Bars</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {coverage.map((range) => {
          const intraday = !["1D", "1W"].includes(range.timeframe);
          // Daily/weekly bars are stamped 00:00 UTC of their date; show that date as-is.
          const show = (iso: string) => (intraday ? formatDateTime(iso) : formatDate(iso, "UTC"));
          return (
            <TableRow key={range.timeframe}>
              <TableCell className="font-medium">{range.timeframe}</TableCell>
              <TableCell>{show(range.from)}</TableCell>
              <TableCell>{show(range.to)}</TableCell>
              <TableCell className="text-right tabular-nums">
                {range.bars.toLocaleString()}
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
