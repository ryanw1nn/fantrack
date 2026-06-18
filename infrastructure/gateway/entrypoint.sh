#!/bin/bash

echo "[INFO] Starting gateway configuration..."

REGISTRAR="/etc/nginx/registrar.json"
TEMP_CONF="/tmp/default.conf.tmp"
FINAL_CONF="/etc/nginx/conf.d/default.conf"
SCAFFOLDING_TEMPLATE="/etc/nginx/templates/default.conf.template"
CONFORMING_SNIPPET="/etc/nginx/templates/conforming_service_template"
AUTHENTICATING_SNIPPET="/etc/nginx/templates/authenticating_service_template"

# ── Helper: check if a service is resolvable on the Docker network ──
is_resolvable() {
    nslookup "$1" 127.0.0.11 > /dev/null 2>&1
}

# ── Helper: disable a hand-written location block using awk ──
# Used for auth-mock mode and infra (adminer/portainer/event-bus/cache).
# Per-module service blocks are handled by the generator in Step 3 —
# disabled catalog entries come out as 503 stubs directly, no awk needed.
disable_location_block() {
    local pattern="$1"
    local label="$2"
    awk -v pat="$pattern" -v lbl="$label" '
    $0 ~ pat { in_block = 1; brace_count = 0 }
    in_block {
        n = split($0, chars, "")
        for (i = 1; i <= n; i++) {
            if (chars[i] == "{") brace_count++
            if (chars[i] == "}") brace_count--
        }
        if ($0 ~ /proxy_pass/) { sub(/proxy_pass .*/, "return 503; # " lbl " is not available") }
        if ($0 ~ /auth_request/) { sub(/auth_request .*/, "# auth_request disabled - " lbl " is not available") }
        if (brace_count <= 0) in_block = 0
    }
    { print }
    ' "$TEMP_CONF" > "${TEMP_CONF}.awk" && mv "${TEMP_CONF}.awk" "$TEMP_CONF"
}

# ── Helper: render a snippet template with service placeholders ──
# Placeholders: @@SVC_NAME@@, @@SVC_PORT@@, @@SVC_VAR@@ (nginx-var-safe form
# of SVC_NAME — hyphens replaced by underscores).
render_service_snippet() {
    local template_path="$1"
    local svc_name="$2"
    local svc_port="$3"
    local svc_var="${svc_name//-/_}"
    sed \
        -e "s|@@SVC_NAME@@|$svc_name|g" \
        -e "s|@@SVC_PORT@@|$svc_port|g" \
        -e "s|@@SVC_VAR@@|$svc_var|g" \
        "$template_path"
}

# ── Helper: render an inline 503 stub for a registered-but-inactive service ──
render_disabled_stub() {
    local svc_name="$1"
    cat <<EOF
# ── ${svc_name} (disabled) ──
location /${svc_name}/ {
    access_log /dev/stdout;
    error_log /dev/stderr debug;
    return 503; # ${svc_name} is not available
}
EOF
}

# ── Step 1: Substitute envvars into the server-level scaffolding ──
# Only $SSL_CERTIFICATE_PATH is interpolated here; nginx's own variables
# (like $host, $scheme, $upstream_*) must NOT be passed to envsubst or
# they'd get clobbered by empty strings.
envsubst '$SSL_CERTIFICATE_PATH' < "$SCAFFOLDING_TEMPLATE" > "$TEMP_CONF"

# ── Step 2: Discover enabled module services from ACTIVE_MODULES + registrar.json ──
# ACTIVE_MODULES is a comma-separated list of module names passed from initialize.sh
# e.g. ACTIVE_MODULES="core-module,project-module,estimating-module"

if [ -z "$ACTIVE_MODULES" ]; then
    echo "[WARN] ACTIVE_MODULES is not set. No module services will be enabled."
    echo "[WARN] Gateway will only serve infrastructure endpoints."
fi

declare -A enabled_services

