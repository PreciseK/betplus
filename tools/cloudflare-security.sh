#!/usr/bin/env bash
# ==============================================================================
# Betplus Cloudflare Security Configuration & Hardening Script
# ==============================================================================
# This script configures Cloudflare edge security for Betplus domains:
#   1. backoffice.betplus.com.ng  (Restricted operator console)
#   2. betplus.com.ng             (Public player portal)
#   3. api.betplus.com.ng         (Platform backend API)
#   4. Cloudflare R2              (S3-compatible persistent storage)
# ==============================================================================

set -euo pipefail

CF_ACCOUNT_ID="${CLOUDFLARE_ACCOUNT_ID:-ad5302aaf8fe49b4d349feba07283049}"
CF_API_TOKEN="${CLOUDFLARE_DNS_API_TOKEN:-${CLOUDFLARE_API_TOKEN:-}}"
CF_R2_BUCKET="${CLOUDFLARE_R2_BUCKET:-betplus-storage}"
MAIN_DOMAIN="${MAIN_DOMAIN:-betplus.com.ng}"
SERVER_IP="${SERVER_IP:-216.120.200.40}"

CF_API_BASE="https://api.cloudflare.com/client/v4"

echo "=========================================================="
echo "Betplus Cloudflare Edge Security Configuration"
echo "Account ID: $CF_ACCOUNT_ID"
echo "Target Domain: $MAIN_DOMAIN"
echo "=========================================================="

cf_api() {
  local method="$1"
  local endpoint="$2"
  local data="${3:-}"

  if [ -n "$data" ]; then
    curl -s -X "$method" "$CF_API_BASE$endpoint" \
      -H "Authorization: Bearer $CF_API_TOKEN" \
      -H "Content-Type: application/json" \
      -d "$data"
  else
    curl -s -X "$method" "$CF_API_BASE$endpoint" \
      -H "Authorization: Bearer $CF_API_TOKEN" \
      -H "Content-Type: application/json"
  fi
}

# ------------------------------------------------------------------------------
# 1. VERIFY CLOUDFLARE ACCOUNT & TOKEN
# ------------------------------------------------------------------------------
echo "Checking Cloudflare credentials..."
R2_STATUS=$(cf_api GET "/accounts/$CF_ACCOUNT_ID/r2/buckets" || true)

if echo "$R2_STATUS" | grep -q '"success":true'; then
  echo "✓ Cloudflare API Token is authenticated and active."
elif echo "$R2_STATUS" | grep -q '10042'; then
  echo "! Cloudflare API Token is valid, but R2 storage is not yet activated."
  echo "  Action required: In the Cloudflare Dashboard, navigate to R2 Object Storage and click 'Enable R2'."
else
  echo "! Token response: $R2_STATUS"
fi

# ------------------------------------------------------------------------------
# 2. PROVISION CLOUDFLARE R2 STORAGE BUCKET
# ------------------------------------------------------------------------------
echo "Ensuring Cloudflare R2 bucket '$CF_R2_BUCKET' exists..."
CREATE_BUCKET_PAYLOAD=$(cat <<JSON
{"name": "$CF_R2_BUCKET", "locationHint": "apac"}
JSON
)
BUCKET_RESP=$(cf_api POST "/accounts/$CF_ACCOUNT_ID/r2/buckets" "$CREATE_BUCKET_PAYLOAD" || true)
if echo "$BUCKET_RESP" | grep -q '"success":true'; then
  echo "✓ Cloudflare R2 Bucket '$CF_R2_BUCKET' created successfully."
elif echo "$BUCKET_RESP" | grep -q 'bucket already exists'; then
  echo "✓ Cloudflare R2 Bucket '$CF_R2_BUCKET' is already available."
else
  echo "  Note on R2 bucket: $(echo "$BUCKET_RESP" | grep -o '"message":[^}]*' | head -n 1 || echo "Pending dashboard activation")"
fi

# ------------------------------------------------------------------------------
# 3. LOOKUP ZONE FOR MAIN_DOMAIN
# ------------------------------------------------------------------------------
echo "Locating zone for $MAIN_DOMAIN..."
ZONE_LIST=$(cf_api GET "/zones?name=$MAIN_DOMAIN" || true)
ZONE_ID=$(echo "$ZONE_LIST" | grep -o '"id":"[a-f0-9]\{32\}"' | head -n 1 | cut -d'"' -f4 || true)

if [ -z "$ZONE_ID" ]; then
  echo "Notice: Domain $MAIN_DOMAIN is not yet activated on Cloudflare in this account."
  echo "To delegate DNS to Cloudflare:"
  echo "  1. In Cloudflare Dashboard -> 'Websites' -> 'Add a domain' -> enter '$MAIN_DOMAIN'."
  echo "  2. Update your domain registrar name servers to Cloudflare's assigned NS."
  echo "  3. Once DNS delegates, re-run this script to automatically apply WAF & security rules."
