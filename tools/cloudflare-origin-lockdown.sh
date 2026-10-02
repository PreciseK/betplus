#!/usr/bin/env bash
# ==============================================================================
# Betplus — Cloudflare Origin Lockdown Generator & Enforcer
#
# Generates Apache .htaccess Origin Lockdown rules that restrict direct HTTP/S
# access to verified Cloudflare edge proxies, server loopback, and explicitly
# allowed IPs (e.g. USSD aggregators).
#
# Prevents attackers from bypassing Cloudflare WAF/DDoS by hitting origin IP
# (216.120.200.40) directly.
# ==============================================================================

set -euo pipefail

TARGET_FILE="${1:-}"

# Official Cloudflare CIDRs (fallback list if curl fails)
CF_IPV4_FALLBACK="
173.245.48.0/20
103.21.244.0/22
103.22.200.0/22
103.31.4.0/22
141.101.64.0/18
108.162.192.0/18
190.93.240.0/20
188.114.96.0/20
197.234.240.0/22
198.41.128.0/17
162.158.0.0/15
104.16.0.0/13
104.24.0.0/14
172.64.0.0/13
131.0.72.0/22
"

CF_IPV6_FALLBACK="
2400:cb00::/32
2606:4700::/32
2803:f800::/32
2405:b500::/32
2405:8100::/32
2a06:98c0::/29
2c0f:f248::/32
"

fetch_ips() {
  local ipv4
  local ipv6
  ipv4=$(curl -sSL --connect-timeout 5 https://www.cloudflare.com/ips-v4 2>/dev/null || echo "$CF_IPV4_FALLBACK")
  ipv6=$(curl -sSL --connect-timeout 5 https://www.cloudflare.com/ips-v6 2>/dev/null || echo "$CF_IPV6_FALLBACK")

  echo "# === BEGIN CLOUDFLARE ORIGIN LOCKDOWN ==="
  echo "# Generated on $(date -u)"
  echo "# Direct requests to origin IP without passing through Cloudflare edge are rejected."
  echo "<IfModule mod_authz_core.c>"
  echo "  <RequireAny>"
  echo "    # Localhost & Server Loopback"
  echo "    Require ip 127.0.0.1"
  echo "    Require ip ::1"
  echo "    Require ip 216.120.200.40"
  echo ""
  echo "    # Cloudflare IPv4 Ranges"
  for ip in $ipv4; do
    [ -n "$ip" ] && echo "    Require ip $ip"
  done
  echo ""
  echo "    # Cloudflare IPv6 Ranges"
  for ip in $ipv6; do
    [ -n "$ip" ] && echo "    Require ip $ip"
  done

  if [ -n "${ORIGIN_EXTRA_ALLOWED_IPS:-}" ]; then
    echo ""
    echo "    # Custom Allowed IPs (USSD aggregators / Admin VPN)"
    for ip in $(echo "$ORIGIN_EXTRA_ALLOWED_IPS" | tr ',' ' '); do
      [ -n "$ip" ] && echo "    Require ip $ip"
    done
  fi
  echo "  </RequireAny>"
  echo "</IfModule>"
  echo "# === END CLOUDFLARE ORIGIN LOCKDOWN ==="
}

if [ -n "$TARGET_FILE" ]; then
  fetch_ips > "$TARGET_FILE"
  echo "Generated Cloudflare origin lockdown rules -> $TARGET_FILE"
else
  fetch_ips
fi
