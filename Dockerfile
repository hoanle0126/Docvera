FROM php:8.3-cli-bookworm

LABEL org.opencontainers.image.title="Docvera" \
      org.opencontainers.image.description="Privacy-First, Self-Hosted Document Processing Platform & REST API" \
      org.opencontainers.image.licenses="Apache-2.0"

# Install required PHP extensions and full native document processing toolchain
# (QPDF, Poppler, Ghostscript, LibreOffice, Tesseract OCR) so all 22 tools are active.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ghostscript \
        poppler-utils \
        qpdf \
        libreoffice-core \
        libreoffice-writer \
        libreoffice-calc \
        libreoffice-impress \
        tesseract-ocr \
        tesseract-ocr-eng \
        fonts-dejavu-core \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libsqlite3-dev \
        libonig-dev \
        ca-certificates \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        mbstring \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Create unprivileged runtime user
RUN groupadd -g 10001 docvera \
    && useradd -u 10001 -g docvera -m -s /bin/sh docvera

WORKDIR /app

COPY --chown=docvera:docvera . /app

RUN mkdir -p \
        /app/storage/app/jobs \
        /app/storage/app/staged \
        /app/storage/app/database \
        /app/storage/logs \
    && chmod -R 700 /app/storage \
    && chown -R docvera:docvera /app/storage

USER docvera

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/healthz || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]

