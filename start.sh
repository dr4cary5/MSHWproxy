#!/bin/bash
# MSHW-proxy - Startup Script for GitHub Actions + ngrok
# Starts PHP server, ttyd, and ngrok tunnel

set -e  # Exit on error

# Configuration (from environment variables)
PROXY_PORT="${PROXY_PORT:-8080}"
TTY_PORT="${TTY_PORT:-7681}"
NGROK_CONFIG="${NGROK_CONFIG:-ngrok.yml}"
LOG_FILE="/tmp/mshw-proxy.log"

echo "🚀 Starting MSHW-proxy..."
echo "   Proxy Port: $PROXY_PORT"
echo "   TTY Port: $TTY_PORT"
echo "   Log File: $LOG_FILE"

# Function to log with timestamp
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# Wait for dependencies
wait_for_composer() {
    if [ ! -d "vendor" ]; then
        log "⏳ Installing Composer dependencies..."
        composer install --no-dev --optimize-autoloader --quiet
        log "✅ Dependencies installed"
    fi
}

# Start PHP built-in server
start_php_server() {
    log "📦 Starting PHP server on port $PROXY_PORT..."
    php -S "0.0.0.0:$PROXY_PORT" -t public/ >> "$LOG_FILE" 2>&1 &
    PHP_PID=$!
    echo $PHP_PID > /tmp/php.pid
    
    # Wait for server to be ready
    for i in {1..30}; do
        if curl -s "http://localhost:$PROXY_PORT/" > /dev/null 2>&1; then
            log "✅ PHP server ready (PID: $PHP_PID)"
            return 0
        fi
        sleep 1
    done
    log "❌ PHP server failed to start"
    return 1
}

# Start ttyd for web terminal
start_ttyd() {
    if [ "${TTY_ENABLED:-true}" = "true" ]; then
        log "🔧 Starting ttyd on port $TTY_PORT..."
        # Install ttyd if not present
        if ! command -v ttyd &> /dev/null; then
            log "⏳ Installing ttyd..."
            curl -LO https://github.com/tsl0922/ttyd/releases/download/1.7.4/ttyd.x86_64
            chmod +x ttyd.x86_64 && sudo mv ttyd.x86_64 /usr/local/bin/ttyd
        fi
        
        # Start ttyd with auth
        TTY_PASS="${TTY_AUTH:-${DASHBOARD_PASS:-proxy}}"
        ttyd -p "$TTY_PORT" -c "user:$TTY_PASS" bash >> "$LOG_FILE" 2>&1 &
        TTY_PID=$!
        echo $TTY_PID > /tmp/ttyd.pid
        log "✅ ttyd ready (PID: $TTY_PID)"
    fi
}

# Start ngrok with dual tunnels
start_ngrok() {
    log "🌐 Starting ngrok tunnels..."
    
    # Install ngrok if not present
    if ! command -v ngrok &> /dev/null; then
        log "⏳ Installing ngrok..."
        curl -sSL https://ngrok-agent.s3.amazonaws.com/ngrok.asc | sudo tee /etc/apt/trusted.gpg.d/ngrok.asc >/dev/null
        echo "deb https://ngrok-agent.s3.amazonaws.com bookworm main" | sudo tee /etc/apt/sources.list.d/ngrok.list
        sudo apt update && sudo apt install -y ngrok
    fi
    
    # Configure auth token
    ngrok config add-authtoken "$NGROK_AUTH_TOKEN" 2>/dev/null || true
    
    # Generate ngrok.yml if not exists
    if [ ! -f "$NGROK_CONFIG" ]; then
        cat > "$NGROK_CONFIG" << EOF
version: 2
authtoken: $NGROK_AUTH_TOKEN
tunnels:
  proxy:
    proto: http
    addr: $PROXY_PORT
    hostname: ${NGROK_HOSTNAME:-}
    request_header:
      add:
        ngrok-skip-browser-warning: "true"
  tty:
    proto: http
    addr: $TTY_PORT
    request_header:
      add:
        ngrok-skip-browser-warning: "true"
EOF
    fi
    
    # Start ngrok with config
    ngrok start --all --config "$NGROK_CONFIG" >> "$LOG_FILE" 2>&1 &
    NGROK_PID=$!
    echo $NGROK_PID > /tmp/ngrok.pid
    
    # Wait for tunnels to be ready
    for i in {1..20}; do
        if curl -s http://127.0.0.1:4040/api/tunnels | grep -q "public_url"; then
            log "✅ ngrok tunnels ready (PID: $NGROK_PID)"
            return 0
        fi
        sleep 2
    done
    log "❌ ngrok failed to establish tunnels"
    return 1
}

# Health check loop (keep-alive for GitHub Actions)
run_health_check() {
    log "🔄 Starting health monitor..."
    while true; do
        # Check PHP server
        if ! kill -0 $(cat /tmp/php.pid 2>/dev/null) 2>/dev/null; then
            log "⚠️ PHP server down, restarting..."
            start_php_server
        fi
        
        # Check ngrok
        if ! kill -0 $(cat /tmp/ngrok.pid 2>/dev/null) 2>/dev/null; then
            log "⚠️ ngrok down, restarting..."
            start_ngrok
        fi
        
        # Log heartbeat
        log "💓 Heartbeat - all services running"
        
        # Sleep before next check (5 minutes)
        sleep 300
    done
}

# Extract and display public URLs
show_urls() {
    sleep 5  # Wait for ngrok API to populate
    PROXY_URL=$(curl -s http://127.0.0.1:4040/api/tunnels | jq -r '.tunnels[] | select(.config.addr == "'$PROXY_PORT'") | .public_url' | head -1)
    TTY_URL=$(curl -s http://127.0.0.1:4040/api/tunnels | jq -r '.tunnels[] | select(.config.addr == "'$TTY_PORT'") | .public_url' | head -1)
    
    echo ""
    echo "========================================="
    echo "🎉 MSHW-proxy Deployed Successfully!"
    echo "========================================="
    echo "🌐 Proxy & Dashboard: $PROXY_URL"
    echo "🔧 Web Terminal (TTY): $TTY_URL"
    echo ""
    echo "📋 Quick Test:"
    echo "   $PROXY_URL/?q=$(echo -n 'https://example.com' | base64)"
    echo ""
    echo "🔐 Dashboard Login: Use DASHBOARD_PASS secret"
    echo "========================================="
    echo ""
    
    # Save to GitHub summary (if available)
    if [ -n "$GITHUB_STEP_SUMMARY" ]; then
        cat >> "$GITHUB_STEP_SUMMARY" << EOF
## 🎉 MSHW-proxy Deployed

| Service | URL |
|---------|-----|
| 🌐 Proxy & Dashboard | \`$PROXY_URL\` |
| 🔧 Web Terminal | \`$TTY_URL\` |

**Quick Test:**  
\`$PROXY_URL/?q=$(echo -n 'https://example.com' | base64)\`

> 🔐 Use \`DASHBOARD_PASS\` secret for login
EOF
    fi
}

# Main execution
main() {
    wait_for_composer
    start_php_server
    start_ttyd
    start_ngrok
    show_urls
    
    # Start health monitor in background
    run_health_check &
    MONITOR_PID=$!
    
    # Keep script running (GitHub Actions will timeout after 6h)
    log "🔄 Keeping alive... (max 6 hours)"
    wait $MONITOR_PID
}

# Run
main "$@"
