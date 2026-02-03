FROM python:3.11-slim

WORKDIR /opt/app

# Install system deps for building wheels if needed
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    gcc \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt pytest

EXPOSE 5000

# Note: app files will be mounted as volumes at runtime
# Default to running tests, but can be overridden
CMD ["pytest", "-q", "test_agento_image_unified.py"]
