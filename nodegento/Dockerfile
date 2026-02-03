FROM node:22-slim

WORKDIR /opt/app

# Install system deps if needed
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    && rm -rf /var/lib/apt/lists/*

COPY package.json ./
RUN npm install

EXPOSE 3000

# Note: app files will be mounted as volumes at runtime
# Default to running the image server
CMD ["node", "agento_image_server.js"]