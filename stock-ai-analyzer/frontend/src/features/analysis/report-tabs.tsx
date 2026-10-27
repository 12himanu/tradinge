"use client";

import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";

import {
  BIAS_GLYPH,
  BIAS_TONE,
  formatNumber,
  formatPrice,
  titleCase,
} from "./display";
import type { Analysis, AnalysisLevel, AnalysisPattern } from "@/lib/api/types";

/** The depth behind the headline (P5-08). Everything here is computed, never narrated. */
export function ReportTabs({ analysis }: { analysis: Analysis }) {
  const tier = analysis.data_tier;
  const classic = analysis.patterns.filter((pattern) => pattern.category !== "smc");
  const smcEvents = analysis.smc?.events ?? analysis.patterns.filter((p) => p.category === "smc");

  return (
    <Tabs defaultValue="technical" className="w-full">
      <TabsList className="flex-wrap">
        <TabsTrigger value="technical">Technical</TabsTrigger>
        <TabsTrigger value="patterns">Patterns ({classic.length})</TabsTrigger>
        <TabsTrigger value="smc">Smart Money</TabsTrigger>
        <TabsTrigger value="levels">Levels ({analysis.levels.length})</TabsTrigger>
        <TabsTrigger value="evidence" data-evidence-tab="true">
          Evidence ({analysis.evidence.length})
        </TabsTrigger>
      </TabsList>

      <TabsContent value="technical">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Indicator</TableHead>
                <TableHead className="text-right">Value</TableHead>
                <TableHead>Signal</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {analysis.indicators.map((indicator) => (
                <TableRow key={indicator.key}>
                  <TableCell className="font-mono text-xs">{indicator.key}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatNumber(indicator.value)}
                  </TableCell>
                  <TableCell>
                    {indicator.signal ? (
                      <span className={BIAS_TONE[indicator.signal]}>
                        <span aria-hidden>{BIAS_GLYPH[indicator.signal]}</span>{" "}
                        {titleCase(indicator.signal)}
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {indicator.status === "computed" ? (
                      indicator.unavailable_reason ? (
                        <span title={indicator.unavailable_reason}>Computed (thin data)</span>
                      ) : (
                        "Computed"
                      )
                    ) : (
                      <span>{indicator.unavailable_reason ?? titleCase(indicator.status)}</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </TabsContent>

      <TabsContent value="patterns">
        {classic.length === 0 ? (
          <Empty>No chart pattern cleared the confidence floor.</Empty>
        ) : (
          <ul className="grid gap-3">
            {classic.map((pattern, index) => (
              <PatternRow key={`${pattern.type}-${index}`} pattern={pattern} tier={tier} />
            ))}
          </ul>
        )}
      </TabsContent>

      <TabsContent value="smc">
        <div className="grid gap-4">
          <p className="rounded-md border-l-2 border-muted-foreground/40 bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
            {analysis.smc?.framing_note ??
              "Smart Money Concepts are a rule-based framework for reading price structure. The zones below are derived from price action alone; they do not reveal actual institutional orders or intentions."}
          </p>
          {analysis.smc ? (
            <p className="text-sm">
              Structure <span className="font-medium">{titleCase(analysis.smc.structure)}</span>
              {analysis.smc.last_event ? (
                <>
                  {" · last event "}
                  <span className="font-medium">
                    {analysis.smc.last_event.replace(/_/g, " ").toUpperCase()}
                  </span>
                </>
              ) : null}
              {analysis.smc.dealing_range ? (
                <>
                  {" · price in the "}
                  <span className="font-medium">{analysis.smc.dealing_range.zone}</span>
                  {" of "}
                  {formatPrice(analysis.smc.dealing_range.low, tier)}–
                  {formatPrice(analysis.smc.dealing_range.high, tier)}
                </>
              ) : null}
            </p>
          ) : null}
          {smcEvents.length === 0 ? (
            <Empty>No structural events in this window.</Empty>
          ) : (
            <ul className="grid gap-3">
              {smcEvents.map((event, index) => (
                <PatternRow key={`${event.type}-${index}`} pattern={event} tier={tier} />
              ))}
            </ul>
          )}
        </div>
      </TabsContent>

      <TabsContent value="levels">
        {analysis.levels.length === 0 ? (
          <Empty>No levels were identified.</Empty>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <LevelColumn
              title="Resistance"
              levels={analysis.levels.filter((level) => level.type === "resistance")}
              tier={tier}
            />
            <LevelColumn
              title="Support"
              levels={analysis.levels.filter((level) => level.type === "support")}
              tier={tier}
            />
          </div>
        )}
      </TabsContent>

      <TabsContent value="evidence">
        <p className="mb-3 text-sm text-muted-foreground">
          Every number in this report comes from one of these rows. The analyst may cite them; it
          may not invent others.
        </p>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-14">Id</TableHead>
                <TableHead>Fact</TableHead>
                <TableHead className="w-24">Source</TableHead>
                <TableHead className="w-16">Tier</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {analysis.evidence.map((item) => (
                <TableRow
                  key={item.id}
                  id={`evidence-${item.id}`}
                  className="scroll-mt-24 data-[highlight=true]:bg-accent"
                >
                  <TableCell className="font-mono text-xs">{item.id}</TableCell>
                  <TableCell>{item.text}</TableCell>
                  <TableCell className="text-muted-foreground">{item.source}</TableCell>
                  <TableCell className="text-muted-foreground">{item.tier}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </TabsContent>
    </Tabs>
  );
}

function PatternRow({ pattern, tier }: { pattern: AnalysisPattern; tier: Analysis["data_tier"] }) {
  return (
    <li className="grid gap-1 rounded-md border p-3 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <span className={cn("font-medium", BIAS_TONE[pattern.direction])}>
          <span aria-hidden>{BIAS_GLYPH[pattern.direction]}</span> {titleCase(pattern.type)}
        </span>
        <Badge variant="outline">{titleCase(pattern.status)}</Badge>
        <span className="text-muted-foreground">{Math.round(pattern.confidence * 100)}% confidence</span>
      </div>
      {pattern.confirmation_rule ? (
        <p className="text-muted-foreground">{pattern.confirmation_rule}</p>
      ) : null}
      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
        {pattern.price_low !== null ? (
          <span className="tabular-nums">
            Zone {formatPrice(pattern.price_low, tier)}
            {pattern.price_high !== null && pattern.price_high !== pattern.price_low
              ? `–${formatPrice(pattern.price_high, tier)}`
              : ""}
          </span>
        ) : null}
        {pattern.measured_target !== null ? (
          <span className="tabular-nums">Target {formatPrice(pattern.measured_target, tier)}</span>
        ) : null}
        {pattern.invalidation_price !== null ? (
          <span className="tabular-nums">
            Invalid at {formatPrice(pattern.invalidation_price, tier)}
          </span>
        ) : null}
      </div>
    </li>
  );
}

function LevelColumn({
  title,
  levels,
  tier,
}: {
  title: string;
  levels: AnalysisLevel[];
  tier: Analysis["data_tier"];
}) {
  return (
    <section aria-label={title} className="grid gap-2">
      <h3 className="text-sm font-medium">{title}</h3>
      {levels.length === 0 ? (
        <p className="text-sm text-muted-foreground">None inside the window.</p>
      ) : (
        <ul className="grid gap-2">
          {levels.map((level, index) => (
            <li key={`${level.price_low}-${index}`} className="text-sm tabular-nums">
              {formatPrice(level.price_low, tier)} – {formatPrice(level.price_high, tier)}
              <span className="ml-2 text-xs text-muted-foreground">
                strength {level.strength.toFixed(2)}
                {level.touches ? ` · ${level.touches} touches` : ""}
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Empty({ children }: { children: React.ReactNode }) {
  return <p className="py-6 text-center text-sm text-muted-foreground">{children}</p>;
}
