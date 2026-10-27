"use client";

import { Plus, X } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { formatPrice, formatSigned, titleCase } from "@/features/analysis/display";
import { formatDate } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { ExitReason, Trade } from "@/lib/api/types";

import { JournalAnalyticsPanel } from "@/features/stats/journal-analytics";

import { useCloseTrade, useTrades } from "./queries";
import { TradeForm } from "./trade-form";

/** Open and closed trades, with the numbers that matter (P5-16, P5-18). */
export function JournalView() {
  const [adding, setAdding] = useState(false);
  const open = useTrades({ status: "open" });
  const closed = useTrades({ status: "closed" });

  return (
    <div className="grid gap-6">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Journal</h1>
          <p className="mt-1 text-muted-foreground">
            What you actually did, next to what the analysis planned.
          </p>
        </div>
        <Button
          onClick={() => setAdding((value) => !value)}
          variant={adding ? "outline" : "default"}
        >
          {adding ? <X /> : <Plus />}
          {adding ? "Cancel" : "Record a trade"}
        </Button>
      </div>

      {adding ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">New trade</CardTitle>
          </CardHeader>
          <CardContent>
            <TradeForm onCreated={() => setAdding(false)} />
          </CardContent>
        </Card>
      ) : null}

      <JournalAnalyticsPanel />

      <Tabs defaultValue="open">
        <TabsList>
          <TabsTrigger value="open">Open ({open.data?.data.length ?? 0})</TabsTrigger>
          <TabsTrigger value="closed">Closed ({closed.data?.data.length ?? 0})</TabsTrigger>
        </TabsList>

        <TabsContent value="open">
          {open.isPending ? (
            <Skeleton className="h-40 w-full" />
          ) : open.isError ? (
            <ErrorBox message={open.error.message} />
          ) : open.data.data.length === 0 ? (
            <Empty>No open trades. Record one when you take a position.</Empty>
          ) : (
            <div className="grid gap-3">
              {open.data.data.map((trade) => (
                <OpenTradeCard key={trade.id} trade={trade} />
              ))}
            </div>
          )}
        </TabsContent>

        <TabsContent value="closed">
          {closed.isPending ? (
            <Skeleton className="h-40 w-full" />
          ) : closed.isError ? (
            <ErrorBox message={closed.error.message} />
          ) : closed.data.data.length === 0 ? (
            <Empty>Nothing closed yet.</Empty>
          ) : (
            <ClosedTable trades={closed.data.data} />
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}

function OpenTradeCard({ trade }: { trade: Trade }) {
  const [closing, setClosing] = useState(false);

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-3">
        <div>
          <CardTitle className="text-base">
            {trade.stock?.symbol ?? "—"}{" "}
            <span className="font-normal text-muted-foreground">
              {titleCase(trade.side)} · {trade.quantity ?? 0} shares
            </span>
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            Entered {trade.entry_date ? formatDate(trade.entry_date) : "—"} at{" "}
            {formatPrice(trade.entry_price)}
            {trade.stop_loss !== null ? ` · stop ${formatPrice(trade.stop_loss)}` : ""}
            {trade.target_price !== null ? ` · target ${formatPrice(trade.target_price)}` : ""}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {trade.analysis_id ? (
            <Button asChild variant="ghost" size="sm">
              <Link href={`/analysis/${trade.analysis_id}`}>Open analysis</Link>
            </Button>
          ) : null}
          <Button
            size="sm"
            variant={closing ? "outline" : "default"}
            onClick={() => setClosing((v) => !v)}
          >
            {closing ? "Cancel" : "Close trade"}
          </Button>
        </div>
      </CardHeader>
      <CardContent className="grid gap-3">
        {trade.reason ? <p className="text-sm">{trade.reason}</p> : null}
        {trade.tags.length > 0 ? (
          <div className="flex flex-wrap gap-1">
            {trade.tags.map((tag) => (
              <Badge key={tag} variant="outline">
                {tag}
              </Badge>
            ))}
          </div>
        ) : null}
        {closing ? <CloseTradeForm trade={trade} onClosed={() => setClosing(false)} /> : null}
      </CardContent>
    </Card>
  );
}

const EXIT_REASONS: { value: ExitReason; label: string }[] = [
  { value: "target", label: "Target hit" },
  { value: "stop", label: "Stopped out" },
  { value: "manual", label: "Closed manually" },
  { value: "time", label: "Ran out of time" },
];

function CloseTradeForm({ trade, onClosed }: { trade: Trade; onClosed: () => void }) {
  const close = useCloseTrade(trade.id);
  const [exitPrice, setExitPrice] = useState("");
  const [reason, setReason] = useState<ExitReason>("manual");
  const [fees, setFees] = useState("");
  const [followedPlan, setFollowedPlan] = useState(true);
  const [lessons, setLessons] = useState("");

  return (
    <form
      className="grid gap-3 rounded-md border bg-muted/30 p-3"
      onSubmit={(event) => {
        event.preventDefault();
        close.mutate(
          {
            exit_price: Number(exitPrice),
            exit_reason: reason,
            fees: fees === "" ? undefined : Number(fees),
            followed_plan: followedPlan,
            lessons: lessons || undefined,
          },
          {
            onSuccess: (closed) => {
              toast.success(
                closed.r_multiple === null
                  ? "Trade closed"
                  : `Trade closed at ${formatSigned(closed.r_multiple)}R`,
              );
              onClosed();
            },
            onError: (error) => toast.error(error.message),
          },
        );
      }}
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="grid gap-1.5">
          <Label htmlFor={`exit-${trade.id}`}>Exit price</Label>
          <Input
            id={`exit-${trade.id}`}
            type="number"
            step="0.01"
            required
            value={exitPrice}
            onChange={(event) => setExitPrice(event.target.value)}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor={`reason-${trade.id}`}>Why</Label>
          <Select value={reason} onValueChange={(value) => setReason(value as ExitReason)}>
            <SelectTrigger id={`reason-${trade.id}`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {EXIT_REASONS.map((item) => (
                <SelectItem key={item.value} value={item.value}>
                  {item.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor={`fees-${trade.id}`}>Fees</Label>
          <Input
            id={`fees-${trade.id}`}
            type="number"
            step="0.01"
            value={fees}
            onChange={(event) => setFees(event.target.value)}
          />
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={followedPlan}
          onChange={(event) => setFollowedPlan(event.target.checked)}
          className="size-4"
        />
        I followed the plan
      </label>

      <div className="grid gap-1.5">
        <Label htmlFor={`lessons-${trade.id}`}>Lessons</Label>
        <Textarea
          id={`lessons-${trade.id}`}
          rows={2}
          value={lessons}
          onChange={(event) => setLessons(event.target.value)}
          placeholder="What would you do differently?"
        />
      </div>

      <div>
        <Button type="submit" size="sm" disabled={close.isPending || exitPrice === ""}>
          Close trade
        </Button>
      </div>
    </form>
  );
}

function ClosedTable({ trades }: { trades: Trade[] }) {
  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Symbol</TableHead>
            <TableHead>Entry → exit</TableHead>
            <TableHead className="text-right">Net P&amp;L</TableHead>
            <TableHead className="text-right">R</TableHead>
            <TableHead>Plan</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {trades.map((trade) => {
            const won = (trade.net_pnl ?? 0) > 0;
            return (
              <TableRow key={trade.id}>
                <TableCell className="font-medium">{trade.stock?.symbol ?? "—"}</TableCell>
                <TableCell className="tabular-nums text-muted-foreground">
                  {formatPrice(trade.entry_price)} → {formatPrice(trade.exit_price)}
                  <span className="block text-xs">
                    {trade.exit_reason ? titleCase(trade.exit_reason) : ""}
                  </span>
                </TableCell>
                <TableCell
                  className={cn(
                    "text-right tabular-nums",
                    won
                      ? "text-emerald-700 dark:text-emerald-300"
                      : "text-rose-700 dark:text-rose-300",
                  )}
                >
                  <span aria-hidden>{won ? "▲" : "▼"}</span> {formatPrice(trade.net_pnl)}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  {trade.r_multiple === null ? "—" : `${formatSigned(trade.r_multiple)}R`}
                </TableCell>
                <TableCell className="text-sm text-muted-foreground">
                  {trade.followed_plan === null
                    ? "—"
                    : trade.followed_plan
                      ? "Followed"
                      : "Deviated"}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

function Empty({ children }: { children: React.ReactNode }) {
  return (
    <div className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
      {children}
    </div>
  );
}

function ErrorBox({ message }: { message: string }) {
  return (
    <Alert variant="destructive">
      <AlertTitle>Can&apos;t load the journal</AlertTitle>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}