IFS=',' read -ra MODULE_LIST <<< "$ACTIVE_MODULES"
for module in "${MODULE_LIST[@]}"; do
    module=$(echo "$module" | xargs) # trim whitespace

    if ! jq -e --arg m "$module" '.[$m]' "$REGISTRAR" > /dev/null 2>&1; then
        echo "[WARN] Module '$module' not found in registrar.json, skipping"
        continue
    fi

    echo "[INFO] Module '$module' is active, enabling its services:"

    while IFS=$'\t' read -r service_name port; do
        echo "[INFO]   - $service_name (port $port)"
        enabled_services["$service_name"]=1
    done < <(jq -r --arg m "$module" '.[$m][] | [.[0], .[1]] | @tsv' "$REGISTRAR")
done

# Infrastructure services: respect gateway/.env flags, fall back to network probe
# when the flag is unset.
infra_flag_enabled() {
    local flag_value="$1"
    [[ "$flag_value" == "1" || "$flag_value" == "true" ]]
}

echo "[INFO] Resolving infrastructure services..."

for entry in "adminer:ADMINER" "portainer:PORTAINER" "event-bus:EVENTBUS" "laf-connector:LAFCONNECTOR"; do
    svc="${entry%:*}"
    flag_name="${entry#*:}"
    flag_value="${!flag_name}"

    if [ -n "$flag_value" ]; then
        if infra_flag_enabled "$flag_value"; then
            echo "[INFO]   - $svc is ENABLED via $flag_name=$flag_value"
            enabled_services["$svc"]=1
        else
            echo "[INFO]   - $svc is DISABLED via $flag_name=$flag_value"
        fi
    elif is_resolvable "$svc"; then
        echo "[INFO]   - $svc is UP (resolvable, no flag set)"
        enabled_services["$svc"]=1
    else
        echo "[INFO]   - $svc is DOWN (not resolvable, no flag set)"
    fi
done

# Unflagged infra services: probe only
for svc in global-cache-api; do
    if is_resolvable "$svc"; then
        echo "[INFO]   - $svc is UP (resolvable)"
        enabled_services["$svc"]=1
    else
        echo "[INFO]   - $svc is DOWN (not resolvable)"
    fi
done

echo "[INFO] All enabled services: ${!enabled_services[*]}"

# ── Step 3: Generate per-service location blocks from registrar.json ──
# Walk every module service in the catalog (excluding the "infrastructure"
# pseudo-module — those are hand-written in the scaffolding). For each
# [service_name, port] entry, render either the proxy snippet (if the
# service is enabled) or an inline 503 stub (if registered but not in
# ACTIVE_MODULES). The auth-service uses the authenticating_service_template
# (no auth_request, no derived Authorization header) because it IS the
# token validator — can't self-validate, and it reads the real Authorization
# header / cookie itself.

SERVICES_BUF_FILE="$(mktemp)"

while IFS=$'\t' read -r module_key svc_name svc_port; do
    # Skip the infrastructure pseudo-module — handled by hand-written
    # blocks in the scaffolding.
    [ "$module_key" = "infrastructure" ] && continue

    if [[ -v enabled_services["$svc_name"] ]]; then
        if [ "$svc_name" = "auth-service" ]; then
            snippet="$AUTHENTICATING_SNIPPET"
        else
            snippet="$CONFORMING_SNIPPET"
        fi
        echo "[INFO] Rendering ENABLED block for $svc_name (port $svc_port)"
        render_service_snippet "$snippet" "$svc_name" "$svc_port" >> "$SERVICES_BUF_FILE"
    else
        echo "[INFO] Rendering 503 stub for $svc_name (cataloged, not active)"
        render_disabled_stub "$svc_name" >> "$SERVICES_BUF_FILE"
    fi
    # Blank line between blocks for readability of the rendered config.
    echo "" >> "$SERVICES_BUF_FILE"
done < <(jq -r 'to_entries[] | .key as $m | .value[] | [$m, .[0], .[1]] | @tsv' "$REGISTRAR")

# Substitute the rendered buffer in place of the marker. Use awk to avoid
# sed pitfalls with multiline replacement content (and with special chars
# like $, /, {} inside nginx directives). The marker line itself is
# dropped via `next`.
awk -v buf_file="$SERVICES_BUF_FILE" '
BEGIN {
    buf = ""
    while ((getline line < buf_file) > 0) {
        buf = buf (buf == "" ? "" : "\n") line
    }
    close(buf_file)
}
/# @@SERVICE_LOCATIONS@@/ { print buf; next }
{ print }
' "$TEMP_CONF" > "${TEMP_CONF}.awk" && mv "${TEMP_CONF}.awk" "$TEMP_CONF"
rm -f "$SERVICES_BUF_FILE"

