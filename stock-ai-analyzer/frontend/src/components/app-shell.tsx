"use client";

import {
  BarChart3,
  BookOpen,
  CalendarClock,
  Database,
  History,
  LayoutDashboard,
  LogOut,
  Radar,
  Settings,
  Upload,
  type LucideIcon,
} from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { isUnauthenticated, useLogout, useMe } from "@/features/auth/queries";
import { HealthIndicator } from "@/features/health/health-indicator";
import { cn } from "@/lib/utils";

type NavItem = { href: string; label: string; icon: LucideIcon };

const NAV: NavItem[] = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
  { href: "/analyze", label: "Analyze", icon: Upload },
  { href: "/scanner", label: "Scanner", icon: Radar },
  { href: "/forecast", label: "Forecast", icon: CalendarClock },
  { href: "/history", label: "History", icon: History },
  { href: "/journal", label: "Journal", icon: BookOpen },
  { href: "/performance", label: "Performance", icon: BarChart3 },
  { href: "/market-data", label: "Market data", icon: Database },
  { href: "/settings", label: "Settings", icon: Settings },
];

// Every planned screen now exists.
const UPCOMING: Omit<NavItem, "href">[] = [];

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const me = useMe();
  const logout = useLogout();

  useEffect(() => {
    if (isUnauthenticated(me.error)) router.replace(`/login?next=${encodeURIComponent(pathname)}`);
  }, [me.error, pathname, router]);

  if (!me.data) {
    if (me.error && !isUnauthenticated(me.error)) {
      return (
        <main className="mx-auto w-full max-w-lg px-4 py-16">
          <Alert variant="destructive">
            <AlertTitle>Can&apos;t load your account</AlertTitle>
            <AlertDescription>
              {me.error.message}
              <Button
                variant="outline"
                size="sm"
                className="mt-3"
                onClick={() => void me.refetch()}
              >
                Try again
              </Button>
            </AlertDescription>
          </Alert>
        </main>
      );
    }
    return (
      <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-6">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  async function signOut() {
    await logout.mutateAsync().catch(() => undefined);
    router.replace("/login");
  }

  return (
    <div className="flex min-h-full flex-1 flex-col">
      <header className="sticky top-0 z-30 border-b bg-background/95 backdrop-blur">
        <div className="mx-auto flex h-14 w-full max-w-6xl items-center gap-6 px-4">
          <Link href="/dashboard" className="shrink-0 font-semibold tracking-tight">
            Stock AI Analyzer
          </Link>

          <nav aria-label="Main" className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
            {NAV.map(({ href, label, icon: Icon }) => {
              const active = pathname === href || pathname.startsWith(`${href}/`);
              return (
                <Link
                  key={href}
                  href={href}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    "inline-flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm transition-colors",
                    active
                      ? "bg-muted font-medium text-foreground"
                      : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
                  )}
                >
                  <Icon className="size-4" aria-hidden />
                  {label}
                </Link>
              );
            })}
            {UPCOMING.map(({ label, icon: Icon }) => (
              <span
                key={label}
                title="Coming in a later phase"
                className="hidden shrink-0 cursor-default items-center gap-1.5 px-2.5 py-1.5 text-sm text-muted-foreground/50 lg:inline-flex"
              >
                <Icon className="size-4" aria-hidden />
                {label}
              </span>
            ))}
          </nav>

          <div className="flex shrink-0 items-center gap-3">
            <HealthIndicator />
            <span className="hidden text-sm text-muted-foreground md:inline">{me.data.name}</span>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => void signOut()}
              aria-label="Sign out"
              title="Sign out"
            >
              <LogOut aria-hidden />
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">{children}</main>
    </div>
  );
}
