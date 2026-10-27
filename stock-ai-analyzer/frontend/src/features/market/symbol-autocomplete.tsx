"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useState, type KeyboardEvent } from "react";

import { Input } from "@/components/ui/input";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import type { Stock } from "@/lib/api/types";
import { cn } from "@/lib/utils";

import { searchStocks } from "./api";

type Props = {
  id: string;
  value: string;
  onChange: (value: string) => void;
  onSelect?: (stock: Stock) => void;
  exchange?: string;
  placeholder?: string;
  invalid?: boolean;
  describedBy?: string;
  emptyHint?: string;
};

/** Symbol or company-name search against /stocks (pg_trgm), with keyboard navigation. */
export function SymbolAutocomplete({
  id,
  value,
  onChange,
  onSelect,
  exchange,
  placeholder = "Symbol or company name",
  invalid,
  describedBy,
  emptyHint = "No matching stock.",
}: Props) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const query = useDebouncedValue(value.trim(), 200);
  const listId = `${id}-listbox`;

  const { data: stocks = [], isFetching } = useQuery({
    queryKey: ["stocks", "search", exchange ?? "", query],
    queryFn: ({ signal }) => searchStocks(query, exchange, signal),
    enabled: open && query.length > 0,
    staleTime: 60_000,
    placeholderData: keepPreviousData,
  });

  const showList = open && query.length > 0;

  function choose(stock: Stock) {
    onChange(stock.symbol);
    onSelect?.(stock);
    setOpen(false);
    setActive(-1);
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "ArrowDown" && stocks.length > 0) {
      event.preventDefault();
      setOpen(true);
      setActive((index) => (index + 1) % stocks.length);
    } else if (event.key === "ArrowUp" && stocks.length > 0) {
      event.preventDefault();
      setActive((index) => (index <= 0 ? stocks.length - 1 : index - 1));
    } else if (event.key === "Enter" && showList && active >= 0 && stocks[active]) {
      event.preventDefault();
      choose(stocks[active]);
    } else if (event.key === "Escape") {
      setOpen(false);
      setActive(-1);
    }
  }

  return (
    <div className="relative">
      <Input
        id={id}
        role="combobox"
        aria-expanded={showList}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={showList && active >= 0 ? `${listId}-${active}` : undefined}
        aria-invalid={invalid || undefined}
        aria-describedby={describedBy}
        autoComplete="off"
        spellCheck={false}
        placeholder={placeholder}
        value={value}
        onChange={(event) => {
          onChange(event.target.value);
          setOpen(true);
          setActive(-1);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onKeyDown={onKeyDown}
      />
      {showList && (
        <ul
          id={listId}
          role="listbox"
          className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-lg border bg-popover p-1 text-popover-foreground shadow-md"
        >
          {stocks.length === 0 ? (
            <li className="px-2 py-1.5 text-sm text-muted-foreground">
              {isFetching ? "Searching…" : emptyHint}
            </li>
          ) : (
            stocks.map((stock, index) => (
              <li
                key={stock.id}
                id={`${listId}-${index}`}
                role="option"
                aria-selected={index === active}
                // mousedown, not click: fires before the input's blur closes the list.
                onMouseDown={(event) => {
                  event.preventDefault();
                  choose(stock);
                }}
                className={cn(
                  "flex cursor-pointer items-baseline gap-2 rounded-md px-2 py-1.5 text-sm",
                  index === active ? "bg-muted" : "hover:bg-muted/60",
                )}
              >
                <span className="font-mono font-medium">{stock.symbol}</span>
                <span className="min-w-0 truncate text-muted-foreground">{stock.name}</span>
                {stock.exchange && (
                  <span className="ml-auto text-xs text-muted-foreground">{stock.exchange}</span>
                )}
              </li>
            ))
          )}
        </ul>
      )}
    </div>
  );
}