# ── Step 4: Handle auth mock mode ──
# If auth-service is NOT active and AUTH_MODE=mock, neutralize the
# scaffolding's public auth endpoints (/me, /login, /sign-in) and the
# /validate-token sub-request so the SPA doesn't fail against a missing
# upstream. The /auth-service/ block itself was emitted as a 503 stub
# by Step 3 — no additional work needed for that path here.
if [[ -v enabled_services["auth-service"] ]]; then
    echo "[INFO] auth-service is active, token validation enabled"
else
    echo "[INFO] auth-service is NOT active"
    AUTH_MODE="${AUTH_MODE:-mock}"
    echo "[INFO] AUTH_MODE=$AUTH_MODE"

    if [ "$AUTH_MODE" = "mock" ]; then
        echo "[INFO] Disabling token validation (mock mode)"
        # Remove auth_request directives in the scaffolding (the fixed
        # auth endpoints and /cache/). Generated service blocks are
        # handled independently by Step 3 (503 stubs don't have
        # auth_request to begin with).
        sed -i'' 's|auth_request /validate-token;|# auth_request /validate-token; # Mock auth|g' "$TEMP_CONF"
        # Comment out auth-service upstream sets in scaffolding
        # (/me, /login, /sign-in, /sign-out, /validate-token each have
        # their own `set $upstream_auth … = http://auth-service:…;`).
        sed -i'' "s|set \$[a-zA-Z_]* http://auth-service:[0-9]*;|# DISABLED: auth-service (mock mode)|g" "$TEMP_CONF"
        # Replace the /validate-token block's proxy_pass with return 200
        # so auth_request sub-requests (if any survived the sed above)
        # always succeed.
        awk '
        /location = \/validate-token/ { in_block = 1; brace_count = 0 }
        in_block {
            n = split($0, chars, "")
            for (i = 1; i <= n; i++) {
                if (chars[i] == "{") brace_count++
                if (chars[i] == "}") brace_count--
            }
            if ($0 ~ /proxy_pass/) { sub(/proxy_pass .*/, "return 200; # Mock auth - always passes") }
            if (brace_count <= 0) in_block = 0
        }
        { print }
        ' "$TEMP_CONF" > "${TEMP_CONF}.awk" && mv "${TEMP_CONF}.awk" "$TEMP_CONF"
        # Disable the scaffolding's public auth endpoints.
        disable_location_block "location = /me" "auth-service"
        disable_location_block "location = /login" "auth-service"
        disable_location_block "location = /sign-in" "auth-service"
    else
        echo "[INFO] AUTH_MODE=strict, keeping validation (will return 502 if auth-service is down)"
    fi
fi

# ── Step 5: Disable hand-written infra blocks for services that aren't up ──
if [[ ! -v enabled_services["adminer"] ]]; then
    echo "[INFO] Disabling adminer location block"
    disable_location_block "location /adminer/" "adminer"
fi

if [[ ! -v enabled_services["portainer"] ]]; then
    echo "[INFO] Disabling portainer location block"
    disable_location_block "location /portainer/" "portainer"
fi

if [[ ! -v enabled_services["event-bus"] ]]; then
    echo "[INFO] Disabling event-bus location block"
    disable_location_block "location /event-bus/" "event-bus"
fi

if [[ ! -v enabled_services["laf-connector"] ]]; then
    echo "[INFO] Disabling laf-connector location block"
    disable_location_block "location /laf-connector/" "laf-connector"
fi

if [[ ! -v enabled_services["global-cache-api"] ]]; then
    echo "[INFO] Disabling /cache/ location block (global-cache-api not available)"
    disable_location_block "location /cache/" "global-cache-api"
fi

# ── Apply final config and start nginx ──
mv "$TEMP_CONF" "$FINAL_CONF"

echo "[INFO] Configuration updated successfully"
echo "[INFO] Starting Nginx..."
exec nginx -g 'daemon off;'