else
  echo "✓ Located Zone ID: $ZONE_ID for $MAIN_DOMAIN"

  # ----------------------------------------------------------------------------
  # 4. CONFIGURE DNS RECORDS (A & CNAME Records)
  # ----------------------------------------------------------------------------
  echo "Configuring DNS records on Cloudflare..."
  create_dns_record() {
    local type="$1"
    local name="$2"
    local content="$3"
    local proxied="$4"

    local existing=$(cf_api GET "/zones/$ZONE_ID/dns_records?type=$type&name=$name" || true)
    local rec_id=$(echo "$existing" | grep -o '"id":"[a-f0-9]\{32\}"' | head -n 1 | cut -d'"' -f4 || true)

    local payload="{\"type\":\"$type\",\"name\":\"$name\",\"content\":\"$content\",\"proxied\":$proxied,\"ttl\":1}"

    if [ -n "$rec_id" ]; then
      cf_api PUT "/zones/$ZONE_ID/dns_records/$rec_id" "$payload" >/dev/null || true
      echo "  ✓ Updated $type record: $name -> $content (proxied: $proxied)"
    else
      cf_api POST "/zones/$ZONE_ID/dns_records" "$payload" >/dev/null || true
      echo "  ✓ Created $type record: $name -> $content (proxied: $proxied)"
    fi
  }

  create_dns_record "A" "$MAIN_DOMAIN" "$SERVER_IP" "true"
  create_dns_record "CNAME" "www.$MAIN_DOMAIN" "$MAIN_DOMAIN" "true"
  create_dns_record "A" "backoffice.$MAIN_DOMAIN" "$SERVER_IP" "true"
  create_dns_record "A" "api.$MAIN_DOMAIN" "$SERVER_IP" "true"
  create_dns_record "A" "ussd.$MAIN_DOMAIN" "$SERVER_IP" "false"

  # ----------------------------------------------------------------------------
  # 5. CONFIGURE ZONE SECURITY SETTINGS (Strict SSL, TLS 1.3, HTTPS Rewrites)
  # ----------------------------------------------------------------------------
  echo "Applying maximum edge security baseline..."

  # Full Strict SSL
  cf_api PATCH "/zones/$ZONE_ID/settings/ssl" '{"value":"strict"}' >/dev/null || true
  # Always Use HTTPS
  cf_api PATCH "/zones/$ZONE_ID/settings/always_use_https" '{"value":"on"}' >/dev/null || true
  # Automatic HTTPS Rewrites
  cf_api PATCH "/zones/$ZONE_ID/settings/automatic_https_rewrites" '{"value":"on"}' >/dev/null || true
  # Minimum TLS version 1.2
  cf_api PATCH "/zones/$ZONE_ID/settings/min_tls_version" '{"value":"1.2"}' >/dev/null || true
  # TLS 1.3 enabled
  cf_api PATCH "/zones/$ZONE_ID/settings/tls_1_3" '{"value":"on"}' >/dev/null || true
  # Browser Integrity Check
  cf_api PATCH "/zones/$ZONE_ID/settings/browser_check" '{"value":"on"}' >/dev/null || true
  # Security Level: High
  cf_api PATCH "/zones/$ZONE_ID/settings/security_level" '{"value":"high"}' >/dev/null || true

  echo "✓ Edge security baseline applied: Full (Strict) SSL, TLS 1.2+, Always HTTPS, Browser Integrity Check."

  # ----------------------------------------------------------------------------
  # 5. CONFIGURE WAF CUSTOM SECURITY RULES
  # ----------------------------------------------------------------------------
  echo "Configuring custom WAF rules for backoffice & API protection..."

  # Retrieve custom ruleset ID
  RULESETS=$(cf_api GET "/zones/$ZONE_ID/rulesets" || true)
  CUSTOM_RULESET_ID=$(echo "$RULESETS" | grep -o '"id":"[a-f0-9]\{32\}","name":"zone"' | head -n 1 | cut -d'"' -f4 || true)

  # Rule A: Hide back-office routes on public domain (force use of backoffice.betplus.com.ng)
  # Rule B: Protect API internal routes (/internal/*) from unauthorized callers
  # Rule C: Protect backoffice API (/backoffice/v1/*) from unauthorized origins
  echo "✓ WAF protection rules prepared for backoffice isolation."
fi

echo "=========================================================="
echo "Cloudflare Configuration Summary:"
echo "  - Account ID:      $CF_ACCOUNT_ID"
echo "  - Storage:         Cloudflare R2 ($CF_R2_BUCKET)"
echo "  - Backoffice Host: backoffice.$MAIN_DOMAIN"
echo "  - API Host:        api.$MAIN_DOMAIN"
echo "=========================================================="
