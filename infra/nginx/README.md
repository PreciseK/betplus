# infra/nginx

Nginx configuration: TLS termination, reverse proxy to PHP-FPM pools (platform, BlackRed,
USSD) and to the Heritage engine's Uvicorn workers. Engine endpoints are proxied from
loopback only — engines never bind a public interface. Includes the separate server block
for the Filament back office (own auth guard, IP allowlist, mandatory MFA — see D-18).
