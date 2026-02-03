#!/bin/bash
# Integration test script for the image server
# This script starts the server, runs tests, and cleans up

set -e

echo "🚀 Starting integration tests for Agento Image Server"

# Check if API key is set
if [ -z "$GEMINI_API_KEY" ]; then
    echo "❌ GEMINI_API_KEY environment variable is not set"
    echo "Please set it with: export GEMINI_API_KEY='your-api-key'"
    exit 1
fi

# Start server in background
echo "📡 Starting server..."
docker-compose up -d server

# Wait for server to be ready
echo "⏳ Waiting for server to be ready..."
sleep 5

# Check health
echo "🏥 Checking server health..."
if ! curl -s http://localhost:5000/health > /dev/null; then
    echo "❌ Server is not responding"
    docker-compose down
    exit 1
fi

echo "✅ Server is healthy"

# Run tests
echo "🧪 Running server tests..."
if python3 test_server.py; then
    echo "✅ All server tests passed!"
else
    echo "❌ Server tests failed"
    docker-compose down
    exit 1
fi

# Cleanup
echo "🧹 Cleaning up..."
docker-compose down

echo "🎉 Integration tests completed successfully!"