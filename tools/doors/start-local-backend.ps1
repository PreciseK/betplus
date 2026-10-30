$ErrorActionPreference = 'Stop'
$doorsRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$doorsOutput = Join-Path $doorsRoot 'output/doors-local'
New-Item -ItemType Directory -Force $doorsOutput | Out-Null
$doorsDatabase = Join-Path $doorsOutput 'doors-local-preview.sqlite'
if (-not (Test-Path -LiteralPath $doorsDatabase)) { New-Item -ItemType File -Path $doorsDatabase | Out-Null }
$doorsEnvironment = Join-Path $doorsRoot 'apps/platform/.env.doors-local'
if (-not (Test-Path -LiteralPath $doorsEnvironment)) {
    $doorsKeyBytes = [byte[]]::new(32)
    [Security.Cryptography.RandomNumberGenerator]::Fill($doorsKeyBytes)
    $doorsKey = [Convert]::ToBase64String($doorsKeyBytes)
    @"
APP_NAME=BetplusDoorsLocal
APP_ENV=doors-local
APP_KEY=base64:$doorsKey
APP_DEBUG=true
APP_URL=http://localhost:8001
DB_CONNECTION=sqlite
DB_DATABASE="$($doorsDatabase.Replace('\','/'))"
CACHE_STORE=database
CACHE_PREFIX=doors_local_
QUEUE_CONNECTION=database
SESSION_DRIVER=array
MAIL_MAILER=log
DOORS_ENABLED=true
CLOUDFLARE_TURNSTILE_ENABLED=false
CORS_ALLOWED_ORIGINS=http://localhost:3102
"@ | Set-Content -LiteralPath $doorsEnvironment -Encoding utf8
}
$env:APP_ENV = 'doors-local'
Set-Location -LiteralPath (Join-Path $doorsRoot 'apps/platform')
php (Join-Path $PSScriptRoot 'local-preview.php')
if ($LASTEXITCODE -ne 0) { throw 'Local fixture setup failed.' }
php artisan serve --host=127.0.0.1 --port=8001 --no-reload
