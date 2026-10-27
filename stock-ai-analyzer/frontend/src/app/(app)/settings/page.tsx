import type { Metadata } from "next";

import { SettingsForm } from "@/features/settings/settings-form";

export const metadata: Metadata = { title: "Settings" };

export default function SettingsPage() {
  return (
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
        <p className="mt-1 text-muted-foreground">
          How the engine sizes positions and what it considers a viable trade.
        </p>
      </div>
      <SettingsForm />
    </div>
  );
}
