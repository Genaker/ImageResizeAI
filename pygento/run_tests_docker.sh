#!/usr/bin/env bash
set -euo pipefail

# Run tests using Docker Compose with volume mounts
cd "$(dirname "$0")"

echo "Building Docker image with docker-compose..."
docker-compose build

echo "Running tests with volume mount for live code reload..."
echo ""
echo "To run with real API test enabled:"
echo "  GEMINI_API_KEY='your-api-key' RUN_REAL_API_TEST=1 docker-compose run tests"
echo ""

docker-compose run --rm tests
