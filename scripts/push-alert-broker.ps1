$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "=== Alert Broker GitHub Push ===" -ForegroundColor Cyan

$RepoRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $RepoRoot

Write-Host "Repo: $RepoRoot"
Write-Host ""

Write-Host "Current git status:" -ForegroundColor Yellow
git status

Write-Host ""
$CommitMessage = Read-Host "Enter commit message"

if ([string]::IsNullOrWhiteSpace($CommitMessage)) {
    Write-Host "Commit message cannot be empty. Exiting." -ForegroundColor Red
    exit 1
}

$PythonExe = "$env:APPDATA\uv\python\cpython-3.14.5-windows-x86_64-none\python.exe"

if (-not (Test-Path $PythonExe)) {
    Write-Host "Python executable not found at:" -ForegroundColor Red
    Write-Host $PythonExe
    exit 1
}

$PythonFiles = @(
    ".\broker\app.py",
    ".\broker\bot.py",
    ".\broker\formatters.py",
    ".\broker\templates.py",
    ".\broker\mappings.py",
    ".\broker\models.py"
)

Write-Host ""
Write-Host "Compiling Python files..." -ForegroundColor Yellow

foreach ($File in $PythonFiles) {
    if (Test-Path $File) {
        Write-Host "Checking $File"
        & $PythonExe -m py_compile $File
    }
}

Write-Host ""
Write-Host "Diff summary:" -ForegroundColor Yellow
git diff --stat

Write-Host ""
$Confirm = Read-Host "Stage, commit, and push these changes? Type YES to continue"

if ($Confirm -ne "YES") {
    Write-Host "Cancelled. No commit created." -ForegroundColor Yellow
    exit 0
}

git add broker scripts README.md README-alert-engine.md

git commit -m "$CommitMessage"

Write-Host ""
Write-Host "Pulling latest changes with rebase before push..." -ForegroundColor Yellow
git pull --rebase origin main

Write-Host ""
Write-Host "Pushing to GitHub..." -ForegroundColor Yellow
git push origin main

Write-Host ""
Write-Host "Final git status:" -ForegroundColor Yellow
git status

Write-Host ""
Write-Host "Done. Changes pushed to GitHub." -ForegroundColor Green