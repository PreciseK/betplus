$ErrorActionPreference = 'Stop'
$doorsRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$env:NEXT_DIST_DIR = '.next-doors-local'
$env:NEXT_PUBLIC_API_BASE_URL = 'http://localhost:8001/v1'
Set-Location -LiteralPath (Join-Path $doorsRoot 'apps/web')
pnpm dev --port 3102
