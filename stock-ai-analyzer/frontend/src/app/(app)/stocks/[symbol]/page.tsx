import type { Metadata } from "next";

import { StockView } from "@/features/market/stock-view";

type Props = { params: Promise<{ symbol: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { symbol } = await params;
  return { title: decodeURIComponent(symbol).toUpperCase() };
}

export default async function StockPage({ params }: Props) {
  const { symbol } = await params;

  return <StockView symbol={decodeURIComponent(symbol).toUpperCase()} />;
}
