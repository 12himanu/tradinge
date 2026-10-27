<#
.SYNOPSIS
    Runs every app's lint, type-check and tests inside the running Docker stack (P2-32).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File infra/scripts/check.ps1
    powershell -ExecutionPolicy Bypass -File infra/scripts/check.ps1 -Only backend
#>
param(
    [ValidateSet('all', 'backend', 'engine', 'frontend')]
    [string]$Only = 'all'
)

Set-Location (Resolve-Path "$PSScriptRoot\..\..")

$steps = @(
    @{ App = 'backend';  Name = 'Pint';          Command = @('backend', 'vendor/bin/pint', '--test') },
    @{ App = 'backend';  Name = 'PHPStan (L8)';  Command = @('backend', 'php', '-d', 'memory_limit=1G', 'vendor/bin/phpstan', 'analyse', '--no-progress') },
    @{ App = 'backend';  Name = 'Pest';          Command = @('backend', 'php', 'artisan', 'test', '--compact') },
    @{ App = 'engine';   Name = 'ruff';          Command = @('ai-engine', 'ruff', 'check', '.') },
    @{ App = 'engine';   Name = 'mypy --strict'; Command = @('ai-engine', 'mypy') },
    @{ App = 'engine';   Name = 'pytest';        Command = @('ai-engine', 'pytest', '-q',
                                                             '--cov=ai_engine.analysis', '--cov=ai_engine.agents') },
    @{ App = 'frontend'; Name = 'ESLint';        Command = @('frontend', 'npx', 'eslint', '.') },
    @{ App = 'frontend'; Name = 'tsc';           Command = @('frontend', 'npx', 'tsc', '--noEmit') },
    @{ App = 'frontend'; Name = 'Vitest';        Command = @('frontend', 'npx', 'vitest', 'run') }
)

$failed = @()
foreach ($step in $steps) {
    if ($Only -ne 'all' -and $step.App -ne $Only) { continue }

    Write-Host ""
    Write-Host "==> $($step.App): $($step.Name)" -ForegroundColor Cyan
    $command = $step.Command
    & docker compose exec -T @command
    if ($LASTEXITCODE -ne 0) { $failed += "$($step.App): $($step.Name)" }
}

Write-Host ""
if ($failed.Count -gt 0) {
    Write-Host "Failed: $($failed -join ', ')" -ForegroundColor Red
    exit 1
}
Write-Host "All checks passed." -ForegroundColor Green
