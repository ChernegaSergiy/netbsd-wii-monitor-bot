FROM node:20-slim

# Install Chromium and dependencies for ARM64
RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    fonts-liberation \
    fonts-noto-color-emoji \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcups2 \
    libdrm2 \
    libgbm1 \
    libnss3 \
    libxcomposite1 \
    libxdamage1 \
    libxrandr2 \
    xdg-utils \
    php-cli \
    php-curl \
    php-sqlite3 \
    php-mbstring \
    composer \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Tell puppeteer-core where Chromium is
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true

WORKDIR /app

# Copy and install Node.js dependencies
COPY puppeteer-server/package.json puppeteer-server/
RUN cd puppeteer-server && npm install --production

# Copy and install PHP dependencies
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader

# Copy the rest of the application
COPY . .

# Create data directory for SQLite
RUN mkdir -p /app/data && chmod 777 /app/data

# Copy supervisord config
COPY supervisord.conf /etc/supervisor/conf.d/bot.conf

EXPOSE 3000

CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/bot.conf"]
